<?php

namespace AshrafTalha\OpConsole\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Viewer for the configured log directory. Large files are read from the end,
 * so the newest entries are always there and the page stays fast.
 */
class LogViewer
{
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** Levels that count as errors in the summary and the "recent errors" advice. */
    public const ERROR_LEVELS = ['emergency', 'alert', 'critical', 'error'];

    /** Bytes read from the end of a file; older entries are skipped (the file can still be downloaded). */
    private const TAIL_BYTES = 2 * 1024 * 1024;

    private const ENTRY_START = '/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.]+(?:[+-]\d{2}:?\d{2}|Z)?)\] ([\w.-]+)\.([A-Za-z]+):[ \t]*/m';

    /** @var array<string, array{signature: string, files: list<array{name: string, size: int, modified: Carbon}>}> */
    private array $filesCache = [];

    /** @var array<string, array{mtime: int, size: int, entries: list<array{time: string, env: string, level: string, message: string, details: string}>, truncated: bool}> */
    private array $parsedCache = [];

    /** @return list<array{name: string, size: int, modified: Carbon}> newest first */
    public function files(): array
    {
        $dir = $this->directory();
        if (! is_dir($dir) || ! is_readable($dir)) {
            return [];
        }

        $candidates = glob($dir.'/*.log') ?: [];
        $signature = implode('|', array_map(fn ($p) => $p.':'.(@filemtime($p) ?: 0).':'.(@filesize($p) ?: 0), $candidates));

        if (isset($this->filesCache[$dir]) && $this->filesCache[$dir]['signature'] === $signature) {
            return $this->filesCache[$dir]['files'];
        }

        $valid = [];
        foreach ($candidates as $candidate) {
            $name = basename($candidate);
            $realPath = $this->path($name);
            if ($realPath === null) {
                continue;
            }

            $size = @filesize($realPath);
            $mtime = @filemtime($realPath);
            if ($size === false || $mtime === false) {
                continue;
            }

            $valid[] = [
                'name' => $name,
                'size' => $size,
                'modified' => Carbon::createFromTimestamp($mtime),
                'mtime' => $mtime,
            ];
        }

        usort($valid, fn ($a, $b) => ($b['mtime'] <=> $a['mtime']) ?: strcmp($b['name'], $a['name']));

        $files = array_map(fn ($item) => [
            'name' => $item['name'],
            'size' => $item['size'],
            'modified' => $item['modified'],
        ], $valid);

        $this->filesCache[$dir] = ['signature' => $signature, 'files' => $files];

        return $files;
    }

    public function totalSize(): int
    {
        return array_sum(array_column($this->files(), 'size'));
    }

    /** The file's full, real path, or null unless it is a readable .log file directly inside the logs directory. */
    public function path(?string $name): ?string
    {
        if (! is_string($name) || ! preg_match('/^[\w.-]+\.log$/', $name)) {
            return null;
        }

        $directory = @realpath($this->directory());
        if ($directory === false) {
            return null;
        }

        $path = @realpath($directory.DIRECTORY_SEPARATOR.$name);

        // realpath resolves symlinks: a .log symlink that escapes the logs directory is refused.
        if ($path === false || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($path) && is_readable($path) ? $path : null;
    }

    /**
     * Newest entries first, filtered by level / environment / text.
     *
     * @param  ?string  $env  null = every environment
     * @return array{entries: list<array>, counts: array<string, int>, envs: array<string, int>, groups: list<array>, total: int, allTotal: int, shown: int, truncated: bool, more: bool, resolvedEnv: ?string}
     */
    public function read(string $name, ?string $level = null, ?string $env = null, ?string $search = null, int $limit = 50, bool $fallbackToAllEnvs = false): array
    {
        $realPath = $this->path($name) ?? throw new \InvalidArgumentException("Unknown log file [$name].");
        ['entries' => $all, 'truncated' => $truncated] = $this->parsed($realPath);

        $envs = array_count_values(array_column($all, 'env'));
        if ($fallbackToAllEnvs && $env !== null && ! isset($envs[$env])) {
            $env = null;
        }

        // the environment filter applies first, so level counts and groups describe what is on screen
        $inEnv = $env === null ? $all : array_values(array_filter($all, fn ($entry) => $entry['env'] === $env));
        $hasSearch = filled($search);
        $matching = ($level === null && ! $hasSearch)
            ? $inEnv
            : array_values(array_filter($inEnv, fn ($entry) => (! $level || $entry['level'] === $level)
                && (! $hasSearch || Str::contains($entry['message'].' '.$entry['details'], (string) $search, ignoreCase: true))));

        return [
            'entries' => array_slice(array_reverse($matching), 0, $limit),
            'counts' => array_filter(array_merge(array_fill_keys(self::LEVELS, 0), array_count_values(array_column($inEnv, 'level')))),
            'envs' => $envs,
            'groups' => $this->groups($matching),
            'total' => count($inEnv),
            'allTotal' => count($all),
            'shown' => count($matching),
            'truncated' => $truncated,
            'more' => count($matching) > $limit,
            'resolvedEnv' => $env,
        ];
    }

    /**
     * Errors in the newest valid log file during the last $hours, in this app's environment.
     *
     * @return array{count: int, last: ?string}
     */
    public function recentErrors(int $hours = 24): array
    {
        $newest = $this->files()[0]['name'] ?? null;
        $realPath = $newest !== null ? $this->path($newest) : null;
        if ($realPath === null) {
            return ['count' => 0, 'last' => null];
        }

        $since = now()->subHours($hours);
        $cutoff = $since->copy()->subMinutes(5);
        $currentEnv = app()->environment();
        $entries = $this->parsed($realPath)['entries'];

        $count = 0;
        $latestTime = null;

        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $entry = $entries[$i];
            if ($entry['env'] !== $currentEnv || ! in_array($entry['level'], self::ERROR_LEVELS, true)) {
                continue;
            }

            $parsedTime = $this->time($entry['time']);
            if ($parsedTime === null) {
                continue;
            }

            if ($parsedTime->greaterThanOrEqualTo($since)) {
                $count++;
                $latestTime ??= $parsedTime;
            } elseif ($parsedTime->lessThan($cutoff)) {
                break;
            }
        }

        return [
            'count' => $count,
            'last' => $latestTime ? $latestTime->locale('en')->diffForHumans() : null,
        ];
    }

    public function download(string $name): BinaryFileResponse
    {
        return response()->download($this->path($name) ?? abort(404), $name, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Delete an old log file. The newest file is still being written to, so it stays. */
    public function delete(string $name): bool
    {
        return $this->deleteOutcome($name) === 'deleted';
    }

    /** @return 'deleted'|'latest_blocked'|'not_found'|'failed' */
    public function deleteOutcome(string $name): string
    {
        $path = $this->path($name);
        if ($path === null) {
            return 'not_found';
        }

        if ($name === ($this->files()[0]['name'] ?? null)) {
            return 'latest_blocked';
        }

        if (! @unlink($path)) {
            return 'failed';
        }

        unset($this->filesCache[$this->directory()], $this->parsedCache[$path]);

        return 'deleted';
    }

    private function directory(): string
    {
        return config('opconsole.logs.path') ?: storage_path('logs');
    }

    private function time(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{entries: list<array{time: string, env: string, level: string, message: string, details: string}>, truncated: bool}
     */
    private function parsed(string $realPath): array
    {
        $mtime = @filemtime($realPath) ?: 0;
        $size = @filesize($realPath) ?: 0;

        if (isset($this->parsedCache[$realPath])
            && $this->parsedCache[$realPath]['mtime'] === $mtime
            && $this->parsedCache[$realPath]['size'] === $size) {
            return [
                'entries' => $this->parsedCache[$realPath]['entries'],
                'truncated' => $this->parsedCache[$realPath]['truncated'],
            ];
        }

        [$content, $truncated] = $this->tail($realPath);
        $entries = $this->parse($content);

        $this->parsedCache[$realPath] = [
            'mtime' => $mtime,
            'size' => $size,
            'entries' => $entries,
            'truncated' => $truncated,
        ];

        return ['entries' => $entries, 'truncated' => $truncated];
    }

    /** @return array{0: string, 1: bool} the last TAIL_BYTES of the file, and whether anything was cut off */
    private function tail(string $path): array
    {
        $size = @filesize($path);
        $handle = @fopen($path, 'rb');
        if ($size === false || $handle === false) {
            return ['', false];
        }

        $truncated = $size > self::TAIL_BYTES;
        if ($truncated) {
            @fseek($handle, -self::TAIL_BYTES, SEEK_END);
        }
        $content = stream_get_contents($handle, self::TAIL_BYTES);
        fclose($handle);

        if (! is_string($content) || $content === '') {
            return ['', $truncated];
        }

        return [mb_scrub($content, 'UTF-8'), $truncated];
    }

    /** @return list<array{time: string, env: string, level: string, message: string, details: string}> oldest first */
    private function parse(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $parts = preg_split(self::ENTRY_START, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return [];
        }

        if (count($parts) <= 1) {
            return $this->parseJsonLines($content);
        }

        array_shift($parts); // text before the first entry header: empty, or the cut-off end of an older entry

        $entries = [];
        foreach (array_chunk($parts, 4) as $chunk) {
            if (count($chunk) < 4) {
                continue; // malformed / unparsable tail chunk: skip rather than warn
            }
            [$time, $env, $level, $body] = $chunk;
            $body = rtrim($body);
            [$firstLine] = explode("\n", $body, 2);
            $firstLine = rtrim($firstLine, "\r");
            // the summary is the message without its JSON context ({"exception": ...})
            $message = trim(Str::before($firstLine, ' {"')) ?: $firstLine;
            $normalizedLevel = strtolower($level);

            $entries[] = [
                'time' => $time,
                'env' => $env,
                'level' => in_array($normalizedLevel, self::LEVELS, true) ? $normalizedLevel : 'info',
                'message' => Str::limit($message, 400),
                'details' => $body === $message ? '' : Str::limit($body, 20000),
            ];
        }

        return $entries;
    }

    /**
     * Straightforward support for Monolog JsonFormatter / NDJSON log lines when no
     * standard "[timestamp] env.LEVEL:" headers exist in the file.
     *
     * @return list<array{time: string, env: string, level: string, message: string, details: string}>
     */
    private function parseJsonLines(string $content): array
    {
        $entries = [];

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] !== '{') {
                continue;
            }

            $decoded = json_decode($trimmed, true);
            if (! is_array($decoded) || ! isset($decoded['message']) || (! is_string($decoded['message']) && ! is_numeric($decoded['message']))) {
                continue;
            }

            $rawLevel = $decoded['level_name'] ?? $decoded['level'] ?? null;
            if ($rawLevel === null) {
                continue;
            }

            $level = $this->normalizeJsonLevel($rawLevel);
            $time = (string) ($decoded['datetime'] ?? $decoded['timestamp'] ?? $decoded['time'] ?? '');
            $env = (string) ($decoded['channel'] ?? $decoded['env'] ?? $decoded['environment'] ?? app()->environment());
            $message = trim((string) $decoded['message']);

            $extraPayload = array_filter([
                'context' => $decoded['context'] ?? null,
                'extra' => $decoded['extra'] ?? null,
            ], fn ($v) => ! empty($v));

            $details = $extraPayload !== []
                ? (json_encode($extraPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
                : '';

            $entries[] = [
                'time' => $time,
                'env' => $env !== '' ? $env : 'production',
                'level' => $level,
                'message' => Str::limit($message, 400),
                'details' => $details !== '' ? Str::limit($details, 20000) : '',
            ];
        }

        return $entries;
    }

    private function normalizeJsonLevel(mixed $rawLevel): string
    {
        if (is_int($rawLevel) || (is_string($rawLevel) && ctype_digit($rawLevel))) {
            return match ((int) $rawLevel) {
                600 => 'emergency',
                550 => 'alert',
                500 => 'critical',
                400 => 'error',
                300 => 'warning',
                250 => 'notice',
                200 => 'info',
                default => 'debug',
            };
        }

        $lower = strtolower((string) $rawLevel);

        return in_array($lower, self::LEVELS, true) ? $lower : 'info';
    }

    /**
     * The same error repeated: grouped by message (numbers and quoted values ignored), most frequent first.
     *
     * @return list<array{message: string, level: string, count: int, last: string}>
     */
    private function groups(array $entries, int $top = 5): array
    {
        return collect($entries)
            ->groupBy(fn ($entry) => $entry['level'].'|'.preg_replace(['/\d+/', "/'[^']*'|\"[^\"]*\"/"], ['#', '…'], $entry['message']))
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => ['message' => $group->last()['message'], 'level' => $group->last()['level'], 'count' => $group->count(), 'last' => $group->last()['time']])
            ->sortByDesc('count')
            ->take($top)
            ->values()
            ->all();
    }
}

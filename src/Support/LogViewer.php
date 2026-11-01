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

    private const ENTRY_START = '/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.]+(?:[+-]\d{2}:?\d{2}|Z)?)\] ([\w-]+)\.(\w+): /m';

    /** @return list<array{name: string, size: int, modified: Carbon}> newest first */
    public function files(): array
    {
        return collect(glob($this->directory().'/*.log') ?: [])
            ->map(fn ($path) => ['name' => basename($path), 'size' => filesize($path), 'modified' => Carbon::createFromTimestamp(filemtime($path))])
            ->sortByDesc('modified')
            ->values()
            ->all();
    }

    public function totalSize(): int
    {
        return array_sum(array_column($this->files(), 'size'));
    }

    /** The file's full, real path, or null unless it is a .log file directly inside the logs directory. */
    public function path(?string $name): ?string
    {
        if (! is_string($name) || ! preg_match('/^[\w.-]+\.log$/', $name)) {
            return null;
        }

        $directory = realpath($this->directory());
        $path = realpath($directory.'/'.$name);

        // realpath resolves symlinks: a .log symlink that escapes the logs directory is refused.
        if ($directory === false || $path === false || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * Newest entries first, filtered by level / environment / text.
     *
     * @param  ?string  $env  null = every environment
     * @return array{entries: list<array>, counts: array<string, int>, envs: array<string, int>, groups: list<array>, total: int, shown: int, truncated: bool, more: bool}
     */
    public function read(string $name, ?string $level = null, ?string $env = null, ?string $search = null, int $limit = 50): array
    {
        [$content, $truncated] = $this->tail($this->path($name) ?? throw new \InvalidArgumentException("Unknown log file [$name]."));
        $all = $this->parse($content);

        // the environment filter applies first, so level counts and groups describe what is on screen
        $inEnv = array_values(array_filter($all, fn ($entry) => ! $env || $entry['env'] === $env));
        $matching = array_values(array_filter($inEnv, fn ($entry) => (! $level || $entry['level'] === $level)
            && (blank($search) || Str::contains($entry['message'].' '.$entry['details'], $search, ignoreCase: true))));

        return [
            'entries' => array_slice(array_reverse($matching), 0, $limit),
            'counts' => array_filter(array_merge(array_fill_keys(self::LEVELS, 0), array_count_values(array_column($inEnv, 'level')))),
            'envs' => array_count_values(array_column($all, 'env')),
            'groups' => $this->groups($matching),
            'total' => count($inEnv),
            'shown' => count($matching),
            'truncated' => $truncated,
            'more' => count($matching) > $limit,
        ];
    }

    /**
     * Errors in the newest log file during the last $hours, in this app's environment.
     *
     * @return array{count: int, last: ?string}
     */
    public function recentErrors(int $hours = 24): array
    {
        $newest = $this->files()[0]['name'] ?? null;
        if (! $newest) {
            return ['count' => 0, 'last' => null];
        }

        $since = now()->subHours($hours);
        $errors = array_filter($this->parse($this->tail($this->path($newest))[0]), fn ($entry) => $entry['env'] === app()->environment()
            && in_array($entry['level'], self::ERROR_LEVELS, true)
            && $this->time($entry['time'])?->greaterThanOrEqualTo($since));

        $last = end($errors);

        return ['count' => count($errors), 'last' => $last ? $this->time($last['time'])->locale('en')->diffForHumans() : null];
    }

    public function download(string $name): BinaryFileResponse
    {
        return response()->download($this->path($name) ?? abort(404), $name, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** Delete an old log file. The newest file is still being written to, so it stays. */
    public function delete(string $name): bool
    {
        $path = $this->path($name);

        return $path !== null && $name !== ($this->files()[0]['name'] ?? null) && unlink($path);
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

    /** @return array{0: string, 1: bool} the last TAIL_BYTES of the file, and whether anything was cut off */
    private function tail(string $path): array
    {
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if ($size > self::TAIL_BYTES) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
        }
        $content = stream_get_contents($handle);
        fclose($handle);

        return [$content, $size > self::TAIL_BYTES];
    }

    /** @return list<array{time: string, env: string, level: string, message: string, details: string}> oldest first */
    private function parse(string $content): array
    {
        $parts = preg_split(self::ENTRY_START, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        array_shift($parts); // text before the first entry header: empty, or the cut-off end of an older entry

        $entries = [];
        foreach (array_chunk($parts, 4) as $chunk) {
            if (count($chunk) < 4) {
                continue; // malformed / unparsable tail chunk: skip rather than warn
            }
            [$time, $env, $level, $body] = $chunk;
            $body = rtrim($body);
            [$firstLine] = explode("\n", $body, 2);
            // the summary is the message without its JSON context ({"exception": ...})
            $message = trim(Str::before($firstLine, ' {"')) ?: $firstLine;

            $entries[] = [
                'time' => $time,
                'env' => $env,
                'level' => strtolower($level),
                'message' => Str::limit($message, 400),
                'details' => $body === $message ? '' : Str::limit($body, 20000),
            ];
        }

        return $entries;
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

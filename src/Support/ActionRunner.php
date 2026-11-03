<?php

namespace AshrafTalha\OpConsole\Support;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\NoLock;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the allow-listed Artisan actions and writes the audit trail.
 * Different risk class from ConsoleStatus (this one writes); kept separate
 * so authorization and tests can treat them differently.
 */
class ActionRunner
{
    /** Action => [artisan command, options]. Closed allow-list: never derived from request input. */
    public const ACTIONS = [
        'clear' => ['optimize:clear', []],
        'optimize' => ['optimize', []],
        'migrate' => ['migrate', ['--force' => true, '--isolated' => true]], // --isolated: a second concurrent run exits instead of racing
        'down' => ['down', []], // a random --secret is added per run so the admin keeps a bypass URL
        'up' => ['up', []],
    ];

    private const LOCK_KEY = 'opconsole:action-lock';

    private const MAX_OUTPUT_CHARS = 16000;

    private ?bool $hasDurationColumn = null;

    /** @return array{ok: bool, output: string, busy: bool, lock_error?: bool, audited?: bool} */
    public function run(string $action, ?Authenticatable $user): array
    {
        if (! array_key_exists($action, self::ACTIONS) || ! config("opconsole.actions.{$action}")) {
            throw new \InvalidArgumentException("Action [$action] is not enabled.");
        }

        try {
            $lock = $this->lock();
            $acquired = $lock->get();
        } catch (Throwable $e) {
            $store = (string) (config('opconsole.concurrency.lock_store') ?: 'null');
            $reason = self::sanitizeText($e->getMessage());

            Log::error('OpConsole: action blocked because lock could not be acquired safely', [
                'action' => $action,
                'store' => $store,
                'error' => $reason,
            ]);

            $audited = $this->record($action, $user, false, null, 'Lock error: '.$reason);

            return [
                'ok' => false,
                'output' => __('opconsole::opconsole.lock_unavailable', ['store' => $store, 'reason' => $reason]),
                'busy' => false,
                'lock_error' => true,
                'audited' => $audited,
            ];
        }

        if (! $acquired) {
            return [
                'ok' => false,
                'output' => __('opconsole::opconsole.lock_busy'),
                'busy' => true,
                'lock_error' => false,
                'audited' => true,
            ];
        }

        try {
            $run = $this->recordStart($action, $user);
            [$command, $options] = self::ACTIONS[$action];
            $bypassSecret = null;
            if ($action === 'down') {
                $bypassSecret = Str::random(40);
                $options['--secret'] = $bypassSecret;
            }
            $startedAt = hrtime(true);

            try {
                $exitCode = Artisan::call($command, $options);
                $output = $this->cleanOutput($this->safeArtisanOutput());
                $ok = $exitCode === 0;
                $firstLine = strtok($output, "\n");
                $error = $ok ? null : ($firstLine !== false && trim($firstLine) !== '' ? trim($firstLine) : 'Exit code '.$exitCode);
            } catch (Throwable $e) {
                $exitCode = 1;
                $ok = false;
                $partial = $this->cleanOutput($this->safeArtisanOutput());
                $summary = class_basename($e).': '.self::sanitizeText($e->getMessage());
                $output = $this->cleanOutput($partial !== '' ? $partial."\n\n".$summary : $summary);
                $error = $summary;

                Log::error("OpConsole: {$command} failed with exception", [
                    'user_id' => $user?->getAuthIdentifier(),
                    'error' => $summary,
                ]);
            }

            // The command has already run by this point: a failure to persist the audit row
            // must never be reported back to the user as the action itself having failed.
            if ($bypassSecret !== null && $ok) {
                // Only ever shown in the one-time output flash; never logged or written to the audit row.
                $output = trim($output."\n\nBypass URL (shown once): ".url($bypassSecret));
            }

            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $audited = $this->recordFinish($run, $ok, $exitCode, $error, $durationMs);
            Log::info("OpConsole: {$command}", ['user_id' => $user?->getAuthIdentifier(), 'exit_code' => $exitCode]);

            return [
                'ok' => $ok,
                'output' => $output,
                'busy' => false,
                'lock_error' => false,
                'audited' => $audited,
            ];
        } finally {
            try {
                $lock->release();
            } catch (Throwable $e) {
                Log::warning('OpConsole: failed to release action lock cleanly', [
                    'action' => $action,
                    'error' => self::sanitizeText($e->getMessage()),
                ]);
            }
        }
    }

    /**
     * Validates that the configured action lock store exists, supports real atomic locks,
     * and cannot be wiped mid-run when `optimize:clear` flushes `cache.default`.
     *
     * @return array{ok: bool, store: string, driver: ?string, reason: ?string, distributed: bool}
     */
    public function validateLockStore(): array
    {
        $store = config('opconsole.concurrency.lock_store');
        if (! is_string($store) || trim($store) === '') {
            return ['ok' => false, 'store' => 'null', 'driver' => null, 'reason' => 'No lock store is configured.', 'distributed' => false];
        }

        $store = trim($store);
        $storeConfig = config("cache.stores.{$store}");
        if (! is_array($storeConfig)) {
            return ['ok' => false, 'store' => $store, 'driver' => null, 'reason' => "Cache store [{$store}] is not defined.", 'distributed' => false];
        }

        $driver = isset($storeConfig['driver']) && is_string($storeConfig['driver']) ? $storeConfig['driver'] : null;

        try {
            $underlyingStore = Cache::store($store)->getStore();
        } catch (Throwable $e) {
            return ['ok' => false, 'store' => $store, 'driver' => $driver, 'reason' => "Cache store [{$store}] could not be resolved.", 'distributed' => false];
        }

        if (! $underlyingStore instanceof LockProvider || $underlyingStore instanceof NullStore) {
            return ['ok' => false, 'store' => $store, 'driver' => $driver, 'reason' => "Cache store [{$store}] does not support atomic locks.", 'distributed' => false];
        }

        if ($underlyingStore instanceof ArrayStore && ! app()->runningUnitTests()) {
            return ['ok' => false, 'store' => $store, 'driver' => $driver, 'reason' => "In-memory array store [{$store}] cannot synchronize locks across HTTP requests.", 'distributed' => false];
        }

        $defaultStore = config('cache.default');
        if (is_string($defaultStore) && $defaultStore !== '') {
            if ($store === $defaultStore) {
                $separate = $this->hasSeparateLockStorage($underlyingStore, $storeConfig);

                if (! $separate) {
                    return [
                        'ok' => false,
                        'store' => $store,
                        'driver' => $driver,
                        'reason' => "Lock store [{$store}] shares storage with cache.default and would be flushed by optimize:clear.",
                        'distributed' => false,
                    ];
                }
            } elseif ($underlyingStore instanceof FileStore) {
                try {
                    $defaultUnderlying = Cache::store($defaultStore)->getStore();
                    if ($defaultUnderlying instanceof FileStore) {
                        $lockPath = $this->normalizePath((string) ($storeConfig['lock_path'] ?? $underlyingStore->getDirectory()));
                        $defaultPath = $this->normalizePath($defaultUnderlying->getDirectory());

                        if ($lockPath !== '' && $defaultPath !== '' && ($lockPath === $defaultPath || str_starts_with($lockPath.'/', $defaultPath.'/'))) {
                            return [
                                'ok' => false,
                                'store' => $store,
                                'driver' => $driver,
                                'reason' => "Lock store [{$store}] writes lock files inside cache.default [{$defaultStore}] directory.",
                                'distributed' => false,
                            ];
                        }
                    }
                } catch (Throwable) {
                    // If cache.default itself is broken, that does not make the isolated file lock store unsafe.
                }
            }
        }

        $probe = $underlyingStore->lock(self::LOCK_KEY, 1);
        if ($probe instanceof NoLock) {
            return ['ok' => false, 'store' => $store, 'driver' => $driver, 'reason' => "Cache store [{$store}] returned a no-op lock.", 'distributed' => false];
        }

        return [
            'ok' => true,
            'store' => $store,
            'driver' => $driver ?? class_basename($underlyingStore),
            'reason' => null,
            'distributed' => in_array($driver, ['redis', 'database', 'memcached', 'dynamodb'], true),
        ];
    }

    /**
     * Fail-closed lock on an isolated cache store, independent of cache.default:
     * `optimize:clear` runs `cache:clear`, which flushes the default store.
     * Never falls back to cache.default when the configured lock store is unsafe or unavailable.
     */
    private function lock(): Lock
    {
        $validation = $this->validateLockStore();
        if (! $validation['ok']) {
            throw new \RuntimeException($validation['reason'] ?? 'Lock store configuration is unsafe.');
        }

        $seconds = max(5, (int) config('opconsole.concurrency.lock_seconds', 30));
        $underlyingStore = Cache::store($validation['store'])->getStore();

        if (! $underlyingStore instanceof LockProvider) {
            throw new \RuntimeException("Cache store [{$validation['store']}] does not support atomic locks.");
        }

        $lock = $underlyingStore->lock(self::LOCK_KEY, $seconds);
        if ($lock instanceof NoLock) {
            throw new \RuntimeException("Cache store [{$validation['store']}] returned a no-op lock.");
        }

        return $lock;
    }

    /** Audit entry for an instantaneous operation that isn't an Artisan call (log delete/download) or a blocked lock. */
    public function record(string $action, ?Authenticatable $user, bool $ok, ?string $subject = null, ?string $error = null): bool
    {
        try {
            OpConsoleRun::query()->create([
                'action' => Str::limit($action, 32, ''),
                'status' => $ok ? 'succeeded' : 'failed',
                ...$this->actorAttributes($user),
                'subject' => $this->limitNullable($subject),
                'error' => $this->limitNullable($error !== null ? self::sanitizeText($error) : null),
                'started_at' => now(),
                'finished_at' => now(),
                ...$this->durationAttributes(0),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('OpConsole: failed to write audit row', ['action' => $action, 'error' => self::sanitizeText($e->getMessage())]);

            return false;
        }
    }

    /** Redacts credentials and internal base paths from exception/error messages before display or persistence. */
    public static function sanitizeText(string $text): string
    {
        $clean = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $text) ?? $text;
        $clean = preg_replace('#(://[^/\s:@]+:)[^@\s]+(@)#', '$1[REDACTED]$2', $clean) ?? $clean;
        $clean = preg_replace('/\b(password|passwd|pwd|secret|token|api_key)\s*=\s*([^\s;,\'"]+)/i', '$1=[REDACTED]', $clean) ?? $clean;

        $basePath = rtrim(base_path(), '/\\').DIRECTORY_SEPARATOR;
        if ($basePath !== DIRECTORY_SEPARATOR) {
            $clean = str_replace($basePath, '', $clean);
        }

        return trim($clean);
    }

    private function recordStart(string $action, ?Authenticatable $user): ?OpConsoleRun
    {
        try {
            return OpConsoleRun::query()->create([
                'action' => Str::limit($action, 32, ''),
                'status' => 'started',
                ...$this->actorAttributes($user),
                'started_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('OpConsole: failed to write audit row', ['action' => $action, 'error' => self::sanitizeText($e->getMessage())]);

            return null;
        }
    }

    /** @return array{user_id: ?string, user_label: ?string} */
    private function actorAttributes(?Authenticatable $user): array
    {
        return [
            'user_id' => $this->limitNullable($user?->getAuthIdentifier() !== null ? (string) $user->getAuthIdentifier() : null),
            'user_label' => $this->limitNullable($this->label($user)),
        ];
    }

    /** @return array{duration_ms?: int} */
    private function durationAttributes(int $durationMs): array
    {
        $this->hasDurationColumn ??= OpConsoleRun::hasDurationColumn();

        return $this->hasDurationColumn ? ['duration_ms' => max(0, $durationMs)] : [];
    }

    private function recordFinish(?OpConsoleRun $run, bool $ok, int $exitCode, ?string $error = null, int $durationMs = 0): bool
    {
        if ($run === null) {
            return false;
        }

        try {
            $run->update([
                'status' => $ok ? 'succeeded' : 'failed',
                'exit_code' => $exitCode,
                'error' => $this->limitNullable($error !== null ? self::sanitizeText($error) : null),
                'finished_at' => now(),
                ...$this->durationAttributes($durationMs),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('OpConsole: failed to update audit row', ['action' => $run->action, 'error' => self::sanitizeText($e->getMessage())]);

            return false;
        }
    }

    private function safeArtisanOutput(): string
    {
        try {
            return Artisan::output();
        } catch (Throwable) {
            return '';
        }
    }

    private function cleanOutput(string $raw): string
    {
        return Str::limit(self::sanitizeText($raw), self::MAX_OUTPUT_CHARS, "\n… [output truncated]");
    }

    private function limitNullable(?string $value, int $limit = 250): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Str::limit($value, $limit, '…');
    }

    /**
     * Cross-version check (Laravel 12 & 13) for whether flushing a cache store leaves its locks intact.
     *
     * @param  array<string, mixed>  $storeConfig
     */
    private function hasSeparateLockStorage(Store $underlyingStore, array $storeConfig): bool
    {
        if ($underlyingStore instanceof ArrayStore) {
            return false;
        }

        if (method_exists($underlyingStore, 'hasSeparateLockStore')) {
            return (bool) $underlyingStore->hasSeparateLockStore();
        }

        if ($underlyingStore instanceof DatabaseStore) {
            $table = (string) ($storeConfig['table'] ?? 'cache');
            $lockTable = (string) ($storeConfig['lock_table'] ?: 'cache_locks');
            $connection = $storeConfig['connection'] ?? null;
            $lockConnection = $storeConfig['lock_connection'] ?? $connection;

            return $lockTable !== $table || $lockConnection !== $connection;
        }

        if ($underlyingStore instanceof RedisStore) {
            $connection = (string) ($storeConfig['connection'] ?? 'cache');
            $lockConnection = (string) ($storeConfig['lock_connection'] ?? 'default');

            return $lockConnection !== $connection;
        }

        if ($underlyingStore instanceof FileStore) {
            $cachePath = $this->normalizePath($underlyingStore->getDirectory());
            $lockPath = $this->normalizePath((string) ($storeConfig['lock_path'] ?? $cachePath));

            return $lockPath !== '' && $cachePath !== '' && $lockPath !== $cachePath && ! str_starts_with($lockPath.'/', $cachePath.'/');
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        $clean = rtrim(str_replace('\\', '/', $path), '/');
        if ($clean === '') {
            return '';
        }

        $resolved = @realpath($clean);
        if ($resolved !== false) {
            return rtrim(str_replace('\\', '/', $resolved), '/');
        }

        $segments = [];
        $current = $clean;
        while ($current !== '.' && $current !== '/') {
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            array_unshift($segments, basename($current));
            $resolvedParent = @realpath($parent);
            if ($resolvedParent !== false) {
                return rtrim(str_replace('\\', '/', $resolvedParent), '/').'/'.implode('/', $segments);
            }
            $current = $parent;
        }

        return $clean;
    }

    private function label(?Authenticatable $user): ?string
    {
        if ($user === null) {
            return null;
        }

        foreach (['name', 'email'] as $property) {
            if (isset($user->{$property}) && filled($user->{$property})) {
                return (string) $user->{$property};
            }
        }

        return (string) $user->getAuthIdentifier();
    }
}

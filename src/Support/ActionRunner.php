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
        'storage_link' => ['storage:link', []],
        'security_scan' => ['opconsole:security', []],
    ];

    /**
     * Actions whose runtime is bounded by their own subprocess timeouts (Composer probe + audit),
     * so they stay safe without pcntl as long as the lease covers that bound.
     */
    private const SELF_BOUNDED = ['security_scan'];

    private const LOCK_KEY = 'opconsole:action-lock';

    private const MAX_OUTPUT_CHARS = 16000;

    private ?bool $hasDurationColumn = null;

    /** @var array{run: ?OpConsoleRun, lock: Lock}|null set only while an Artisan command is executing */
    private ?array $inFlight = null;

    private bool $shutdownHookRegistered = false;

    private SecurityScanner $security;

    public function __construct(?SecurityScanner $security = null)
    {
        $this->security = $security ?? app(SecurityScanner::class);
    }

    /**
     * `blocked` means the command was refused before it started (unsupported runtime or required audit unavailable).
     *
     * @return array{ok: bool, output: string, busy: bool, lock_error?: bool, blocked?: bool, audited?: bool}
     */
    public function run(string $action, ?Authenticatable $user): array
    {
        if (! array_key_exists($action, self::ACTIONS) || ! config("opconsole.actions.{$action}")) {
            throw new \InvalidArgumentException("Action [$action] is not enabled.");
        }

        // Checked before the lock and the audit row: nothing may start that this runtime cannot bound.
        $unsupported = $this->unsupportedRuntimeReason($action);
        if ($unsupported !== null) {
            Log::error('OpConsole: action blocked because this PHP runtime cannot enforce the execution timeout', [
                'action' => $action,
                'sapi' => PHP_SAPI,
            ]);

            return [
                'ok' => false,
                'output' => $unsupported,
                'busy' => false,
                'lock_error' => false,
                'blocked' => true,
                'audited' => $this->record($action, $user, false, null, 'Blocked: PHP runtime cannot enforce the execution timeout'),
            ];
        }

        try {
            $lock = $this->lock($action);
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
            if ($run === null && $this->auditRequired()) {
                Log::error('OpConsole: action refused because audit.policy is "required" and the audit row could not be written', ['action' => $action]);

                return [
                    'ok' => false,
                    'output' => __('opconsole::opconsole.audit_required'),
                    'busy' => false,
                    'lock_error' => false,
                    'blocked' => true,
                    'audited' => false,
                ];
            }

            [$command, $options] = self::ACTIONS[$action];
            $bypassSecret = null;
            if ($action === 'down') {
                $bypassSecret = Str::random(40);
                $options['--secret'] = $bypassSecret;
            }
            $startedAt = hrtime(true);

            $this->inFlight = ['run' => $run, 'lock' => $lock];
            if (! $this->shutdownHookRegistered) {
                register_shutdown_function($this->abandonInFlight(...));
                $this->shutdownHookRegistered = true;
            }

            try {
                $exitCode = $this->callArtisanWithLease($command, $options, $lock, $action);
                $output = $this->cleanOutput($this->safeArtisanOutput());
                $ok = $exitCode === 0;
                $firstLine = strtok($output, "\n");
                $error = $ok ? null : ($firstLine !== false && trim($firstLine) !== '' ? trim($firstLine) : 'Exit code '.$exitCode);

                // opconsole:security exits non-zero when it finds advisories or config warnings, which is
                // correct for CI but not an "action failed" state here: the Security tab already surfaces them.
                if ($action === 'security_scan') {
                    $ok = ($this->security->report()['status'] ?? null) === 'complete';
                    $error = $ok ? null : $error;
                }
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
                $output = trim($output."\n\n".__('opconsole::opconsole.bypass_url', ['url' => url($bypassSecret)]));
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
            $this->inFlight = null;

            try {
                // Owner-checked by every Laravel lock driver: never releases another process's lock.
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
            } elseif ($underlyingStore instanceof RedisStore) {
                try {
                    $defaultConfig = config("cache.stores.{$defaultStore}", []);
                    $defaultUnderlying = Cache::store($defaultStore)->getStore();
                    if ($defaultUnderlying instanceof RedisStore && is_array($defaultConfig)) {
                        $lockConnection = (string) ($storeConfig['lock_connection'] ?? $storeConfig['connection'] ?? 'default');
                        $defaultConnection = (string) ($defaultConfig['connection'] ?? 'default');
                        if ($this->redisStorageIdentity($lockConnection) === $this->redisStorageIdentity($defaultConnection)) {
                            return [
                                'ok' => false,
                                'store' => $store,
                                'driver' => $driver,
                                'reason' => "Lock store [{$store}] shares Redis database storage with cache.default [{$defaultStore}] and would be flushed by optimize:clear.",
                                'distributed' => false,
                            ];
                        }
                    }
                } catch (Throwable) {
                    // Keep validating the isolated lock store if cache.default cannot be resolved.
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
    private function lock(string $action): Lock
    {
        $validation = $this->validateLockStore();
        if (! $validation['ok']) {
            throw new \RuntimeException($validation['reason'] ?? 'Lock store configuration is unsafe.');
        }

        $seconds = $this->leaseSeconds($action);
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

    /**
     * Shutdown hook. A fatal error (memory exhaustion, engine time limit) skips `finally`, so this
     * closes the audit row and releases the lock instead of leaving both stuck until the lease expires.
     * A no-op once run() has finished normally.
     *
     * @internal
     */
    public function abandonInFlight(): void
    {
        if ($this->inFlight === null) {
            return;
        }
        ['run' => $run, 'lock' => $lock] = $this->inFlight;
        $this->inFlight = null;

        $fatal = error_get_last();
        $this->recordFinish($run, false, 1, 'Process terminated before completion; the command may have partially applied. '.($fatal['message'] ?? ''));
        try {
            $lock->release();
        } catch (Throwable) {
        }
    }

    /**
     * Null when this PHP runtime can run the action safely; otherwise an actionable reason.
     * Timer-bound actions need pcntl (CLI, or PHP-FPM with pcntl enabled) to interrupt an
     * over-budget command; without it a command could outlive its lock lease.
     */
    public function unsupportedRuntimeReason(string $action): ?string
    {
        if (in_array($action, self::SELF_BOUNDED, true) || $this->canEnforceTimeout()) {
            return null;
        }

        return __('opconsole::opconsole.runtime_unsupported', [
            'sapi' => PHP_SAPI,
            'command' => 'php artisan '.self::ACTIONS[$action][0],
        ]);
    }

    /** Signals are only interruptible with pcntl; hosts often disable these functions for PHP-FPM. */
    protected function canEnforceTimeout(): bool
    {
        foreach (['pcntl_signal', 'pcntl_alarm', 'pcntl_async_signals', 'pcntl_signal_get_handler'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        return defined('SIGALRM');
    }

    protected function maxRuntimeSeconds(): int
    {
        return max(5, (int) config('opconsole.concurrency.max_runtime_seconds', 1500));
    }

    /** Seconds between lease renewals (and timeout checks) while an action blocks. */
    protected function renewIntervalSeconds(): int
    {
        return 10;
    }

    /**
     * Interrupt an over-budget command, renewing the owner-bound lease while it blocks.
     * The lease always exceeds the runtime budget, so renewal is a safety margin rather than a
     * requirement: drivers without refresh() (Laravel < 12.63 / < 13.16) still run safely.
     */
    private function callArtisanWithLease(string $command, array $options, Lock $lock, string $action): int
    {
        if (in_array($action, self::SELF_BOUNDED, true) && ! $this->canEnforceTimeout()) {
            return Artisan::call($command, $options);
        }

        $timeout = $this->maxRuntimeSeconds();
        $renew = self::renewer($lock);
        $started = hrtime(true);
        $remaining = fn (): float => $timeout - (hrtime(true) - $started) / 1_000_000_000;
        $interval = $this->renewIntervalSeconds();
        $nextAlarm = fn (): int => max(1, (int) ceil(min($interval, $remaining())));

        // Never lower an existing limit; raise PHP's own limit so this budget, not a fatal error, ends the command.
        $phpLimit = (int) ini_get('max_execution_time');
        if ($phpLimit > 0 && $phpLimit < $timeout + 30 && function_exists('set_time_limit')) {
            @set_time_limit($timeout + 30);
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true); // a browser or proxy disconnect must not abort a migration halfway
        }

        $previousAsync = pcntl_async_signals();
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use ($timeout, $renew, $remaining, $nextAlarm, $action): void {
            if ($remaining() <= 0) {
                throw new \RuntimeException("Artisan operation exceeded the {$timeout}-second timeout and was interrupted; it may have partially completed.");
            }
            if ($renew !== null) {
                try {
                    $owned = $renew($this->leaseSeconds($action));
                } catch (Throwable $e) {
                    // A transient store error is not ownership loss: the current lease still outlasts the budget.
                    $owned = true;
                    Log::warning('OpConsole: action lock renewal failed; continuing on the existing lease', ['error' => self::sanitizeText($e->getMessage())]);
                }
                if (! $owned) {
                    throw new \RuntimeException('Action lock ownership was lost; the operation was interrupted to prevent concurrent execution.');
                }
            }
            pcntl_alarm($nextAlarm());
        });
        pcntl_alarm($nextAlarm());

        try {
            return Artisan::call($command, $options);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }
    }

    /**
     * Owner-bound renewal, or null when the driver cannot renew. refresh() is not on the Lock contract,
     * and the base Illuminate\Cache\Lock::refresh() (Laravel 12.63+ / 13.16+) only throws.
     *
     * @return (\Closure(int): bool)|null
     */
    private static function renewer(Lock $lock): ?\Closure
    {
        if (! method_exists($lock, 'refresh')
            || (new \ReflectionMethod($lock, 'refresh'))->getDeclaringClass()->getName() === \Illuminate\Cache\Lock::class) {
            return null;
        }

        return fn (int $seconds): bool => (bool) $lock->refresh($seconds);
    }

    private function leaseSeconds(string $action): int
    {
        $configured = max(5, (int) config('opconsole.concurrency.lock_seconds', 1800));
        $lease = max($configured, $this->maxRuntimeSeconds() + 60);

        if (in_array($action, self::SELF_BOUNDED, true)) {
            // SecurityScanner: 10 s Composer version probe plus the audit process timeout.
            $lease = max($lease, max(10, (int) config('opconsole.security.timeout', 120)) + 10 + 60);
        }

        return $lease;
    }

    /** Anything other than an unset value or "best_effort" (including typos) fails closed as "required". */
    public function auditRequired(): bool
    {
        $policy = config('opconsole.audit.policy', 'best_effort');

        return ! in_array($policy, [null, '', 'best_effort'], true);
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
            $clean = str_replace(rtrim($basePath, '/\\'), '[application]', $clean);
        }

        return trim($clean);
    }

    /** Writes the "started" audit row before a destructive operation; null when the audit store is unavailable. */
    public function recordStart(string $action, ?Authenticatable $user, ?string $subject = null): ?OpConsoleRun
    {
        try {
            return OpConsoleRun::query()->create([
                'action' => Str::limit($action, 32, ''),
                'status' => 'started',
                ...$this->actorAttributes($user),
                'subject' => $this->limitNullable($subject),
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

    /** Closes a row from recordStart(); false when there is no row or it could not be updated. */
    public function recordFinish(?OpConsoleRun $run, bool $ok, ?int $exitCode, ?string $error = null, int $durationMs = 0): bool
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

        if ($underlyingStore instanceof RedisStore) {
            $cacheConnection = (string) ($storeConfig['connection'] ?? 'default');
            $lockConnection = (string) ($storeConfig['lock_connection'] ?? $cacheConnection);
            $cacheIdentity = $this->redisStorageIdentity($cacheConnection);
            $lockIdentity = $this->redisStorageIdentity($lockConnection);

            return $cacheIdentity !== null && $lockIdentity !== null && $cacheIdentity !== $lockIdentity;
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

        if ($underlyingStore instanceof FileStore) {
            $cachePath = $this->normalizePath($underlyingStore->getDirectory());
            $lockPath = $this->normalizePath((string) ($storeConfig['lock_path'] ?? $cachePath));

            return $lockPath !== '' && $cachePath !== '' && $lockPath !== $cachePath && ! str_starts_with($lockPath.'/', $cachePath.'/');
        }

        return false;
    }

    private function redisStorageIdentity(string $connection): ?string
    {
        $config = config('database.redis.'.$connection);
        if (! is_array($config)) {
            $config = config('database.redis.default');
        }
        if (! is_array($config)) {
            return null;
        }

        $url = isset($config['url']) && is_string($config['url']) ? parse_url($config['url']) : false;
        $database = $config['database'] ?? (is_array($url) ? ltrim((string) ($url['path'] ?? ''), '/') : 0);

        return implode('|', [
            (string) ($config['scheme'] ?? 'tcp'),
            strtolower((string) ($config['host'] ?? (is_array($url) ? ($url['host'] ?? '127.0.0.1') : '127.0.0.1'))),
            (string) ($config['port'] ?? (is_array($url) ? ($url['port'] ?? 6379) : 6379)),
            (string) $database,
            (string) ($config['path'] ?? ''),
        ]);
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

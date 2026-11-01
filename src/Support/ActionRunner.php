<?php

namespace AshrafTalha\OpConsole\Support;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the three allow-listed Artisan actions and writes the audit trail.
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
    ];

    private const LOCK_KEY = 'opconsole:action-lock';

    /** @return array{ok: bool, output: string, busy: bool} */
    public function run(string $action, ?Authenticatable $user): array
    {
        if (! array_key_exists($action, self::ACTIONS) || ! config("opconsole.actions.{$action}")) {
            throw new \InvalidArgumentException("Action [$action] is not enabled.");
        }

        $lock = $this->lock();
        if (! $lock->get()) {
            return ['ok' => false, 'output' => 'Another operation is already running. Try again shortly.', 'busy' => true];
        }

        try {
            $run = $this->recordStart($action, $user);
            [$command, $options] = self::ACTIONS[$action];

            $exitCode = Artisan::call($command, $options);
            $output = trim(preg_replace('/\e\[[\d;]*m/', '', Artisan::output()));
            $ok = $exitCode === 0;

            // The command has already run by this point: a failure to persist the audit row
            // must never be reported back to the user as the action itself having failed.
            $this->recordFinish($run, $ok, $exitCode);
            Log::info("OpConsole: {$command}", ['user_id' => $user?->getAuthIdentifier(), 'exit_code' => $exitCode]);

            return ['ok' => $ok, 'output' => $output, 'busy' => false];
        } finally {
            $lock->release();
        }
    }

    /**
     * A lock on its own cache store, independent of cache.default: `optimize:clear`
     * runs `cache:clear`, which flushes the *default* store. If the lock lived there
     * too, that flush would delete its own lock mid-run, letting a second action
     * start concurrently. Falls back to the default store if the configured one
     * cannot be resolved (e.g. a misconfigured custom store name), rather than
     * failing the action outright.
     */
    private function lock(): Lock
    {
        $store = config('opconsole.concurrency.lock_store', 'file');
        $seconds = config('opconsole.concurrency.lock_seconds', 30);

        try {
            // instanceof must be checked against the underlying store, not the Repository
            // wrapper Cache::store() returns: Repository doesn't implement LockProvider itself,
            // it only forwards lock() to the store via __call. Checking the wrapper is always
            // false, silently defeating the store isolation this method exists for.
            $underlyingStore = Cache::store($store)->getStore();

            if (! $underlyingStore instanceof LockProvider) {
                throw new \RuntimeException("Cache store [{$store}] does not support atomic locks.");
            }

            return $underlyingStore->lock(self::LOCK_KEY, $seconds);
        } catch (Throwable $e) {
            Log::warning('OpConsole: lock store is unavailable, falling back to the default cache store', ['store' => $store, 'error' => $e->getMessage()]);

            return Cache::lock(self::LOCK_KEY, $seconds);
        }
    }

    /** Audit entry for an instantaneous operation that isn't an Artisan call (log delete/download). */
    public function record(string $action, ?Authenticatable $user, bool $ok, ?string $subject = null, ?string $error = null): void
    {
        try {
            OpConsoleRun::query()->create([
                'action' => $action,
                'status' => $ok ? 'succeeded' : 'failed',
                'user_id' => $user?->getAuthIdentifier(),
                'user_label' => $this->label($user),
                'subject' => $subject,
                'error' => $error,
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('OpConsole: failed to write audit row', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    private function recordStart(string $action, ?Authenticatable $user): ?OpConsoleRun
    {
        try {
            return OpConsoleRun::query()->create([
                'action' => $action,
                'status' => 'started',
                'user_id' => $user?->getAuthIdentifier(),
                'user_label' => $this->label($user),
                'started_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('OpConsole: failed to write audit row', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function recordFinish(?OpConsoleRun $run, bool $ok, int $exitCode): void
    {
        if ($run === null) {
            return;
        }

        try {
            $run->update([
                'status' => $ok ? 'succeeded' : 'failed',
                'exit_code' => $exitCode,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('OpConsole: failed to update audit row', ['action' => $run->action, 'error' => $e->getMessage()]);
        }
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

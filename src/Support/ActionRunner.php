<?php

namespace AshrafTalha\OpConsole\Support;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use Illuminate\Contracts\Auth\Authenticatable;
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

        $lock = Cache::lock(self::LOCK_KEY, 30);
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

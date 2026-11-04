<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use AshrafTalha\OpConsole\Support\ActionRunner;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Execution model: pcntl timeout, lease renewal across Laravel versions, lock ownership,
 * and the audit policy. Real SIGALRM delivery and real lock drivers are used throughout;
 * only the runtime bounds are shortened so the suite stays fast.
 */
class ExecutionSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    private function fakeArtisan(?\Closure $during = null, int $exitCode = 0): ScriptedArtisanKernel
    {
        $fake = new ScriptedArtisanKernel($during, $exitCode);
        $this->app->instance(KernelContract::class, $fake);
        $this->app->instance('artisan', $fake);
        Artisan::clearResolvedInstance(KernelContract::class);
        Artisan::swap($fake);

        return $fake;
    }

    /** Binds a runner with shortened bounds and an optionally simulated missing pcntl. */
    private function useRunner(int $maxRuntime = 30, int $interval = 1, bool $pcntl = true): void
    {
        $this->app->instance(ActionRunner::class, new class($maxRuntime, $interval, $pcntl) extends ActionRunner
        {
            public function __construct(private int $max, private int $interval, private bool $pcntl)
            {
                parent::__construct();
            }

            protected function canEnforceTimeout(): bool
            {
                return $this->pcntl && parent::canEnforceTimeout();
            }

            protected function maxRuntimeSeconds(): int
            {
                return $this->max;
            }

            protected function renewIntervalSeconds(): int
            {
                return $this->interval;
            }
        });
    }

    /** Busy-waits (usleep alone returns early when SIGALRM arrives) so signals fire mid-command. */
    private static function work(float $seconds): void
    {
        $until = hrtime(true) + (int) ($seconds * 1_000_000_000);
        while (hrtime(true) < $until) {
            usleep(20_000);
        }
    }

    private function contender(): \Illuminate\Contracts\Cache\Lock
    {
        return Cache::store(config('opconsole.concurrency.lock_store'))->lock('opconsole:action-lock', 30);
    }

    public function test_missing_pcntl_blocks_timer_bound_actions_before_locking_or_running_and_audits_the_refusal(): void
    {
        $this->useRunner(pcntl: false);
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_error', fn ($message) => str_contains($message, 'pcntl')
            && str_contains($message, 'php artisan optimize:clear'));

        $this->assertSame([], $fake->calls);
        $run = OpConsoleRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Blocked', (string) $run->error);

        // Never took the lock, so nothing is left held.
        $this->assertTrue($this->contender()->get());
    }

    public function test_missing_pcntl_is_shown_on_the_action_cards_before_anyone_clicks(): void
    {
        $this->useRunner(pcntl: false);
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk()->assertSee('cannot enforce the action timeout');
    }

    public function test_self_bounded_security_scan_still_runs_without_pcntl(): void
    {
        $this->useRunner(pcntl: false);
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/security_scan')->assertRedirect();

        $this->assertSame([['opconsole:security', []]], $fake->calls);
    }

    /**
     * Regression: Laravel < 12.63 / < 13.16 locks have no refresh(); the base class throws.
     * The first renewal tick used to abort the running command (e.g. a migration) after ten seconds.
     */
    public function test_long_action_completes_on_a_lock_driver_without_refresh_support(): void
    {
        Cache::extend('legacy_lock', fn () => new CacheRepository(new LegacyLockStore));
        config(['cache.stores.legacy' => ['driver' => 'legacy_lock'], 'opconsole.concurrency.lock_store' => 'legacy']);
        $this->useRunner(maxRuntime: 30, interval: 1);
        $fake = $this->fakeArtisan(fn () => self::work(2.2));
        $this->actingAsUser();

        $this->post('/admin/opconsole/migrate')->assertSessionHas('opconsole_success');

        $this->assertCount(1, $fake->calls);
        $this->assertSame('succeeded', OpConsoleRun::query()->latest('id')->firstOrFail()->status);
        $this->assertSame([], LegacyLockStore::$owners, 'The lock must be released after completion.');
    }

    public function test_timeout_interrupts_the_command_and_is_recorded_as_a_failure_never_a_success(): void
    {
        $this->useRunner(maxRuntime: 1, interval: 1);
        $this->fakeArtisan(fn () => self::work(5));
        $this->actingAsUser();
        $started = hrtime(true);

        $this->post('/admin/opconsole/migrate')->assertSessionHas('opconsole_error');

        $this->assertLessThan(4, (hrtime(true) - $started) / 1_000_000_000, 'The command must be interrupted at the deadline.');
        $run = OpConsoleRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('timeout', (string) $run->error);
        $this->assertStringContainsString('timeout', session('opconsole_output')['text']);
        $this->assertTrue($this->contender()->get(), 'The lock must be released after a timeout.');
    }

    public function test_lost_lock_ownership_interrupts_the_command_and_never_releases_the_new_owners_lock(): void
    {
        if (! method_exists(ArrayLock::class, 'refresh')) {
            // Mid-run ownership checks ride on refresh(); before Laravel 12.63 / 13.16 safety rests on the lease alone.
            $this->markTestSkipped('This Laravel release has no lock refresh().');
        }
        $this->useRunner(maxRuntime: 30, interval: 1);
        $intruder = null;
        $this->fakeArtisan(function () use (&$intruder) {
            // Another process takes over the key (e.g. after a forced release).
            Cache::store('opconsole_lock')->lock('opconsole:action-lock')->forceRelease();
            $intruder = $this->contender();
            $this->assertTrue($intruder->get());
            self::work(3);
        });
        $this->actingAsUser();

        $this->post('/admin/opconsole/migrate')->assertSessionHas('opconsole_error');

        $this->assertStringContainsString('ownership was lost', (string) OpConsoleRun::query()->latest('id')->firstOrFail()->error);
        $this->assertFalse($this->contender()->get(), "The runner's finally block must not release the new owner's lock.");
        $intruder->release();
    }

    public function test_concurrent_attempt_is_rejected_while_a_long_action_runs(): void
    {
        $this->useRunner(maxRuntime: 30, interval: 1);
        $observed = null;
        $this->fakeArtisan(function () use (&$observed) {
            self::work(1.5); // past at least one renewal tick
            $observed = $this->contender()->get();
        });
        $this->actingAsUser();

        $this->post('/admin/opconsole/migrate')->assertSessionHas('opconsole_success');

        $this->assertFalse($observed, 'A second operation must not acquire the lock mid-run.');
    }

    /**
     * A fatal error skips `finally`; PHP then runs the shutdown hook. Invoking that hook mid-command
     * is exactly the state PHP leaves behind, without killing the test process.
     */
    public function test_fatal_error_shutdown_hook_closes_the_audit_row_and_releases_the_lock(): void
    {
        $runner = new ActionRunner;
        $this->app->instance(ActionRunner::class, $runner);
        $atShutdown = null;
        $this->fakeArtisan(function () use ($runner, &$atShutdown) {
            $runner->abandonInFlight();
            $atShutdown = [OpConsoleRun::query()->latest('id')->firstOrFail(), $this->contender()];
        });
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear');

        [$row, $contender] = $atShutdown;
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Process terminated before completion', (string) $row->error);
        $this->assertTrue($contender->get(), 'The hook must release the lock immediately.');
        $contender->release();

        // After a normal finish the hook is a no-op and must not touch a completed row.
        $finished = OpConsoleRun::query()->latest('id')->firstOrFail();
        $runner->abandonInFlight();
        $this->assertEquals($finished->getAttributes(), $finished->fresh()?->getAttributes());
    }

    public function test_required_audit_policy_refuses_to_run_when_the_start_row_cannot_be_written(): void
    {
        Schema::drop('opconsole_runs');
        config(['opconsole.audit.policy' => 'required']);
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')
            ->assertSessionHas('opconsole_error', fn ($message) => str_contains($message, 'audit policy is "required"'));

        $this->assertSame([], $fake->calls);
        $this->assertTrue($this->contender()->get(), 'A refused action must release the lock.');
    }

    public function test_unrecognised_audit_policy_fails_closed_as_required(): void
    {
        Schema::drop('opconsole_runs');
        config(['opconsole.audit.policy' => 'requird']);
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_error');

        $this->assertSame([], $fake->calls);
    }

    public function test_best_effort_policy_still_runs_without_an_audit_table(): void
    {
        Schema::drop('opconsole_runs');
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_success')->assertSessionHas('opconsole_warning');

        $this->assertCount(1, $fake->calls);
    }

    /** The command ran: losing only the final audit update must not turn its success into a failure. */
    public function test_required_policy_keeps_the_real_outcome_when_only_the_final_audit_update_fails(): void
    {
        config(['opconsole.audit.policy' => 'required']);
        $this->fakeArtisan(fn () => Schema::drop('opconsole_runs'));
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')
            ->assertSessionHas('opconsole_success')
            ->assertSessionHas('opconsole_warning')
            ->assertSessionMissing('opconsole_error');
    }

    public function test_required_policy_refuses_log_delete_and_download_it_cannot_audit(): void
    {
        $directory = $this->useTemporaryLogDirectory();
        File::put($directory.'/old.log', 'old');
        touch($directory.'/old.log', time() - 3600);
        File::put($directory.'/laravel.log', 'current');
        Schema::drop('opconsole_runs');
        config(['opconsole.audit.policy' => 'required']);
        $this->actingAsUser();

        $this->delete('/admin/opconsole/logs/old.log')->assertRedirect()->assertSessionHas('opconsole_error');
        $this->assertFileExists($directory.'/old.log');

        $this->get('/admin/opconsole/logs/laravel.log')->assertStatus(503);
    }

    public function test_log_delete_records_a_started_row_then_its_outcome(): void
    {
        $directory = $this->useTemporaryLogDirectory();
        File::put($directory.'/old.log', 'old');
        touch($directory.'/old.log', time() - 3600);
        File::put($directory.'/laravel.log', 'current');
        config(['opconsole.audit.policy' => 'required']);
        $this->actingAsUser();

        $this->delete('/admin/opconsole/logs/old.log')->assertSessionHas('opconsole_success')->assertSessionMissing('opconsole_warning');

        $this->assertFileDoesNotExist($directory.'/old.log');
        $this->assertSame(1, OpConsoleRun::query()->where('action', 'log.delete')->count());
        $this->assertDatabaseHas('opconsole_runs', ['action' => 'log.delete', 'subject' => 'old.log', 'status' => 'succeeded']);
    }
}

/** Runs an optional callback inside call() so tests can act while the action holds its lock. */
class ScriptedArtisanKernel implements KernelContract
{
    public array $calls = [];

    public function __construct(private ?\Closure $during = null, private int $exitCode = 0) {}

    public function handle($input, $output = null)
    {
        return $this->exitCode;
    }

    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        $this->calls[] = [$command, $parameters];
        // One-shot: Testbench's teardown migrate:rollback also goes through this kernel.
        $during = $this->during;
        $this->during = null;
        if ($during !== null) {
            $during();
        }

        return $this->exitCode;
    }

    public function queue($command, array $parameters = [])
    {
        return null;
    }

    public function all()
    {
        return [];
    }

    public function output()
    {
        return '';
    }

    public function terminate($input, $status) {}

    public function bootstrap() {}
}

/** Lock store whose locks inherit the base refresh() (which throws), like Laravel before 12.63 / 13.16. */
class LegacyLockStore extends ArrayStore
{
    /** @var array<string, string> */
    public static array $owners = [];

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return new class($name, $seconds, $owner) extends Lock
        {
            public function acquire(): bool
            {
                if (isset(LegacyLockStore::$owners[$this->name])) {
                    return false;
                }
                LegacyLockStore::$owners[$this->name] = $this->owner;

                return true;
            }

            public function release(): bool
            {
                if (! $this->isOwnedByCurrentProcess()) {
                    return false;
                }
                unset(LegacyLockStore::$owners[$this->name]);

                return true;
            }

            protected function getCurrentOwner(): ?string
            {
                return LegacyLockStore::$owners[$this->name] ?? null;
            }

            public function forceRelease(): void
            {
                unset(LegacyLockStore::$owners[$this->name]);
            }
        };
    }
}

<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use AshrafTalha\OpConsole\Support\ActionRunner;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    /**
     * Swaps the bound Artisan kernel for a recording fake: Mockery cannot partial-mock Testbench's
     * final Kernel. Also load-bearing for a second reason: a *real* `optimize` (unlike
     * `optimize:clear`) runs `config:cache`/`route:cache`, which boot a brand-new Application from
     * the shared Testbench workbench skeleton's own bootstrap/app.php — one that never went through
     * this package's test-time provider injection — and then cache that incomplete snapshot to disk.
     * That silently breaks every later test in the same run (and in later runs) because
     * configurationIsCached() then short-circuits this package's mergeConfigFrom() entirely. Any
     * test that might run `optimize` for real (including via a lock bug letting it slip through)
     * must fake Artisan instead. `optimize:clear` has no such effect and is run for real elsewhere.
     */
    private function fakeArtisan(int $exitCode = 0, string $output = '', ?\Throwable $throwOnCall = null): FakeArtisanKernel
    {
        $fake = new FakeArtisanKernel($exitCode, $output, $throwOnCall);
        $this->app->instance(KernelContract::class, $fake);
        $this->app->instance('artisan', $fake);
        Artisan::clearResolvedInstance(KernelContract::class);
        Artisan::swap($fake);

        return $fake;
    }

    public function test_clear_action_calls_optimize_clear_with_no_options(): void
    {
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')->assertRedirect();

        $this->assertSame(['optimize:clear', []], $fake->calls[0]);
    }

    public function test_optimize_action_calls_optimize_with_no_options(): void
    {
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/optimize')->assertRedirect();

        $this->assertSame(['optimize', []], $fake->calls[0]);
    }

    public function test_migrate_action_runs_forced_and_isolated(): void
    {
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/migrate')->assertRedirect();

        $this->assertSame(['migrate', ['--force' => true, '--isolated' => true]], $fake->calls[0]);
    }

    /** A pending migration in a temporary directory registered with the migrator; returns [name, path]. */
    private function pendingMigration(string $name = '2099_01_01_000000_create_widgets_table'): array
    {
        $dir = sys_get_temp_dir().'/opconsole-migrations-'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put("$dir/$name.php", "<?php\nreturn new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void {} };\n");
        $this->app['migrator']->path($dir);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($dir));

        return [$name, "$dir/$name.php"];
    }

    public function test_migrate_action_can_run_one_pending_migration_by_name(): void
    {
        [$name, $path] = $this->pendingMigration();
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/migrate', ['migration' => $name])->assertSessionHas('opconsole_success');

        $this->assertSame(['migrate', ['--force' => true, '--isolated' => true, '--path' => $path, '--realpath' => true]], $fake->calls[0]);
        $this->assertSame($name, OpConsoleRun::query()->where('action', 'migrate')->value('subject'));
    }

    public function test_migrate_action_refuses_a_migration_that_is_not_pending(): void
    {
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/migrate', ['migration' => '../../../etc/passwd'])->assertSessionHas('opconsole_error');
        $this->post('/admin/opconsole/migrate', ['migration' => '2099_01_01_000000_does_not_exist'])->assertSessionHas('opconsole_error');

        $this->assertSame([], $fake->calls);
        $this->assertSame(0, OpConsoleRun::query()->where('action', 'migrate')->count());
    }

    public function test_dashboard_lists_each_pending_migration_with_its_own_run_form(): void
    {
        [$name] = $this->pendingMigration();
        $this->actingAsUser();

        $this->get('/admin/opconsole')
            ->assertSee('<input type="hidden" name="migration" value="'.$name.'">', false)
            ->assertSee('Run only this')
            ->assertSee('Apply only ['.$name.']', false);
    }

    public function test_migration_input_is_ignored_by_other_actions(): void
    {
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear', ['migration' => 'anything'])->assertRedirect();

        $this->assertSame(['optimize:clear', []], $fake->calls[0]);
    }

    public function test_storage_link_action_is_disabled_by_default(): void
    {
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/storage_link')->assertNotFound();
        $this->assertSame([], $fake->calls);
    }

    public function test_storage_link_action_runs_storage_link_when_enabled(): void
    {
        config(['opconsole.actions.storage_link' => true]);
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/storage_link')->assertRedirect()->assertSessionHas('opconsole_success');

        $this->assertSame(['storage:link', []], $fake->calls[0]);
        $this->assertSame('storage_link', OpConsoleRun::query()->latest('id')->first()?->action);
    }

    public function test_ansi_colour_codes_are_stripped_from_output(): void
    {
        $this->fakeArtisan(0, "\e[32mDone\e[0m");
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear');

        $this->assertSame('Done', session('opconsole_output')['text']);
    }

    public function test_failed_action_is_flagged(): void
    {
        $this->fakeArtisan(1, 'boom');
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_error');
    }

    public function test_disabled_action_is_not_reachable(): void
    {
        $this->app['config']->set('opconsole.actions.migrate', false);
        $this->actingAsUser();

        $this->post('/admin/opconsole/migrate')->assertNotFound();
    }

    public function test_every_action_writes_an_audit_row(): void
    {
        $this->fakeArtisan();
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear');

        $this->assertDatabaseHas('opconsole_runs', ['action' => 'clear', 'status' => 'succeeded']);
    }

    public function test_a_persistence_failure_does_not_turn_a_successful_command_into_a_failure(): void
    {
        // Simulate a missing/unmigrated audit table by dropping it after boot.
        Schema::drop('opconsole_runs');

        $this->fakeArtisan(0, 'all good');
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')
            ->assertSessionHas('opconsole_success')
            ->assertSessionHas('opconsole_warning')
            ->assertSessionMissing('opconsole_error');
    }

    public function test_concurrent_actions_are_rejected_while_a_lock_is_held(): void
    {
        $this->actingAsUser();
        // Must use the same store ActionRunner locks on, or this doesn't actually simulate contention.
        $lock = Cache::store(config('opconsole.concurrency.lock_store'))->lock('opconsole:action-lock', 30);
        $lock->get();

        $response = $this->post('/admin/opconsole/clear');

        $response->assertSessionHas('opconsole_error');
        $lock->release();
    }

    /**
     * Regression for the self-invalidating-lock bug: `optimize:clear` runs `cache:clear`,
     * which flushes cache.default outright. If the action lock lived in that same store,
     * the flush would delete its own lock mid-run and a second action could start
     * concurrently. The lock must live in a separate store (config('opconsole.concurrency.lock_store'))
     * so flushing the default store never touches it.
     */
    public function test_flushing_the_default_cache_store_does_not_release_the_action_lock(): void
    {
        $this->assertNotSame(config('cache.default'), config('opconsole.concurrency.lock_store'));

        $runner = $this->app->make(ActionRunner::class);
        $method = new \ReflectionMethod($runner, 'lock');
        $method->setAccessible(true);
        $lock = $method->invoke($runner, 'clear');
        $this->assertTrue($lock->get());

        // Simulate exactly what `optimize:clear` really does mid-action: flush the default store.
        Cache::store(config('cache.default'))->flush();

        // A second attempt on the real lock store/key must still see it held.
        $contender = Cache::store(config('opconsole.concurrency.lock_store'))->lock('opconsole:action-lock', 30);
        $this->assertFalse($contender->get());

        $lock->release();
    }

    /** The lock must still be released cleanly after a *real* optimize:clear run, not just a faked one. */
    public function test_a_real_optimize_clear_run_does_not_leave_the_lock_stuck(): void
    {
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_success');

        // If the first run left the lock held, this second one would report "busy" instead of succeeding.
        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_success');
    }

    /**
     * Two different actions must not be able to interleave: the lock is shared across all three.
     * Uses the fake kernel (not a real `optimize`/`migrate`) so a lock bug here can never let a
     * real `config:cache` run and poison the shared Testbench skeleton's bootstrap cache for
     * every later test in the process (see the regression note on fakeArtisan()).
     */
    public function test_a_different_action_is_also_rejected_while_the_lock_is_held(): void
    {
        $fake = $this->fakeArtisan();
        $this->actingAsUser();
        $lock = Cache::store(config('opconsole.concurrency.lock_store'))->lock('opconsole:action-lock', 30);
        $lock->get();

        $this->post('/admin/opconsole/optimize')->assertSessionHas('opconsole_error');
        $this->post('/admin/opconsole/migrate')->assertSessionHas('opconsole_error');

        $this->assertSame([], $fake->calls, 'Artisan must never run while the lock is held.');

        $lock->release();
    }

    /**
     * When cache.default is "file" (standard single-server Laravel config where locks share
     * the cache data directory), running `optimize:clear` (`cache:clear`) flushes `cache.default`.
     * OpConsole's default isolated file lock store (`opconsole`) must survive that flush untouched.
     */
    public function test_optimize_clear_with_file_default_cache_does_not_invalidate_isolated_opconsole_file_lock(): void
    {
        $defaultCacheDir = sys_get_temp_dir().'/opconsole-cache-default-'.uniqid();
        $isolatedLockDir = sys_get_temp_dir().'/opconsole-cache-lock-'.uniqid();
        File::makeDirectory($defaultCacheDir, 0755, true);
        File::makeDirectory($isolatedLockDir, 0755, true);

        try {
            $this->app['config']->set('cache.default', 'file');
            $this->app['config']->set('cache.stores.file', [
                'driver' => 'file',
                'path' => $defaultCacheDir,
                'lock_path' => $defaultCacheDir,
            ]);
            $this->app['config']->set('cache.stores.opconsole', [
                'driver' => 'file',
                'path' => $isolatedLockDir,
                'lock_path' => $isolatedLockDir,
            ]);
            $this->app['config']->set('opconsole.concurrency.lock_store', 'opconsole');
            Cache::forgetDriver(['file', 'opconsole']);

            Cache::store('file')->put('canary_key', 'present', 60);
            $this->assertSame('present', Cache::store('file')->get('canary_key'));

            $heldLock = Cache::store('opconsole')->lock('opconsole:action-lock', 30);
            $this->assertTrue($heldLock->get());

            // Real cache:clear flushes the default file cache directory.
            Artisan::call('cache:clear');
            $this->assertNull(Cache::store('file')->get('canary_key'));

            // Contender must still be blocked because the isolated opconsole lock directory survived.
            $contender = Cache::store('opconsole')->lock('opconsole:action-lock', 30);
            $this->assertFalse($contender->get());

            $fake = $this->fakeArtisan();
            $this->actingAsUser();
            $this->post('/admin/opconsole/optimize')->assertSessionHas('opconsole_error');
            $this->assertSame([], $fake->calls);

            $heldLock->release();
        } finally {
            Cache::forgetDriver(['file', 'opconsole']);
            File::deleteDirectory($defaultCacheDir);
            File::deleteDirectory($isolatedLockDir);
        }
    }

    /**
     * Unsafe cache/lock-store configurations must fail closed without silently falling back
     * to Cache::lock() or executing any Artisan command.
     */
    public function test_unsafe_lock_store_configurations_fail_closed_without_executing_artisan(): void
    {
        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        // 1. NullStore / NoLock (cannot enforce mutual exclusion)
        $this->app['config']->set('cache.stores.null_lock', ['driver' => 'null']);
        $this->app['config']->set('opconsole.concurrency.lock_store', 'null_lock');
        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_error');

        // 2. Non-existent cache store name
        $this->app['config']->set('opconsole.concurrency.lock_store', 'unconfigured_store_name');
        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_error');

        // 3. Lock store identical to cache.default without separate lock storage
        $this->app['config']->set('opconsole.concurrency.lock_store', 'array');
        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_error');

        // 4. Separate file lock store whose lock_path overlaps cache.default's file path
        $sharedDir = sys_get_temp_dir().'/opconsole-shared-file-'.uniqid();
        File::makeDirectory($sharedDir, 0755, true);
        try {
            $this->app['config']->set('cache.default', 'file');
            $this->app['config']->set('cache.stores.file', [
                'driver' => 'file',
                'path' => $sharedDir,
                'lock_path' => $sharedDir,
            ]);
            $this->app['config']->set('cache.stores.overlapping_lock', [
                'driver' => 'file',
                'path' => $sharedDir.'/sub',
                'lock_path' => $sharedDir.'/sub',
            ]);
            $this->app['config']->set('opconsole.concurrency.lock_store', 'overlapping_lock');
            Cache::forgetDriver(['file', 'overlapping_lock']);

            $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_error');
        } finally {
            Cache::forgetDriver(['file', 'overlapping_lock']);
            File::deleteDirectory($sharedDir);
            $this->app['config']->set('cache.default', 'array');
        }

        // 5. Ephemeral ArrayStore outside unit tests (e.g. production)
        $this->withoutCsrfMiddleware();
        $this->app['env'] = 'production';
        $this->app['config']->set('opconsole.concurrency.lock_store', 'opconsole_lock');
        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_error');

        $this->assertSame([], $fake->calls, 'Artisan must never execute under any unsafe lock configuration.');
    }

    public function test_differently_named_redis_stores_sharing_a_database_are_not_isolated(): void
    {
        config([
            'cache.default' => 'app_cache',
            'cache.stores.app_cache' => ['driver' => 'redis', 'connection' => 'default'],
            'cache.stores.action_lock' => ['driver' => 'redis', 'connection' => 'action_locks'],
            'database.redis.default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 14],
            'database.redis.action_locks' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 14],
            'opconsole.concurrency.lock_store' => 'action_lock',
        ]);

        $result = app(ActionRunner::class)->validateLockStore();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('shares Redis database storage', $result['reason']);
    }

    /**
     * Runtime lock acquisition exceptions (e.g. Redis outage or unwritable lock storage)
     * must fail closed, never invoke Artisan, and record a sanitized failed audit row.
     */
    public function test_lock_acquisition_exception_fails_closed_without_executing_artisan(): void
    {
        Cache::extend('throwing_lock', function () {
            $store = new class extends ArrayStore
            {
                public function lock($name, $seconds = 0, $owner = null): Lock
                {
                    return new class($name, $seconds, $owner) extends Lock
                    {
                        public function acquire(): bool
                        {
                            throw new \RuntimeException('Redis auth failed: password=topsecretvalue');
                        }

                        public function release(): bool
                        {
                            return true;
                        }

                        protected function getCurrentOwner(): string
                        {
                            return '';
                        }

                        public function forceRelease(): void {}
                    };
                }
            };

            return new CacheRepository($store);
        });

        $this->app['config']->set('cache.stores.broken_lock_store', ['driver' => 'throwing_lock']);
        $this->app['config']->set('opconsole.concurrency.lock_store', 'broken_lock_store');

        $fake = $this->fakeArtisan();
        $this->actingAsUser();

        $response = $this->post('/admin/opconsole/clear');

        $response->assertRedirect();
        $response->assertSessionHas('opconsole_error');
        $this->assertSame([], $fake->calls);

        $run = OpConsoleRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('failed', $run->status);
        $this->assertStringNotContainsString('topsecretvalue', (string) $run->error);
    }

    /**
     * Expired locks allow subsequent operations safely, and abandoned "started" audit rows
     * past the lock window are classified as Interrupted / Unknown (never Succeeded).
     */
    public function test_expired_lock_allows_subsequent_operation_and_stale_started_audit_row_is_classified_as_unknown(): void
    {
        $baseTime = Carbon::parse('2026-06-01 12:00:00');
        Carbon::setTestNow($baseTime);

        try {
            $this->app['config']->set('opconsole.concurrency.lock_seconds', 10);
            $this->app['config']->set('opconsole.concurrency.max_runtime_seconds', 5);
            $lock = Cache::store('opconsole_lock')->lock('opconsole:action-lock', 10);
            $this->assertTrue($lock->get());

            OpConsoleRun::query()->create([
                'action' => 'migrate',
                'status' => 'started',
                'user_id' => '1',
                'user_label' => 'Test Admin',
                'started_at' => $baseTime,
                'finished_at' => null,
            ]);

            // The effective lease is max(10s, runtime 5s + 60s margin), and abandoned rows age out at 2x.
            Carbon::setTestNow($baseTime->copy()->addSeconds(140));

            $this->actingAsUser();
            $this->get('/admin/opconsole')
                ->assertOk()
                ->assertSee('data-run-status="unknown"', false)
                ->assertSee('Interrupted / Unknown');

            $fake = $this->fakeArtisan(0, 'Recovered cleanly');
            $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_success');
            $this->assertCount(1, $fake->calls);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * An exception thrown inside Artisan::call() must be caught, update the existing audit row
     * to "failed" with sanitized error text, release the lock, and redirect cleanly instead of
     * returning HTTP 500 or leaving an orphaned "started" row.
     */
    public function test_thrown_artisan_exception_updates_audit_row_to_failed_releases_lock_and_sanitizes_secrets(): void
    {
        $exception = new \RuntimeException(
            'SQLSTATE[HY000]: connection failed for mysql://dbadmin:MySuperSecret99@127.0.0.1/prod at '.base_path('config/database.php')
        );
        $this->fakeArtisan(0, '', $exception);

        $this->actingAsUser();
        $response = $this->post('/admin/opconsole/migrate');

        $response->assertRedirect();
        $response->assertSessionHas('opconsole_error');

        $output = session('opconsole_output')['text'] ?? '';
        $this->assertStringNotContainsString('MySuperSecret99', $output);
        $this->assertStringNotContainsString(base_path(), $output);
        $this->assertStringContainsString('[REDACTED]', $output);

        $this->assertSame(1, OpConsoleRun::query()->count());
        $run = OpConsoleRun::query()->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->exit_code);
        $this->assertNotNull($run->finished_at);
        $this->assertStringNotContainsString('MySuperSecret99', (string) $run->error);

        // Lock must have been released in finally so the next action succeeds immediately.
        $this->fakeArtisan(0, 'ok');
        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_success');
    }

    public function test_overlong_actor_and_error_fields_are_truncated_safely_for_audit_persistence(): void
    {
        $this->fakeArtisan(1, str_repeat('E', 1200));
        $this->actingAsUser(name: str_repeat('A', 400));

        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_error');

        $run = OpConsoleRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('failed', $run->status);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $run->user_label));
        $this->assertLessThanOrEqual(255, mb_strlen((string) $run->error));
    }

    public function test_action_duration_is_measured_in_milliseconds(): void
    {
        $fake = $this->fakeArtisan();
        $fake->sleepMs = 50;
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')->assertRedirect();

        $run = OpConsoleRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertGreaterThanOrEqual(45, $run->duration_ms);

        $this->get('/admin/opconsole')->assertOk()->assertDontSee('>0 ms<', false);
    }

    public function test_instant_operations_store_zero_duration(): void
    {
        app(ActionRunner::class)->record('log.download', null, true, 'laravel.log');

        $this->assertSame(0, OpConsoleRun::query()->latest('id')->first()?->duration_ms);
    }

    public function test_history_falls_back_to_timestamps_for_rows_without_duration(): void
    {
        OpConsoleRun::query()->create([
            'action' => 'clear',
            'status' => 'succeeded',
            'started_at' => now()->subSeconds(3),
            'finished_at' => now(),
        ]);
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk()->assertSee('3.00 s');
    }
}

/** Records call()/output() without touching the framework's real (final) console kernel. */
class FakeArtisanKernel implements KernelContract
{
    public array $calls = [];

    public int $sleepMs = 0;

    public function __construct(
        private int $exitCode = 0,
        private string $fakeOutput = '',
        private ?\Throwable $throwOnCall = null,
    ) {}

    public function handle($input, $output = null)
    {
        return $this->exitCode;
    }

    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        $this->calls[] = [$command, $parameters];

        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }

        if ($this->throwOnCall !== null) {
            throw $this->throwOnCall;
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
        return $this->fakeOutput;
    }

    public function terminate($input, $status) {}

    public function bootstrap() {}
}

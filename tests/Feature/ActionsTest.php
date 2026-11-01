<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Support\ActionRunner;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Support\Facades\Cache;
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
    private function fakeArtisan(int $exitCode = 0, string $output = ''): FakeArtisanKernel
    {
        $fake = new FakeArtisanKernel($exitCode, $output);
        $this->app->instance(KernelContract::class, $fake);
        $this->app->instance('artisan', $fake);

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

        $response = $this->post('/admin/opconsole/clear');

        $response->assertSessionHas('opconsole_success');
        $response->assertSessionMissing('opconsole_error');
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
        $lock = $method->invoke($runner);
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
}

/** Records call()/output() without touching the framework's real (final) console kernel. */
class FakeArtisanKernel implements KernelContract
{
    public array $calls = [];

    public function __construct(private int $exitCode = 0, private string $fakeOutput = '') {}

    public function handle($input, $output = null)
    {
        return $this->exitCode;
    }

    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        $this->calls[] = [$command, $parameters];

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

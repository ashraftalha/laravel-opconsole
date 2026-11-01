<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

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

    /** Swaps the bound Artisan kernel for a recording fake: Mockery cannot partial-mock Testbench's final Kernel. */
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
        $lock = Cache::lock('opconsole:action-lock', 30);
        $lock->get();

        $response = $this->post('/admin/opconsole/clear');

        $response->assertSessionHas('opconsole_error');
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

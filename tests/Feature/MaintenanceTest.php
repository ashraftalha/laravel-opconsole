<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use AshrafTalha\OpConsole\Support\HealthChecks;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;

class MaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function tearDown(): void
    {
        if ($this->app?->isDownForMaintenance()) {
            Artisan::call('up');
        }

        parent::tearDown();
    }

    public function test_down_action_generates_a_bypass_secret_shown_once_and_never_audited(): void
    {
        $this->actingAsUser();
        $this->withoutCsrfMiddleware();

        $response = $this->post('/admin/opconsole/down')->assertRedirect();

        $this->assertTrue($this->app->isDownForMaintenance());
        $output = session('opconsole_output');
        $this->assertTrue($output['ok']);
        $this->assertMatchesRegularExpression('#Bypass URL \(shown once\): https?://[^/\s]+/[A-Za-z0-9]{40}$#', $output['text']);
        preg_match('#https?://[^/\s]+/([A-Za-z0-9]{40})$#', $output['text'], $match);

        $run = OpConsoleRun::query()->latest('id')->first();
        $this->assertSame('down', $run->action);
        $this->assertSame('succeeded', $run->status);
        $this->assertStringNotContainsString($match[1], json_encode($run->getAttributes()));

        $response->assertSessionHas('opconsole_success');
    }

    public function test_console_stays_reachable_in_maintenance_mode_while_other_routes_return_503(): void
    {
        Artisan::call('down');
        $this->actingAsUser();

        $this->get('/login')->assertStatus(503);
        $this->get('/admin/opconsole')->assertOk()->assertSee('The application is in maintenance mode');
    }

    public function test_up_action_brings_the_application_back_online_from_the_console(): void
    {
        Artisan::call('down');
        $this->actingAsUser();
        $this->withoutCsrfMiddleware();

        $this->post('/admin/opconsole/up')->assertRedirect()->assertSessionHas('opconsole_success');

        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertSame('up', OpConsoleRun::query()->latest('id')->first()->action);
    }

    public function test_console_is_blocked_in_maintenance_mode_when_allow_console_is_disabled(): void
    {
        PreventRequestsDuringMaintenance::flushState();
        static::$configOverrides = ['opconsole.maintenance.allow_console' => false];
        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->allowOpConsole();

        try {
            Artisan::call('down');
            $this->actingAsUser();

            $this->get('/admin/opconsole')->assertStatus(503);
        } finally {
            PreventRequestsDuringMaintenance::flushState();
        }
    }

    /** Regression: an empty prefix used to register "/*", exempting every route of the host application. */
    public function test_empty_route_prefix_does_not_exempt_the_whole_application_from_maintenance_mode(): void
    {
        PreventRequestsDuringMaintenance::flushState();
        static::$configOverrides = ['opconsole.route.prefix' => ''];
        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        try {
            Artisan::call('down');

            $this->get('/login')->assertStatus(503);
        } finally {
            PreventRequestsDuringMaintenance::flushState();
        }
    }

    public function test_maintenance_mode_check_warns_while_down(): void
    {
        $database = ['ok' => true, 'driver' => 'sqlite', 'name' => ':memory:', 'ran' => 0, 'pending' => [], 'error' => null];
        $find = fn () => collect($this->app->make(HealthChecks::class)->run($database, null))->firstWhere('id', 'app.maintenance_mode');

        $this->assertSame('healthy', $find()['status']);

        Artisan::call('down');
        $check = $find();

        $this->assertSame('warning', $check['status']);
        $this->assertSame('php artisan up', $check['fix']);
    }

    public function test_maintenance_cards_reflect_the_current_state(): void
    {
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk()
            ->assertSee('Medium risk · Site offline')
            ->assertSee('Take offline')
            ->assertSee('Already online');
    }

    public function test_down_and_up_require_the_run_gate(): void
    {
        Gate::define('runOpConsoleActions', fn () => false);
        $this->actingAsUser();
        $this->withoutCsrfMiddleware();

        $this->post('/admin/opconsole/down')->assertForbidden();
        $this->assertFalse($this->app->isDownForMaintenance());
    }
}

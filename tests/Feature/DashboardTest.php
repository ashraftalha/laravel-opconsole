<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;

class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_page_renders_with_no_log_files(): void
    {
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk()->assertSee('Health checks');
    }

    public function test_three_tiles_are_shown(): void
    {
        $this->actingAsUser();

        $this->get('/admin/opconsole')
            ->assertSee('Database')
            ->assertSee('Health checks')
            ->assertSee('Errors in the last 24 hours');
    }

    public function test_back_link_is_hidden_when_not_configured(): void
    {
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertDontSee('href="/admin/dashboard"', false);
    }

    public function test_back_link_appears_when_configured(): void
    {
        $this->app['config']->set('opconsole.back_url', '/admin/dashboard');
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertSee('/admin/dashboard', false);
    }

    public function test_disabled_actions_are_not_rendered(): void
    {
        $this->app['config']->set('opconsole.actions.migrate', false);
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertDontSee('action-migrate', false);
    }

    /**
     * Standalone dashboard loads no third-party CDN assets, emits no inline <script> blocks or
     * inline style attributes, and serves its self-contained CSS/JS fallback route behind auth.
     */
    public function test_standalone_dashboard_uses_self_contained_assets_and_strict_csp_compatible_markup(): void
    {
        $this->assertNull(config('opconsole.assets'));

        // Guest cannot bypass auth on fallback asset route.
        $this->get('/admin/opconsole/assets/opconsole.css')->assertRedirect('/login');

        $this->actingAsUser();

        $response = $this->get('/admin/opconsole')->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
        $this->assertStringNotContainsString('unicons', $html);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertStringContainsString('id="opconsole-confirm-modal"', $html);
        $this->assertStringContainsString('id="opconsole-live-region"', $html);

        $this->get('/admin/opconsole/assets/opconsole.css')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get('/admin/opconsole/assets/opconsole.js')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get('/admin/opconsole/assets/unknown.js')->assertNotFound();
    }

    public function test_custom_host_layout_pushes_self_contained_assets_and_renders_embedded_shell(): void
    {
        $viewDir = sys_get_temp_dir().'/opconsole-host-views-'.uniqid();
        File::makeDirectory($viewDir, 0755, true);

        try {
            File::put(
                $viewDir.'/host-shell.blade.php',
                "<!doctype html><html><head>@stack('styles')</head><body data-host-shell>@yield('content')@stack('scripts')</body></html>"
            );
            View::addLocation($viewDir);
            $this->app['config']->set('opconsole.layout', 'host-shell');

            $this->actingAsUser();

            $this->get('/admin/opconsole')
                ->assertOk()
                ->assertSee('data-host-shell', false)
                ->assertSee('/admin/opconsole/assets/opconsole.css', false)
                ->assertSee('/admin/opconsole/assets/opconsole.js', false)
                ->assertSee('id="opconsole-confirm-modal"', false);
        } finally {
            File::deleteDirectory($viewDir);
        }
    }

    public function test_failing_critical_checks_auto_open_accordion_and_actions_render_risk_and_disabled_states(): void
    {
        $this->app['config']->set('cache.stores.null_lock', ['driver' => 'null']);
        $this->app['config']->set('opconsole.concurrency.lock_store', 'null_lock');

        Gate::define('viewOpConsole', fn () => true);
        Gate::define('runOpConsoleActions', fn () => false);
        $this->actingAsUser();

        $this->get('/admin/opconsole')
            ->assertOk()
            ->assertSee('id="checks" open', false)
            ->assertSee('High risk')
            ->assertSee('Read-only access: your account does not have action execution permission.')
            ->assertSee('disabled', false);
    }

    /**
     * Operation history renders bounded recent runs while keeping dashboard SQL queries
     * constant even when opconsole_runs contains many historical rows.
     */
    public function test_operation_history_renders_recent_runs_with_bounded_query_count(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            OpConsoleRun::query()->create([
                'action' => $i % 2 === 0 ? 'clear' : 'optimize',
                'status' => 'succeeded',
                'user_id' => '1',
                'user_label' => 'Actor '.$i,
                'exit_code' => 0,
                'started_at' => now()->subMinutes(60 - $i),
                'finished_at' => now()->subMinutes(60 - $i)->addMilliseconds(150),
            ]);
        }

        $this->actingAsUser();

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->get('/admin/opconsole')->assertOk();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $response->assertSee('id="history"', false)
            ->assertSee('Operation history')
            ->assertSee('Actor 50')
            ->assertDontSee('Actor 10');

        $this->assertLessThanOrEqual(6, count($queries), 'Dashboard SQL query count must remain bounded.');
    }

    public function test_operation_history_respects_configured_limit(): void
    {
        config(['opconsole.history_limit' => 3]);

        for ($i = 1; $i <= 5; $i++) {
            OpConsoleRun::query()->create([
                'action' => 'clear',
                'status' => 'succeeded',
                'user_id' => '1',
                'user_label' => 'Actor '.$i,
                'exit_code' => 0,
                'started_at' => now()->subMinutes(10 - $i),
                'finished_at' => now()->subMinutes(10 - $i),
            ]);
        }

        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk()
            ->assertSee('Actor 5')
            ->assertSee('Actor 3')
            ->assertDontSee('Actor 2');
    }
}

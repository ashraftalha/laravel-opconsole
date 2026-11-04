<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Support\ActionRunner;
use AshrafTalha\OpConsole\Support\ConsoleStatus;
use AshrafTalha\OpConsole\Support\Runtime;
use AshrafTalha\OpConsole\Tests\Support\FakeRuntime;
use AshrafTalha\OpConsole\Tests\TestCase;

class OctaneTest extends TestCase
{
    private FakeRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->runtime = new FakeRuntime;
        $this->app->instance(Runtime::class, $this->runtime);
        $this->app['config']->set('octane', ['server' => 'swoole', 'https' => false, 'garbage' => 50, 'max_execution_time' => 30, 'swoole' => ['options' => []]]);
    }

    /** @return array<string, array{status: string, value: ?string, fix: ?string, severity: string}> */
    private function octaneChecks(): array
    {
        $checks = app(ConsoleStatus::class)->health()['checks'];

        return collect($checks)->where('group', 'Octane and runtime')
            ->mapWithKeys(fn ($check) => [$check['id'] => $check])->all();
    }

    public function test_nothing_changes_when_octane_is_not_installed(): void
    {
        $this->runtime->installed = false;

        $this->assertSame([], $this->octaneChecks());

        $this->actingAsUser();
        $this->get('/admin/opconsole')->assertOk()
            ->assertDontSee('Octane and runtime')
            ->assertDontSee('Reload Octane workers')
            ->assertDontSee('id="action-octane_reload"', false);
    }

    public function test_checks_can_be_turned_off(): void
    {
        $this->app['config']->set('opconsole.octane.checks', false);

        $this->assertSame([], $this->octaneChecks());
    }

    public function test_swoole_installed_but_served_by_fpm_and_stopped_outside_production_is_advisory(): void
    {
        $checks = $this->octaneChecks();

        $this->assertSame('healthy', $checks['octane.server']['status']);
        $this->assertStringContainsString('swoole 6.0.2', (string) $checks['octane.server']['value']);
        $this->assertSame('warning', $checks['octane.serving']['status']);
        $this->assertSame('info', $checks['octane.serving']['severity']);
        $this->assertSame('info', $checks['octane.running']['severity']);
        $this->assertSame('php artisan octane:start --server=swoole', $checks['octane.running']['fix']);
        $this->assertSame('unknown', $checks['octane.opcache_cli']['status']);
    }

    public function test_missing_swoole_extension_is_critical_in_production(): void
    {
        $this->runtime->extensions = [];
        $this->app['env'] = 'production';

        $checks = $this->octaneChecks();

        $this->assertSame('critical', $checks['octane.server']['status']);
        $this->assertSame('pecl install swoole', $checks['octane.server']['fix']);
    }

    public function test_roadrunner_needs_its_binary(): void
    {
        $this->app['config']->set('octane.server', 'roadrunner');

        $this->assertSame('warning', $this->octaneChecks()['octane.server']['status']);

        $this->runtime->files = [base_path('rr')];
        $checks = $this->octaneChecks();
        $this->assertSame('healthy', $checks['octane.server']['status']);
        $this->assertArrayNotHasKey('swoole.package_max_length', $checks);
    }

    public function test_expected_in_production_turns_a_stopped_server_critical(): void
    {
        $this->app['env'] = 'production';
        $this->app['config']->set('opconsole.octane.expected_in_production', true);

        $checks = $this->octaneChecks();

        $this->assertSame('critical', $checks['octane.running']['status']);
        $this->assertSame('warning', $checks['octane.serving']['status']);
        $this->assertSame('warning', $checks['octane.serving']['severity']);
    }

    public function test_swoole_upload_limit_https_garbage_and_xdebug_are_checked(): void
    {
        $this->runtime->ini['post_max_size'] = '64M';
        $this->runtime->ini['memory_limit'] = '64M';
        $this->runtime->extensions['xdebug'] = '3.4.0';
        $this->app['config']->set('app.url', 'https://shop.test');
        $this->app['config']->set('octane.garbage', 128);
        $this->app['config']->set('octane.swoole.options', ['worker_num' => 64]);

        $checks = $this->octaneChecks();

        $this->assertSame('warning', $checks['swoole.package_max_length']['status']);
        $this->assertStringContainsString('package_max_length', (string) $checks['swoole.package_max_length']['fix']);
        $this->assertSame('warning', $checks['octane.https']['status']);
        $this->assertSame('.env: OCTANE_HTTPS=true', $checks['octane.https']['fix']);
        $this->assertSame('warning', $checks['octane.garbage']['status']);
        $this->assertNotSame('healthy', $checks['swoole.xdebug']['status']);
        $this->assertSame('info', $checks['swoole.worker_num']['severity']);
        $this->assertNotSame('healthy', $checks['swoole.worker_num']['status']);
    }

    public function test_served_by_octane_reports_running_and_reads_cli_opcache(): void
    {
        $this->runtime->serving = true;
        $this->runtime->ini['opcache.enable_cli'] = '0';
        $this->app['env'] = 'production';

        $checks = $this->octaneChecks();

        $this->assertSame('healthy', $checks['octane.serving']['status']);
        $this->assertSame('healthy', $checks['octane.running']['status']);
        $this->assertSame('warning', $checks['octane.opcache_cli']['status']);
    }

    public function test_timed_actions_are_refused_inside_a_swoole_worker_unless_allowed(): void
    {
        $this->runtime->serving = true;
        $runner = app(ActionRunner::class);

        $this->assertStringContainsString('Swoole worker', (string) $runner->unsupportedRuntimeReason('clear'));
        $this->assertNull($runner->unsupportedRuntimeReason('security_scan'));
        $this->assertNull($runner->unsupportedRuntimeReason('octane_reload'));

        $this->app['config']->set('opconsole.octane.allow_actions_in_swoole', true);
        if (function_exists('pcntl_alarm')) {
            $this->assertNull($runner->unsupportedRuntimeReason('clear'));
        }

        $this->app['config']->set('octane.server', 'roadrunner');
        $this->app['config']->set('opconsole.octane.allow_actions_in_swoole', false);
        if (function_exists('pcntl_alarm')) {
            $this->assertNull($runner->unsupportedRuntimeReason('clear'));
        }
    }

    public function test_reload_card_is_disabled_until_the_server_runs(): void
    {
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk()
            ->assertSee('id="action-octane_reload"', false)
            ->assertSee('No Octane server is running for this application.');

        $this->runtime->running = true;
        $this->get('/admin/opconsole')->assertOk()
            ->assertSee('id="action-octane_reload"', false)
            ->assertDontSee('No Octane server is running for this application.')
            ->assertSee('Octane server')
            ->assertSee('Garbage threshold');
    }

    public function test_cache_changes_suggest_reloading_workers(): void
    {
        $this->runtime->running = true;
        $this->actingAsUser();

        $this->withSession(['opconsole_output' => ['action' => 'clear', 'text' => 'Caches cleared.', 'ok' => true]])
            ->get('/admin/opconsole')->assertOk()
            ->assertSee('Octane workers still hold the old configuration in memory.')
            ->assertSee('href="#action-octane_reload"', false);
    }
}

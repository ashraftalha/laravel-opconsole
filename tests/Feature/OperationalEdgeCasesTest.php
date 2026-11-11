<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Http\Middleware\AuthorizeOpConsole;
use AshrafTalha\OpConsole\Support\FailedJobs;
use AshrafTalha\OpConsole\Support\HealthNotifier;
use AshrafTalha\OpConsole\Support\LogViewer;
use AshrafTalha\OpConsole\Support\SecurityScanner;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Mockery;

class OperationalEdgeCasesTest extends TestCase
{
    public function test_security_commands_report_failure_and_json_and_include_dev(): void
    {
        $scanner = Mockery::mock(SecurityScanner::class);
        $scanner->shouldReceive('scan')->twice()->andReturn(['status' => 'failed', 'last_failure' => ['message' => 'offline']]);
        $this->app->instance(SecurityScanner::class, $scanner);
        $this->artisan('opconsole:security')->expectsOutput('offline')->assertExitCode(1);
        $this->artisan('opconsole:security --json --include-dev')->assertExitCode(1);
        $this->assertTrue(config('opconsole.security.include_dev'));
    }

    public function test_health_notify_invokes_notifier_and_security_schedule_is_registered(): void
    {
        config(['opconsole.notifications.enabled' => true]);
        $notifier = Mockery::mock(HealthNotifier::class);
        $notifier->shouldReceive('evaluate')->once()->with(Mockery::type('array'));
        $this->app->instance(HealthNotifier::class, $notifier);
        $this->artisan('opconsole:health --notify')->run();
        config(['opconsole.security.schedule' => true]);
        $events = app(Schedule::class)->events();
        $this->assertNotEmpty(array_filter($events, fn ($event) => $event->description === 'opconsole:security'));
    }

    public function test_invalid_authorization_config_has_no_gate(): void
    {
        config(['opconsole.authorization' => 'invalid']);
        $this->assertNull(AuthorizeOpConsole::gateNameFor('view'));
    }

    public function test_failed_retry_is_reported_with_missing_audit_warning(): void
    {
        $this->allowOpConsole();
        $this->actingAsUser();
        $this->withoutCsrfMiddleware();
        $jobs = Mockery::mock(FailedJobs::class);
        $jobs->shouldReceive('exists')->with('1')->andReturn(true);
        $jobs->shouldReceive('retry')->with('1')->once()->andReturn(['ok' => false, 'error' => 'offline']);
        $this->app->instance(FailedJobs::class, $jobs);
        $this->post('/admin/opconsole/failed-jobs/1/retry')->assertSessionHas('opconsole_error')->assertSessionHas('opconsole_warning');
        config(['opconsole.audit.policy' => 'required']);
        $this->post('/admin/opconsole/failed-jobs/1/retry')->assertSessionHas('opconsole_error');
    }

    public function test_log_disappearance_and_delete_failure_are_reported(): void
    {
        $this->allowOpConsole();
        $this->actingAsUser();
        $this->withoutCsrfMiddleware();
        $logs = Mockery::mock(LogViewer::class);
        $logs->shouldReceive('path')->with('old.log')->andReturn('/tmp/old.log');
        $logs->shouldReceive('deleteOutcome')->with('old.log')->andReturn('not_found', 'failed');
        $this->app->instance(LogViewer::class, $logs);
        $this->delete('/admin/opconsole/logs/old.log')->assertSessionHas('opconsole_error');
        $this->delete('/admin/opconsole/logs/old.log')->assertSessionHas('opconsole_error');
    }

    public function test_log_environment_filters_accept_all_and_explicit_environment(): void
    {
        $this->allowOpConsole();
        $this->actingAsUser();
        $dir = $this->useTemporaryLogDirectory();
        file_put_contents($dir.'/test.log', '[2026-01-01 00:00:00] production.ERROR: production-only');
        $this->get('/admin/opconsole?env=all')->assertOk()->assertSee('production-only');
        $this->get('/admin/opconsole?env=production')->assertOk()->assertSee('production-only');
    }
}

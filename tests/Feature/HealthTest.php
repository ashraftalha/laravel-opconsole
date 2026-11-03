<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Contracts\HealthCheck;
use AshrafTalha\OpConsole\Support\ConsoleStatus;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class HealthTest extends TestCase
{
    private const TOKEN = 'test-health-token-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        // A baseline with no failing built-in checks, so each test controls what fails.
        config([
            'queue.default' => 'sync',
            'queue.failed.driver' => 'null',
            'filesystems.disks.public' => null,
        ]);
    }

    private function enableEndpoint(): void
    {
        config(['opconsole.health_endpoint.enabled' => true, 'opconsole.health_endpoint.token' => self::TOKEN]);
    }

    public function test_health_route_is_registered(): void
    {
        $this->assertTrue($this->app['router']->getRoutes()->hasNamedRoute('opconsole.health'));
    }

    public function test_endpoint_returns_404_when_disabled(): void
    {
        config(['opconsole.health_endpoint.token' => self::TOKEN]);

        $this->getJson('/admin/opconsole/health', ['Authorization' => 'Bearer '.self::TOKEN])->assertNotFound();
    }

    public function test_endpoint_stays_off_while_no_token_is_configured(): void
    {
        config(['opconsole.health_endpoint.enabled' => true, 'opconsole.health_endpoint.token' => '']);

        $this->getJson('/admin/opconsole/health', ['Authorization' => 'Bearer '])->assertNotFound();
    }

    public function test_endpoint_rejects_missing_or_wrong_tokens(): void
    {
        $this->enableEndpoint();

        $this->getJson('/admin/opconsole/health')->assertUnauthorized();
        $this->getJson('/admin/opconsole/health', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    public function test_endpoint_returns_statuses_without_hints_fixes_or_session(): void
    {
        $this->enableEndpoint();

        $response = $this->getJson('/admin/opconsole/health', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['status', 'checked_at', 'checks' => [['id', 'group', 'label', 'status', 'value']]]);

        foreach ($response->json('checks') as $check) {
            $this->assertSame(['id', 'group', 'label', 'status', 'value'], array_keys($check));
        }
        $this->assertContains($response->json('status'), ['healthy', 'warning']);
        $response->assertCookieMissing(config('session.cookie'));
    }

    public function test_endpoint_returns_503_when_any_check_is_critical(): void
    {
        $this->enableEndpoint();
        config(['opconsole.checks' => [CriticalCheck::class]]);

        $this->getJson('/admin/opconsole/health', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(503)
            ->assertJsonPath('status', 'critical');
    }

    public function test_required_unknown_check_promotes_overall_status_for_endpoint_and_cli(): void
    {
        config([
            'queue.default' => 'redis',
            'opconsole.health.required_checks' => ['queue.stuck_jobs'],
        ]);
        $this->enableEndpoint();

        $this->getJson('/admin/opconsole/health', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(503)
            ->assertJsonPath('status', 'critical');
        $this->artisan('opconsole:health')->assertExitCode(1);
    }

    /**
     * Regression: a throwing custom check is reported under a class-derived fallback ID, so its
     * required ID silently vanished from the report and the endpoint stayed healthy.
     */
    public function test_required_custom_check_that_throws_makes_the_endpoint_critical(): void
    {
        config([
            'opconsole.checks' => [ThrowingRequiredCheck::class],
            'opconsole.health.required_checks' => ['custom.payments'],
        ]);
        $this->enableEndpoint();

        $this->getJson('/admin/opconsole/health', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertStatus(503)
            ->assertJsonPath('status', 'critical')
            ->assertJsonFragment(['id' => 'custom.payments', 'status' => 'critical']);
        $this->artisan('opconsole:health')->assertExitCode(1);
    }

    public function test_required_custom_check_that_reports_normally_is_not_duplicated(): void
    {
        config([
            'opconsole.checks' => [WarningCheck::class],
            'opconsole.health.required_checks' => ['custom.always-warning'],
        ]);

        $report = app(ConsoleStatus::class)->health();

        $this->assertSame(1, count(array_keys(array_column($report['checks'], 'id'), 'custom.always-warning')));
        $this->assertSame('warning', $report['status']);
    }

    /** Built-in IDs only appear conditionally (database.connection only on failure), so absence is not a failure. */
    public function test_required_built_in_check_that_is_absent_does_not_fail_the_report(): void
    {
        config(['opconsole.health.required_checks' => ['database.connection']]);

        $this->assertNotSame('critical', app(ConsoleStatus::class)->health()['status']);
    }

    /** Regression: a failing security report store used to throw out of every dashboard render and health check. */
    public function test_unreadable_security_report_store_degrades_to_unknown_instead_of_a_500(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->allowOpConsole();
        config(['opconsole.security.store' => 'store-that-does-not-exist']);
        $this->enableEndpoint();

        $this->getJson('/admin/opconsole/health', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonFragment(['id' => 'security.dependency_audit', 'status' => 'unknown']);

        $this->actingAsUser();
        $this->get('/admin/opconsole')->assertOk()->assertSee('Scan status: unavailable');
    }

    public function test_unknown_policy_can_promote_unrequired_checks_to_warning(): void
    {
        config(['queue.default' => 'redis', 'opconsole.health.unknown_policy' => 'warning']);
        $report = app(ConsoleStatus::class)->health();

        $this->assertContains('warning', array_column($report['checks'], 'status'));
        $this->assertContains($report['status'], ['warning', 'critical']);
    }

    public function test_endpoint_is_rate_limited(): void
    {
        static::$configOverrides = [
            'opconsole.health_endpoint.rate_limit' => 2,
            'queue.default' => 'sync',
            'queue.failed.driver' => 'null',
        ];
        $this->refreshApplication();
        $this->enableEndpoint();
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];

        $this->getJson('/admin/opconsole/health', $headers)->assertOk();
        $this->getJson('/admin/opconsole/health', $headers)->assertOk();
        $this->getJson('/admin/opconsole/health', $headers)->assertStatus(429);
    }

    public function test_command_prints_a_table_and_succeeds_without_critical_checks(): void
    {
        $this->artisan('opconsole:health')
            ->expectsOutputToContain('scheduler.heartbeat')
            ->expectsOutputToContain('Overall:')
            ->assertExitCode(0);
    }

    public function test_command_fails_when_a_check_reaches_the_fail_on_level(): void
    {
        config(['opconsole.checks' => [CriticalCheck::class]]);

        $this->artisan('opconsole:health')->assertExitCode(1);
    }

    public function test_command_fail_on_warning_also_fails_on_warnings(): void
    {
        config(['opconsole.checks' => [WarningCheck::class]]);

        $this->artisan('opconsole:health')->assertExitCode(0);
        $this->artisan('opconsole:health', ['--fail-on' => 'warning'])->assertExitCode(1);
    }

    public function test_command_rejects_an_invalid_fail_on_level(): void
    {
        $this->artisan('opconsole:health', ['--fail-on' => 'unknown'])->assertExitCode(2);
    }

    public function test_command_prints_machine_readable_json(): void
    {
        config(['opconsole.checks' => [CriticalCheck::class]]);

        $exitCode = Artisan::call('opconsole:health', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);

        $this->assertSame('critical', $decoded['status']);
        $this->assertArrayHasKey('checked_at', $decoded);
        $this->assertContains('custom.always-critical', array_column($decoded['checks'], 'id'));
    }
}

class CriticalCheck implements HealthCheck
{
    public function run(): array
    {
        return ['id' => 'always-critical', 'group' => 'Custom', 'label' => 'Always critical', 'ok' => false, 'severity' => 'danger', 'hint' => 'Test.', 'value' => 'down', 'fix' => 'fix it'];
    }
}

class ThrowingRequiredCheck implements HealthCheck
{
    public function run(): array
    {
        throw new \RuntimeException('Payment gateway unreachable');
    }
}

class WarningCheck implements HealthCheck
{
    public function run(): array
    {
        return ['id' => 'always-warning', 'group' => 'Custom', 'label' => 'Always warning', 'ok' => false, 'severity' => 'warning', 'hint' => 'Test.', 'value' => 'meh', 'fix' => null];
    }
}

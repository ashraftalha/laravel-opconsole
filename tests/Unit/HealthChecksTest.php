<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Contracts\HealthCheck;
use AshrafTalha\OpConsole\Support\HealthChecks;
use AshrafTalha\OpConsole\Support\Heartbeat;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class HealthChecksTest extends TestCase
{
    private array $database = ['ok' => true, 'driver' => 'sqlite', 'name' => ':memory:', 'ran' => 0, 'pending' => [], 'error' => null];

    public function test_built_in_checks_are_returned_grouped(): void
    {
        $checks = $this->app->make(HealthChecks::class)->run($this->database, 'localhost');

        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            $this->assertArrayHasKey('group', $check);
            $this->assertArrayHasKey('severity', $check);
        }
    }

    public function test_loopback_hosts_are_treated_as_equivalent(): void
    {
        config(['app.url' => 'http://127.0.0.1']);

        $checks = collect($this->app->make(HealthChecks::class)->run($this->database, 'localhost'));
        $hostCheck = $checks->firstWhere('label', 'Application URL matches current host');

        $this->assertTrue($hostCheck['ok']);
    }

    public function test_custom_check_from_config_is_included(): void
    {
        config(['opconsole.checks' => [PassingCheck::class]]);

        $checks = collect($this->app->make(HealthChecks::class)->run($this->database, null));

        $this->assertTrue($checks->contains('label', 'Custom check'));
    }

    public function test_malformed_custom_check_result_is_rejected_without_breaking_the_page(): void
    {
        config(['opconsole.checks' => [MalformedCheck::class]]);

        $checks = collect($this->app->make(HealthChecks::class)->run($this->database, null));

        $this->assertFalse($checks->contains('label', 'Malformed'));
    }

    /** Laravel's mariadb driver uses PHP's pdo_mysql extension, never pdo_mariadb. */
    public function test_mariadb_driver_checks_pdo_mysql_extension_without_false_pdo_mariadb_failure(): void
    {
        $mariaDatabase = ['ok' => true, 'driver' => 'mariadb', 'name' => 'app_db', 'ran' => 5, 'pending' => [], 'error' => null];
        config(['database.connections.mariadb.driver' => 'mariadb']);

        $checks = collect($this->app->make(HealthChecks::class)->run($mariaDatabase, 'localhost'));
        $extCheck = $checks->firstWhere('id', 'php.extensions');

        $this->assertNotNull($extCheck);
        $this->assertStringNotContainsString('pdo_mariadb', (string) ($extCheck['value'] ?? ''));
    }

    public function test_checks_include_consistent_four_state_status_and_stable_ids(): void
    {
        $checks = $this->app->make(HealthChecks::class)->run($this->database, 'localhost');
        $allowedStatuses = ['healthy', 'warning', 'critical', 'unknown'];
        $ids = [];

        foreach ($checks as $check) {
            $this->assertArrayHasKey('id', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertContains($check['status'], $allowedStatuses);
            $this->assertArrayNotHasKey($check['id'], $ids, "Duplicate check id [{$check['id']}]");
            $ids[$check['id']] = true;
        }
    }

    /** Uninspected external queues or absent failed_jobs tables must report unknown, not healthy. */
    public function test_external_queue_and_missing_failed_jobs_table_report_unknown_rather_than_healthy(): void
    {
        config(['queue.default' => 'redis']);

        $checks = collect($this->app->make(HealthChecks::class)->run($this->database, 'localhost'));
        $stuckCheck = $checks->firstWhere('id', 'queue.stuck_jobs');
        $failedCheck = $checks->firstWhere('id', 'queue.failed_jobs');

        $this->assertNotNull($stuckCheck);
        $this->assertSame('unknown', $stuckCheck['status']);

        $this->assertNotNull($failedCheck);
        $this->assertSame('unknown', $failedCheck['status']);
    }

    public function test_configured_session_and_queue_table_names_are_respected(): void
    {
        Schema::create('custom_sessions_tbl', function (Blueprint $table): void {
            $table->string('id')->primary();
        });
        Schema::create('custom_jobs_tbl', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('created_at')->default(0);
        });

        config([
            'session.driver' => 'database',
            'session.table' => 'custom_sessions_tbl',
            'queue.default' => 'database',
            'queue.connections.database.table' => 'custom_jobs_tbl',
            'queue.failed.driver' => null,
        ]);

        $checks = collect($this->app->make(HealthChecks::class)->run($this->database, 'localhost'));

        $this->assertSame('healthy', $checks->firstWhere('id', 'database.required_tables')['status']);
        $this->assertSame('healthy', $checks->firstWhere('id', 'queue.stuck_jobs')['status']);
    }

    public function test_unsafe_lock_store_is_flagged_as_critical_health_check(): void
    {
        config([
            'cache.stores.null_lock' => ['driver' => 'null'],
            'opconsole.concurrency.lock_store' => 'null_lock',
        ]);

        $checks = collect($this->app->make(HealthChecks::class)->run($this->database, 'localhost'));
        $lockCheck = $checks->firstWhere('id', 'security.lock_store');

        $this->assertNotNull($lockCheck);
        $this->assertFalse($lockCheck['ok']);
        $this->assertSame('critical', $lockCheck['status']);
    }

    public function test_type_invalid_duplicate_and_core_colliding_custom_checks_are_handled_safely(): void
    {
        CountingCustomCheck::$runs = 0;

        config([
            'opconsole.checks' => [
                TypeInvalidCheck::class,
                CollidingCoreCheck::class,
                CountingCustomCheck::class,
                CountingCustomCheck::class, // duplicate entry must only execute once
            ],
        ]);

        $checks = collect($this->app->make(HealthChecks::class)->run($this->database, 'localhost'));

        $this->assertSame(1, CountingCustomCheck::$runs);
        $this->assertSame(1, $checks->where('label', 'Application key configured')->count());
        $this->assertTrue($checks->firstWhere('label', 'Application key configured')['ok']);
        $this->assertFalse($checks->contains('label', 'Type invalid'));
    }

    private function checkById(string $id): ?array
    {
        return collect($this->app->make(HealthChecks::class)->run($this->database, 'localhost'))->firstWhere('id', $id);
    }

    public function test_scheduler_heartbeat_is_unknown_until_the_scheduler_has_run(): void
    {
        $check = $this->checkById('scheduler.heartbeat');

        $this->assertSame('unknown', $check['status']);
        $this->assertSame('Scheduler and workers', $check['group']);
        $this->assertSame('Scheduler has not run yet.', $check['hint']);
        $this->assertStringContainsString('schedule:run', (string) $check['fix']);
    }

    public function test_fresh_scheduler_heartbeat_is_healthy(): void
    {
        app(Heartbeat::class)->beat(Heartbeat::SCHEDULER);

        $check = $this->checkById('scheduler.heartbeat');

        $this->assertSame('healthy', $check['status']);
        $this->assertStringStartsWith('last run', (string) $check['value']);
        $this->assertNull($check['fix']);
    }

    public function test_stale_scheduler_heartbeat_warns_outside_production(): void
    {
        $this->travelTo(now()->subMinutes(5));
        app(Heartbeat::class)->beat(Heartbeat::SCHEDULER);
        $this->travelBack();

        $check = $this->checkById('scheduler.heartbeat');

        $this->assertSame('warning', $check['status']);
        $this->assertStringContainsString('5 minutes ago', (string) $check['value']);
    }

    public function test_stale_scheduler_heartbeat_is_critical_in_production(): void
    {
        $this->travelTo(now()->subMinutes(5));
        app(Heartbeat::class)->beat(Heartbeat::SCHEDULER);
        $this->travelBack();
        $this->app['env'] = 'production';

        $this->assertSame('critical', $this->checkById('scheduler.heartbeat')['status']);
    }

    public function test_scheduler_heartbeat_threshold_is_configurable(): void
    {
        config(['opconsole.heartbeats.scheduler_max_age' => 600]);
        $this->travelTo(now()->subMinutes(5));
        app(Heartbeat::class)->beat(Heartbeat::SCHEDULER);
        $this->travelBack();

        $this->assertSame('healthy', $this->checkById('scheduler.heartbeat')['status']);
    }

    public function test_scheduler_heartbeat_check_is_omitted_when_disabled(): void
    {
        config(['opconsole.heartbeats.scheduler' => false]);

        $this->assertNull($this->checkById('scheduler.heartbeat'));
    }

    public function test_scheduled_callback_writes_the_scheduler_heartbeat(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'opconsole:heartbeat:scheduler');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);

        $event->run($this->app);

        $this->assertNotNull(app(Heartbeat::class)->last(Heartbeat::SCHEDULER)['at']);
    }

    public function test_scheduler_heartbeat_is_not_scheduled_when_disabled(): void
    {
        config(['opconsole.heartbeats.scheduler' => false]);

        $names = collect($this->app->make(Schedule::class)->events())->pluck('description');

        $this->assertNotContains('opconsole:heartbeat:scheduler', $names);
    }
}

class PassingCheck implements HealthCheck
{
    public function run(): array
    {
        return ['group' => 'Custom', 'label' => 'Custom check', 'ok' => true, 'severity' => 'info', 'hint' => 'hint', 'value' => null, 'fix' => null];
    }
}

class MalformedCheck implements HealthCheck
{
    public function run(): array
    {
        return ['label' => 'Malformed']; // missing required keys
    }
}

class TypeInvalidCheck implements HealthCheck
{
    public function run(): array
    {
        /** @phpstan-ignore-next-line intentional invalid runtime types */
        return ['group' => 'Custom', 'label' => 'Type invalid', 'ok' => 'yes', 'severity' => 'info', 'hint' => ['bad'], 'value' => null, 'fix' => null];
    }
}

class CollidingCoreCheck implements HealthCheck
{
    public function run(): array
    {
        return ['group' => 'Security', 'label' => 'Application key configured', 'ok' => false, 'severity' => 'danger', 'hint' => 'spoofed', 'value' => null, 'fix' => null];
    }
}

class CountingCustomCheck implements HealthCheck
{
    public static int $runs = 0;

    public function run(): array
    {
        self::$runs++;

        return ['id' => 'counting-check', 'group' => 'Custom', 'label' => 'Counting check', 'ok' => true, 'status' => 'healthy', 'severity' => 'info', 'hint' => 'ok', 'value' => '1', 'fix' => null];
    }
}

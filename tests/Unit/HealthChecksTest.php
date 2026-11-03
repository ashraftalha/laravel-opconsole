<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Contracts\HealthCheck;
use AshrafTalha\OpConsole\Jobs\OpConsoleHeartbeatJob;
use AshrafTalha\OpConsole\Support\HealthChecks;
use AshrafTalha\OpConsole\Support\Heartbeat;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
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

    private function beatAt(string $name, int $minutesAgo): void
    {
        $this->travelTo(now()->subMinutes($minutesAgo));
        app(Heartbeat::class)->beat($name);
        $this->travelBack();
    }

    private function useWorkerQueue(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis' => ['driver' => 'redis', 'queue' => 'default']]);
    }

    public function test_queue_worker_heartbeat_is_skipped_for_sync_driver(): void
    {
        config(['queue.default' => 'sync']);

        $check = $this->checkById('queue.worker_heartbeat');

        $this->assertSame('healthy', $check['status']);
        $this->assertSame('info', $check['severity']);
        $this->assertStringContainsString('sync', (string) $check['value']);
    }

    public function test_queue_worker_heartbeat_is_unknown_when_scheduler_is_not_running(): void
    {
        $this->useWorkerQueue();

        $this->assertSame('unknown', $this->checkById('queue.worker_heartbeat')['status']);
    }

    public function test_fresh_queue_worker_heartbeat_is_healthy_for_external_brokers(): void
    {
        $this->useWorkerQueue();
        app(Heartbeat::class)->beat(Heartbeat::SCHEDULER);
        $this->beatAt(Heartbeat::QUEUE, 2);

        $check = $this->checkById('queue.worker_heartbeat');

        $this->assertSame('healthy', $check['status']);
        $this->assertStringContainsString('2 minutes ago', (string) $check['value']);
    }

    public function test_queue_worker_heartbeat_waits_for_the_first_job_after_install(): void
    {
        $this->useWorkerQueue();
        app(Heartbeat::class)->beat(Heartbeat::SCHEDULER);
        $this->beatAt(Heartbeat::QUEUE_DISPATCHED, 1);

        $this->assertSame('unknown', $this->checkById('queue.worker_heartbeat')['status']);
    }

    public function test_stale_queue_worker_heartbeat_warns_locally_and_is_critical_in_production(): void
    {
        $this->useWorkerQueue();
        config(['opconsole.heartbeats.queue_name' => 'ops']);
        app(Heartbeat::class)->beat(Heartbeat::SCHEDULER);
        $this->beatAt(Heartbeat::QUEUE_DISPATCHED, 30);
        $this->beatAt(Heartbeat::QUEUE, 10);

        $check = $this->checkById('queue.worker_heartbeat');
        $this->assertSame('warning', $check['status']);
        $this->assertSame('php artisan queue:work --queue=ops', $check['fix']);

        $this->app['env'] = 'production';
        $this->assertSame('critical', $this->checkById('queue.worker_heartbeat')['status']);
    }

    public function test_queue_worker_heartbeat_threshold_is_configurable(): void
    {
        $this->useWorkerQueue();
        config(['opconsole.heartbeats.queue_max_age' => 900]);
        app(Heartbeat::class)->beat(Heartbeat::SCHEDULER);
        $this->beatAt(Heartbeat::QUEUE, 10);

        $this->assertSame('healthy', $this->checkById('queue.worker_heartbeat')['status']);
    }

    public function test_queue_worker_heartbeat_check_is_omitted_when_disabled(): void
    {
        config(['opconsole.heartbeats.queue' => false]);

        $this->assertNull($this->checkById('queue.worker_heartbeat'));
    }

    public function test_scheduler_dispatches_the_heartbeat_job_on_the_configured_queue(): void
    {
        Queue::fake();
        config(['opconsole.heartbeats.queue_connection' => 'redis', 'opconsole.heartbeats.queue_name' => 'ops']);

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'opconsole:heartbeat:queue');
        $this->assertNotNull($event);

        $event->run($this->app);

        Queue::assertPushed(OpConsoleHeartbeatJob::class, fn (OpConsoleHeartbeatJob $job) => $job->connection === 'redis' && $job->queue === 'ops');
        $this->assertNotNull(app(Heartbeat::class)->last(Heartbeat::QUEUE_DISPATCHED)['at']);
    }

    public function test_heartbeat_job_writes_the_queue_heartbeat(): void
    {
        app()->call([new OpConsoleHeartbeatJob, 'handle']);

        $this->assertNotNull(app(Heartbeat::class)->last(Heartbeat::QUEUE)['at']);
    }

    public function test_cache_round_trip_is_skipped_for_array_driver_outside_production(): void
    {
        $check = $this->checkById('cache.round_trip');

        $this->assertSame('healthy', $check['status']);
        $this->assertSame('info', $check['severity']);
        $this->assertStringContainsString('skipped', $check['hint']);
    }

    public function test_cache_round_trip_passes_on_a_working_store(): void
    {
        config(['cache.default' => 'probe', 'cache.stores.probe' => ['driver' => 'file', 'path' => storage_path('framework/cache/opconsole-probe')]]);

        $check = $this->checkById('cache.round_trip');

        $this->assertSame('healthy', $check['status']);
        $this->assertMatchesRegularExpression('/^store=probe · \d+ ms$/', (string) $check['value']);
    }

    public function test_cache_round_trip_is_critical_when_the_value_does_not_match(): void
    {
        Cache::extend('lossy', fn () => Cache::repository(new LossyStore));
        config(['cache.default' => 'probe', 'cache.stores.probe' => ['driver' => 'lossy']]);

        $check = $this->checkById('cache.round_trip');

        $this->assertSame('critical', $check['status']);
        $this->assertNotNull($check['fix']);
    }

    public function test_cache_round_trip_is_critical_when_the_store_throws(): void
    {
        Cache::extend('broken', fn () => throw new \RuntimeException('connection refused'));
        config(['cache.default' => 'probe', 'cache.stores.probe' => ['driver' => 'broken']]);

        $check = $this->checkById('cache.round_trip');

        $this->assertSame('critical', $check['status']);
        $this->assertStringContainsString('exception', $check['hint']);
    }

    public function test_caches_built_is_informational_outside_production(): void
    {
        $check = $this->checkById('performance.caches_built');

        $this->assertSame('healthy', $check['status']);
        $this->assertSame('info', $check['severity']);
    }

    public function test_caches_built_warns_in_production_and_lists_missing_caches(): void
    {
        $this->app['env'] = 'production';
        $this->fakeFrameworkCaches(config: false, routes: true, events: false);

        $check = $this->checkById('performance.caches_built');

        $this->assertSame('warning', $check['status']);
        $this->assertSame('not cached: config, events', $check['value']);
        $this->assertSame('php artisan optimize', $check['fix']);
    }

    public function test_caches_built_is_healthy_in_production_when_everything_is_cached(): void
    {
        $this->app['env'] = 'production';
        $this->fakeFrameworkCaches(config: true, routes: true, events: true);

        $this->assertSame('healthy', $this->checkById('performance.caches_built')['status']);
    }

    /** The framework memoizes *IsCached()/*AreCached() in these bindings, so no cache files are touched. */
    private function fakeFrameworkCaches(bool $config, bool $routes, bool $events): void
    {
        $this->app->instance('config_loaded_from_cache', $config);
        $this->app->instance('routes.cached', $routes);
        $this->app->instance('events.cached', $events);
    }

    public function test_storage_link_check_is_omitted_without_a_public_disk(): void
    {
        config(['filesystems.disks.public' => null]);

        $this->assertNull($this->checkById('files.storage_link'));
    }

    public function test_storage_link_check_warns_when_the_link_is_missing(): void
    {
        $this->useTemporaryPublicAndStoragePaths();

        $check = $this->checkById('files.storage_link');

        $this->assertSame('warning', $check['status']);
        $this->assertSame('php artisan storage:link', $check['fix']);
    }

    public function test_storage_link_check_is_healthy_when_the_link_points_to_the_public_disk(): void
    {
        [$public, $storage] = $this->useTemporaryPublicAndStoragePaths();
        symlink($storage.'/app/public', $public.'/storage');

        $this->assertSame('healthy', $this->checkById('files.storage_link')['status']);
    }

    public function test_storage_link_check_warns_when_the_link_points_elsewhere(): void
    {
        [$public, $storage] = $this->useTemporaryPublicAndStoragePaths();
        symlink($storage, $public.'/storage');

        $this->assertSame('warning', $this->checkById('files.storage_link')['status']);
    }

    /** @return array{string, string} */
    private function useTemporaryPublicAndStoragePaths(): array
    {
        $root = sys_get_temp_dir().'/opconsole-link-'.uniqid();
        mkdir($root.'/public', 0755, true);
        mkdir($root.'/storage/app/public', 0755, true);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

        $this->app->usePublicPath($root.'/public');
        $this->app->useStoragePath($root.'/storage');
        config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $root.'/storage/app/public'], 'filesystems.links' => []]);

        return [$root.'/public', $root.'/storage'];
    }

    private function diskCheck(float $free, float $total): array
    {
        $checks = new class($free, $total) extends HealthChecks
        {
            public function __construct(private float $free, private float $total)
            {
                parent::__construct();
            }

            protected function diskSpace(): array
            {
                return ['free' => $this->free, 'total' => $this->total];
            }
        };

        return collect($checks->run($this->database, null))->firstWhere('id', 'files.disk_space');
    }

    public function test_disk_space_warns_below_the_free_percentage_even_with_plenty_of_bytes(): void
    {
        $check = $this->diskCheck(50 * 1024 ** 3, 1000 * 1024 ** 3);

        $this->assertSame('warning', $check['status']);
        $this->assertStringContainsString('(5%)', (string) $check['value']);
        $this->assertNotNull($check['fix']);
    }

    public function test_disk_space_is_healthy_above_both_limits(): void
    {
        $check = $this->diskCheck(50 * 1024 ** 3, 100 * 1024 ** 3);

        $this->assertSame('healthy', $check['status']);
        $this->assertStringContainsString('(50%)', (string) $check['value']);
    }

    public function test_disk_space_percentage_threshold_is_configurable(): void
    {
        config(['opconsole.disk.min_free_percent' => 3]);

        $this->assertSame('healthy', $this->diskCheck(50 * 1024 ** 3, 1000 * 1024 ** 3)['status']);
    }

    public function test_disk_space_is_still_critical_below_256_mb(): void
    {
        $this->assertSame('critical', $this->diskCheck(100 * 1024 ** 2, 1000 * 1024 ** 3)['status']);
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

/** Accepts writes but never returns what was stored. */
class LossyStore extends ArrayStore
{
    public function get($key)
    {
        return 'something-else';
    }
}

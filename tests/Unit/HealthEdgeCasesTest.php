<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Contracts\HealthCheck;
use AshrafTalha\OpConsole\Support\HealthChecks;
use AshrafTalha\OpConsole\Support\Runtime;
use AshrafTalha\OpConsole\Support\SecurityScanner;
use AshrafTalha\OpConsole\Tests\Support\FunctionOverrides as Functions;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class HealthEdgeCasesTest extends TestCase
{
    private function checks(string $driver = 'testing', bool $ok = true): array
    {
        return array_column((new HealthChecks)->run(['driver' => $driver, 'ok' => $ok], null), null, 'id');
    }

    public function test_unavailable_disk_and_database_are_reported(): void
    {
        Functions::$callbacks['disk_free_space'] = fn () => false;
        $checks = $this->checks('sqlsrv', false);
        $this->assertSame('unknown', $checks['files.disk_space']['status']);
        $this->assertSame('critical', $checks['database.connection']['status']);
        $this->assertStringContainsString('pdo_sqlsrv', $checks['php.extensions']['value']);
        $this->assertArrayHasKey('php.extensions', $this->checks('unknown-driver'));
        $this->assertArrayHasKey('php.extensions', $this->checks('pgsql'));
    }

    public function test_broken_heartbeat_store_and_storage_link_are_unknown(): void
    {
        config(['opconsole.heartbeats.store' => 'missing', 'queue.default' => 'redis']);
        Functions::$callbacks['is_link'] = fn () => throw new RuntimeException('unavailable');
        $checks = $this->checks();
        foreach (['scheduler.heartbeat', 'queue.worker_heartbeat', 'files.storage_link'] as $id) {
            $this->assertSame('unknown', $checks[$id]['status']);
        }
    }

    public function test_database_requirement_probes_fail_closed(): void
    {
        config(['session.driver' => 'database', 'session.connection' => 'missing', 'cache.default' => 'database', 'opconsole.concurrency.lock_store' => 'database']);
        $this->assertSame('unknown', $this->checks()['database.required_tables']['status']);
    }

    public function test_failed_backlog_queries_report_unknown(): void
    {
        config(['queue.default' => 'database', 'queue.failed.driver' => 'database']);
        DB::shouldReceive('connection->getSchemaBuilder->hasTable')->andReturn(true);
        DB::shouldReceive('connection->table')->andThrow(new RuntimeException('password=private-value'));
        $checks = $this->checks();
        foreach (['queue.stuck_jobs', 'queue.failed_jobs'] as $id) {
            $this->assertSame('unknown', $checks[$id]['status']);
            $this->assertSame('password=[REDACTED]', $checks[$id]['value']);
        }
    }

    public function test_null_queue_in_production_warns(): void
    {
        $this->app['env'] = 'production';
        config(['queue.default' => 'null']);
        $this->assertSame('warning', $this->checks()['queue.stuck_jobs']['status']);
    }

    #[DataProvider('invalidCheckShapes')]
    public function test_custom_validation_failures_are_unknown(array $overrides): void
    {
        $check = $this->createMock(HealthCheck::class);
        $check->method('run')->willReturn(array_replace(['id' => 'probe', 'group' => 'Custom', 'label' => 'Probe', 'ok' => true, 'severity' => 'info', 'hint' => 'Probe result', 'value' => null, 'fix' => null], $overrides));
        $this->app->instance('custom-probe', $check);
        config(['opconsole.checks' => ['custom-probe']]);
        $checks = $this->checks();
        $this->assertSame('unknown', $checks['custom.custom-probe']['status']);
    }

    public static function invalidCheckShapes(): array
    {
        return [[['group' => '']], [['ok' => 1]], [['severity' => 'bad']], [['value' => []]], [['status' => 'bad']], [['id' => '']]];
    }

    public function test_invalid_and_duplicate_custom_registrations(): void
    {
        config(['opconsole.checks' => 'invalid']);
        $this->assertNotEmpty($this->checks());
        config(['opconsole.checks' => ['', new \stdClass, \stdClass::class, \stdClass::class]]);
        $this->assertSame('unknown', $this->checks()['custom.stdclass']['status']);
    }

    public function test_colliding_failure_ids_remain_distinct(): void
    {
        $valid = $this->createMock(HealthCheck::class);
        $valid->method('run')->willReturn(['id' => 'stdclass', 'group' => 'Custom', 'label' => 'Probe', 'ok' => true, 'severity' => 'info', 'hint' => 'Probe result', 'value' => null, 'fix' => null]);
        $this->app->instance('custom-probe', $valid);
        config(['opconsole.checks' => ['custom-probe', \stdClass::class]]);
        $checks = $this->checks();
        $this->assertSame('healthy', $checks['custom.stdclass']['status']);
        $this->assertSame('unknown', $checks['custom.stdclass.'.substr(hash('sha256', \stdClass::class), 0, 8)]['status']);
    }

    #[DataProvider('memorySizes')]
    public function test_memory_units_are_interpreted(string $value, bool $healthy): void
    {
        Functions::$callbacks['ini_get'] = fn ($key) => $key === 'memory_limit' ? $value : ini_get($key);
        $this->assertSame($healthy, $this->checks()['php.memory_limit']['ok']);
    }

    public static function memorySizes(): array
    {
        return [['', true], ['1G', true], ['128M', true], ['128K', false], ['134217728', true]];
    }

    public function test_stack_and_direct_log_levels_are_resolved(): void
    {
        config(['logging.default' => 'stack', 'logging.channels.stack' => ['driver' => 'stack', 'channels' => ['daily']], 'logging.channels.daily.level' => 'WARNING']);
        $this->assertSame('LOG_LEVEL=warning', $this->checks()['security.log_level']['value']);
        config(['logging.default' => 'daily']);
        $this->assertSame('LOG_LEVEL=warning', $this->checks()['security.log_level']['value']);
    }

    public function test_runtime_uses_swoole_cpu_probe_when_available(): void
    {
        Functions::$callbacks['function_exists'] = fn ($name) => $name === 'swoole_cpu_num' || function_exists($name);
        Functions::$callbacks['swoole_cpu_num'] = fn () => 8;
        $this->assertSame(8, (new Runtime)->cpuCount());
    }

    public function test_fresh_clean_audit_is_healthy_and_missing_log_channel_uses_daily_level(): void
    {
        $scanner = $this->createMock(SecurityScanner::class);
        $scanner->method('report')->willReturn(['status' => 'complete', 'stale' => false, 'advisories' => []]);
        $this->app->instance(SecurityScanner::class, $scanner);
        config(['logging.default' => 'missing', 'logging.channels.daily.level' => 'NOTICE']);
        $checks = $this->checks();
        $this->assertSame('healthy', $checks['security.dependency_audit']['status']);
        $this->assertSame('LOG_LEVEL=notice', $checks['security.log_level']['value']);
    }
}

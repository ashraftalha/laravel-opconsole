<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use AshrafTalha\OpConsole\Support\ConsoleStatus;
use AshrafTalha\OpConsole\Support\HealthChecks;
use AshrafTalha\OpConsole\Support\LogViewer;
use AshrafTalha\OpConsole\Support\PagePresenter;
use AshrafTalha\OpConsole\Support\Runtime;
use AshrafTalha\OpConsole\Tests\Support\FakeRuntime;
use AshrafTalha\OpConsole\Tests\Support\FunctionOverrides as Functions;
use AshrafTalha\OpConsole\Tests\TestCase;
use Composer\InstalledVersions;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;

class PresentationEdgeCasesTest extends TestCase
{
    private function invoke(object $object, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    public function test_published_assets_are_preferred_and_missing_timestamp_is_omitted(): void
    {
        $dir = $this->useTemporaryLogDirectory();
        mkdir($dir.'/vendor/opconsole', 0755, true);
        foreach (['css', 'js', 'svg'] as $ext) {
            file_put_contents($dir.'/vendor/opconsole/opconsole.'.$ext, 'fixture');
        }
        $this->app->usePublicPath($dir);
        $presenter = new PagePresenter;
        $assets = $this->invoke($presenter, 'assets');
        $this->assertSame(asset('vendor/opconsole/opconsole.css'), $assets['css']);
        $this->assertSame(asset('vendor/opconsole/opconsole.js'), $assets['js']);
        $this->assertSame(asset('vendor/opconsole/opconsole.svg'), $assets['icon']);
        $this->assertNull($this->invoke($presenter, 'lastChecked', null));
        $this->assertSame(e("\xff<unsafe>"), $this->invoke($presenter, 'highlight', "\xff<unsafe>", 'unsafe'));
    }

    public function test_service_tiles_include_critical_states_and_omit_disabled_checks(): void
    {
        $status = ['database' => ['ok' => false, 'pending' => [], 'driver' => 'sqlite', 'ran' => 0], 'checks' => [['id' => 'cache.round_trip', 'status' => 'critical', 'severity' => 'danger', 'value' => 'offline', 'hint' => 'offline']], 'errors' => ['count' => 0, 'last' => null]];
        $tiles = $this->invoke(new PagePresenter, 'tiles', $status, [], false);
        $cache = collect($tiles)->firstWhere('label', 'Cache');
        $this->assertSame('Critical', $cache['value']);
        $this->assertSame('is-bad', $cache['tone']);
        $this->assertNull(collect($tiles)->firstWhere('label', 'Scheduler'));
    }

    public function test_started_unknown_and_long_running_history_are_presented(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        foreach (['started', 'unknown', 'unexpected', 'failed'] as $status) {
            OpConsoleRun::create(['action' => 'custom', 'status' => $status, 'started_at' => now(), 'duration_ms' => 1234, 'exit_code' => 1, 'subject' => 'job-1', 'error' => $status === 'failed' ? 'offline' : null]);
        }
        $history = $this->invoke(app(ConsoleStatus::class), 'audit')['history'];
        $this->assertSame(['failed', 'unknown', 'unknown', 'started'], array_column($history['items'], 'status'));
        $presented = $this->invoke(new PagePresenter, 'history', $history);
        $json = json_encode($presented);
        $this->assertStringContainsString('1.23 s', $json);
        $this->assertStringContainsString('exit 1', $json);
        $this->assertStringContainsString('Incomplete:', $json);
        $this->assertStringContainsString('Started', $json);
        $this->assertStringContainsString('offline', $json);
        $this->assertStringContainsString('job-1', $json);
    }

    public function test_database_failure_and_invalid_unknown_policy_remain_honest(): void
    {
        config(['database.default' => 'missing', 'opconsole.health.unknown_policy' => 'invalid']);
        $status = app(ConsoleStatus::class)->status();
        $this->assertFalse($status['database']['ok']);
        $this->assertSame('Cannot connect to the database. Check DB_* settings in .env.', $status['advice'][0]['text']);
    }

    public function test_database_filename_hides_absolute_path(): void
    {
        DB::shouldReceive('connection->getPdo')->andReturn(new \stdClass);
        DB::shouldReceive('connection->getDatabaseName')->andReturn('/private/app/database.sqlite');
        $migrator = \Mockery::mock(Migrator::class);
        $migrator->shouldReceive('paths')->andReturn([]);
        $migrator->shouldReceive('getMigrationFiles')->andReturn([]);
        $migrator->shouldReceive('repositoryExists')->andReturn(false);
        $status = new ConsoleStatus($migrator, app(LogViewer::class), app(HealthChecks::class));
        $this->assertSame('database.sqlite', $this->invoke($status, 'database')['name']);
    }

    public function test_system_information_handles_opcache_jit_swoole_and_missing_server_version(): void
    {
        $runtime = new FakeRuntime;
        $this->app->instance(Runtime::class, $runtime);
        config(['octane.server' => 'swoole']);
        Functions::$callbacks['opcache_get_status'] = fn () => ['opcache_enabled' => true, 'memory_usage' => ['used_memory' => 1024], 'jit' => ['on' => true]];
        Functions::$callbacks['ini_get'] = fn ($key) => $key === 'opcache.jit' ? 'tracing' : ini_get($key);
        DB::shouldReceive('connection')->andThrow(new RuntimeException('offline'));
        $caches = ['config' => false, 'routes' => false, 'events' => false, 'views' => 0, 'viewsSize' => 0, 'store' => 'array'];
        $info = $this->invoke(app(ConsoleStatus::class), 'info', ['ok' => true, 'driver' => 'testing', 'name' => 'db', 'ran' => 0, 'pending' => []], $caches);
        $json = json_encode($info);
        $this->assertStringContainsString('Enabled', $json);
        $this->assertStringContainsString('On', $json);
        $this->assertStringContainsString('Unavailable', $json);
        $this->assertSame('swoole 6.0.2', $info['Octane']['Swoole extension']);
        Functions::$callbacks['function_exists'] = fn ($name) => $name === 'opcache_get_status' ? false : function_exists($name);
        $info = $this->invoke(app(ConsoleStatus::class), 'info', ['ok' => false, 'driver' => 'testing', 'name' => null, 'ran' => 0, 'pending' => []], $caches);
        $this->assertStringContainsString('Not installed', json_encode($info));
    }

    public function test_development_cached_configuration_prompts_clear(): void
    {
        $advice = $this->invoke(app(ConsoleStatus::class), 'advice', ['ok' => true, 'pending' => []], ['config' => true, 'routes' => false], ['count' => 0], []);
        $this->assertSame('clear', $advice[0]['action']);
    }

    public function test_warning_service_tiles_and_denied_octane_reload(): void
    {
        $this->app->instance(Runtime::class, new FakeRuntime);
        config(['octane.server' => 'swoole']);
        $status = app(ConsoleStatus::class)->status();
        $status['checks'] = [['id' => 'cache.round_trip', 'status' => 'warning', 'severity' => 'warning', 'value' => 'slow', 'hint' => 'slow']];
        $tiles = $this->invoke(new PagePresenter, 'tiles', $status, [], false);
        $cache = collect($tiles)->firstWhere('label', 'Cache');
        $this->assertSame('Warning', $cache['value']);
        $this->assertSame('is-warning', $cache['tone']);
        $actions = $this->invoke(new PagePresenter, 'actions', $status, true, ['octane_reload' => false]);
        $this->assertSame('Your account cannot reload Octane workers.', $actions['octane_reload']['disabledReason']);
    }

    public function test_missing_compiled_view_directory_and_composer_metadata_are_supported(): void
    {
        config(['view.compiled' => null]);
        $status = app(ConsoleStatus::class);
        $caches = $this->invoke($status, 'caches');
        $this->assertSame(0, $caches['views']);
        Functions::$callbacks['class_exists'] = fn ($class, $autoload = true) => $class === InstalledVersions::class ? false : class_exists($class, $autoload);
        $info = $this->invoke($status, 'info', ['ok' => false, 'driver' => 'testing', 'name' => null, 'ran' => 0, 'pending' => []], $caches);
        $this->assertSame('—', $info['Application']['Composer packages']);
    }

    public function test_installed_octane_version_is_displayed(): void
    {
        $original = InstalledVersions::getRawData();
        $data = $original;
        $data['versions']['laravel/octane'] = ['pretty_version' => 'v2.0.0', 'version' => '2.0.0.0'];
        InstalledVersions::reload($data);
        $this->app->instance(Runtime::class, new FakeRuntime);
        try {
            $info = $this->invoke(app(ConsoleStatus::class), 'octaneInfo');
            $this->assertSame('v2.0.0', $info['Octane']['Version']);
        } finally {
            InstalledVersions::reload($original);
        }
    }
}

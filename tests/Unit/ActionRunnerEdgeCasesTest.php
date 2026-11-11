<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use AshrafTalha\OpConsole\Support\ActionRunner;
use AshrafTalha\OpConsole\Support\FailedJobs;
use AshrafTalha\OpConsole\Tests\Support\FunctionOverrides as Functions;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Lock;
use Illuminate\Cache\NoLock;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;

class ActionRunnerEdgeCasesTest extends TestCase
{
    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(ActionRunner::class, $method))->invoke(new ActionRunner, ...$args);
    }

    #[DataProvider('invalidActions')]
    public function test_invalid_action_arguments_are_rejected(string $action, ?string $migration): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ActionRunner)->run($action, null, $migration);
    }

    public static function invalidActions(): array
    {
        return [['invalid', null], ['clear', 'migration']];
    }

    public function test_broken_migrator_and_failed_job_provider_are_safe(): void
    {
        $this->app->bind('migrator', fn () => throw new RuntimeException('offline'));
        $this->assertNull((new ActionRunner)->pendingMigrationPath('migration'));
        $this->app->bind('queue.failer', fn () => throw new RuntimeException('offline'));
        $this->assertFalse((new FailedJobs)->list()['available']);
        config(['opconsole.connection' => 'missing']);
        $this->assertFalse(OpConsoleRun::hasDurationColumn());
    }

    public function test_unconfigured_and_unresolvable_lock_stores_are_refused(): void
    {
        config(['opconsole.concurrency.lock_store' => null]);
        $this->assertFalse((new ActionRunner)->validateLockStore()['ok']);
        config(['opconsole.concurrency.lock_store' => 'broken', 'cache.stores.broken' => ['driver' => 'missing']]);
        $this->assertStringContainsString('could not be resolved', (new ActionRunner)->validateLockStore()['reason']);
    }

    public function test_missing_signal_function_blocks_execution(): void
    {
        Functions::$callbacks['function_exists'] = fn ($name) => $name === 'pcntl_alarm' ? false : function_exists($name);
        $this->assertNotNull((new ActionRunner)->unsupportedRuntimeReason('clear'));
    }

    public function test_artisan_output_failure_and_missing_actor_name_are_safe(): void
    {
        Artisan::swap(Mockery::mock(Kernel::class));
        Artisan::shouldReceive('output')->andThrow(new RuntimeException('offline'));
        $this->assertSame('', $this->invoke('safeArtisanOutput'));
        $user = $this->makeUser();
        unset($user->name, $user->email);
        $this->assertSame('1', $this->invoke('label', $user));
    }

    public function test_redis_lock_storage_is_compared_by_database(): void
    {
        $store = $this->createMock(RedisStore::class);
        config(['database.redis.default' => null]);
        $this->assertFalse($this->invoke('hasSeparateLockStorage', $store, []));
        config(['database.redis.default' => ['url' => 'redis://localhost:6379/0'], 'database.redis.locks' => ['url' => 'redis://localhost:6379/1']]);
        $this->assertTrue($this->invoke('hasSeparateLockStorage', $store, ['lock_connection' => 'locks']));
        $this->assertFalse($this->invoke('hasSeparateLockStorage', $store, []));
        $this->assertFalse($this->invoke('hasSeparateLockStorage', new ArrayStore, []));
    }

    public function test_legacy_database_and_file_lock_isolation(): void
    {
        Functions::$callbacks['method_exists'] = fn ($object, $method) => $method === 'hasSeparateLockStore' ? false : method_exists($object, $method);
        $database = $this->createMock(DatabaseStore::class);
        $this->assertTrue($this->invoke('hasSeparateLockStorage', $database, ['table' => 'cache', 'lock_table' => 'locks']));
        $this->assertFalse($this->invoke('hasSeparateLockStorage', $database, ['table' => 'cache', 'lock_table' => 'cache']));
        $files = $this->createMock(FileStore::class);
        $files->method('getDirectory')->willReturn('/tmp/cache');
        $this->assertTrue($this->invoke('hasSeparateLockStorage', $files, ['lock_path' => '/tmp/locks']));
        $this->assertFalse($this->invoke('hasSeparateLockStorage', $files, ['lock_path' => '/tmp/cache/locks']));
        $this->assertFalse($this->invoke('hasSeparateLockStorage', $this->createMock(Store::class), []));
    }

    public function test_native_lock_isolation_contract_is_honored(): void
    {
        $store = new class extends ArrayStore
        {
            public function hasSeparateLockStore(): bool
            {
                return true;
            }
        };
        // Array stores are deliberately rejected before consulting any native isolation API.
        $this->assertFalse($this->invoke('hasSeparateLockStorage', $store, []));
        $store = $this->createMock(DatabaseStore::class);
        if (method_exists($store, 'hasSeparateLockStore')) {
            $store->method('hasSeparateLockStore')->willReturn(true);
            $this->assertTrue($this->invoke('hasSeparateLockStorage', $store, []));
        }
    }

    public function test_normalizing_missing_paths_handles_existing_ancestors_and_roots(): void
    {
        $this->assertSame('', $this->invoke('normalizePath', ''));
        $dir = $this->useTemporaryLogDirectory();
        $this->assertSame(realpath($dir).'/missing/locks', $this->invoke('normalizePath', $dir.'/missing/locks'));
        Functions::$callbacks['realpath'] = fn () => false;
        $this->assertSame('missing/locks', $this->invoke('normalizePath', 'missing/locks'));
    }

    public function test_transient_renewal_and_release_errors_are_logged_without_failing_action(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $lock = new class('test', 60) extends Lock
        {
            public function acquire(): bool
            {
                return true;
            }

            public function release(): bool
            {
                throw new RuntimeException('release failure');
            }

            public function forceRelease(): void {}

            protected function getCurrentOwner(): string
            {
                return $this->owner;
            }

            public function refresh($seconds = null): bool
            {
                throw new RuntimeException('renewal failure');
            }
        };
        $store = Mockery::mock(ArrayStore::class)->makePartial();
        $store->shouldReceive('lock')->andReturn($lock);
        Cache::shouldReceive('store')->with('opconsole_lock')->andReturn(new Repository($store));
        Artisan::swap(Mockery::mock(Kernel::class));
        Artisan::shouldReceive('call')->with('migrate:rollback', Mockery::type('array'), Mockery::any())->andReturn(0);
        Artisan::shouldReceive('call')->with('optimize:clear', [])->once()->andReturnUsing(function () {
            $handler = pcntl_signal_get_handler(SIGALRM);
            $handler();

            return 0;
        });
        Artisan::shouldReceive('output')->andReturn('Done');
        Log::spy();
        $previous = ini_get('max_execution_time');
        ini_set('max_execution_time', '1');
        try {
            $this->assertTrue((new ActionRunner)->run('clear', null)['ok']);
        } finally {
            ini_set('max_execution_time', $previous);
        }
        Log::shouldHaveReceived('warning')->with('OpConsole: action lock renewal failed; continuing on the existing lease', Mockery::type('array'))->once();
        Log::shouldHaveReceived('warning')->with('OpConsole: failed to release action lock cleanly', Mockery::type('array'))->once();
    }

    public function test_broken_default_cache_does_not_invalidate_isolated_lock_store(): void
    {
        foreach ([RedisStore::class, FileStore::class] as $class) {
            $store = $this->createMock($class);
            $store->method('lock')->willReturn(new ArrayLock(new ArrayStore, 'probe', 1));
            Cache::shouldReceive('store')->with('opconsole_lock')->andReturn(new Repository($store));
            Cache::shouldReceive('store')->with('broken')->andThrow(new RuntimeException('offline'));
            config(['cache.default' => 'broken']);
            $this->assertTrue((new ActionRunner)->validateLockStore()['ok']);
            Cache::clearResolvedInstances();
            $this->app->forgetInstance('cache');
        }
    }

    public function test_noop_locks_are_rejected_during_validation(): void
    {
        $store = Mockery::mock(ArrayStore::class)->makePartial();
        $store->shouldReceive('lock')->andReturn(new NoLock('test', 1));
        Cache::shouldReceive('store')->andReturn(new Repository($store));
        $this->assertStringContainsString('no-op', (new ActionRunner)->validateLockStore()['reason']);
    }

    public function test_lock_provider_changing_after_validation_is_rejected(): void
    {
        $valid = new Repository(new ArrayStore);
        $invalid = new Repository($this->createMock(Store::class));
        Cache::shouldReceive('store')->with('opconsole_lock')->andReturn($valid, $invalid);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support atomic locks');
        $this->invoke('lock', 'clear');
    }

    public function test_noop_lock_returned_after_validation_is_rejected(): void
    {
        $store = Mockery::mock(ArrayStore::class)->makePartial();
        $store->shouldReceive('lock')->andReturn(new ArrayLock(new ArrayStore, 'probe', 1), new NoLock('test', 1));
        Cache::shouldReceive('store')->andReturn(new Repository($store));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no-op lock');
        $this->invoke('lock', 'clear');
    }

    public function test_shutdown_release_failure_is_contained_and_hook_is_idempotent(): void
    {
        $runner = new ActionRunner;
        $lock = Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $lock->shouldReceive('release')->once()->andThrow(new RuntimeException('offline'));
        (new \ReflectionProperty($runner, 'inFlight'))->setValue($runner, ['run' => null, 'lock' => $lock]);
        $runner->abandonInFlight();
        $runner->abandonInFlight();
        $this->assertNull((new \ReflectionProperty($runner, 'inFlight'))->getValue($runner));
    }

    public function test_unresolvable_platform_root_stops_parent_traversal(): void
    {
        Functions::$callbacks['realpath'] = fn () => false;
        Functions::$callbacks['dirname'] = fn ($path) => $path;
        $this->assertSame('C:', $this->invoke('normalizePath', 'C:'));
    }
}

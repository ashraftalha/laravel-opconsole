<?php

namespace AshrafTalha\OpConsole\Tests;

use AshrafTalha\OpConsole\OpConsoleServiceProvider;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    /** Config overrides re-applied across refreshApplication() calls, which otherwise rebuild the app from scratch. */
    public static array $configOverrides = [];

    protected function tearDown(): void
    {
        static::$configOverrides = [];
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [OpConsoleServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        foreach (static::$configOverrides as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn () => 'login')->name('login');
    }

    protected function allowOpConsole(): void
    {
        Gate::define('viewOpConsole', fn () => true);
        Gate::define('runOpConsoleActions', fn () => true);
    }

    protected function actingAsUser(): Authenticatable
    {
        $user = new class extends Authenticatable
        {
            protected $guarded = [];
        };
        $user->id = 1;
        $user->name = 'Test Admin';
        $user->email = 'admin@example.com';
        $user->exists = true;

        $this->actingAs($user);

        return $user;
    }
}

<?php

namespace AshrafTalha\OpConsole\Tests;

use AshrafTalha\OpConsole\OpConsoleServiceProvider;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    /** Config overrides re-applied across refreshApplication() calls, which otherwise rebuild the app from scratch. */
    public static array $configOverrides = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }
        $this->temporaryDirectories = [];
        static::$configOverrides = [];

        if ($this->app !== null) {
            $this->app['env'] = 'testing';
        }

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
        $app['config']->set('opconsole.security.store', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Distinct in-memory store for the action lock: proves real store isolation from
        // cache.default without persisting filesystem lock files across separate test runs.
        $app['config']->set('cache.stores.opconsole_lock', ['driver' => 'array']);
        $app['config']->set('opconsole.concurrency.lock_store', 'opconsole_lock');

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

    protected function makeUser(int $id = 1, string $name = 'Test Admin', string $email = 'admin@example.com'): Authenticatable
    {
        $user = new class extends Authenticatable
        {
            protected $guarded = [];
        };
        $user->id = $id;
        $user->name = $name;
        $user->email = $email;
        $user->exists = true;

        return $user;
    }

    protected function actingAsUser(int $id = 1, string $name = 'Test Admin', string $email = 'admin@example.com'): Authenticatable
    {
        $user = $this->makeUser($id, $name, $email);
        $this->actingAs($user);

        return $user;
    }

    /** Disables CSRF verification across both Laravel 12 (ValidateCsrfToken) and Laravel 13 (PreventRequestForgery). */
    protected function withoutCsrfMiddleware(): void
    {
        $this->withoutMiddleware(array_values(array_filter([
            class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : null,
            class_exists(ValidateCsrfToken::class) ? ValidateCsrfToken::class : null,
        ])));
    }

    protected function useTemporaryLogDirectory(): string
    {
        $directory = sys_get_temp_dir().'/opconsole-logs-'.uniqid();
        File::makeDirectory($directory, 0755, true);
        $this->temporaryDirectories[] = $directory;
        $this->app['config']->set('opconsole.logs.path', $directory);

        return $directory;
    }
}

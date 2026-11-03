<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class AuthorizationTest extends TestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/opconsole')->assertRedirect('/login');
    }

    public function test_default_gate_denies_outside_local_environment(): void
    {
        $this->app['env'] = 'production';
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertForbidden();
    }

    public function test_default_gate_allows_in_local_environment(): void
    {
        $this->app['env'] = 'local';
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk();
    }

    public function test_host_defined_gate_is_respected_over_the_default(): void
    {
        Gate::define('viewOpConsole', fn () => false);
        $this->app['env'] = 'local';
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertForbidden();
    }

    public function test_view_gate_does_not_grant_run_ability(): void
    {
        Gate::define('viewOpConsole', fn () => true);
        Gate::define('runOpConsoleActions', fn () => false);
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk();
        $this->post('/admin/opconsole/clear')->assertForbidden();
    }

    public function test_action_specific_gate_overrides_run_gate_and_undefined_gate_fails_closed(): void
    {
        Gate::define('viewOpConsole', fn () => true);
        Gate::define('runOpConsoleActions', fn () => false);
        Gate::define('clearCaches', fn () => true);
        $this->actingAsUser();
        config(['opconsole.authorization.action_gates' => ['clear' => 'clearCaches']]);
        $this->post('/admin/opconsole/clear')->assertRedirect();
        $this->post('/admin/opconsole/optimize')->assertForbidden();

        config(['opconsole.authorization.action_gates.clear' => 'missingGate']);
        $this->post('/admin/opconsole/clear')->assertForbidden();
    }

    public function test_authenticated_user_without_any_gate_defined_is_denied_outside_local(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertForbidden();
    }

    /** Production must deny by default even when the request is otherwise well-formed. */
    public function test_production_environment_denies_access_without_explicit_gates(): void
    {
        // Setting $this->app['env'] disables Testbench's test-only CSRF bypass; isolate authorization here.
        $this->withoutCsrfMiddleware();
        $this->app['env'] = 'production';
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertForbidden();
        $this->post('/admin/opconsole/clear')->assertForbidden();
    }

    /** A misconfigured (empty) gate name must fail closed, not fail open. */
    public function test_empty_gate_name_denies_rather_than_bypassing_authorization(): void
    {
        $this->app['config']->set('opconsole.authorization.view_gate', null);
        $this->app['env'] = 'local';
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertForbidden();
    }

    /**
     * Regression: a guest hitting the console on a host with no named "login" route
     * must get a controlled 401, never an uncaught RouteNotFoundException (HTTP 500).
     * Laravel's own 'auth' middleware calls route('login') unconditionally and would
     * crash here; OpConsole's middleware checks the guard itself instead.
     */
    public function test_guest_gets_401_instead_of_500_when_no_login_route_is_configured(): void
    {
        $this->app['config']->set('opconsole.authentication.login_route', 'a-route-name-that-does-not-exist');

        $response = $this->get('/admin/opconsole');

        $response->assertStatus(401);
    }

    public function test_guest_gets_401_when_login_route_is_explicitly_disabled(): void
    {
        $this->app['config']->set('opconsole.authentication.login_route', null);

        $this->get('/admin/opconsole')->assertStatus(401);
    }

    /** Destructive routes must get the same safe 401, not just the dashboard GET route. */
    public function test_guest_gets_401_on_action_routes_when_no_login_route_is_configured(): void
    {
        $this->app['config']->set('opconsole.authentication.login_route', null);

        $this->post('/admin/opconsole/clear')->assertStatus(401);
        $this->delete('/admin/opconsole/logs/laravel.log')->assertStatus(401);
    }

    /** Guests expecting JSON receive a 401 response even when a named login route exists. */
    public function test_guest_expecting_json_receives_401_even_when_login_route_exists(): void
    {
        $this->getJson('/admin/opconsole')->assertStatus(401);
    }

    /** Custom authentication.guard must drive both Gate evaluation and audit attribution. */
    public function test_custom_authentication_guard_evaluates_gate_and_audits_the_configured_guard_user(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
        $this->app['config']->set('opconsole.authentication.guard', 'admin');

        Gate::define('viewOpConsole', fn (Authenticatable $user) => $user->email === 'ops-admin@example.com');
        Gate::define('runOpConsoleActions', fn (Authenticatable $user) => $user->email === 'ops-admin@example.com');

        $webUser = $this->makeUser(10, 'Regular Web User', 'regular@example.com');
        $adminUser = $this->makeUser(99, 'Ops Admin', 'ops-admin@example.com');

        // Signed in only on default 'web' guard: must NOT satisfy the 'admin' guard check.
        $this->actingAs($webUser, 'web');
        $this->get('/admin/opconsole')->assertRedirect('/login');

        // Signed in on both guards simultaneously: Gate and audit row must resolve the 'admin' user.
        $this->actingAs($adminUser, 'admin');
        $this->get('/admin/opconsole')->assertOk();

        $this->post('/admin/opconsole/clear')->assertRedirect();
        $this->assertDatabaseHas('opconsole_runs', [
            'action' => 'clear',
            'user_id' => '99',
            'user_label' => 'Ops Admin',
        ]);
    }

    public function test_invalid_authentication_guard_configuration_fails_closed(): void
    {
        $this->allowOpConsole();
        $this->actingAsUser();

        $this->app['config']->set('opconsole.authentication.guard', 'undefined_guard');
        $this->get('/admin/opconsole')->assertForbidden();

        $this->app['config']->set('opconsole.authentication.guard', '   ');
        $this->get('/admin/opconsole')->assertForbidden();

        $this->app['config']->set('opconsole.authentication.guard', ['bad']);
        $this->get('/admin/opconsole')->assertForbidden();
    }

    /** Parameterized login routes must return 401 rather than throwing UrlGenerationException (500). */
    public function test_parameterized_login_route_returns_401_instead_of_500(): void
    {
        Route::get('/{locale}/signin', fn () => 'signin')->name('localized.login');
        $this->app['config']->set('opconsole.authentication.login_route', 'localized.login');

        $this->get('/admin/opconsole')->assertStatus(401);
    }

    /** Custom undefined gate names or non-string gate values must deny access even in local. */
    public function test_undefined_custom_gate_and_non_string_gate_config_fail_closed(): void
    {
        $this->app['env'] = 'local';
        $this->actingAsUser();

        $this->app['config']->set('opconsole.authorization.view_gate', 'customUndefinedOpConsoleGate');
        $this->get('/admin/opconsole')->assertForbidden();

        $this->app['config']->set('opconsole.authorization.view_gate', ['invalid']);
        $this->get('/admin/opconsole')->assertForbidden();
    }

    /** Viewing, running actions, downloading logs, and deleting logs enforce distinct Gates. */
    public function test_distinct_permissions_for_viewing_running_downloading_and_deleting(): void
    {
        $logDir = $this->useTemporaryLogDirectory();

        File::put($logDir.'/old.log', "[2026-01-01 00:00:00] testing.INFO: old\n");
        touch($logDir.'/old.log', time() - 3600);
        File::put($logDir.'/laravel.log', "[2026-01-01 00:00:00] testing.INFO: current\n");

        $allowDownload = false;
        $allowDelete = false;

        Gate::define('viewOpConsole', fn () => true);
        Gate::define('runOpConsoleActions', fn () => false);
        Gate::define('downloadOpConsoleLogs', function () use (&$allowDownload): bool {
            return $allowDownload;
        });
        Gate::define('deleteOpConsoleLogs', function () use (&$allowDelete): bool {
            return $allowDelete;
        });

        $this->actingAsUser();

        // View-only user: dashboard loads, but download, delete, and actions are all 403.
        $this->get('/admin/opconsole')
            ->assertOk()
            ->assertDontSee('/admin/opconsole/logs/laravel.log', false);
        $this->get('/admin/opconsole/logs/laravel.log')->assertForbidden();
        $this->delete('/admin/opconsole/logs/old.log')->assertForbidden();
        $this->post('/admin/opconsole/clear')->assertForbidden();

        // Grant download only: download succeeds while delete and actions stay 403.
        $allowDownload = true;
        $this->get('/admin/opconsole/logs/laravel.log')->assertOk();
        $this->delete('/admin/opconsole/logs/old.log')->assertForbidden();

        // Grant delete only: delete succeeds while download and actions stay 403.
        $allowDownload = false;
        $allowDelete = true;
        $this->get('/admin/opconsole/logs/laravel.log')->assertForbidden();
        $this->delete('/admin/opconsole/logs/old.log')->assertRedirect();
        $this->assertFileDoesNotExist($logDir.'/old.log');
    }

    public function test_legacy_two_gate_configuration_without_download_or_delete_keys_remains_compatible(): void
    {
        $logDir = $this->useTemporaryLogDirectory();

        File::put($logDir.'/old.log', 'old');
        touch($logDir.'/old.log', time() - 3600);
        File::put($logDir.'/laravel.log', 'current');

        // Simulate a published v0.1.0 config file missing download_gate and delete_gate keys.
        $this->app['config']->set('opconsole.authorization', [
            'view_gate' => 'viewOpConsole',
            'run_gate' => 'runOpConsoleActions',
        ]);

        Gate::define('viewOpConsole', fn () => true);
        Gate::define('runOpConsoleActions', fn () => false);
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk();
        $this->get('/admin/opconsole/logs/laravel.log')->assertOk();
        $this->delete('/admin/opconsole/logs/old.log')->assertForbidden();
    }

    /** Documented default: an undefined deleteOpConsoleLogs follows whichever Gate run_gate names. */
    public function test_default_log_delete_gate_follows_a_custom_run_gate_and_never_the_view_gate(): void
    {
        $logDir = $this->useTemporaryLogDirectory();
        File::put($logDir.'/old.log', 'old');
        touch($logDir.'/old.log', time() - 3600);
        File::put($logDir.'/laravel.log', 'current');

        $canRun = false;
        config(['opconsole.authorization.run_gate' => 'opsRun']);
        Gate::define('viewOpConsole', fn () => true);
        Gate::define('opsRun', function () use (&$canRun): bool {
            return $canRun;
        });
        $this->actingAsUser();

        $this->delete('/admin/opconsole/logs/old.log')->assertForbidden();
        $this->assertFileExists($logDir.'/old.log');

        $canRun = true;
        $this->delete('/admin/opconsole/logs/old.log')->assertRedirect();
        $this->assertFileDoesNotExist($logDir.'/old.log');
    }

    /** Overriding route.middleware cannot strip mandatory 'web' (session + CSRF) protection. */
    public function test_overriding_route_middleware_preserves_web_session_and_csrf_protection(): void
    {
        static::$configOverrides = ['opconsole.route.middleware' => []];
        $this->refreshApplication();
        $this->allowOpConsole();
        $this->app['env'] = 'production';
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk();
        $this->post('/admin/opconsole/clear')->assertStatus(419);
    }
}

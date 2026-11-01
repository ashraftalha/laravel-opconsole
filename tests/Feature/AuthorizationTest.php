<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Gate;

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

    public function test_authenticated_user_without_any_gate_defined_is_denied_outside_local(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertForbidden();
    }

    /** Regression: production must deny by default even when the request is otherwise well-formed. */
    public function test_production_environment_denies_access_without_explicit_gates(): void
    {
        // Setting $this->app['env'] also disables Testbench's test-only CSRF bypass
        // (it keys off app()->runningUnitTests(), which checks 'env' === 'testing').
        // Disable CSRF explicitly so this test isolates authorization, not CSRF.
        // The middleware class was renamed between supported Laravel versions
        // (ValidateCsrfToken on 12.x, PreventRequestForgery on 13.x); disable both.
        $this->withoutMiddleware(array_filter([
            class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : null,
            class_exists(ValidateCsrfToken::class) ? ValidateCsrfToken::class : null,
        ]));
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

    /** When a login route genuinely exists, the original redirect behaviour is unchanged. */
    public function test_guest_is_still_redirected_when_a_login_route_exists(): void
    {
        $this->get('/admin/opconsole')->assertRedirect('/login');
    }
}

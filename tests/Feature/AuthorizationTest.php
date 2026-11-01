<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Tests\TestCase;
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
}

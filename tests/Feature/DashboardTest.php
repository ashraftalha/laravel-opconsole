<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Tests\TestCase;

class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_page_renders_with_no_log_files(): void
    {
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk()->assertSee('Health checks');
    }

    public function test_three_tiles_are_shown(): void
    {
        $this->actingAsUser();

        $this->get('/admin/opconsole')
            ->assertSee('Database')
            ->assertSee('Health checks')
            ->assertSee('Errors in the last 24 hours');
    }

    public function test_back_link_is_hidden_when_not_configured(): void
    {
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertDontSee('href="/admin/dashboard"', false);
    }

    public function test_back_link_appears_when_configured(): void
    {
        $this->app['config']->set('opconsole.back_url', '/admin/dashboard');
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertSee('/admin/dashboard', false);
    }

    public function test_disabled_actions_are_not_rendered(): void
    {
        $this->app['config']->set('opconsole.actions.migrate', false);
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertDontSee('action-migrate', false);
    }
}

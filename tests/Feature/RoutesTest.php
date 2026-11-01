<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\OpConsoleServiceProvider;
use AshrafTalha\OpConsole\Tests\TestCase;

class RoutesTest extends TestCase
{
    public function test_service_provider_is_discovered_via_package_manifest(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);

        $this->assertSame(
            [OpConsoleServiceProvider::class],
            $composer['extra']['laravel']['providers']
        );
    }

    public function test_default_routes_are_registered(): void
    {
        $this->allowOpConsole();

        $names = collect($this->app['router']->getRoutes())->map(fn ($route) => $route->getName())->filter()->values();

        $this->assertTrue($names->contains('opconsole.index'));
        $this->assertTrue($names->contains('opconsole.log'));
        $this->assertTrue($names->contains('opconsole.log.delete'));
        $this->assertTrue($names->contains('opconsole.run'));
    }

    public function test_routes_use_the_configured_prefix(): void
    {
        $this->allowOpConsole();
        $this->actingAsUser();

        $response = $this->get('/admin/opconsole');

        $response->assertOk();
    }

    public function test_disabling_the_package_registers_no_routes(): void
    {
        static::$configOverrides = ['opconsole.enabled' => false];
        $this->refreshApplication();

        $names = collect($this->app['router']->getRoutes())->map(fn ($route) => $route->getName())->filter()->values();

        $this->assertFalse($names->contains('opconsole.index'));
    }

    public function test_unknown_artisan_actions_are_not_routed(): void
    {
        $this->allowOpConsole();
        $this->actingAsUser();

        $response = $this->post('/admin/opconsole/fresh');

        $response->assertNotFound();
    }
}

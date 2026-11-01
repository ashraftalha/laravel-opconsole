<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_config_file_has_no_closures(): void
    {
        $config = require __DIR__.'/../../config/opconsole.php';

        $this->assertNoClosures($config);
    }

    private function assertNoClosures(array $config): void
    {
        foreach ($config as $value) {
            $this->assertNotInstanceOf(\Closure::class, $value);
            if (is_array($value)) {
                $this->assertNoClosures($value);
            }
        }
    }

    public function test_route_prefix_is_configurable(): void
    {
        static::$configOverrides = ['opconsole.route.prefix' => 'ops/console'];
        $this->refreshApplication();
        $this->allowOpConsole();
        $this->actingAsUser();

        $this->get('/ops/console')->assertOk();
    }

    public function test_route_name_prefix_is_configurable(): void
    {
        static::$configOverrides = ['opconsole.route.name_prefix' => 'ops.'];
        $this->refreshApplication();

        $names = collect($this->app['router']->getRoutes())->map(fn ($route) => $route->getName())->filter()->values();

        $this->assertTrue($names->contains('ops.index'));
    }
}

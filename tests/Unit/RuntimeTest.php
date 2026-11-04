<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Support\Runtime;
use AshrafTalha\OpConsole\Tests\Support\InspectorState;
use AshrafTalha\OpConsole\Tests\TestCase;

/**
 * Tests the real Runtime class (no fake). Octane's classes are provided by tests/Stubs/octane.php,
 * so detection and the process-inspector integration run exactly as they would with Octane installed.
 */
class RuntimeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    /** @var array<string, mixed> */
    private array $envBackup;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Stubs/octane.php';
        InspectorState::reset();
        $this->serverBackup = $_SERVER;
        $this->envBackup = $_ENV;
        unset($_SERVER['LARAVEL_OCTANE'], $_ENV['LARAVEL_OCTANE']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_ENV = $this->envBackup;
        InspectorState::reset();
        parent::tearDown();
    }

    private function runtime(): Runtime
    {
        return new Runtime;
    }

    public function test_octane_counts_as_installed_only_with_its_class_and_its_config(): void
    {
        $this->assertFalse($this->runtime()->octaneInstalled(), 'class present but no config/octane.php');

        $this->app['config']->set('octane', ['server' => 'swoole']);
        $this->assertTrue($this->runtime()->octaneInstalled());

        $this->app['config']->set('octane', 'swoole');
        $this->assertFalse($this->runtime()->octaneInstalled(), 'a non-array octane config is not an Octane install');
    }

    public function test_serving_via_octane_reads_the_flag_octane_sets_on_each_request(): void
    {
        $this->assertFalse($this->runtime()->servingViaOctane());

        $_SERVER['LARAVEL_OCTANE'] = 1;
        $this->assertTrue($this->runtime()->servingViaOctane());

        unset($_SERVER['LARAVEL_OCTANE']);
        $_ENV['LARAVEL_OCTANE'] = '1';
        $this->assertTrue($this->runtime()->servingViaOctane());

        $_ENV['LARAVEL_OCTANE'] = '';
        $this->assertFalse($this->runtime()->servingViaOctane(), 'an empty flag is not set');
    }

    public function test_server_name_is_normalised_and_empty_values_mean_none(): void
    {
        $this->assertNull($this->runtime()->octaneServer());

        foreach (['Swoole' => 'swoole', 'ROADRUNNER' => 'roadrunner', 'frankenphp' => 'frankenphp'] as $configured => $expected) {
            $this->app['config']->set('octane.server', $configured);
            $this->assertSame($expected, $this->runtime()->octaneServer());
        }

        foreach (['', null, 42, ['swoole']] as $invalid) {
            $this->app['config']->set('octane.server', $invalid);
            $this->assertNull($this->runtime()->octaneServer(), var_export($invalid, true));
        }
    }

    public function test_inside_a_swoole_worker_needs_both_an_octane_request_and_the_swoole_server(): void
    {
        $this->app['config']->set('octane.server', 'swoole');
        $this->assertFalse($this->runtime()->insideSwooleWorker(), 'swoole configured, but this request is not served by Octane');

        $_SERVER['LARAVEL_OCTANE'] = 1;
        $this->assertTrue($this->runtime()->insideSwooleWorker());

        foreach (['roadrunner', 'frankenphp'] as $server) {
            $this->app['config']->set('octane.server', $server);
            $this->assertFalse($this->runtime()->insideSwooleWorker(), $server);
        }
    }

    public function test_server_running_uses_the_inspector_for_the_configured_server(): void
    {
        foreach (['swoole', 'roadrunner', 'frankenphp'] as $server) {
            InspectorState::reset();
            $this->app['config']->set('octane.server', $server);

            InspectorState::$running = true;
            $this->assertTrue($this->runtime()->octaneServerRunning(), $server.' running');

            InspectorState::$running = false;
            $this->assertFalse($this->runtime()->octaneServerRunning(), $server.' stopped');

            $this->assertSame([$server, $server], InspectorState::$called, 'only the configured server\'s inspector is asked');
        }
    }

    public function test_server_running_is_unknown_when_it_cannot_be_determined(): void
    {
        $this->assertNull($this->runtime()->octaneServerRunning(), 'no server configured');

        $this->app['config']->set('octane.server', 'nginx-unit');
        $this->assertNull($this->runtime()->octaneServerRunning(), 'unsupported server');

        $this->app['config']->set('octane.server', 'swoole');
        InspectorState::$running = new \RuntimeException('state file unreadable');
        $this->assertNull($this->runtime()->octaneServerRunning(), 'an inspector failure is unknown, not stopped');
    }

    public function test_php_probes_reflect_the_running_php(): void
    {
        $runtime = $this->runtime();

        $this->assertTrue($runtime->extensionLoaded('json'));
        $this->assertFalse($runtime->extensionLoaded('definitely_not_an_extension'));
        $this->assertSame(phpversion('json'), $runtime->extensionVersion('json'));
        $this->assertNull($runtime->extensionVersion('definitely_not_an_extension'));

        $this->assertSame((string) ini_get('memory_limit'), $runtime->ini('memory_limit'));
        $this->assertSame('', $runtime->ini('definitely.not.an.ini.key'));

        $this->assertTrue($runtime->fileExists(__FILE__));
        $this->assertFalse($runtime->fileExists(__DIR__), 'a directory is not a file (binaries must be files)');
        $this->assertTrue($runtime->isWritable(sys_get_temp_dir()));

        $cpus = $runtime->cpuCount();
        $this->assertTrue($cpus === null || $cpus >= 1);
    }
}

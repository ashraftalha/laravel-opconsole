<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Support\HealthChecks;
use AshrafTalha\OpConsole\Support\OctaneChecks;
use AshrafTalha\OpConsole\Tests\Support\FakeRuntime;
use AshrafTalha\OpConsole\Tests\TestCase;

/**
 * Edge cases for every Octane and Swoole check. Results go through HealthChecks' real
 * normaliser, so the status a severity maps to is exactly what the dashboard shows.
 */
class OctaneChecksTest extends TestCase
{
    private FakeRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = new FakeRuntime;
        $this->app['config']->set('octane', ['server' => 'swoole', 'https' => false, 'garbage' => 50, 'swoole' => ['options' => []]]);
    }

    /** @return array<string, array{id: string, group: string, status: string, severity: string, value: ?string, fix: ?string, ok: bool}> */
    private function results(bool $production = false): array
    {
        $normalize = new \ReflectionMethod(HealthChecks::class, 'normalizeCheck');
        $healthChecks = app(HealthChecks::class);
        $check = fn (string $id, string $group, string $label, bool $ok, string $severity, string $hint, ?string $value = null, ?string $fix = null, ?string $status = null) => $normalize->invoke($healthChecks, compact('id', 'group', 'label', 'ok', 'severity', 'hint', 'value', 'fix', 'status'));

        $results = (new OctaneChecks($this->runtime))->run($production, $check);

        return collect($results)->keyBy('id')->all();
    }

    /** A check that failed with severity info: shown as an advisory, never counted as a problem. */
    private function assertAdvisory(array $check): void
    {
        $this->assertSame('warning', $check['status']);
        $this->assertSame('info', $check['severity']);
    }

    public function test_the_checks_toggle_accepts_false_in_any_form(): void
    {
        foreach ([false, 'false'] as $off) {
            $this->app['config']->set('opconsole.octane.checks', $off);
            $this->assertSame([], $this->results(), var_export($off, true));
        }

        $this->app['config']->set('opconsole.octane.checks', 'auto');
        $this->assertNotSame([], $this->results());

        $this->runtime->installed = false;
        $this->assertSame([], $this->results(), 'auto without Octane installed');
    }

    public function test_every_check_is_in_the_octane_group_with_a_unique_id(): void
    {
        $checks = (new OctaneChecks($this->runtime));
        $results = $this->results();

        $this->assertCount(10, $results, '6 Octane checks + 4 Swoole checks');
        foreach ($results as $id => $result) {
            $this->assertSame(OctaneChecks::GROUP, $result['group'], $id);
            $this->assertMatchesRegularExpression('/^(octane|swoole)\.[a-z_]+$/', $id);
        }
        $this->assertTrue($checks->enabled());
    }

    public function test_server_dependency_for_each_server(): void
    {
        $this->app['config']->set('octane.server', null);
        $missing = $this->results()['octane.server'];
        $this->assertSame('warning', $missing['status']);
        $this->assertSame('.env: OCTANE_SERVER=swoole', $missing['fix']);

        $this->app['config']->set('octane.server', 'swoole');
        $this->runtime->extensions = ['openswoole' => '22.1.2'];
        $openSwoole = $this->results()['octane.server'];
        $this->assertSame('healthy', $openSwoole['status']);
        $this->assertStringContainsString('openswoole 22.1.2', (string) $openSwoole['value']);

        $this->app['config']->set('octane.server', 'frankenphp');
        $this->assertSame('warning', $this->results()['octane.server']['status']);
        $this->assertSame('php artisan octane:install --server=frankenphp', $this->results()['octane.server']['fix']);
        $this->runtime->files = [base_path('frankenphp')];
        $this->assertSame('healthy', $this->results()['octane.server']['status']);

        $this->app['config']->set('octane.server', 'nginx-unit');
        $this->assertSame('warning', $this->results()['octane.server']['status'], 'an unsupported server is never reported as installed');
    }

    public function test_a_missing_server_is_a_warning_locally_and_critical_in_production(): void
    {
        $this->runtime->extensions = [];

        $this->assertSame('warning', $this->results(production: false)['octane.server']['status']);
        $this->assertSame('critical', $this->results(production: true)['octane.server']['status']);
    }

    public function test_server_running_states(): void
    {
        $this->runtime->running = true;
        $this->assertSame('healthy', $this->results()['octane.running']['status']);

        $this->runtime->running = null;
        $this->assertSame('unknown', $this->results(production: true)['octane.running']['status'], 'undeterminable is unknown, never stopped');

        $this->runtime->running = false;
        $this->assertAdvisory($this->results(production: true)['octane.running']);
        $this->assertSame('php artisan octane:start --server=swoole', $this->results()['octane.running']['fix']);

        $this->app['config']->set('opconsole.octane.expected_in_production', true);
        $this->assertSame('critical', $this->results(production: true)['octane.running']['status']);
        $this->assertAdvisory($this->results(production: false)['octane.running']);

        $this->runtime->serving = true;
        $this->runtime->running = false;
        $this->assertSame('healthy', $this->results(production: true)['octane.running']['status'], 'serving this request proves it runs');
    }

    public function test_serving_is_an_advisory_unless_octane_is_expected_in_production(): void
    {
        $this->assertAdvisory($this->results(production: true)['octane.serving']);

        $this->app['config']->set('opconsole.octane.expected_in_production', true);
        $this->assertAdvisory($this->results(production: false)['octane.serving']);
        $serving = $this->results(production: true)['octane.serving'];
        $this->assertSame('warning', $serving['status']);
        $this->assertSame('warning', $serving['severity']);
    }

    public function test_garbage_threshold_boundaries(): void
    {
        $cases = [
            ['-1', 50, 'healthy'],   // no memory limit
            ['128M', 127, 'healthy'],
            ['128M', 128, 'warning'], // equal is already too late
            ['1G', 512, 'healthy'],
            ['512K', 1, 'warning'],
        ];

        foreach ($cases as [$memoryLimit, $garbage, $expected]) {
            $this->runtime->ini['memory_limit'] = $memoryLimit;
            $this->app['config']->set('octane.garbage', $garbage);
            $this->assertSame($expected, $this->results()['octane.garbage']['status'], "memory_limit=$memoryLimit garbage=$garbage");
        }
    }

    public function test_https_only_matters_for_an_https_app_url(): void
    {
        $cases = [
            ['http://shop.test', false, 'healthy'],
            ['http://shop.test', true, 'healthy'],
            ['https://shop.test', true, 'healthy'],
            ['HTTPS://shop.test', false, 'warning'],
        ];

        foreach ($cases as [$url, $octaneHttps, $expected]) {
            $this->app['config']->set('app.url', $url);
            $this->app['config']->set('octane.https', $octaneHttps);
            $this->assertSame($expected, $this->results()['octane.https']['status'], "$url / ".var_export($octaneHttps, true));
        }
    }

    public function test_cli_opcache_needs_both_the_ini_flag_and_the_extension(): void
    {
        $this->assertSame('unknown', $this->results(production: true)['octane.opcache_cli']['status'], 'unreadable unless Octane serves the request');

        $this->runtime->serving = true;
        $this->runtime->extensions['Zend OPcache'] = '8.5.0';
        $this->assertSame('healthy', $this->results(production: true)['octane.opcache_cli']['status']);

        unset($this->runtime->extensions['Zend OPcache']);
        $this->assertSame('warning', $this->results(production: true)['octane.opcache_cli']['status'], 'flag on but extension missing');

        $this->runtime->extensions['Zend OPcache'] = '8.5.0';
        $this->runtime->ini['opcache.enable_cli'] = '0';
        $this->assertSame('warning', $this->results(production: true)['octane.opcache_cli']['status']);
        $this->assertSame('healthy', $this->results(production: false)['octane.opcache_cli']['status'], 'only required in production');
    }

    public function test_swoole_package_max_length_against_php_upload_limits(): void
    {
        $cases = [
            [[], '8M', '2M', 'healthy'],                                  // 10 MB default covers 8 MB
            [[], '64M', '2M', 'warning'],                                 // default too small for 64 MB posts
            [[], '2M', '64M', 'warning'],                                 // the larger of the two limits counts
            [['package_max_length' => 128 * 1024 * 1024], '64M', '64M', 'healthy'],
            [[], '0', '0', 'healthy'],                                    // 0 means unlimited in PHP, nothing to compare
        ];

        foreach ($cases as [$options, $post, $upload, $expected]) {
            $this->app['config']->set('octane.swoole.options', $options);
            $this->runtime->ini['post_max_size'] = $post;
            $this->runtime->ini['upload_max_filesize'] = $upload;
            $result = $this->results()['swoole.package_max_length'];
            $this->assertSame($expected, $result['status'], "post=$post upload=$upload ".json_encode($options));
            if ($expected === 'warning') {
                $this->assertStringContainsString('package_max_length', (string) $result['fix']);
            }
        }
    }

    public function test_swoole_worker_count_against_cpus(): void
    {
        $cases = [
            [null, 4, 'healthy'],  // auto
            [16, 4, 'healthy'],    // exactly 4 per core
            [17, 4, 'warning'],
            [64, null, 'healthy'], // CPU count unknown: no judgement
        ];

        foreach ($cases as [$workers, $cpus, $expected]) {
            $this->app['config']->set('octane.swoole.options', $workers === null ? [] : ['worker_num' => $workers]);
            $this->runtime->cpus = $cpus;
            $result = $this->results()['swoole.worker_num'];
            $this->assertSame($expected, $result['status'], "workers=$workers cpus=$cpus");
            $this->assertSame('info', $result['severity'], 'worker count is advice, never a failure');
        }
    }

    public function test_swoole_xdebug_and_log_file(): void
    {
        $this->assertSame('healthy', $this->results()['swoole.xdebug']['status']);

        $this->runtime->extensions['xdebug'] = '3.4.0';
        $this->assertAdvisory($this->results(production: false)['swoole.xdebug']);
        $this->assertSame('warning', $this->results(production: true)['swoole.xdebug']['severity']);

        $this->assertSame('healthy', $this->results()['swoole.log_file']['status'], 'no log file configured');

        $this->app['config']->set('octane.swoole.options', ['log_file' => '/var/log/swoole/octane.log']);
        $this->runtime->writable = false;
        $log = $this->results()['swoole.log_file'];
        $this->assertSame('warning', $log['status']);
        $this->assertSame('/var/log/swoole/octane.log', $log['value']);
    }

    public function test_swoole_checks_only_run_for_the_swoole_server(): void
    {
        foreach (['roadrunner', 'frankenphp', null] as $server) {
            $this->app['config']->set('octane.server', $server);
            $ids = array_keys($this->results());
            $this->assertSame([], array_values(array_filter($ids, fn ($id) => str_starts_with($id, 'swoole.'))), var_export($server, true));
        }
    }
}

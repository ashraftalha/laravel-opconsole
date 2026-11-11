<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use AshrafTalha\OpConsole\Support\SecurityScanner;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;

class SecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::store(config('opconsole.security.store'))->forget('opconsole.security.report');
        parent::tearDown();
    }

    public function test_security_report_is_stored_and_advisory_content_is_escaped(): void
    {
        $this->allowOpConsole();
        $report = [
            'status' => 'complete', 'scanned_at' => now()->toIso8601String(), 'last_success_at' => now()->toIso8601String(),
            'manifest_hash' => hash('sha256', (string) @file_get_contents(base_path('composer.json'))."\0".(string) @file_get_contents(base_path('composer.lock'))),
            'scope' => 'fixture production lock', 'advisories' => [[
                'package' => 'fixture/package', 'version' => '1.0.0', 'version_mismatch' => false, 'severity' => null,
                'title' => '<script>alert(1)</script> hostile', 'identifier' => null, 'affected' => null, 'published' => null,
                'link' => 'javascript:alert(1)', 'remediation' => null,
            ]], 'ignored' => [], 'abandoned' => [], 'config_warnings' => [],
        ];
        Cache::store(config('opconsole.security.store'))->forever('opconsole.security.report', $report);
        $this->actingAsUser();
        $response = $this->get('/admin/opconsole');
        $response->assertOk()->assertSee('Not supplied')->assertSee('hostile');
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('href="javascript:', false);
    }

    public function test_missing_key_and_cookie_configuration_are_reported_without_secrets(): void
    {
        config(['app.key' => null, 'session.http_only' => false, 'session.same_site' => null]);
        $warnings = app(SecurityScanner::class)->configurationWarnings();
        $ids = array_column($warnings, 'id');
        $this->assertContains('app_key', $ids);
        $this->assertContains('session_httponly', $ids);
        $this->assertContains('session_samesite', $ids);
    }

    public function test_security_report_remains_behind_view_gate(): void
    {
        Gate::define('viewOpConsole', fn () => false);
        $this->actingAsUser();
        $this->get('/admin/opconsole')->assertForbidden();
    }

    /**
     * opconsole:security exits non-zero when it finds advisories (correct for CI), but clicking
     * "Scan now" must not report that as a failed action: the scan itself completed successfully
     * and the findings are already surfaced on the Security tab.
     */
    public function test_security_scan_action_succeeds_even_when_advisories_are_found(): void
    {
        $this->assertSecurityScanActionSucceeds();
    }

    /** PHP-FPM, Herd and `artisan serve` boot the package outside the console. */
    public function test_security_scan_action_runs_from_a_web_request(): void
    {
        $_ENV['APP_RUNNING_IN_CONSOLE'] = $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

        try {
            // Artisan bootstrappers are static: drop those the console-booted app from setUp() registered.
            ArtisanApplication::forgetBootstrappers();
            $this->refreshApplication();
            $this->assertFalse($this->app->runningInConsole());
            $this->withoutCsrfMiddleware(); // Laravel only skips CSRF for tests running in the console.

            $this->assertSecurityScanActionSucceeds();
        } finally {
            unset($_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_RUNNING_IN_CONSOLE']);
        }
    }

    private function assertSecurityScanActionSucceeds(): void
    {
        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $directory = sys_get_temp_dir().'/opconsole-security-action-'.uniqid('', true);
        File::makeDirectory($directory.'/cache', 0755, true);
        file_put_contents($directory.'/composer.json', '{}');
        file_put_contents($directory.'/composer.lock', json_encode(['packages' => [['name' => 'fixture/vulnerable', 'version' => 'v1.2.0']]]));
        file_put_contents($directory.'/audit.json', json_encode([
            'advisories' => ['fixture/vulnerable' => [[
                'advisoryId' => 'PKSA-999', 'title' => 'Fixture vulnerability', 'severity' => 'high',
            ]]],
        ]));
        file_put_contents($directory.'/composer', '#!/bin/sh'."\n".'if [ "$1" = "--version" ]; then echo "Composer version 2.10.0"; else cat "$PWD/audit.json"; exit 1; fi'."\n");
        chmod($directory.'/composer', 0755);
        $this->app->setBasePath($directory);
        config([
            'opconsole.actions.security_scan' => true,
            'opconsole.security.composer' => $directory.'/composer',
            'opconsole.security.store' => 'security_action_fixture',
            'cache.stores.security_action_fixture' => ['driver' => 'file', 'path' => $directory.'/cache'],
        ]);
        $this->actingAsUser();

        try {
            $response = $this->post('/admin/opconsole/security_scan');

            $response->assertRedirect()->assertSessionHas('opconsole_success');
            $this->assertSame('succeeded', OpConsoleRun::query()->latest('id')->first()?->status);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Web SAPIs hide PATH and HOME from subprocesses ($_SERVER holds request data; PHP-FPM clears the
     * environment). Like the real one, this composer needs `php` on PATH and a home directory.
     */
    #[DataProvider('webEnvironments')]
    public function test_scan_gives_composer_a_path_and_home_in_web_requests(bool $withHome, string $expectedHome): void
    {
        $directory = sys_get_temp_dir().'/opconsole-security-env-'.uniqid('', true);
        File::makeDirectory($directory.'/cache', 0755, true);
        file_put_contents($directory.'/composer.json', '{}');
        file_put_contents($directory.'/composer.lock', json_encode(['packages' => []]));
        file_put_contents($directory.'/composer', '#!/usr/bin/env php'."\n".'<?php'."\n"
            .'$home = getenv("COMPOSER_HOME") ?: getenv("HOME");'."\n"
            .'if (! $home) { fwrite(STDERR, "The HOME or COMPOSER_HOME environment variable must be set"); exit(1); }'."\n"
            .'file_put_contents(getcwd()."/home.txt", (getenv("COMPOSER_HOME") ? "COMPOSER_HOME" : "HOME"));'."\n"
            .'echo ($argv[1] ?? "") === "--version" ? "Composer version 2.10.0\n" : json_encode(["advisories" => []]);'."\n");
        chmod($directory.'/composer', 0755);
        $this->app->setBasePath($directory);
        config(['opconsole.security.composer' => $directory.'/composer', 'opconsole.security.store' => 'security_env_fixture', 'cache.stores.security_env_fixture' => ['driver' => 'file', 'path' => $directory.'/cache']]);

        $server = $_SERVER;
        $home = getenv('HOME');
        $composerHome = getenv('COMPOSER_HOME');
        unset($_SERVER['PATH'], $_SERVER['HOME'], $_SERVER['COMPOSER_HOME']);
        putenv('COMPOSER_HOME');
        if (! $withHome) {
            putenv('HOME');
        }

        try {
            $report = app(SecurityScanner::class)->scan();

            $this->assertSame('complete', $report['status'], (string) ($report['last_failure']['message'] ?? ''));
            $this->assertSame($expectedHome, file_get_contents($directory.'/home.txt'));
        } finally {
            $_SERVER = $server;
            putenv($home === false ? 'HOME' : 'HOME='.$home);
            putenv($composerHome === false ? 'COMPOSER_HOME' : 'COMPOSER_HOME='.$composerHome);
            File::deleteDirectory($directory);
        }
    }

    public static function webEnvironments(): array
    {
        return [
            'server has HOME, request does not' => [true, 'HOME'],
            'no HOME at all (PHP-FPM clear_env)' => [false, 'COMPOSER_HOME'],
        ];
    }

    public function test_composer_json_fixture_keeps_active_ignored_and_abandoned_records_separate(): void
    {
        $directory = sys_get_temp_dir().'/opconsole-security-'.uniqid('', true);
        File::makeDirectory($directory.'/cache', 0755, true);
        file_put_contents($directory.'/composer.json', '{}');
        file_put_contents($directory.'/composer.lock', json_encode(['packages' => [['name' => 'fixture/vulnerable', 'version' => 'v1.2.0']]]));
        file_put_contents($directory.'/audit.json', json_encode([
            'advisories' => ['fixture/vulnerable' => [[
                'advisoryId' => 'PKSA-123', 'cve' => 'CVE-2026-1234', 'title' => 'Fixture vulnerability',
                'link' => 'https://packagist.org/security-advisories/PKSA-123', 'affectedVersions' => '<1.3.0',
                'reportedAt' => '2026-01-01T00:00:00+00:00', 'severity' => null,
            ]]],
            'ignored-advisories' => ['fixture/vulnerable' => [['advisoryId' => 'PKSA-IGNORE', 'ignoreReason' => 'Reviewed exception.']]],
            'abandoned' => ['fixture/old' => 'fixture/new'],
        ]));
        file_put_contents($directory.'/composer', '#!/bin/sh'."\n".'if [ "$1" = "--version" ]; then echo "Composer version 2.10.0"; else cat "$PWD/audit.json"; fi'."\n");
        chmod($directory.'/composer', 0755);
        $this->app->setBasePath($directory);
        config(['opconsole.security.composer' => $directory.'/composer', 'opconsole.security.store' => 'security_fixture', 'cache.stores.security_fixture' => ['driver' => 'file', 'path' => $directory.'/cache']]);

        try {
            $report = app(SecurityScanner::class)->scan();
            $this->assertSame('complete', $report['status']);
            $this->assertSame(['CVE-2026-1234', 'PKSA-123'], $report['advisories'][0]['identifiers']);
            $this->assertNull($report['advisories'][0]['severity']);
            $this->assertSame('<1.3.0', $report['advisories'][0]['affected']);
            $this->assertCount(1, $report['ignored']);
            $this->assertSame('Reviewed exception.', $report['ignored'][0]['ignored_reason']);
            $this->assertSame('fixture/old', $report['abandoned'][0]['package']);
            file_put_contents($directory.'/composer.lock', '{"packages":[]}');
            $this->assertTrue(app(SecurityScanner::class)->report()['manifest_changed']);
            file_put_contents($directory.'/audit.json', 'not valid json');
            $failed = app(SecurityScanner::class)->scan();
            $this->assertSame('failed', $failed['status']);
            $this->assertNotEmpty($failed['last_success_at']);
            $this->assertCount(1, $failed['advisories']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_version_ranges_keep_their_upper_bound_and_stay_escaped_on_the_dashboard(): void
    {
        $this->allowOpConsole();
        $range = '>=12.0.0,<12.9.2';
        Cache::store(config('opconsole.security.store'))->forever('opconsole.security.report', [
            'status' => 'complete', 'scanned_at' => now()->toIso8601String(), 'last_success_at' => now()->toIso8601String(),
            'manifest_hash' => hash('sha256', (string) @file_get_contents(base_path('composer.json'))."\0".(string) @file_get_contents(base_path('composer.lock'))),
            'scope' => 'fixture', 'advisories' => [[
                'package' => 'laravel/framework', 'version' => '12.9.0', 'version_mismatch' => false, 'severity' => 'high',
                'title' => 'Range <b>fixture</b>', 'identifier' => null, 'affected' => $range, 'published' => null,
                'link' => null, 'remediation' => "Update\nnow",
            ]], 'ignored' => [], 'abandoned' => [], 'config_warnings' => [],
        ]);

        $report = app(SecurityScanner::class)->report();
        $this->assertSame($range, $report['advisories'][0]['affected']);
        $this->assertSame('Update now', $report['advisories'][0]['remediation']);

        $this->actingAsUser();
        $this->get('/admin/opconsole')->assertOk()
            ->assertSee('&gt;=12.0.0,&lt;12.9.2', false)
            ->assertSee('Range &lt;b&gt;fixture&lt;/b&gt;', false)
            ->assertDontSee('<b>fixture</b>', false);
    }
}

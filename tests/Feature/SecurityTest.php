<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Support\SecurityScanner;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;

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
}

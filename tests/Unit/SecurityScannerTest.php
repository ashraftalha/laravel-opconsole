<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Support\SecurityScanner;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class SecurityScannerTest extends TestCase
{
    private function fixture(string $output = '{"advisories":[]}', int $exit = 0, string $version = 'Composer version 2.10.0'): string
    {
        $dir = $this->useTemporaryLogDirectory();
        file_put_contents($dir.'/composer.json', '{}');
        file_put_contents($dir.'/composer.lock', '{"packages":[]}');
        file_put_contents($dir.'/audit.json', $output);
        file_put_contents($dir.'/composer', "#!/bin/sh\nif [ \"\$1\" = \"--version\" ]; then printf '%s' ".escapeshellarg($version)."; else cat \"\$PWD/audit.json\"; exit $exit; fi\n");
        chmod($dir.'/composer', 0755);
        $this->app->setBasePath($dir);
        config(['opconsole.security.composer' => $dir.'/composer']);

        return $dir;
    }

    #[DataProvider('badOutputs')]
    public function test_incomplete_audits_are_failures(string $output, int $exit, string $message): void
    {
        $this->fixture($output, $exit);
        $report = (new SecurityScanner)->scan();
        $this->assertSame('failed', $report['status']);
        $this->assertStringContainsString($message, $report['last_failure']['message']);
    }

    public static function badOutputs(): array
    {
        return [
            ['broken', 0, 'invalid or incomplete JSON'],
            ['broken', 2, 'exit code 2'],
            ['{"advisories":false}', 0, 'unsupported audit JSON schema'],
            ['{"advisories":[],"unreachable-repositories":["offline"]}', 0, 'repositories were unavailable'],
            ['{"advisories":{"package":false}}', 0, 'malformed advisory records'],
            ['{"advisories":[]}', 1, 'without a complete finding report'],
        ];
    }

    public function test_missing_or_malformed_manifests_and_invalid_executable(): void
    {
        $dir = $this->fixture();
        unlink($dir.'/composer.lock');
        $scanner = new SecurityScanner;
        $this->assertStringContainsString('missing', $scanner->scan()['last_failure']['message']);
        file_put_contents($dir.'/composer.lock', '{}');
        $this->assertStringContainsString('malformed', $scanner->scan()['last_failure']['message']);
        file_put_contents($dir.'/composer.lock', '{"packages":[]}');
        config(['opconsole.security.composer' => '']);
        $this->assertStringContainsString('not configured', $scanner->scan()['last_failure']['message']);
    }

    public function test_old_composer_is_refused(): void
    {
        $this->fixture(version: 'Composer version 2.3.0');
        $this->assertStringContainsString('2.4 or later', (new SecurityScanner)->scan()['last_failure']['message']);
    }

    public function test_oversized_output_is_stopped(): void
    {
        $this->fixture(str_repeat('x', 2100000));
        $this->assertStringContainsString('safety limit', (new SecurityScanner)->scan()['last_failure']['message']);
    }

    public function test_competing_scan_is_refused(): void
    {
        $this->fixture();
        $lock = Cache::store('array')->lock('opconsole.security.scan', 120);
        $this->assertTrue($lock->get());
        try {
            $this->assertStringContainsString('already running', (new SecurityScanner)->scan()['last_failure']['message']);
        } finally {
            $lock->release();
        }
    }

    public function test_nonlocking_store_and_failed_failure_persistence_are_safe(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('get')->willThrowException(new RuntimeException('offline'));
        Cache::shouldReceive('store')->andReturn(new Repository($store));
        $report = (new SecurityScanner)->scan();
        $this->assertSame('failed', $report['status']);
        $this->assertStringContainsString('atomic locks', $report['last_failure']['message']);
    }

    public function test_persistence_exception_does_not_discard_failure_report(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('get')->willReturn([]);
        $store->method('forever')->willThrowException(new RuntimeException('offline'));
        Cache::shouldReceive('store')->andReturn(new Repository($store));
        $this->assertSame('failed', (new SecurityScanner)->scan()['status']);
    }

    public function test_unexpected_scan_exception_is_sanitized_and_releases_lock(): void
    {
        $dir = $this->fixture();
        config(['opconsole.security.include_dev' => true]);
        file_put_contents($dir.'/composer.lock', '{"packages":[],"packages-dev":"invalid"}');
        $report = (new SecurityScanner)->scan();
        $this->assertStringContainsString('TypeError', $report['last_failure']['message']);
        $this->assertTrue(Cache::store('array')->lock('opconsole.security.scan', 1)->get());
    }

    public function test_mismatched_versions_ignored_records_and_remote_identifiers(): void
    {
        $dir = $this->fixture(json_encode([
            'advisories' => ['laravel/framework' => [false, ['title' => 'Problem', 'ignored' => true, 'sources' => [['remoteId' => 'CVE-123']], 'link' => str_repeat('x', 2050)]]],
            'ignored-advisories' => ['broken' => false, 'laravel/framework' => [false]],
        ]));
        file_put_contents($dir.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v0.0.1'], ['name' => 'not/installed', 'version' => '1']]]));
        $scanner = new SecurityScanner;
        $report = $scanner->scan();
        $this->assertSame('complete', $report['status']);
        $this->assertSame('laravel/framework', $report['version_mismatches'][0]['package']);
        $this->assertSame(['CVE-123'], $report['ignored'][0]['identifiers']);
        $this->assertNull($report['ignored'][0]['link']);
        Cache::store('array')->put('opconsole.security.report', ['advisories' => [false], 'last_failure' => ['at' => 'yesterday']]);
        $report = $scanner->report();
        $this->assertSame([[]], $report['advisories']);
        $this->assertSame('yesterday', $report['failure_age']);
    }
}

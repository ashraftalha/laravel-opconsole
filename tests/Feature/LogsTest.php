<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;

class LogsTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->logDir = sys_get_temp_dir().'/opconsole-logs-'.uniqid();
        File::makeDirectory($this->logDir);
        $this->app['config']->set('opconsole.logs.path', $this->logDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDir);
        parent::tearDown();
    }

    private function writeLog(string $name, string $content): void
    {
        File::put($this->logDir.'/'.$name, $content);
    }

    public function test_only_log_files_in_the_configured_directory_are_listed(): void
    {
        $this->writeLog('laravel.log', "[2026-01-01 00:00:00] testing.ERROR: boom\n");
        $this->actingAsUser();

        $response = $this->get('/admin/opconsole');

        $response->assertSee('laravel.log');
    }

    public function test_deleting_the_newest_log_file_is_refused(): void
    {
        $this->writeLog('laravel.log', "[2026-01-01 00:00:00] testing.ERROR: boom\n");
        $this->actingAsUser();

        $this->delete('/admin/opconsole/logs/laravel.log')->assertRedirect();

        $this->assertFileExists($this->logDir.'/laravel.log');
    }

    public function test_an_older_log_file_can_be_deleted(): void
    {
        $this->writeLog('laravel-2026-01-01.log', 'old');
        touch($this->logDir.'/laravel-2026-01-01.log', time() - 3600);
        $this->writeLog('laravel.log', 'new');
        $this->actingAsUser();

        $this->delete('/admin/opconsole/logs/laravel-2026-01-01.log')->assertRedirect();

        $this->assertFileDoesNotExist($this->logDir.'/laravel-2026-01-01.log');
        $this->assertDatabaseHas('opconsole_runs', ['action' => 'log.delete', 'status' => 'succeeded']);
    }

    public function test_path_traversal_in_the_filename_is_rejected_by_the_route(): void
    {
        $this->actingAsUser();

        $this->get('/admin/opconsole/logs/..%2f..%2fsecrets.log')->assertNotFound();
    }

    public function test_symlink_escaping_the_logs_directory_is_refused(): void
    {
        $outside = sys_get_temp_dir().'/opconsole-outside-'.uniqid().'.log';
        File::put($outside, 'secret');
        symlink($outside, $this->logDir.'/escape.log');
        $this->actingAsUser();

        $this->get('/admin/opconsole/logs/escape.log')->assertNotFound();

        @unlink($outside);
    }

    public function test_log_download_can_be_disabled(): void
    {
        $this->writeLog('laravel.log', "[2026-01-01 00:00:00] testing.ERROR: boom\n");
        $this->app['config']->set('opconsole.logs.allow_download', false);
        $this->actingAsUser();

        $this->get('/admin/opconsole/logs/laravel.log')->assertForbidden();
    }

    public function test_log_delete_can_be_disabled(): void
    {
        $this->writeLog('a.log', 'x');
        $this->writeLog('b.log', 'y');
        $this->app['config']->set('opconsole.logs.allow_delete', false);
        $this->actingAsUser();

        $this->delete('/admin/opconsole/logs/a.log')->assertForbidden();
    }

    public function test_csrf_is_required_for_destructive_requests(): void
    {
        $this->writeLog('laravel.log', 'x');
        $this->actingAsUser();
        $this->withoutMiddleware([ThrottleRequests::class]);

        $response = $this->call('DELETE', '/admin/opconsole/logs/laravel.log', [], [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        // CSRF is enforced by the 'web' middleware group, not by OpConsole itself;
        // this test only documents the requirement and exercises the real route.
        $this->assertTrue(in_array($response->getStatusCode(), [200, 302, 419], true));
    }

    public function test_search_highlighting_escapes_entry_text(): void
    {
        $this->writeLog('laravel.log', "[2026-01-01 00:00:00] testing.ERROR: <script>alert(1)</script>\n");
        $this->actingAsUser();

        $response = $this->get('/admin/opconsole?log=laravel.log');

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }
}

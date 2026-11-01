<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class LogsTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->logDir = $this->useTemporaryLogDirectory();
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

        Schema::drop('opconsole_runs');
        $this->writeLog('laravel-2026-01-02.log', 'older');
        touch($this->logDir.'/laravel-2026-01-02.log', time() - 1800);

        $this->delete('/admin/opconsole/logs/laravel-2026-01-02.log')
            ->assertRedirect()
            ->assertSessionHas('opconsole_success')
            ->assertSessionHas('opconsole_warning');
        $this->assertFileDoesNotExist($this->logDir.'/laravel-2026-01-02.log');
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
        $this->app['env'] = 'production';
        $this->actingAsUser();

        $this->delete('/admin/opconsole/logs/laravel.log')->assertStatus(419);
    }

    public function test_search_highlighting_escapes_entry_text(): void
    {
        $this->writeLog('laravel.log', "[2026-01-01 00:00:00] testing.ERROR: <script>alert(1)</script>\n");
        $this->actingAsUser();

        $response = $this->get('/admin/opconsole?log=laravel.log');

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    /**
     * An external symlink or invalidly named file with the newest mtime must not crash the
     * dashboard, appear in the file selector, or unlock deletion of the newest valid log file.
     */
    public function test_newest_external_symlink_does_not_crash_dashboard_or_unlock_deleting_newest_valid_log(): void
    {
        $this->writeLog('laravel.log', "[2026-01-01 00:00:00] testing.ERROR: active log\n");
        touch($this->logDir.'/laravel.log', time() - 120);

        $outside = sys_get_temp_dir().'/opconsole-outside-newest-'.uniqid().'.log';
        File::put($outside, "[2026-01-01 00:00:05] testing.ERROR: secret\n");

        try {
            symlink($outside, $this->logDir.'/escape.log');
            File::put($this->logDir.'/invalid file name.log', 'bad');

            $this->actingAsUser();

            $this->get('/admin/opconsole')
                ->assertOk()
                ->assertSee('laravel.log')
                ->assertDontSee('escape.log')
                ->assertDontSee('invalid file name.log');

            // laravel.log is still the newest *valid* log file and must remain protected from deletion.
            $this->delete('/admin/opconsole/logs/laravel.log')
                ->assertRedirect()
                ->assertSessionHas('opconsole_error');

            $this->assertFileExists($this->logDir.'/laravel.log');
        } finally {
            @unlink($outside);
        }
    }

    /**
     * A 404 or rejected download request must not record a false "succeeded" log.download
     * audit row before file validation runs.
     */
    public function test_failed_or_missing_log_download_does_not_write_succeeded_audit_row(): void
    {
        $this->writeLog('laravel.log', "[2026-01-01 00:00:00] testing.INFO: ok\n");
        $this->actingAsUser();

        $this->get('/admin/opconsole/logs/nonexistent.log')->assertNotFound();
        $this->assertDatabaseMissing('opconsole_runs', [
            'action' => 'log.download',
            'subject' => 'nonexistent.log',
        ]);

        $this->get('/admin/opconsole/logs/laravel.log')->assertOk();
        $this->assertDatabaseHas('opconsole_runs', [
            'action' => 'log.download',
            'subject' => 'laravel.log',
            'status' => 'succeeded',
        ]);
    }

    public function test_deleting_nonexistent_log_returns_404_instead_of_newest_file_message(): void
    {
        $this->writeLog('laravel.log', "[2026-01-01 00:00:00] testing.INFO: current\n");
        $this->actingAsUser();

        $this->delete('/admin/opconsole/logs/missing.log')->assertNotFound();
    }
}

<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Support\LogViewer;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\File;

class LogViewerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/opconsole-unit-'.uniqid();
        File::makeDirectory($this->dir);
        $this->app['config']->set('opconsole.logs.path', $this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function viewer(): LogViewer
    {
        return $this->app->make(LogViewer::class);
    }

    public function test_multi_line_stack_traces_are_parsed_as_one_entry(): void
    {
        File::put($this->dir.'/laravel.log', "[2026-01-01 00:00:00] testing.ERROR: Something broke {\"exception\":\"[object] (Exception(code: 0): Something broke at /app/Foo.php:10)\n[stacktrace]\n#0 {main}\"}\n");

        $result = $this->viewer()->read('laravel.log');

        $this->assertCount(1, $result['entries']);
        $this->assertStringContainsString('Something broke', $result['entries'][0]['message']);
        $this->assertStringContainsString('stacktrace', $result['entries'][0]['details']);
    }

    public function test_level_counts_are_correct(): void
    {
        File::put($this->dir.'/laravel.log',
            "[2026-01-01 00:00:00] testing.ERROR: one\n".
            "[2026-01-01 00:00:01] testing.WARNING: two\n".
            "[2026-01-01 00:00:02] testing.ERROR: three\n"
        );

        $result = $this->viewer()->read('laravel.log');

        $this->assertSame(2, $result['counts']['error']);
        $this->assertSame(1, $result['counts']['warning']);
    }

    public function test_environment_filter_falls_back_correctly(): void
    {
        File::put($this->dir.'/laravel.log',
            "[2026-01-01 00:00:00] production.ERROR: prod issue\n".
            "[2026-01-01 00:00:01] staging.ERROR: staging issue\n"
        );

        $all = $this->viewer()->read('laravel.log', env: null);
        $this->assertSame(2, $all['total']);

        $prod = $this->viewer()->read('laravel.log', env: 'production');
        $this->assertSame(1, $prod['total']);
    }

    public function test_repeated_errors_are_grouped(): void
    {
        File::put($this->dir.'/laravel.log',
            "[2026-01-01 00:00:00] testing.ERROR: Call to undefined method Foo::bar() for user 1\n".
            "[2026-01-01 00:00:01] testing.ERROR: Call to undefined method Foo::bar() for user 2\n".
            "[2026-01-01 00:00:02] testing.ERROR: Call to undefined method Foo::bar() for user 3\n"
        );

        $result = $this->viewer()->read('laravel.log');

        $this->assertCount(1, $result['groups']);
        $this->assertSame(3, $result['groups'][0]['count']);
    }

    public function test_tail_truncation_flag_is_set_for_large_files(): void
    {
        $path = $this->dir.'/laravel.log';
        $handle = fopen($path, 'w');
        fwrite($handle, "[2026-01-01 00:00:00] testing.INFO: start\n");
        fwrite($handle, str_repeat('x', 3 * 1024 * 1024));
        fclose($handle);

        $result = $this->viewer()->read('laravel.log');

        $this->assertTrue($result['truncated']);
    }

    public function test_unparsable_content_returns_no_entries_without_error(): void
    {
        File::put($this->dir.'/laravel.log', "not a log line at all\njust noise\n");

        $result = $this->viewer()->read('laravel.log');

        $this->assertSame([], $result['entries']);
    }

    public function test_crlf_line_endings_are_handled(): void
    {
        File::put($this->dir.'/laravel.log', "[2026-01-01 00:00:00] testing.ERROR: windows style\r\n");

        $result = $this->viewer()->read('laravel.log');

        $this->assertCount(1, $result['entries']);
    }

    public function test_path_rejects_names_outside_the_pattern(): void
    {
        $this->assertNull($this->viewer()->path('../secret.log'));
        $this->assertNull($this->viewer()->path('not-a-log.txt'));
        $this->assertNull($this->viewer()->path(null));
    }
}

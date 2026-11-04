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
        $this->dir = $this->useTemporaryLogDirectory();
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

    public function test_files_excludes_symlinks_escaping_directory_and_invalid_filenames(): void
    {
        File::put($this->dir.'/laravel.log', "[2026-01-01 00:00:00] testing.INFO: valid\n");
        File::put($this->dir.'/space in name.log', 'invalid');

        $outside = sys_get_temp_dir().'/opconsole-unit-escape-'.uniqid().'.log';
        File::put($outside, 'secret');

        try {
            symlink($outside, $this->dir.'/escape.log');

            $names = array_column($this->viewer()->files(), 'name');

            $this->assertSame(['laravel.log'], $names);
        } finally {
            @unlink($outside);
        }
    }

    public function test_split_multibyte_utf8_at_tail_boundary_and_unicode_entries_parse_cleanly(): void
    {
        $path = $this->dir.'/unicode.log';
        $unicodeLine = "[2026-01-01 00:00:05] testing.ERROR: فشل الاتصال بقاعدة البيانات — naïve café 🚀\n";

        // Position a 4-byte UTF-8 emoji ("🚀" = \xF0\x9F\x9A\x80) right across the -2 MB seek boundary.
        $tailPayload = str_repeat('a', (2 * 1024 * 1024) - strlen($unicodeLine) - 10)."\n".$unicodeLine;
        File::put($path, 'prefix-🚀'.$tailPayload);

        $result = $this->viewer()->read('unicode.log');

        $this->assertTrue($result['truncated']);
        $this->assertCount(1, $result['entries']);
        $this->assertStringContainsString('فشل الاتصال بقاعدة البيانات', $result['entries'][0]['message']);
        $this->assertStringContainsString('🚀', $result['entries'][0]['message']);
    }

    public function test_json_formatted_monolog_lines_are_parsed_filtered_and_grouped(): void
    {
        $lines = [
            json_encode([
                'datetime' => '2026-02-10T14:20:00+00:00',
                'channel' => 'production',
                'level_name' => 'ERROR',
                'message' => 'Payment gateway timeout for order 41',
                'context' => ['order_id' => 41, 'gateway' => 'stripe'],
            ]),
            json_encode([
                'datetime' => '2026-02-10T14:21:00+00:00',
                'channel' => 'production',
                'level_name' => 'ERROR',
                'message' => 'Payment gateway timeout for order 42',
                'context' => ['order_id' => 42, 'gateway' => 'stripe'],
            ]),
            json_encode([
                'datetime' => '2026-02-10T14:22:00+00:00',
                'channel' => 'staging',
                'level_name' => 'INFO',
                'message' => 'Health ping ok',
                'context' => [],
            ]),
        ];
        File::put($this->dir.'/json.log', implode("\n", $lines)."\n");

        $prodErrors = $this->viewer()->read('json.log', level: 'error', env: 'production');

        $this->assertSame(2, $prodErrors['total']);
        $this->assertCount(1, $prodErrors['groups']);
        $this->assertSame(2, $prodErrors['groups'][0]['count']);
        $this->assertStringContainsString('stripe', $prodErrors['entries'][0]['details']);
    }

    public function test_fallback_to_all_environments_returns_entries_in_single_pass(): void
    {
        File::put($this->dir.'/laravel.log', "[2026-01-01 00:00:00] production.WARNING: disk at 85%\n");

        $withoutFallback = $this->viewer()->read('laravel.log', env: 'local', fallbackToAllEnvs: false);
        $this->assertSame(0, $withoutFallback['total']);

        $withFallback = $this->viewer()->read('laravel.log', env: 'local', fallbackToAllEnvs: true);
        $this->assertSame(1, $withFallback['total']);
        $this->assertNull($withFallback['resolvedEnv']);
    }

    public function test_timeline_buckets_the_24_hours_up_to_the_newest_entry_by_level(): void
    {
        File::put($this->dir.'/laravel.log', implode("\n", [
            '[2026-01-01 08:15:00] testing.INFO: way too old',
            '[2026-01-02 09:05:00] testing.INFO: first hour',
            '[2026-01-02 09:59:00] testing.WARNING: first hour warning',
            '[2026-01-03 07:10:00] testing.ERROR: newest hour error',
            '[2026-01-03 07:20:00] testing.CRITICAL: newest hour critical',
        ])."\n");

        $timeline = $this->viewer()->read('laravel.log')['timeline'];

        $this->assertCount(24, $timeline);
        $this->assertSame('08:00', $timeline[0]['label']);
        $this->assertSame('07:00', $timeline[23]['label']);
        $this->assertSame(['errors' => 2, 'warnings' => 0, 'other' => 0], array_intersect_key($timeline[23], array_flip(['errors', 'warnings', 'other'])));
        $this->assertSame(['errors' => 0, 'warnings' => 1, 'other' => 1], array_intersect_key($timeline[1], array_flip(['errors', 'warnings', 'other'])));
        $this->assertSame(5 - 1, array_sum(array_map(fn ($hour) => $hour['errors'] + $hour['warnings'] + $hour['other'], $timeline)));
    }

    public function test_timeline_is_empty_without_parsable_timestamps(): void
    {
        File::put($this->dir.'/laravel.log', "not a log line\n");

        $this->assertSame([], $this->viewer()->read('laravel.log')['timeline']);
    }
}

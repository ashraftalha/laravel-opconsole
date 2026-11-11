<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Support\LogViewer;
use AshrafTalha\OpConsole\Tests\Support\FunctionOverrides as Functions;
use AshrafTalha\OpConsole\Tests\TestCase;

class LogViewerEdgeCasesTest extends TestCase
{
    public function test_missing_directory_and_empty_file_are_safe(): void
    {
        $dir = $this->useTemporaryLogDirectory();
        config(['opconsole.logs.path' => $dir.'/missing']);
        $viewer = new LogViewer;
        $this->assertSame([], $viewer->files());
        $this->assertNull($viewer->path('missing.log'));
        $this->assertSame('not_found', $viewer->deleteOutcome('missing.log'));
        config(['opconsole.logs.path' => $dir]);
        file_put_contents($dir.'/empty.log', '');
        $this->assertSame([], $viewer->read('empty.log')['entries']);
    }

    public function test_files_disappearing_during_inspection_or_open_do_not_crash(): void
    {
        $dir = $this->useTemporaryLogDirectory();
        file_put_contents($dir.'/race.log', 'data');
        Functions::$callbacks['filesize'] = fn () => false;
        $this->assertSame([], (new LogViewer)->files());
        $this->assertSame([], (new LogViewer)->read('race.log')['entries']);
    }

    public function test_failed_unlink_does_not_report_success(): void
    {
        $dir = $this->useTemporaryLogDirectory();
        file_put_contents($dir.'/old.log', 'old');
        file_put_contents($dir.'/new.log', 'new');
        touch($dir.'/old.log', time() - 100);
        Functions::$callbacks['unlink'] = fn () => false;
        $this->assertSame('failed', (new LogViewer)->deleteOutcome('old.log'));
        $this->assertFileExists($dir.'/old.log');
    }

    public function test_recent_errors_skip_bad_dates_and_stop_after_old_entries(): void
    {
        $dir = $this->useTemporaryLogDirectory();
        $this->travelTo(now()->startOfSecond());
        file_put_contents($dir.'/errors.log', implode("\n", array_map(fn ($time) => json_encode(['datetime' => $time, 'channel' => 'testing', 'level' => 400, 'message' => 'failure']), [now()->subDays(2)->toIso8601String(), now()->subMinutes(5)->toIso8601String(), 'invalid-date'])));
        $result = (new LogViewer)->recentErrors();
        $this->assertSame(1, $result['count']);
        $this->assertSame('5 minutes ago', $result['last']);
    }

    public function test_json_levels_and_malformed_records(): void
    {
        $dir = $this->useTemporaryLogDirectory();
        $levels = [600, 550, 500, 400, 300, 250, 200, 100];
        $lines = array_map(fn ($level) => json_encode(['message' => 'event', 'level' => (string) $level]), $levels);
        $lines = [...$lines, '{broken', '{"message":[]}', '{"message":"missing level"}', '{"message":42,"level":"unknown"}'];
        file_put_contents($dir.'/json.log', implode("\n", $lines));
        $entries = (new LogViewer)->read('json.log')['entries'];
        $this->assertSame(['info', 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], array_column($entries, 'level'));
        $this->assertSame('42', $entries[0]['message']);
    }

    public function test_regex_failure_returns_no_entries(): void
    {
        $dir = $this->useTemporaryLogDirectory();
        file_put_contents($dir.'/entry.log', '[2026-01-01 00:00:00] testing.ERROR: failure');
        Functions::$callbacks['preg_split'] = fn () => false;
        $this->assertSame([], (new LogViewer)->read('entry.log')['entries']);
    }
}

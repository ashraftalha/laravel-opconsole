<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use AshrafTalha\OpConsole\Support\ActionRunner;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class MigrationTest extends TestCase
{
    public function test_migration_creates_the_runs_table(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->assertTrue(Schema::hasTable('opconsole_runs'));
        $this->assertTrue(Schema::hasColumns('opconsole_runs', [
            'action', 'status', 'user_id', 'user_label', 'exit_code', 'duration_ms', 'subject', 'error', 'started_at', 'finished_at',
        ]));
    }

    public function test_audit_rows_are_still_written_before_the_duration_migration_runs(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_11_01_000000_create_opconsole_runs_table.php';
        $migration->up();

        $this->assertTrue(app(ActionRunner::class)->record('log.download', null, true, 'laravel.log'));
        $this->assertSame(1, OpConsoleRun::query()->count());
    }

    public function test_table_name_is_configurable(): void
    {
        static::$configOverrides = ['opconsole.table' => 'custom_opconsole_runs'];
        $this->refreshApplication();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->assertTrue(Schema::hasTable('custom_opconsole_runs'));
    }

    public function test_dashboard_loads_when_the_runs_table_does_not_exist_yet(): void
    {
        $this->allowOpConsole();
        $this->actingAsUser();

        // No loadMigrationsFrom call: the table genuinely does not exist.
        $this->get('/admin/opconsole')->assertOk();
    }

    public function test_migration_rollback_drops_the_configured_runs_table(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_11_01_000000_create_opconsole_runs_table.php';

        $migration->up();
        $this->assertTrue(Schema::hasTable('opconsole_runs'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('opconsole_runs'));
    }
}

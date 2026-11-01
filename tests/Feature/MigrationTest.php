<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class MigrationTest extends TestCase
{
    public function test_migration_creates_the_runs_table(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->assertTrue(Schema::hasTable('opconsole_runs'));
        $this->assertTrue(Schema::hasColumns('opconsole_runs', [
            'action', 'status', 'user_id', 'user_label', 'exit_code', 'subject', 'error', 'started_at', 'finished_at',
        ]));
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
}

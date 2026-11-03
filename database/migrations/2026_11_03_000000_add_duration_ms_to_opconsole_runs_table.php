<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('opconsole.connection'));
        $table = config('opconsole.table', 'opconsole_runs');

        if ($schema->hasColumn($table, 'duration_ms')) {
            return;
        }

        $schema->table($table, function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('exit_code');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('opconsole.connection'));
        $table = config('opconsole.table', 'opconsole_runs');

        if ($schema->hasColumn($table, 'duration_ms')) {
            $schema->table($table, function (Blueprint $table) {
                $table->dropColumn('duration_ms');
            });
        }
    }
};

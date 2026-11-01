<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('opconsole.connection'))->create(config('opconsole.table', 'opconsole_runs'), function (Blueprint $table) {
            $table->id();
            $table->string('action', 32)->index();
            $table->string('status', 16)->default('started')->index(); // started|succeeded|failed
            $table->string('user_id')->nullable();
            $table->string('user_label')->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->string('subject')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(config('opconsole.connection'))->dropIfExists(config('opconsole.table', 'opconsole_runs'));
    }
};

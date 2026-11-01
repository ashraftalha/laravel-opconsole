<?php

namespace AshrafTalha\OpConsole\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per operation (started/succeeded/failed): the Artisan actions and log
 * delete/download. Doubles as the "last run" source and the audit trail.
 *
 * @property int $id
 * @property string $action
 * @property string $status
 * @property ?string $user_id
 * @property ?string $user_label
 * @property ?int $exit_code
 * @property ?string $subject
 * @property ?string $error
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 */
class OpConsoleRun extends Model
{
    protected $fillable = [
        'action', 'status', 'user_id', 'user_label', 'exit_code', 'subject', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return config('opconsole.connection') ?? $this->connection;
    }

    public function getTable(): string
    {
        return config('opconsole.table', 'opconsole_runs');
    }
}

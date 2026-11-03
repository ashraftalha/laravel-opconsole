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
 * @property ?int $duration_ms
 * @property ?string $subject
 * @property ?string $error
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class OpConsoleRun extends Model
{
    protected $fillable = [
        'action', 'status', 'user_id', 'user_label', 'exit_code', 'duration_ms', 'subject', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
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

    /** False until the host runs the duration_ms migration; audit writes must keep working before then. */
    public static function hasDurationColumn(): bool
    {
        try {
            $model = new self;

            return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'duration_ms');
        } catch (\Throwable) {
            return false;
        }
    }
}

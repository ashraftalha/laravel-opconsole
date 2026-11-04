<?php

namespace AshrafTalha\OpConsole\Support;

use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only view of Laravel's failed-job store, plus retrying one job by its ID.
 *
 * Listing never loads the whole store: database providers are queried with a limit,
 * other providers are sliced. Payloads are never exposed; only the job's display name,
 * queue, failure time and the first line of the (sanitised) exception.
 */
class FailedJobs
{
    /** IDs as Laravel's providers issue them: auto-increment integers or UUIDs. */
    public const ID_PATTERN = '[A-Za-z0-9-]{1,64}';

    /**
     * @return array{available: bool, total: int, items: list<array{id: string, name: string, connection: string, queue: string, failedAt: ?string, exception: string}>, error: ?string}
     */
    public function list(int $limit = 50): array
    {
        $provider = $this->provider();
        if ($provider === null) {
            return ['available' => false, 'total' => 0, 'items' => [], 'error' => null];
        }

        try {
            if ($provider instanceof DatabaseUuidFailedJobProvider || $provider instanceof DatabaseFailedJobProvider) {
                $uuid = $provider instanceof DatabaseUuidFailedJobProvider;
                $query = DB::connection(config('queue.failed.database'))->table((string) config('queue.failed.table', 'failed_jobs'));
                $total = (clone $query)->count();
                $rows = $query->orderByDesc('id')->limit($limit)
                    ->get(array_values(array_unique(['id', $uuid ? 'uuid' : 'id', 'connection', 'queue', 'payload', 'exception', 'failed_at'])))
                    ->map(function ($row) use ($uuid) {
                        $row->id = $uuid ? $row->uuid : $row->id;

                        return $row;
                    })->all();
            } else {
                $all = $provider->all();
                $total = count($all);
                $rows = array_slice($all, 0, $limit);
            }
        } catch (Throwable $e) {
            return ['available' => true, 'total' => 0, 'items' => [], 'error' => ActionRunner::sanitizeText(class_basename($e).': '.$e->getMessage())];
        }

        return [
            'available' => true,
            'total' => $total,
            'items' => array_values(array_map(fn ($row) => $this->present((object) $row), $rows)),
            'error' => null,
        ];
    }

    /** @phpstan-impure The store changes between calls (retry() checks again after queue:retry). */
    public function exists(string $id): bool
    {
        if (! preg_match('/^'.self::ID_PATTERN.'$/', $id) || ($provider = $this->provider()) === null) {
            return false;
        }

        try {
            return $provider->find($id) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Push one failed job back onto its queue with Laravel's own `queue:retry`, which also
     * removes it from the failed-job store.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function retry(string $id): array
    {
        if (! $this->exists($id)) {
            return ['ok' => false, 'error' => 'The failed job no longer exists.'];
        }

        try {
            $exit = Artisan::call('queue:retry', ['id' => [$id]]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => ActionRunner::sanitizeText(class_basename($e).': '.$e->getMessage())];
        }

        // queue:retry exits 0 even when it cannot push a job, so confirm the record actually left the store.
        if ($exit !== 0 || $this->exists($id)) {
            return ['ok' => false, 'error' => trim(ActionRunner::sanitizeText(Artisan::output())) ?: 'queue:retry did not re-queue the job.'];
        }

        return ['ok' => true, 'error' => null];
    }

    private function provider(): ?FailedJobProviderInterface
    {
        try {
            /** @var FailedJobProviderInterface $provider The concrete provider depends on queue.failed.driver at runtime. */
            $provider = app('queue.failer');
        } catch (Throwable) {
            return null;
        }

        return $provider instanceof NullFailedJobProvider ? null : $provider;
    }

    /** @return array{id: string, name: string, connection: string, queue: string, failedAt: ?string, exception: string} */
    private function present(object $row): array
    {
        $payload = json_decode((string) ($row->payload ?? ''), true);
        $name = is_array($payload) ? (string) ($payload['displayName'] ?? $payload['job'] ?? 'Unknown job') : 'Unknown job';
        $exception = trim(strtok((string) ($row->exception ?? ''), "\n") ?: '');

        try {
            $failedAt = isset($row->failed_at) ? Carbon::parse((string) $row->failed_at)->toIso8601String() : null;
        } catch (Throwable) {
            $failedAt = null;
        }

        return [
            'id' => (string) $row->id,
            'name' => Str::limit($name, 160),
            'connection' => (string) ($row->connection ?? ''),
            'queue' => (string) ($row->queue ?? ''),
            'failedAt' => $failedAt,
            'exception' => Str::limit(ActionRunner::sanitizeText($exception), 300),
        ];
    }
}

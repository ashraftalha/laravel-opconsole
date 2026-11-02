<?php

namespace AshrafTalha\OpConsole\Support;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Throwable;

/** Everything the dashboard shows, gathered in one place. Pure read model. */
class ConsoleStatus
{
    private const HISTORY_LIMIT = 15;

    public function __construct(
        private Migrator $migrator,
        private LogViewer $logs,
        private HealthChecks $healthChecks,
    ) {}

    /** @param  ?string  $host  the host the admin is browsing on */
    public function status(?string $host = null): array
    {
        $database = $this->database();
        $caches = $this->caches();
        $checks = $this->healthChecks->run($database, $host);
        $errors = $this->logs->recentErrors();
        $audit = $this->audit();

        return [
            'app' => [
                'environment' => app()->environment(),
                'production' => app()->isProduction(),
                'debug' => (bool) config('app.debug'),
            ],
            'database' => $database,
            'checks' => $checks,
            'errors' => $errors,
            'info' => $this->info($database, $caches),
            'lastRuns' => $audit['lastRuns'],
            'history' => $audit['history'],
            'advice' => $this->advice($database, $caches, $errors, $checks),
        ];
    }

    /**
     * Queries the migration repository at most once per call instead of duplicating
     * repositoryExists() and getRan() queries.
     *
     * @return array{ran: int, pending: list<string>}
     */
    private function migrationState(): array
    {
        $files = array_keys($this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]));

        if (! $this->migrator->repositoryExists()) {
            return ['ran' => 0, 'pending' => $files];
        }

        $ran = $this->migrator->getRepository()->getRan();

        return [
            'ran' => count($ran),
            'pending' => array_values(array_diff($files, $ran)),
        ];
    }

    private function database(): array
    {
        $connection = (string) config('database.default');

        try {
            DB::connection()->getPdo();
            ['ran' => $ran, 'pending' => $pending] = $this->migrationState();
            $dbName = DB::connection()->getDatabaseName();
            if ($dbName !== '' && str_contains($dbName, DIRECTORY_SEPARATOR)) {
                $dbName = basename($dbName);
            }

            return ['ok' => true, 'driver' => $connection, 'name' => $dbName, 'ran' => $ran, 'pending' => $pending, 'error' => null];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'driver' => $connection,
                'name' => null,
                'ran' => 0,
                'pending' => [],
                'error' => ActionRunner::sanitizeText($e->getMessage()),
            ];
        }
    }

    private function caches(): array
    {
        $compiledPath = config('view.compiled');
        $views = is_string($compiledPath) && $compiledPath !== '' && is_dir($compiledPath)
            ? (glob(rtrim($compiledPath, '/\\').'/*.php') ?: [])
            : [];

        return [
            'config' => app()->configurationIsCached(),
            'routes' => app()->routesAreCached(),
            'events' => app()->eventsAreCached(),
            'views' => count($views),
            'viewsSize' => array_sum(array_map(fn ($file) => @filesize($file) ?: 0, $views)),
            'store' => config('cache.default'),
        ];
    }

    /** Grouped facts for the "System information" panel. */
    private function info(array $database, array $caches): array
    {
        $yes = fn (bool $value) => $value ? 'Yes' : 'No';
        $disk = [@disk_free_space(storage_path()), @disk_total_space(storage_path())];

        return [
            'Application' => [
                'Laravel' => app()->version(),
                'Environment' => app()->environment(),
                'URL' => config('app.url'),
                'Timezone' => config('app.timezone'),
            ],
            'PHP' => [
                'Version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'max_execution_time' => (int) ini_get('max_execution_time') ? ini_get('max_execution_time').'s' : 'Unlimited',
            ],
            'Server' => [
                'Operating system' => PHP_OS_FAMILY.' '.php_uname('r'),
                'Web server' => request()->server('SERVER_SOFTWARE') ?: PHP_SAPI,
                'Disk space' => $disk[0] && $disk[1] ? Number::fileSize($disk[0], 1).' available of '.Number::fileSize($disk[1], 1) : '—',
                'Log size' => Number::fileSize($this->logs->totalSize(), 1),
            ],
            'Services' => [
                'Database' => $database['driver'].($database['name'] ? ' · '.$database['name'] : ''),
                'Cache' => $caches['store'],
                'Action lock' => (string) config('opconsole.concurrency.lock_store', 'opconsole'),
                'Sessions' => config('session.driver'),
                'Queues' => config('queue.default'),
                'Mail' => config('mail.default'),
            ],
            'Cache' => [
                'Configuration' => $yes($caches['config']),
                'Routes' => $yes($caches['routes']),
                'Events' => $yes($caches['events']),
                'Compiled views' => $caches['views'].' files · '.Number::fileSize($caches['viewsSize']),
            ],
        ];
    }

    /**
     * Bounded queries for latest finished run per action and recent audit history.
     * Never throws when the audit table has not been migrated yet.
     *
     * @return array{
     *     lastRuns: array<string, ?array{at: ?string, by: string, ok: bool}>,
     *     history: array{available: bool, items: list<array{action: string, status: 'succeeded'|'failed'|'started'|'unknown', actor: string, startedAt: ?string, durationMs: ?int, exitCode: ?int, subject: ?string, error: ?string}>}
     * }
     */
    private function audit(): array
    {
        $actions = array_keys(array_filter(config('opconsole.actions', [])));
        $staleSeconds = max(5, (int) config('opconsole.concurrency.lock_seconds', 30)) * 2;
        $staleThreshold = now()->subSeconds($staleSeconds);

        try {
            $rows = collect();
            if ($actions !== []) {
                $latestIds = OpConsoleRun::query()
                    ->selectRaw('MAX(id) as id')
                    ->whereIn('action', $actions)
                    ->whereIn('status', ['succeeded', 'failed'])
                    ->groupBy('action');

                $rows = OpConsoleRun::query()
                    ->whereIn('id', $latestIds)
                    ->get(['id', 'action', 'status', 'user_label', 'finished_at'])
                    ->keyBy('action');
            }

            $recentRows = OpConsoleRun::query()
                ->latest('id')
                ->limit(self::HISTORY_LIMIT)
                ->get(['id', 'action', 'status', 'user_id', 'user_label', 'exit_code', 'subject', 'error', 'started_at', 'finished_at', 'created_at']);

            $available = true;
        } catch (Throwable) {
            $rows = collect();
            $recentRows = collect();
            $available = false;
        }

        $lastRuns = collect($actions)->mapWithKeys(fn ($action) => [
            $action => $rows->has($action) ? [
                'at' => $rows[$action]->finished_at?->toIso8601String(),
                'by' => $rows[$action]->user_label ?? 'Unknown',
                'ok' => $rows[$action]->status === 'succeeded',
            ] : null,
        ])->all();

        $items = $recentRows->map(function (OpConsoleRun $row) use ($staleThreshold): array {
            $startedAt = $row->started_at ?? $row->created_at;
            $finishedAt = $row->finished_at;

            // Do not infer success from a missing error: incomplete runs past the lock window are classified as unknown/interrupted.
            /** @var 'succeeded'|'failed'|'started'|'unknown' $status */
            $status = match ($row->status) {
                'succeeded' => 'succeeded',
                'failed' => 'failed',
                'started' => ($startedAt !== null && $startedAt->greaterThanOrEqualTo($staleThreshold) && $finishedAt === null)
                    ? 'started'
                    : 'unknown',
                default => 'unknown',
            };

            $durationMs = ($startedAt !== null && $finishedAt !== null)
                ? max(0, (int) round($startedAt->diffInMilliseconds($finishedAt)))
                : null;

            return [
                'action' => (string) $row->action,
                'status' => $status,
                'actor' => $row->user_label ?: ($row->user_id !== null ? 'User #'.$row->user_id : 'System'),
                'startedAt' => $startedAt?->toIso8601String(),
                'durationMs' => $durationMs,
                'exitCode' => $row->exit_code !== null ? (int) $row->exit_code : null,
                'subject' => $row->subject,
                'error' => $row->error,
            ];
        })->values()->all();

        return [
            'lastRuns' => $lastRuns,
            'history' => [
                'available' => $available,
                'items' => $items,
            ],
        ];
    }

    /**
     * What an admin should do next, most important first.
     *
     * @return list<array{level: 'danger'|'warning'|'info', text: string, action: ?string}>
     */
    private function advice(array $database, array $caches, array $errors, array $checks): array
    {
        $advice = [];

        if (! $database['ok']) {
            $advice[] = ['level' => 'danger', 'text' => 'Cannot connect to the database. Check DB_* settings in .env.', 'action' => null];
        } elseif ($database['pending']) {
            $advice[] = ['level' => 'warning', 'text' => 'There are '.count($database['pending']).' pending migrations. New features may require them.', 'action' => 'migrate'];
        }

        $criticalChecks = array_values(array_filter($checks, fn ($c) => $c['status'] === 'critical' && $c['id'] !== 'database.connection'));
        if ($criticalChecks !== []) {
            $advice[] = [
                'level' => 'danger',
                'text' => count($criticalChecks).' critical health '.(count($criticalChecks) === 1 ? 'check requires' : 'checks require').' immediate attention ('.$criticalChecks[0]['label'].').',
                'action' => 'checks',
            ];
        }

        if ($errors['count'] > 0) {
            $advice[] = ['level' => 'warning', 'text' => $errors['count'].' errors in the last 24 hours in '.app()->environment().'. Latest: '.$errors['last'], 'action' => 'logs'];
        }

        if (app()->isProduction() && ! $caches['config']) {
            $advice[] = ['level' => 'info', 'text' => 'Production configuration is not cached. Optimize performance to speed up page loading.', 'action' => 'optimize'];
        }

        if (! app()->isProduction() && ($caches['config'] || $caches['routes'])) {
            $advice[] = ['level' => 'warning', 'text' => 'Configuration or routes are cached in development. Clear caches to apply .env and route changes.', 'action' => 'clear'];
        }

        // most urgent first
        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($advice, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $advice;
    }
}

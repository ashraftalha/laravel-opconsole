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

        return [
            'app' => [
                'environment' => app()->environment(),
                'production' => app()->isProduction(),
                'debug' => (bool) config('app.debug'),
            ],
            'database' => $database,
            'caches' => $caches,
            'checks' => $checks,
            'errors' => $errors,
            'info' => $this->info($database, $caches),
            'lastRuns' => $this->lastRuns(),
            'advice' => $this->advice($database, $caches, $errors),
        ];
    }

    /** @return list<string> migration names not run yet */
    public function pendingMigrations(): array
    {
        $files = array_keys($this->migrator->getMigrationFiles([database_path('migrations'), ...$this->migrator->paths()]));

        if (! $this->migrator->repositoryExists()) {
            return $files;
        }

        return array_values(array_diff($files, $this->migrator->getRepository()->getRan()));
    }

    private function database(): array
    {
        $connection = config('database.default');

        try {
            DB::connection()->getPdo();
            $pending = $this->pendingMigrations();
            $ran = $this->migrator->repositoryExists() ? count($this->migrator->getRepository()->getRan()) : 0;

            return ['ok' => true, 'driver' => $connection, 'name' => DB::connection()->getDatabaseName(), 'ran' => $ran, 'pending' => $pending, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'driver' => $connection, 'name' => null, 'ran' => 0, 'pending' => [], 'error' => $e->getMessage()];
        }
    }

    private function caches(): array
    {
        $views = glob(config('view.compiled').'/*.php') ?: [];

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
     * Latest finished run per action. Never throws: the audit table may not be
     * migrated yet, especially right before the "migrate" action is used to create it.
     *
     * @return array<string, ?array{at: string, by: string, ok: bool}>
     */
    private function lastRuns(): array
    {
        $actions = array_keys(array_filter(config('opconsole.actions', [])));

        try {
            $rows = OpConsoleRun::query()
                ->whereIn('action', $actions)
                ->whereIn('status', ['succeeded', 'failed'])
                ->latest('id')
                ->get(['action', 'status', 'user_label', 'finished_at'])
                ->unique('action')
                ->keyBy('action');
        } catch (Throwable) {
            $rows = collect();
        }

        return collect($actions)->mapWithKeys(fn ($action) => [
            $action => $rows->has($action) ? [
                'at' => $rows[$action]->finished_at?->toIso8601String(),
                'by' => $rows[$action]->user_label ?? 'Unknown',
                'ok' => $rows[$action]->status === 'succeeded',
            ] : null,
        ])->all();
    }

    /**
     * What an admin should do next, most important first.
     *
     * @return list<array{level: 'danger'|'warning'|'info', text: string, action: ?string}>
     */
    private function advice(array $database, array $caches, array $errors): array
    {
        $advice = [];

        if (! $database['ok']) {
            $advice[] = ['level' => 'danger', 'text' => 'Cannot connect to the database. Check DB_* settings in .env.', 'action' => null];
        } elseif ($database['pending']) {
            $advice[] = ['level' => 'warning', 'text' => 'There are '.count($database['pending']).' pending migrations. New features may require them.', 'action' => 'migrate'];
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

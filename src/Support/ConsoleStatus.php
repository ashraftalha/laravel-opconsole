<?php

namespace AshrafTalha\OpConsole\Support;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use Composer\InstalledVersions;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Throwable;

/** Everything the dashboard shows, gathered in one place. Pure read model. */
class ConsoleStatus
{
    private const STATUS_RANK = ['healthy' => 0, 'unknown' => 0, 'warning' => 1, 'critical' => 2];

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
        $health = $this->health($host, $database);
        $checks = $health['checks'];
        $errors = $this->logs->recentErrors();
        $audit = $this->audit();

        return [
            'app' => [
                'environment' => app()->environment(),
                'production' => app()->isProduction(),
                'debug' => (bool) config('app.debug'),
                'down' => app()->isDownForMaintenance(),
            ],
            'database' => $database,
            'checks' => $checks,
            'checkedAt' => $health['checked_at'],
            'errors' => $errors,
            'info' => $this->info($database, $caches),
            'lastRuns' => $audit['lastRuns'],
            'history' => $audit['history'],
            'advice' => $this->advice($database, $caches, $errors, $checks),
        ];
    }

    /**
     * The single health runner shared by the dashboard, `opconsole:health`, and the JSON endpoint.
     *
     * @return array{status: 'healthy'|'warning'|'critical', checked_at: string, checks: list<array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}>}
     */
    public function health(?string $host = null, ?array $database = null): array
    {
        $checks = $this->healthChecks->run($database ?? $this->database(), $host);
        $required = config('opconsole.health.required_checks', []);
        $required = is_array($required) ? $required : [];
        $unknownPolicy = config('opconsole.health.unknown_policy', 'healthy');
        if (! in_array($unknownPolicy, ['healthy', 'warning', 'critical'], true)) {
            $unknownPolicy = 'healthy';
        }
        foreach ($checks as &$check) {
            if ($check['status'] !== 'unknown') {
                continue;
            }
            $policy = in_array($check['id'], $required, true) ? 'critical' : $unknownPolicy;
            if ($policy !== 'healthy') {
                $check['status'] = $policy;
                $check['ok'] = false;
            }
        }
        unset($check);

        // Custom checks always run, so a required custom ID missing from the report means the check
        // threw (its fallback row carries a class-derived ID) or is misnamed: never report that as healthy.
        // Built-in IDs are exempt because several only appear conditionally (e.g. database.connection).
        $reported = array_column($checks, 'id');
        foreach ($required as $id) {
            if (is_string($id) && str_starts_with($id, 'custom.') && ! in_array($id, $reported, true)) {
                $checks[] = [
                    'id' => $id,
                    'group' => 'Custom checks',
                    'label' => 'Required check '.$id,
                    'ok' => false,
                    'status' => 'critical',
                    'severity' => 'danger',
                    'hint' => 'This required check did not report a result; it failed to run or is not registered under this ID.',
                    'value' => 'not reported',
                    'fix' => 'Check config(\'opconsole.checks\') and the application log',
                ];
            }
        }

        return [
            'status' => self::overallStatus($checks),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * Worst check status; unknown never degrades the result because nothing failed verification.
     *
     * @param  list<array{status: string}>  $checks
     * @return 'healthy'|'warning'|'critical'
     */
    public static function overallStatus(array $checks): string
    {
        $worst = max([0, ...array_map(fn (array $check) => self::rank($check['status']), $checks)]);

        return match ($worst) {
            2 => 'critical',
            1 => 'warning',
            default => 'healthy',
        };
    }

    public static function rank(string $status): int
    {
        return self::STATUS_RANK[$status] ?? 0;
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
    /** @return array<string, array<string, string>> An "Octane" group, or nothing when Octane is not installed. */
    private function octaneInfo(): array
    {
        $runtime = app(Runtime::class);
        if (! $runtime->octaneInstalled()) {
            return [];
        }

        $server = $runtime->octaneServer() ?? 'not set';
        $running = $runtime->servingViaOctane() ? true : $runtime->octaneServerRunning();
        $options = config('octane.swoole.options', []);
        $options = is_array($options) ? $options : [];
        $swoole = null;
        foreach (['swoole', 'openswoole'] as $extension) {
            if ($swoole === null && $runtime->extensionLoaded($extension)) {
                $swoole = $extension.' '.($runtime->extensionVersion($extension) ?? '');
            }
        }

        return ['Octane' => array_filter([
            'Version' => class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('laravel/octane')
                ? (string) InstalledVersions::getPrettyVersion('laravel/octane')
                : 'installed',
            'Server' => $server,
            'Server process' => match ($running) {
                true => 'Running', false => 'Stopped', default => 'Unknown'
            },
            'This request' => $runtime->servingViaOctane() ? 'Served by Octane' : 'Served by '.PHP_SAPI,
            'Swoole extension' => $server === 'swoole' ? ($swoole ?? 'Not loaded') : null,
            'Workers' => $server === 'swoole' ? (isset($options['worker_num']) ? (string) $options['worker_num'] : 'auto') : null,
            'Garbage threshold' => (int) config('octane.garbage', 50).' MB',
            'Max execution time' => ((int) config('octane.max_execution_time', 30)) ? (int) config('octane.max_execution_time', 30).'s' : 'Unlimited',
            'HTTPS' => config('octane.https', false) ? 'On' : 'Off',
        ], fn ($value) => $value !== null)];
    }

    private function info(array $database, array $caches): array
    {
        $yes = fn (bool $value) => $value ? 'Yes' : 'No';
        $disk = [@disk_free_space(storage_path()), @disk_total_space(storage_path())];
        $ini = fn (string $key, string $fallback = '—') => ($value = ini_get($key)) === false || $value === '' ? $fallback : (string) $value;
        $on = fn (string $key) => filter_var(ini_get($key), FILTER_VALIDATE_BOOLEAN) ? 'On' : 'Off';

        $opcache = 'Not installed';
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            $opcache = is_array($status) && ($status['opcache_enabled'] ?? false)
                ? 'Enabled · '.Number::fileSize((int) ($status['memory_usage']['used_memory'] ?? 0), 1).' used'
                : 'Installed, disabled for '.PHP_SAPI;
        }
        $jit = function_exists('opcache_get_status') && is_array($jitStatus = @opcache_get_status(false)) && ($jitStatus['jit']['on'] ?? false)
            ? 'On · '.$ini('opcache.jit', 'tracing')
            : 'Off';

        $extensions = ['intl', 'mbstring', 'bcmath', 'gd', 'imagick', 'redis', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'pcntl', 'posix', 'sodium', 'zip', 'curl', 'openssl', 'fileinfo', 'exif'];
        $loaded = array_values(array_filter($extensions, 'extension_loaded'));
        $missing = array_values(array_diff($extensions, $loaded));

        $serverVersion = '—';
        if ($database['ok']) {
            try {
                $serverVersion = (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            } catch (Throwable) {
                $serverVersion = 'Unavailable';
            }
        }

        $packages = class_exists(InstalledVersions::class)
            ? count(InstalledVersions::getInstalledPackages())
            : null;

        return [
            'Application' => [
                'Laravel' => app()->version(),
                'Environment' => app()->environment(),
                'URL' => config('app.url'),
                'Timezone' => config('app.timezone'),
                'Locale' => app()->getLocale().' (fallback '.config('app.fallback_locale', '—').')',
                'Maintenance mode' => $yes(app()->isDownForMaintenance()),
                'Composer packages' => $packages === null ? '—' : $packages.' installed',
            ],
            'PHP' => [
                'Version' => PHP_VERSION,
                'SAPI' => PHP_SAPI,
                'memory_limit' => ini_get('memory_limit'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'max_execution_time' => (int) ini_get('max_execution_time') ? ini_get('max_execution_time').'s' : 'Unlimited',
                'max_input_vars' => $ini('max_input_vars'),
                'display_errors' => $on('display_errors'),
                'date.timezone' => $ini('date.timezone', 'not set'),
            ],
            'Performance' => [
                'OPcache' => $opcache,
                'JIT' => $jit,
                'realpath_cache_size' => $ini('realpath_cache_size'),
                'Xdebug' => extension_loaded('xdebug') ? 'Loaded' : 'Not loaded',
                'Peak memory (this request)' => Number::fileSize(memory_get_peak_usage(true), 1),
            ],
            'PHP extensions' => [
                'Loaded' => $loaded ? implode(', ', $loaded) : 'none of the common ones',
                'Not loaded' => $missing ? implode(', ', $missing) : 'none',
            ],
            'Server' => [
                'Operating system' => PHP_OS_FAMILY.' '.php_uname('r'),
                'Architecture' => php_uname('m'),
                'Web server' => request()->server('SERVER_SOFTWARE') ?: PHP_SAPI,
                'Disk space' => $disk[0] && $disk[1] ? Number::fileSize($disk[0], 1).' available of '.Number::fileSize($disk[1], 1) : '—',
                'Log size' => Number::fileSize($this->logs->totalSize(), 1),
            ],
            'Database' => [
                'Connection' => $database['driver'].($database['name'] ? ' · '.$database['name'] : ''),
                'Driver' => (string) config('database.connections.'.config('database.default').'.driver', '—'),
                'Server version' => $serverVersion,
                'Migrations applied' => (string) $database['ran'],
                'Pending migrations' => (string) count($database['pending'] ?? []),
            ],
            'Services' => [
                'Cache' => $caches['store'],
                'Action lock' => (string) config('opconsole.concurrency.lock_store', 'opconsole'),
                'Sessions' => config('session.driver').' · '.config('session.lifetime', '—').' min',
                'Queues' => config('queue.default'),
                'Failed jobs store' => (string) (config('queue.failed.driver') ?? 'null'),
                'Mail' => config('mail.default'),
                'Filesystem disk' => (string) config('filesystems.default', '—'),
                'Log channel' => (string) config('logging.default', '—'),
                'Broadcasting' => (string) config('broadcasting.default', '—'),
            ],
            ...$this->octaneInfo(),
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
        $leaseSeconds = max(
            5,
            (int) config('opconsole.concurrency.lock_seconds', 1800),
            max(5, (int) config('opconsole.concurrency.max_runtime_seconds', 1500)) + 60,
        );
        $staleSeconds = $leaseSeconds * 2;
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
                ->limit(min(100, max(1, (int) config('opconsole.history_limit', 15))))
                ->get(); // all columns: duration_ms may not exist until the host migrates

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

            // Second-precision timestamps only bound older rows recorded before duration_ms existed.
            $durationMs = $row->duration_ms !== null
                ? max(0, (int) $row->duration_ms)
                : (($startedAt !== null && $finishedAt !== null)
                    ? max(0, (int) round($startedAt->diffInMilliseconds($finishedAt)))
                    : null);

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

        if (app()->isDownForMaintenance()) {
            $advice[] = ['level' => 'danger', 'text' => 'The application is in maintenance mode: visitors receive a 503 page.', 'action' => 'up'];
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

<?php

namespace AshrafTalha\OpConsole\Support;

use AshrafTalha\OpConsole\Contracts\HealthCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Generic, host-agnostic health checks grouped by area. Application-specific checks
 * (third-party service probes, custom queues, domain invariants, ...) do not belong here:
 * register them via config('opconsole.checks') instead.
 */
class HealthChecks
{
    /** Hosts that all mean "this machine", so localhost vs 127.0.0.1 is not a mismatch. */
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1', '[::1]'];

    private const STATUSES = ['healthy', 'warning', 'critical', 'unknown'];

    private const WORKERS_GROUP = 'Scheduler and workers';

    private const CACHE_GROUP = 'Cache and performance';

    private ActionRunner $actionRunner;

    private Heartbeat $heartbeat;

    public function __construct(?ActionRunner $actionRunner = null, ?Heartbeat $heartbeat = null)
    {
        $this->actionRunner = $actionRunner ?? app(ActionRunner::class);
        $this->heartbeat = $heartbeat ?? app(Heartbeat::class);
    }

    /**
     * @return list<array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}>
     */
    public function run(array $database, ?string $host): array
    {
        $builtIn = $this->builtIn($database, $host);

        return [...$builtIn, ...$this->custom($builtIn)];
    }

    /**
     * @return list<array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}>
     */
    private function builtIn(array $database, ?string $host): array
    {
        $production = app()->isProduction();
        $memory = $this->iniBytes('memory_limit');
        $driver = (string) (config('database.connections.'.$database['driver'].'.driver') ?? $database['driver']);
        $pdoExtension = match ($driver) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'sqlsrv' => 'pdo_sqlsrv',
            default => null,
        };
        $extensions = array_filter(['mbstring', 'openssl', 'tokenizer', 'ctype', 'json', $pdoExtension]);
        $missing = array_values(array_filter($extensions, fn ($extension) => ! extension_loaded($extension)));
        $appUrl = (string) config('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        ['free' => $free, 'total' => $total] = $this->diskSpace();
        $logLevel = $this->effectiveLogLevel();
        $lockValidation = $this->actionRunner->validateLockStore();
        $cacheStore = (string) config('cache.default');
        $cacheDriver = (string) (config("cache.stores.{$cacheStore}.driver") ?? $cacheStore);
        $sessionDriver = (string) config('session.driver');
        $ephemeralDrivers = ['array', 'null'];
        $ephemeralInProd = $production && (in_array($cacheDriver, $ephemeralDrivers, true) || in_array($sessionDriver, $ephemeralDrivers, true));
        $dependencyAudit = app(SecurityScanner::class)->report();
        $dependencyStatus = ($dependencyAudit['status'] ?? '') !== 'complete' || ! empty($dependencyAudit['stale'])
            ? 'unknown'
            : (! empty($dependencyAudit['advisories']) ? 'critical' : 'healthy');

        $check = fn (
            string $id,
            string $group,
            string $label,
            bool $ok,
            string $severity,
            string $hint,
            ?string $value = null,
            ?string $fix = null,
            ?string $status = null,
        ) => $this->normalizeCheck([
            'id' => $id,
            'group' => $group,
            'label' => $label,
            'ok' => $ok,
            'severity' => $severity,
            'hint' => $hint,
            'value' => $value,
            'fix' => $fix,
            'status' => $status,
        ]);

        if ($free === false) {
            $diskCheck = $check(
                'files.disk_space',
                'Files and folders',
                'Sufficient disk space',
                true,
                'info',
                'Disk free space could not be queried in this PHP environment.',
                'Unavailable',
                null,
                'unknown',
            );
        } elseif ($free < 256 * 1024 ** 2) {
            $diskCheck = $check(
                'files.disk_space',
                'Files and folders',
                'Sufficient disk space',
                false,
                'danger',
                'Critically low free disk space (< 256 MB); logging and caching may fail.',
                Number::fileSize($free, 1).' free',
                'Rotate or archive old logs and temporary files',
                'critical',
            );
        } else {
            $minPercent = max(0.0, min(100.0, (float) config('opconsole.disk.min_free_percent', 10)));
            $percent = $total !== false && $total > 0 ? $free / $total * 100 : null;
            $lowAbsolute = $free < 1024 ** 3;
            $lowPercent = $percent !== null && $percent < $minPercent;

            $diskCheck = $check(
                'files.disk_space',
                'Files and folders',
                'Sufficient disk space',
                ! $lowAbsolute && ! $lowPercent,
                'warning',
                'Warns when less than 1 GB or '.rtrim(rtrim(number_format($minPercent, 1), '0'), '.').'% of the volume is free.',
                Number::fileSize($free, 1).' free'.($percent !== null ? ' ('.round($percent, 1).'%)' : ''),
                ($lowAbsolute || $lowPercent) ? 'Rotate or archive old logs and temporary files' : null,
            );
        }

        $opcacheEnabled = extension_loaded('Zend OPcache') && (bool) ini_get('opcache.enable');

        $checks = [
            // Files and folders
            $check('files.storage_writable', 'Files and folders', 'Storage directory is writable', is_writable(storage_path()), 'danger', 'Stores logs, cache, and compiled views.', 'storage/', 'chmod -R ug+rw storage'),
            $check('files.bootstrap_cache_writable', 'Files and folders', 'Bootstrap cache is writable', is_writable(base_path('bootstrap/cache')), 'danger', 'Required to optimize performance.', 'bootstrap/cache/', 'chmod -R ug+rw bootstrap/cache'),
            $diskCheck,
            ...$this->storageLinkCheck($check),

            // PHP
            $check('php.version', 'PHP settings', 'Supported PHP version', version_compare(PHP_VERSION, '8.3.0', '>='), 'danger', 'This application requires PHP 8.3 or later.', 'PHP '.PHP_VERSION),
            $check('php.extensions', 'PHP settings', 'Required extensions installed', ! $missing, 'danger', $missing ? 'Missing required extensions.' : 'Framework and database driver extensions.', implode(', ', $missing ?: $extensions), $missing ? 'Install PHP extensions: '.implode(', ', $missing) : null),
            $check('php.memory_limit', 'PHP settings', 'Sufficient memory', $memory === -1 || $memory >= 128 * 1024 ** 2, 'warning', 'Most applications need at least 128 MB.', 'memory_limit='.ini_get('memory_limit'), 'php.ini: memory_limit=256M'),
            $check('php.opcache', 'PHP settings', 'OPcache enabled in production', ! $production || $opcacheEnabled, 'info', 'Improves PHP bytecode execution performance on web servers.', 'opcache.enable='.(ini_get('opcache.enable') ?: '0'), 'php.ini: opcache.enable=1'),

            // Security
            $check('security.dependency_audit', 'Security', 'Host dependencies audited', $dependencyStatus === 'healthy', 'danger', match ($dependencyStatus) {
                'healthy' => 'No known vulnerabilities found in the latest fresh Composer lock audit.',
                'critical' => count($dependencyAudit['advisories']).' active dependency advisory record(s) require review.',
                default => 'Dependency audit is not complete or its report is stale; Composer has not been run during this dashboard request.',
            }, $dependencyAudit['scanned_at'] ?? ($dependencyAudit['status'] ?? 'Never scanned'), $dependencyStatus === 'healthy' ? null : 'php artisan opconsole:security', $dependencyStatus),
            $check('security.app_key', 'Security', 'Application key configured', filled(config('app.key')), 'danger', 'Required for encryption, signed URLs, and sessions.', null, 'php artisan key:generate'),
            $check('security.debug_mode', 'Security', 'Debug mode disabled in production', ! ($production && config('app.debug')), 'danger', 'Debug mode exposes stack traces and configuration details to visitors.', 'APP_DEBUG='.(config('app.debug') ? 'true' : 'false'), '.env: APP_DEBUG=false'),
            $check('security.https', 'Security', 'HTTPS in production', ! $production || str_starts_with($appUrl, 'https://') || (bool) config('opconsole.security.trusted_proxy_https', false), 'warning', 'Sensitive requests should use encrypted HTTPS. Behind a TLS-terminating proxy, configure Laravel trusted proxies and explicitly confirm the edge enforces HTTPS.', 'APP_URL='.$appUrl, '.env: APP_URL=https://…'),
            $check('security.session_secure', 'Security', 'Secure session cookies', ! $production || ! str_starts_with($appUrl, 'https://') || (bool) config('session.secure'), 'warning', 'HTTPS session cookies should set the Secure attribute.', 'SESSION_SECURE_COOKIE='.(config('session.secure') ? 'true' : 'false'), '.env: SESSION_SECURE_COOKIE=true'),
            $check('security.session_httponly', 'Security', 'HttpOnly session cookies', (bool) config('session.http_only'), 'warning', 'HttpOnly prevents browser scripts from reading the session cookie.', 'SESSION_HTTP_ONLY='.(config('session.http_only') ? 'true' : 'false'), '.env: SESSION_HTTP_ONLY=true'),
            $check('security.session_samesite', 'Security', 'Session SameSite policy', in_array(strtolower((string) config('session.same_site')), ['lax', 'strict'], true), 'warning', 'SameSite=Lax or Strict helps reduce cross-site request forgery exposure.', 'SESSION_SAME_SITE='.config('session.same_site'), '.env: SESSION_SAME_SITE=lax'),
            $check('security.log_level', 'Security', 'Production log level', ! $production || $logLevel !== 'debug', 'info', 'Debug logging increases disk usage and sensitive payload exposure in production.', 'LOG_LEVEL='.$logLevel, '.env: LOG_LEVEL=warning'),
            $check(
                'security.lock_store',
                'Security',
                'Action lock store isolation',
                $lockValidation['ok'],
                'danger',
                $lockValidation['ok']
                    ? ($lockValidation['distributed'] ? 'Isolated distributed atomic lock store.' : 'Isolated atomic lock store (single-server filesystem scope).')
                    : ($lockValidation['reason'] ?? 'Action lock store is unsafe.'),
                'store='.$lockValidation['store'].($lockValidation['driver'] ? ' ('.$lockValidation['driver'].')' : ''),
                $lockValidation['ok'] ? null : '.env: OPCONSOLE_LOCK_STORE=opconsole',
            ),
            $check(
                'security.cache_session_persistence',
                'Security',
                'Persistent cache and session drivers',
                ! $ephemeralInProd,
                'danger',
                $ephemeralInProd ? 'Array and null drivers discard cache or session state at the end of every request.' : 'Configured cache and session drivers match environment requirements.',
                'cache='.$cacheDriver.', session='.$sessionDriver,
                $ephemeralInProd ? '.env: CACHE_STORE=database SESSION_DRIVER=database' : null,
            ),

            // Database & queues
            ...$this->databaseChecks($database, $production, $check),

            // Scheduler and workers
            ...$this->workerChecks($production, $check),

            // Cache and performance
            $this->cacheRoundTripCheck($production, $check),
            $this->cachesBuiltCheck($production, $check),

            // URL & email
            $check('mail.delivery', 'URL and email', 'Email delivery enabled in production', ! $production || ! in_array(config('mail.default'), ['log', 'array'], true), 'warning', 'Log and array mailers do not deliver external email (SMTP transport is not probed).', 'MAIL_MAILER='.config('mail.default'), '.env: MAIL_MAILER=smtp'),
            $check('app.maintenance_mode', 'URL and email', 'Application is online', ! app()->isDownForMaintenance(), 'warning', 'Maintenance mode returns 503 to every visitor except bypass-cookie holders.', app()->isDownForMaintenance() ? 'down for maintenance' : 'online', 'php artisan up'),
            $check('app.timezone', 'URL and email', 'Application timezone is valid', in_array((string) config('app.timezone'), timezone_identifiers_list(), true), 'warning', 'Valid IANA timezone identifier for timestamps and scheduled tasks.', 'APP_TIMEZONE='.config('app.timezone'), 'config/app.php: timezone=UTC'),
        ];

        if ($host !== null && $appHost) {
            $same = strcasecmp($host, $appHost) === 0 || (in_array(strtolower($host), self::LOOPBACK, true) && in_array(strtolower($appHost), self::LOOPBACK, true));
            $checks[] = $check('app.url_host', 'URL and email', 'Application URL matches current host', $same, 'warning', 'A host mismatch can generate signed URLs and links with the wrong domain.', 'APP_URL='.$appHost.' ← '.$host, '.env: APP_URL=https://'.$host);
        }

        return $checks;
    }

    /**
     * Session/cache/queue tables the configured drivers need, plus stuck or failed background jobs.
     *
     * @param  \Closure(string, string, string, bool, string, string, ?string=, ?string=, ?string=): array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}  $check
     * @return list<array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}>
     */
    private function databaseChecks(array $database, bool $production, \Closure $check): array
    {
        $tableCache = [];
        $unavailableConnections = [];
        $hasTable = function (string $table, ?string $connection = null) use (&$tableCache, &$unavailableConnections): bool {
            $key = ($connection ?? 'default').':'.$table;
            try {
                return $tableCache[$key] ??= DB::connection($connection)->getSchemaBuilder()->hasTable($table);
            } catch (\Throwable) {
                $unavailableConnections[$connection ?? 'default'] = true;

                return $tableCache[$key] = false;
            }
        };

        $sessionConnection = config('session.connection');
        $cacheStore = (string) config('cache.default');
        $cacheConfig = config("cache.stores.{$cacheStore}", []);
        $cacheDriver = is_array($cacheConfig) ? (string) ($cacheConfig['driver'] ?? $cacheStore) : $cacheStore;
        $cacheConnection = is_array($cacheConfig) ? ($cacheConfig['connection'] ?? null) : null;
        $queueConnectionName = (string) config('queue.default');
        $queueConfig = config("queue.connections.{$queueConnectionName}", []);
        $queueDriver = is_array($queueConfig) ? (string) ($queueConfig['driver'] ?? $queueConnectionName) : $queueConnectionName;
        $queueConnection = is_array($queueConfig) ? ($queueConfig['connection'] ?? null) : null;
        $failedConfig = config('queue.failed', []);
        $sessionTable = (string) config('session.table', 'sessions');
        $cacheTable = (string) (is_array($cacheConfig) ? ($cacheConfig['table'] ?? 'cache') : 'cache');
        $cacheLockTable = (string) (is_array($cacheConfig) ? ($cacheConfig['lock_table'] ?? 'cache_locks') : 'cache_locks');
        $cacheLockConnection = is_array($cacheConfig) ? ($cacheConfig['lock_connection'] ?? $cacheConnection) : $cacheConnection;
        $actionLockStore = (string) config('opconsole.concurrency.lock_store', 'opconsole');
        $actionLockConfig = config("cache.stores.{$actionLockStore}", []);
        $jobsTable = (string) (is_array($queueConfig) ? ($queueConfig['table'] ?? 'jobs') : 'jobs');
        $failedDriver = config('queue.failed.driver');
        $failedTable = (string) (is_array($failedConfig) ? ($failedConfig['table'] ?? 'failed_jobs') : 'failed_jobs');
        $failedConnection = is_array($failedConfig) ? ($failedConfig['database'] ?? null) : null;
        $usesDbFailedJobs = in_array($failedDriver, ['database', 'database-uuids'], true);
        $requirements = [];
        if (config('session.driver') === 'database') {
            $requirements[] = [$sessionTable, $sessionConnection];
        }
        if ($cacheDriver === 'database') {
            $requirements[] = [$cacheTable, $cacheConnection];
        }
        if ($cacheDriver === 'database') {
            $requirements[] = [$cacheLockTable, $cacheLockConnection];
        }
        if (is_array($actionLockConfig) && ($actionLockConfig['driver'] ?? null) === 'database') {
            $requirements[] = [
                (string) ($actionLockConfig['lock_table'] ?? 'cache_locks'),
                $actionLockConfig['lock_connection'] ?? $actionLockConfig['connection'] ?? null,
            ];
        }
        if ($queueDriver === 'database') {
            $requirements[] = [$jobsTable, $queueConnection];
        }
        if ($usesDbFailedJobs) {
            $requirements[] = [$failedTable, $failedConnection];
        }
        $requiredTables = array_values(array_unique(array_column($requirements, 0)));
        $missingTables = [];
        $unverifiedTables = false;
        foreach ($requirements as [$table, $connection]) {
            if (! $hasTable($table, $connection)) {
                $missingTables[] = $table;
                $unverifiedTables = $unverifiedTables || isset($unavailableConnections[$connection ?? 'default']);
            }
        }
        $missingTables = array_values(array_unique($missingTables));

        $tablesCheck = $check(
            'database.required_tables',
            'Database and queues',
            'Session, cache, and queue tables',
            ! $missingTables,
            'danger',
            $missingTables
                ? 'Tables required by the active database drivers are missing.'
                : ($requiredTables ? 'All tables required by active drivers exist.' : 'Current drivers do not require extra framework tables.'),
            $missingTables ? implode(', ', $missingTables) : ($requiredTables ? implode(', ', $requiredTables) : 'none required'),
            $missingTables ? 'php artisan migrate' : null,
            $unverifiedTables ? 'unknown' : null,
        );

        // Stuck queue jobs: never report external brokers (Redis/SQS) as "0 stuck" when uninspected.
        if ($queueDriver === 'database') {
            if ($hasTable($jobsTable, $queueConnection)) {
                try {
                    $stuck = DB::connection($queueConnection)->table($jobsTable)->where('created_at', '<', now()->subHour()->getTimestamp())->count();
                    $stuckCheck = $check(
                        'queue.stuck_jobs',
                        'Database and queues',
                        'No stuck queue jobs',
                        $stuck === 0,
                        'warning',
                        'Jobs waiting over an hour usually indicate the queue worker is stopped.',
                        $stuck.' stuck',
                        $stuck > 0 ? 'php artisan queue:work' : null,
                    );
                } catch (\Throwable $e) {
                    $stuckCheck = $check('queue.stuck_jobs', 'Database and queues', 'No stuck queue jobs', false, 'info', 'The jobs table could not be queried.', ActionRunner::sanitizeText($e->getMessage()), null, 'unknown');
                }
            } else {
                $stuckCheck = $check(
                    'queue.stuck_jobs',
                    'Database and queues',
                    'No stuck queue jobs',
                    true,
                    'info',
                    'Queue backlog cannot be inspected until the jobs table is migrated.',
                    $jobsTable.' missing',
                    'php artisan migrate',
                    'unknown',
                );
            }
        } elseif ($queueDriver === 'null' && $production) {
            $stuckCheck = $check(
                'queue.stuck_jobs',
                'Database and queues',
                'No stuck queue jobs',
                false,
                'warning',
                'The null queue driver discards dispatched background jobs.',
                'QUEUE_CONNECTION=null',
                '.env: QUEUE_CONNECTION=database',
            );
        } elseif ($queueDriver === 'sync' || $queueDriver === 'null') {
            $stuckCheck = $check(
                'queue.stuck_jobs',
                'Database and queues',
                'No stuck queue jobs',
                true,
                'info',
                'Jobs run synchronously inside requests; no background queue backlog applies.',
                'QUEUE_CONNECTION='.$queueDriver,
            );
        } else {
            $stuckCheck = $check(
                'queue.stuck_jobs',
                'Database and queues',
                'No stuck queue jobs',
                true,
                'info',
                'External queue broker backlog is not probed during web requests.',
                'QUEUE_CONNECTION='.$queueDriver.' (unverified)',
                null,
                'unknown',
            );
        }

        // Failed jobs: distinguish verified 0 from unmigrated/non-database failed-job storage.
        if ($usesDbFailedJobs && $hasTable($failedTable, $failedConnection)) {
            try {
                $failed = DB::connection($failedConnection)->table($failedTable)->count();
                $failedCheck = $check(
                    'queue.failed_jobs',
                    'Database and queues',
                    'No failed jobs',
                    $failed === 0,
                    'warning',
                    'Background jobs that failed permanently.',
                    $failed.' failed',
                    $failed > 0 ? 'php artisan queue:retry all' : null,
                );
            } catch (\Throwable $e) {
                $failedCheck = $check('queue.failed_jobs', 'Database and queues', 'No failed jobs', false, 'info', 'The failed-jobs table could not be queried.', ActionRunner::sanitizeText($e->getMessage()), null, 'unknown');
            }
        } elseif ($usesDbFailedJobs) {
            $failedCheck = $check(
                'queue.failed_jobs',
                'Database and queues',
                'No failed jobs',
                true,
                'info',
                'Failed-jobs table is not migrated yet; count cannot be verified.',
                $failedTable.' missing',
                'php artisan migrate',
                'unknown',
            );
        } else {
            $failedCheck = $check(
                'queue.failed_jobs',
                'Database and queues',
                'No failed jobs',
                true,
                'info',
                'Failed-job tracking is disabled or uses a non-database driver.',
                'driver='.($failedDriver ?: 'none'),
                null,
                'unknown',
            );
        }

        $connectionCheck = $database['ok']
            ? []
            : [$check('database.connection', 'Database and queues', 'Database connection', false, 'danger', 'Connection failed; database-backed features cannot operate.', (string) $database['driver'], '.env: DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD')];

        return [...$connectionCheck, $tablesCheck, $stuckCheck, $failedCheck];
    }

    /**
     * Heartbeats written by the scheduler (and the queue job it dispatches): proves they actually run.
     *
     * @param  \Closure(string, string, string, bool, string, string, ?string=, ?string=, ?string=): array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}  $check
     * @return list<array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}>
     */
    private function workerChecks(bool $production, \Closure $check): array
    {
        $checks = [];
        $schedulerFresh = false;

        if (config('opconsole.heartbeats.scheduler', true)) {
            $schedulerCheck = $this->schedulerCheck($production, $check);
            $schedulerFresh = $schedulerCheck['status'] === 'healthy';
            $checks[] = $schedulerCheck;
        }

        if (config('opconsole.heartbeats.queue', true)) {
            $checks[] = $this->queueWorkerCheck($production, $schedulerFresh, $check);
        }

        return $checks;
    }

    /**
     * @param  \Closure(string, string, string, bool, string, string, ?string=, ?string=, ?string=): array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}  $check
     * @return array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}
     */
    private function schedulerCheck(bool $production, \Closure $check): array
    {
        $label = 'Scheduler is running';
        $fix = app()->environment('local') ? 'php artisan schedule:work' : '* * * * * cd /path && php artisan schedule:run';
        $beat = $this->heartbeat->last(Heartbeat::SCHEDULER);

        if ($beat['error'] !== null) {
            return $check('scheduler.heartbeat', self::WORKERS_GROUP, $label, true, 'info', 'Heartbeat store could not be read.', 'store='.$this->heartbeat->storeName(), null, 'unknown');
        }

        if ($beat['at'] === null) {
            return $check('scheduler.heartbeat', self::WORKERS_GROUP, $label, true, 'info', 'Scheduler has not run yet.', 'no heartbeat', $fix, 'unknown');
        }

        $maxAge = max(60, (int) config('opconsole.heartbeats.scheduler_max_age', 120));
        $fresh = now()->getTimestamp() - $beat['at'] <= $maxAge;

        return $check(
            'scheduler.heartbeat',
            self::WORKERS_GROUP,
            $label,
            $fresh,
            $production ? 'danger' : 'warning',
            'schedule:run should fire every minute; checked through a cache heartbeat.',
            'last run '.$this->ago($beat['at']),
            $fresh ? null : $fix,
        );
    }

    /**
     * Driver-agnostic (works for Redis/SQS too): a worker processed the job the scheduler dispatched.
     *
     * @param  \Closure(string, string, string, bool, string, string, ?string=, ?string=, ?string=): array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}  $check
     * @return array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}
     */
    private function queueWorkerCheck(bool $production, bool $schedulerFresh, \Closure $check): array
    {
        $label = 'Queue worker is processing jobs';
        $connection = $this->heartbeat->queueConnection();
        $driver = (string) (config("queue.connections.{$connection}.driver") ?? $connection);

        if (in_array($driver, ['sync', 'null'], true)) {
            return $check('queue.worker_heartbeat', self::WORKERS_GROUP, $label, true, 'info', 'The '.$driver.' queue driver does not use a background worker.', 'connection='.$connection.' ('.$driver.')');
        }

        $queueName = $this->heartbeat->queueName();
        $fix = 'php artisan queue:work'.($connection !== config('queue.default') ? ' '.$connection : '').($queueName !== null ? ' --queue='.$queueName : '');
        $beat = $this->heartbeat->last(Heartbeat::QUEUE);
        $dispatched = $this->heartbeat->last(Heartbeat::QUEUE_DISPATCHED);

        if ($beat['error'] !== null || $dispatched['error'] !== null) {
            return $check('queue.worker_heartbeat', self::WORKERS_GROUP, $label, true, 'info', 'Heartbeat store could not be read.', 'store='.$this->heartbeat->storeName(), null, 'unknown');
        }

        $maxAge = max(60, (int) config('opconsole.heartbeats.queue_max_age', 300));
        $now = now()->getTimestamp();

        if ($beat['at'] !== null && $now - $beat['at'] <= $maxAge) {
            return $check('queue.worker_heartbeat', self::WORKERS_GROUP, $label, true, 'warning', 'A worker processed the heartbeat job the scheduler dispatches every minute.', 'last processed '.$this->ago($beat['at']));
        }

        if (! $schedulerFresh) {
            return $check('queue.worker_heartbeat', self::WORKERS_GROUP, $label, true, 'info', 'Heartbeat jobs are dispatched by the scheduler, which is not confirmed running.', $beat['at'] !== null ? 'last processed '.$this->ago($beat['at']) : 'no heartbeat', null, 'unknown');
        }

        if ($beat['at'] === null && ($dispatched['at'] === null || $now - $dispatched['at'] <= $maxAge)) {
            return $check('queue.worker_heartbeat', self::WORKERS_GROUP, $label, true, 'info', 'Waiting for a worker to process the first heartbeat job.', 'no heartbeat yet', $fix, 'unknown');
        }

        return $check(
            'queue.worker_heartbeat',
            self::WORKERS_GROUP,
            $label,
            false,
            $production ? 'danger' : 'warning',
            'Heartbeat jobs are being dispatched but no worker has processed one recently.',
            $beat['at'] !== null ? 'last processed '.$this->ago($beat['at']) : 'never processed',
            $fix,
        );
    }

    /**
     * Writes, reads back, and forgets a random value on cache.default; never throws.
     *
     * @param  \Closure(string, string, string, bool, string, string, ?string=, ?string=, ?string=): array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}  $check
     * @return array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}
     */
    private function cacheRoundTripCheck(bool $production, \Closure $check): array
    {
        $label = 'Cache read/write works';
        $store = (string) config('cache.default');
        $driver = (string) (config("cache.stores.{$store}.driver") ?? $store);

        if (! $production && in_array($driver, ['array', 'null'], true)) {
            return $check('cache.round_trip', self::CACHE_GROUP, $label, true, 'info', 'The '.$driver.' cache driver keeps nothing between requests; round trip skipped outside production.', 'store='.$store.' ('.$driver.')');
        }

        $key = 'opconsole:probe:'.Str::random(16);
        $value = Str::random(32);
        $started = hrtime(true);

        try {
            $repository = Cache::store($store);
            $repository->put($key, $value, 10);
            $read = $repository->get($key);
            $repository->forget($key);
            $ok = $read === $value;
            $hint = $ok ? 'A random value was written, read back, and removed.' : 'The value read back did not match what was written.';
        } catch (\Throwable) {
            $ok = false;
            $hint = 'The cache store threw an exception during the write/read test.';
        }

        $ms = (int) round((hrtime(true) - $started) / 1_000_000);

        return $check('cache.round_trip', self::CACHE_GROUP, $label, $ok, 'danger', $hint, 'store='.$store.' · '.$ms.' ms', $ok ? null : 'Check the cache backend, or .env: CACHE_STORE=database');
    }

    /**
     * @param  \Closure(string, string, string, bool, string, string, ?string=, ?string=, ?string=): array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}  $check
     * @return array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}
     */
    private function cachesBuiltCheck(bool $production, \Closure $check): array
    {
        $label = 'Framework caches built in production';

        if (! $production) {
            return $check('performance.caches_built', self::CACHE_GROUP, $label, true, 'info', 'Only applies in production; caching in development hides .env and route changes.', 'not production');
        }

        $missing = array_keys(array_filter([
            'config' => ! app()->configurationIsCached(),
            'routes' => ! app()->routesAreCached(),
            'events' => ! app()->eventsAreCached(),
        ]));

        return $check(
            'performance.caches_built',
            self::CACHE_GROUP,
            $label,
            $missing === [],
            'warning',
            'Cached configuration, routes, and events avoid rebuilding them on every request.',
            $missing === [] ? 'config, routes, events cached' : 'not cached: '.implode(', ', $missing),
            $missing === [] ? null : 'php artisan optimize',
        );
    }

    /**
     * Only when a "public" disk exists. realpath() comparison covers symlinks and Windows junctions alike.
     *
     * @param  \Closure(string, string, string, bool, string, string, ?string=, ?string=, ?string=): array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}  $check
     * @return list<array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}>
     */
    private function storageLinkCheck(\Closure $check): array
    {
        if (! is_array(config('filesystems.disks.public'))) {
            return [];
        }

        $label = 'Public storage link';
        $fix = 'php artisan storage:link';

        try {
            $link = public_path('storage');
            $links = config('filesystems.links', []);
            $expected = is_array($links) && isset($links[$link]) && is_string($links[$link]) ? $links[$link] : storage_path('app/public');

            if (! is_link($link) && ! file_exists($link)) {
                return [$check('files.storage_link', 'Files and folders', $label, false, 'warning', 'public/storage is missing, so files on the public disk are not web-accessible.', 'public/storage missing', $fix)];
            }

            $resolved = @realpath($link);
            $target = @realpath($expected);
            $ok = $resolved !== false && $target !== false && $resolved === $target;

            return [$check(
                'files.storage_link',
                'Files and folders',
                $label,
                $ok,
                'warning',
                $ok ? 'public/storage points to the public disk.' : 'public/storage exists but does not point to storage/app/public.',
                $ok ? 'public/storage → storage/app/public' : 'public/storage → '.($resolved !== false ? ActionRunner::sanitizeText($resolved) : 'broken link'),
                $ok ? null : $fix,
            )];
        } catch (\Throwable) {
            return [$check('files.storage_link', 'Files and folders', $label, true, 'info', 'The storage link could not be inspected.', null, null, 'unknown')];
        }
    }

    /**
     * Free and total bytes on the storage volume; false when PHP cannot query them.
     *
     * @return array{free: float|false, total: float|false}
     */
    protected function diskSpace(): array
    {
        return ['free' => @disk_free_space(storage_path()), 'total' => @disk_total_space(storage_path())];
    }

    private function ago(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp)->locale('en')->diffForHumans();
    }

    /**
     * Host-registered checks from config('opconsole.checks'); malformed, duplicate,
     * or core-colliding results are logged and skipped without breaking the dashboard.
     *
     * @param  list<array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}>  $builtIn
     * @return list<array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}>
     */
    private function custom(array $builtIn): array
    {
        $results = [];
        $seenClasses = [];
        $usedIds = [];
        $usedGroupLabels = [];

        foreach ($builtIn as $core) {
            $usedIds[$core['id']] = true;
            $usedGroupLabels[$this->groupLabelKey($core['group'], $core['label'])] = true;
        }

        $configured = config('opconsole.checks', []);
        if (! is_array($configured)) {
            return [];
        }

        foreach ($configured as $class) {
            if (! is_string($class) || trim($class) === '') {
                Log::warning('OpConsole: custom health check entry must be a class-string');

                continue;
            }

            if (isset($seenClasses[$class])) {
                Log::warning('OpConsole: duplicate custom health check skipped', ['class' => $class]);

                continue;
            }
            $seenClasses[$class] = true;

            try {
                $check = app($class);
                if (! $check instanceof HealthCheck) {
                    throw new \InvalidArgumentException($class.' does not implement '.HealthCheck::class);
                }

                $result = $check->run();
                $this->assertShape($result);

                $slug = isset($result['id']) ? Str::slug($result['id']) : '';
                $customId = $slug !== ''
                    ? 'custom.'.$slug
                    : 'custom.'.Str::slug(class_basename($class).'-'.$result['label']);

                $groupLabel = $this->groupLabelKey($result['group'], $result['label']);
                if (isset($usedIds[$customId]) || isset($usedGroupLabels[$groupLabel])) {
                    throw new \InvalidArgumentException("Health check [{$result['label']}] in group [{$result['group']}] collides with an existing check.");
                }

                $normalized = $this->normalizeCheck(['id' => $customId] + $result);
                $usedIds[$normalized['id']] = true;
                $usedGroupLabels[$groupLabel] = true;
                $results[] = $normalized;
            } catch (\Throwable $e) {
                Log::warning('OpConsole: custom health check failed', [
                    'class' => $class,
                    'error' => ActionRunner::sanitizeText($e->getMessage()),
                ]);
                $id = 'custom.'.Str::slug(class_basename($class));
                if (isset($usedIds[$id])) {
                    $id .= '.'.substr(hash('sha256', $class), 0, 8);
                }
                $usedIds[$id] = true;
                $results[] = $this->normalizeCheck([
                    'id' => $id,
                    'group' => 'Custom checks',
                    'label' => class_basename($class),
                    'ok' => false,
                    'status' => 'unknown',
                    'severity' => 'info',
                    'hint' => 'The custom health check could not be completed: '.ActionRunner::sanitizeText($e->getMessage()),
                    'value' => 'Check failed to run',
                    'fix' => null,
                ]);
            }
        }

        return $results;
    }

    private function assertShape(array $result): void
    {
        $required = ['group', 'label', 'ok', 'severity', 'hint', 'value', 'fix'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $result)) {
                throw new \InvalidArgumentException("Health check result is missing key [$key].");
            }
        }

        foreach (['group', 'label', 'hint'] as $stringKey) {
            if (! is_string($result[$stringKey]) || trim($result[$stringKey]) === '') {
                throw new \InvalidArgumentException("Health check key [$stringKey] must be a non-empty string.");
            }
        }

        if (! is_bool($result['ok'])) {
            throw new \InvalidArgumentException('Health check key [ok] must be a boolean.');
        }

        if (! in_array($result['severity'], ['danger', 'warning', 'info'], true)) {
            throw new \InvalidArgumentException('Health check result has an invalid severity.');
        }

        foreach (['value', 'fix'] as $nullableStringKey) {
            if ($result[$nullableStringKey] !== null && ! is_string($result[$nullableStringKey])) {
                throw new \InvalidArgumentException("Health check key [$nullableStringKey] must be a string or null.");
            }
        }

        if (isset($result['status']) && ! in_array($result['status'], self::STATUSES, true)) {
            throw new \InvalidArgumentException('Health check result has an invalid status.');
        }

        if (isset($result['id']) && (! is_string($result['id']) || trim($result['id']) === '')) {
            throw new \InvalidArgumentException('Health check key [id] must be a non-empty string when provided.');
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}
     */
    private function normalizeCheck(array $raw): array
    {
        $ok = (bool) $raw['ok'];
        /** @var 'danger'|'warning'|'info' $severity */
        $severity = $raw['severity'];
        /** @var 'healthy'|'warning'|'critical'|'unknown' $status */
        $status = isset($raw['status']) && in_array($raw['status'], self::STATUSES, true)
            ? $raw['status']
            : ($ok ? 'healthy' : ($severity === 'danger' ? 'critical' : 'warning'));

        if ($status === 'critical' || $status === 'warning') {
            $ok = false;
        }

        return [
            'id' => (string) $raw['id'],
            'group' => trim((string) $raw['group']),
            'label' => trim((string) $raw['label']),
            'ok' => $ok,
            'status' => $status,
            'severity' => $severity,
            'hint' => trim((string) $raw['hint']),
            'value' => $raw['value'] !== null ? (string) $raw['value'] : null,
            'fix' => $raw['fix'] !== null ? (string) $raw['fix'] : null,
        ];
    }

    private function groupLabelKey(string $group, string $label): string
    {
        return strtolower(trim($group).'|'.trim($label));
    }

    private function effectiveLogLevel(): string
    {
        $defaultChannel = (string) config('logging.default', 'stack');
        $channelConfig = config("logging.channels.{$defaultChannel}");

        if (is_array($channelConfig) && ($channelConfig['driver'] ?? null) === 'stack') {
            $firstSubChannel = $channelConfig['channels'][0] ?? null;
            if (is_string($firstSubChannel) && $firstSubChannel !== '') {
                return strtolower((string) config("logging.channels.{$firstSubChannel}.level", 'debug'));
            }
        }

        if (is_array($channelConfig) && isset($channelConfig['level'])) {
            return strtolower((string) $channelConfig['level']);
        }

        return strtolower((string) config('logging.channels.daily.level', 'debug'));
    }

    /** php.ini sizes like "2M" / "1G" in bytes. */
    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}

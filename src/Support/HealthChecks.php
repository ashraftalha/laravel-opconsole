<?php

namespace AshrafTalha\OpConsole\Support;

use AshrafTalha\OpConsole\Contracts\HealthCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

/**
 * Generic, host-agnostic health checks grouped by area. Host-specific checks
 * (upload limits, branding assets, business rules, ...) do not belong here:
 * register them via config('opconsole.checks') instead.
 */
class HealthChecks
{
    /** Hosts that all mean "this machine", so localhost vs 127.0.0.1 is not a mismatch. */
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * @return list<array{group: string, label: string, ok: bool, severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}>
     */
    public function run(array $database, ?string $host): array
    {
        return [...$this->builtIn($database, $host), ...$this->custom()];
    }

    private function builtIn(array $database, ?string $host): array
    {
        $production = app()->isProduction();
        $memory = $this->iniBytes('memory_limit');
        $driver = config('database.connections.'.$database['driver'].'.driver') ?? $database['driver'];
        $extensions = ['mbstring', 'openssl', 'tokenizer', 'ctype', 'json', 'pdo_'.$driver];
        $missing = array_values(array_filter($extensions, fn ($extension) => ! extension_loaded($extension)));
        $appUrl = (string) config('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $free = @disk_free_space(storage_path());
        $logLevel = config('logging.channels.daily.level', 'debug');
        $check = fn (string $group, string $label, bool $ok, string $severity, string $hint, ?string $value = null, ?string $fix = null) => compact('group', 'label', 'ok', 'severity', 'hint', 'value', 'fix');

        $checks = [
            // Files and folders
            $check('Files and folders', 'Storage directory is writable', is_writable(storage_path()), 'danger', 'Stores logs, cache, and compiled views.', 'storage/', 'chmod -R ug+rw storage'),
            $check('Files and folders', 'Bootstrap cache is writable', is_writable(base_path('bootstrap/cache')), 'danger', 'Required to optimize performance.', 'bootstrap/cache/', 'chmod -R ug+rw bootstrap/cache'),
            $check('Files and folders', 'Sufficient disk space', $free === false || $free > 1024 ** 3, 'warning', 'Warns when less than 1 GB is available.', $free === false ? null : Number::fileSize($free, 1).' free'),

            // PHP
            $check('PHP settings', 'Supported PHP version', version_compare(PHP_VERSION, '8.3.0', '>='), 'danger', 'This application requires PHP 8.3 or later.', 'PHP '.PHP_VERSION),
            $check('PHP settings', 'Required extensions installed', ! $missing, 'danger', $missing ? 'Missing required extensions.' : 'Framework and database driver extensions.', implode(', ', $missing ?: $extensions), $missing ? 'apt install php-'.implode(' php-', $missing) : null),
            $check('PHP settings', 'Sufficient memory', $memory === -1 || $memory >= 128 * 1024 ** 2, 'warning', 'Most applications need at least 128 MB.', 'memory_limit='.ini_get('memory_limit'), 'php.ini: memory_limit=256M'),
            $check('PHP settings', 'OPcache enabled in production', ! $production || (bool) ini_get('opcache.enable'), 'info', 'Improves PHP page performance on the server.', 'opcache.enable='.(ini_get('opcache.enable') ?: '0'), 'php.ini: opcache.enable=1'),

            // Security
            $check('Security', 'Application key configured', filled(config('app.key')), 'danger', 'Required for sessions and sign-in.', null, 'php artisan key:generate'),
            $check('Security', 'Debug mode disabled in production', ! ($production && config('app.debug')), 'danger', 'Debug mode exposes error and configuration details to visitors.', 'APP_DEBUG='.(config('app.debug') ? 'true' : 'false'), '.env: APP_DEBUG=false'),
            $check('Security', 'HTTPS in production', ! $production || str_starts_with($appUrl, 'https://'), 'warning', 'Sensitive requests should use a secure connection.', 'APP_URL='.$appUrl, '.env: APP_URL=https://…'),
            $check('Security', 'Secure session cookies', ! $production || ! str_starts_with($appUrl, 'https://') || (bool) config('session.secure'), 'warning', 'HTTPS sessions should not be sent over an unencrypted connection.', 'SESSION_SECURE_COOKIE='.(config('session.secure') ? 'true' : 'false'), '.env: SESSION_SECURE_COOKIE=true'),
            $check('Security', 'Production log level', ! $production || $logLevel !== 'debug', 'info', 'Debug logging adds unnecessary detail in production.', 'LOG_LEVEL='.$logLevel, '.env: LOG_LEVEL=warning'),

            // Database & queues
            ...$this->databaseChecks($database),

            // URL & email
            $check('URL and email', 'Email delivery enabled in production', ! $production || ! in_array(config('mail.default'), ['log', 'array'], true), 'warning', 'Log and array mail drivers do not deliver email.', 'MAIL_MAILER='.config('mail.default'), '.env: MAIL_MAILER=smtp'),
            $check('URL and email', 'Timezone is explicitly configured', config('app.timezone') !== 'UTC', 'info', 'UTC is the Laravel default; set a local timezone if this application has one.', 'APP_TIMEZONE='.config('app.timezone'), 'config/app.php: timezone=...'),
        ];

        if ($host !== null && $appHost) {
            $same = strcasecmp($host, $appHost) === 0 || (in_array(strtolower($host), self::LOOPBACK, true) && in_array(strtolower($appHost), self::LOOPBACK, true));
            $checks[] = $check('URL and email', 'Application URL matches current host', $same, 'warning', 'A host mismatch can produce links with an incorrect address.', 'APP_URL='.$appHost.'  ←  '.$host, '.env: APP_URL=https://'.$host);
        }

        return $checks;
    }

    /** Session/cache/queue tables the configured drivers need, plus stuck or failed background jobs. */
    private function databaseChecks(array $database): array
    {
        if (! $database['ok']) {
            return [['group' => 'Database and queues', 'label' => 'Database connection', 'ok' => false, 'severity' => 'danger', 'hint' => 'Connection failed; the application cannot operate.', 'value' => $database['driver'], 'fix' => '.env: DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD']];
        }

        $schema = DB::connection()->getSchemaBuilder();
        $failedTable = config('queue.failed.table', 'failed_jobs');
        $needs = array_filter([
            'sessions' => config('session.driver') === 'database',
            'cache' => config('cache.default') === 'database',
            'jobs' => config('queue.default') === 'database',
            $failedTable => true,
        ]);
        $missingTables = array_values(array_filter(array_keys($needs), fn ($table) => ! $schema->hasTable($table)));
        $stuck = $schema->hasTable('jobs') ? DB::table('jobs')->where('created_at', '<', now()->subHour()->getTimestamp())->count() : 0;
        $failed = $schema->hasTable($failedTable) ? DB::table($failedTable)->count() : 0;

        return [
            ['group' => 'Database and queues', 'label' => 'Session, cache, and queue tables', 'ok' => ! $missingTables, 'severity' => 'danger', 'hint' => $missingTables ? 'Tables required by the current configuration are missing.' : 'All required tables exist.', 'value' => $missingTables ? implode(', ', $missingTables) : implode(', ', array_keys($needs)), 'fix' => $missingTables ? 'php artisan migrate' : null],
            ['group' => 'Database and queues', 'label' => 'No stuck queue jobs', 'ok' => $stuck === 0, 'severity' => 'warning', 'hint' => 'Jobs waiting over an hour may indicate the queue worker is stopped.', 'value' => $stuck.' stuck', 'fix' => 'php artisan queue:work'],
            ['group' => 'Database and queues', 'label' => 'No failed jobs', 'ok' => $failed === 0, 'severity' => 'warning', 'hint' => 'Background jobs that failed to complete.', 'value' => $failed.' failed', 'fix' => 'php artisan queue:retry all'],
        ];
    }

    /** Host-registered checks from config('opconsole.checks'); malformed results are logged and skipped. */
    private function custom(): array
    {
        $results = [];

        foreach (config('opconsole.checks', []) as $class) {
            try {
                $check = app($class);
                if (! $check instanceof HealthCheck) {
                    throw new \InvalidArgumentException($class.' does not implement '.HealthCheck::class);
                }

                $result = $check->run();
                $this->assertShape($result);
                $results[] = $result;
            } catch (\Throwable $e) {
                Log::warning('OpConsole: custom health check failed', ['class' => $class, 'error' => $e->getMessage()]);
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

        if (! in_array($result['severity'], ['danger', 'warning', 'info'], true)) {
            throw new \InvalidArgumentException('Health check result has an invalid severity.');
        }
    }

    /** php.ini sizes like "2M" / "1G" in bytes. */
    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}

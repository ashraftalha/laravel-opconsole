<?php

namespace AshrafTalha\OpConsole\Support;

use Illuminate\Support\Number;

/**
 * Laravel Octane and Swoole checks, grouped under "Octane and runtime". They only run when
 * Octane is installed (and opconsole.octane.checks is not false), so apps without Octane see
 * nothing new. Swoole-specific checks only run when the configured server is swoole.
 */
class OctaneChecks
{
    public const GROUP = 'Octane and runtime';

    public function __construct(private Runtime $runtime) {}

    public function enabled(): bool
    {
        $setting = config('opconsole.octane.checks', 'auto');

        return $setting !== false && $setting !== 'false' && $this->runtime->octaneInstalled();
    }

    /**
     * @param  \Closure(string, string, string, bool, string, string, ?string=, ?string=, ?string=): array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}  $check
     * @return list<array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}>
     */
    public function run(bool $production, \Closure $check): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $server = $this->runtime->octaneServer();
        $serving = $this->runtime->servingViaOctane();

        $checks = [
            $this->serverDependency($server, $production, $check),
            $this->serving($serving, $production, $check),
            $this->serverRunning($server, $serving, $production, $check),
            $this->garbageThreshold($check),
            $this->https($check),
            $this->opcacheCli($serving, $production, $check),
        ];

        if ($server === 'swoole') {
            $checks = [...$checks, ...$this->swooleChecks($production, $check)];
        }

        return $checks;
    }

    private function serverDependency(?string $server, bool $production, \Closure $check): array
    {
        $label = 'Octane server is installed';

        if ($server === null) {
            return $check('octane.server', self::GROUP, $label, false, 'warning', 'config/octane.php does not name a server (swoole, roadrunner or frankenphp).', 'OCTANE_SERVER not set', '.env: OCTANE_SERVER=swoole');
        }

        [$present, $value, $fix] = match ($server) {
            'swoole' => [
                $this->runtime->extensionLoaded('swoole') || $this->runtime->extensionLoaded('openswoole'),
                $this->swooleVersion() ?? 'swoole extension not loaded',
                'pecl install swoole',
            ],
            'roadrunner' => [$this->runtime->fileExists(base_path('rr')), base_path('rr'), 'php artisan octane:install --server=roadrunner'],
            'frankenphp' => [$this->runtime->fileExists(base_path('frankenphp')), base_path('frankenphp'), 'php artisan octane:install --server=frankenphp'],
            default => [false, $server, '.env: OCTANE_SERVER=swoole'],
        };

        return $check(
            'octane.server',
            self::GROUP,
            $label,
            $present,
            $production ? 'danger' : 'warning',
            $present ? 'The '.$server.' server Octane is configured to use is available.' : 'Octane is configured for '.$server.', but it is not installed on this machine, so `octane:start` will fail.',
            'server='.$server.' · '.$value,
            $present ? null : $fix,
        );
    }

    private function serving(bool $serving, bool $production, \Closure $check): array
    {
        $expected = (bool) config('opconsole.octane.expected_in_production', false);

        if ($serving) {
            return $check('octane.serving', self::GROUP, 'Requests are served by Octane', true, 'info', 'This request was handled by an Octane worker.', 'LARAVEL_OCTANE=1');
        }

        return $check(
            'octane.serving',
            self::GROUP,
            'Requests are served by Octane',
            false,
            $expected && $production ? 'warning' : 'info',
            'Octane is installed, but this request came through PHP-FPM or another SAPI ('.PHP_SAPI.'). Fine if that is intentional.',
            'served by '.PHP_SAPI,
            'php artisan octane:start',
        );
    }

    private function serverRunning(?string $server, bool $serving, bool $production, \Closure $check): array
    {
        $label = 'Octane server is running';

        if ($serving) {
            return $check('octane.running', self::GROUP, $label, true, 'danger', 'This request was served by it.', 'running');
        }

        $running = $server === null ? null : $this->runtime->octaneServerRunning();

        if ($running === null) {
            return $check('octane.running', self::GROUP, $label, true, 'info', 'Octane\'s process inspector for this server could not tell whether it is running.', 'unknown', null, 'unknown');
        }

        return $check(
            'octane.running',
            self::GROUP,
            $label,
            $running,
            $production && config('opconsole.octane.expected_in_production', false) ? 'danger' : 'info',
            $running ? 'The Octane server process answered.' : 'No Octane server process is running for this application.',
            $running ? 'running' : 'stopped',
            $running ? null : 'php artisan octane:start --server='.$server,
        );
    }

    private function garbageThreshold(\Closure $check): array
    {
        $garbage = (int) config('octane.garbage', 50);
        $limit = $this->bytes($this->runtime->ini('memory_limit'));
        $ok = $limit < 0 || $garbage * 1024 ** 2 < $limit;

        return $check(
            'octane.garbage',
            self::GROUP,
            'Garbage threshold is below memory_limit',
            $ok,
            'warning',
            'Octane collects garbage once a worker exceeds octane.garbage. A threshold at or above memory_limit means workers run out of memory first.',
            'garbage='.$garbage.' MB · memory_limit='.($this->runtime->ini('memory_limit') ?: '—'),
            $ok ? null : 'config/octane.php: garbage below memory_limit',
        );
    }

    private function https(\Closure $check): array
    {
        $appHttps = str_starts_with(strtolower((string) config('app.url')), 'https://');
        $octaneHttps = (bool) config('octane.https', false);
        $ok = ! $appHttps || $octaneHttps;

        return $check(
            'octane.https',
            self::GROUP,
            'Octane HTTPS matches APP_URL',
            $ok,
            'warning',
            'With an https APP_URL, octane.https must be true or Octane generates http:// links and redirects.',
            'APP_URL='.($appHttps ? 'https' : 'http').' · OCTANE_HTTPS='.($octaneHttps ? 'true' : 'false'),
            $ok ? null : '.env: OCTANE_HTTPS=true',
        );
    }

    private function opcacheCli(bool $serving, bool $production, \Closure $check): array
    {
        $label = 'OPcache is enabled for Octane workers';

        if (! $serving) {
            return $check('octane.opcache_cli', self::GROUP, $label, true, 'info', 'Octane workers run under the CLI SAPI; their OPcache setting can only be read from a request they serve.', 'not served by Octane', null, 'unknown');
        }

        $enabled = filter_var($this->runtime->ini('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN) && $this->runtime->extensionLoaded('Zend OPcache');

        return $check(
            'octane.opcache_cli',
            self::GROUP,
            $label,
            $enabled || ! $production,
            'warning',
            'Octane runs under the CLI, so opcache.enable_cli controls OPcache for its workers.',
            'opcache.enable_cli='.($enabled ? 'On' : 'Off'),
            $enabled ? null : 'php.ini: opcache.enable_cli=1',
        );
    }

    /** @return list<array{id: string, group: string, label: string, ok: bool, status: 'healthy'|'warning'|'critical'|'unknown', severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string}> */
    private function swooleChecks(bool $production, \Closure $check): array
    {
        $options = config('octane.swoole.options', []);
        $options = is_array($options) ? $options : [];

        // Octane's own default when package_max_length is not configured.
        $packageMax = (int) ($options['package_max_length'] ?? 10 * 1024 * 1024);
        $postMax = max($this->bytes($this->runtime->ini('post_max_size')), $this->bytes($this->runtime->ini('upload_max_filesize')));
        $uploadsOk = $postMax <= 0 || $packageMax >= $postMax;

        $cpus = $this->runtime->cpuCount();
        $workers = isset($options['worker_num']) ? (int) $options['worker_num'] : null;
        $workersOk = $workers === null || $cpus === null || $workers <= $cpus * 4;

        $xdebug = $this->runtime->extensionLoaded('xdebug');

        $logFile = isset($options['log_file']) && is_string($options['log_file']) ? $options['log_file'] : null;
        $logOk = $logFile === null || $this->runtime->isWritable($this->runtime->fileExists($logFile) ? $logFile : dirname($logFile));

        return [
            $check(
                'swoole.package_max_length',
                self::GROUP,
                'Swoole accepts uploads as large as PHP allows',
                $uploadsOk,
                'warning',
                'Swoole ignores upload_max_filesize and post_max_size and rejects bodies over package_max_length, so larger uploads fail without a PHP error.',
                'package_max_length='.Number::fileSize($packageMax).' · PHP allows '.($postMax > 0 ? Number::fileSize($postMax) : 'unlimited'),
                $uploadsOk ? null : "config/octane.php: 'swoole' => ['options' => ['package_max_length' => ".max($postMax, 1).']]',
            ),
            $check(
                'swoole.worker_num',
                self::GROUP,
                'Swoole worker count fits the CPU',
                $workersOk,
                'info',
                'More than about four workers per CPU core adds context switching without more throughput.',
                'workers='.($workers ?? 'auto').' · CPUs='.($cpus ?? 'unknown'),
                $workersOk ? null : 'php artisan octane:start --workers='.max(1, (int) $cpus),
            ),
            $check(
                'swoole.xdebug',
                self::GROUP,
                'Xdebug is not loaded alongside Swoole',
                ! $xdebug,
                $production ? 'warning' : 'info',
                'Xdebug slows Swoole workers sharply and is known to crash them; Swoole warns about it at startup.',
                $xdebug ? 'xdebug loaded' : 'xdebug not loaded',
                $xdebug ? 'Disable xdebug for the CLI SAPI that runs Octane' : null,
            ),
            $check(
                'swoole.log_file',
                self::GROUP,
                'Swoole log file is writable',
                $logOk,
                'warning',
                'Swoole writes server errors to swoole.options.log_file; an unwritable path hides crashes.',
                $logFile ?? 'not configured (Swoole default)',
                $logOk ? null : 'Make the log file directory writable by the Octane user',
            ),
        ];
    }

    private function swooleVersion(): ?string
    {
        foreach (['swoole', 'openswoole'] as $extension) {
            if ($this->runtime->extensionLoaded($extension)) {
                return $extension.' '.($this->runtime->extensionVersion($extension) ?? '');
            }
        }

        return null;
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
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

<?php

namespace AshrafTalha\OpConsole\Support;

use Composer\InstalledVersions;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Process\Process;
use Throwable;

/** Runs Composer's read-only audit against the host application's dependency files. */
class SecurityScanner
{
    private const REPORT_KEY = 'opconsole.security.report';

    private const LOCK_KEY = 'opconsole.security.scan';

    private const MAX_OUTPUT = 2_000_000;

    public function report(): array
    {
        $report = Cache::store(config('opconsole.security.store'))->get(self::REPORT_KEY);
        $hash = $this->manifestHash();
        if (! is_array($report)) {
            return ['status' => 'never_scanned', 'scope' => $this->scope(), 'manifest_changed' => false, 'advisories' => [], 'abandoned' => [], 'ignored' => [], 'config_warnings' => $this->configurationWarnings()];
        }
        foreach (['advisories', 'ignored'] as $bucket) {
            $report[$bucket] = array_values(array_map(function ($item): array {
                if (! is_array($item)) {
                    return [];
                }
                foreach (['package', 'version', 'installed_version', 'title', 'identifier', 'severity', 'affected', 'published', 'remediation', 'ignored_reason'] as $field) {
                    if (array_key_exists($field, $item)) {
                        $item[$field] = $this->clean($item[$field]);
                    }
                }
                if (isset($item['identifiers']) && is_array($item['identifiers'])) {
                    $item['identifiers'] = array_values(array_filter(array_map($this->clean(...), $item['identifiers'])));
                }
                $item['link'] = $this->validLink($item['link'] ?? null);

                return $item;
            }, is_array($report[$bucket] ?? null) ? $report[$bucket] : []));
        }
        $report['manifest_changed'] = ! hash_equals((string) ($report['manifest_hash'] ?? ''), $hash);
        $scannedAt = isset($report['scanned_at']) ? strtotime((string) $report['scanned_at']) : false;
        $report['stale'] = $report['manifest_changed'] || $scannedAt === false || $scannedAt < time() - max(60, (int) config('opconsole.security.max_age_seconds', 604800));
        $report['config_warnings'] = $this->configurationWarnings();
        if (isset($report['last_failure'])) {
            $report['failure_age'] = $report['last_failure']['at'] ?? null;
        }

        return $report;
    }

    public function scan(): array
    {
        $cache = Cache::store(config('opconsole.security.store'));
        $lockStore = $cache->getStore();
        if (! $lockStore instanceof LockProvider) {
            return $this->failure('The configured security cache store does not support atomic locks.');
        }
        $lock = $lockStore->lock(self::LOCK_KEY, max(60, (int) config('opconsole.security.timeout', 120) + 30));
        if (! $lock->get()) {
            return $this->failure('Another security scan is already running.');
        }
        try {
            $root = base_path();
            $manifest = $root.'/composer.json';
            $lockFile = $root.'/composer.lock';
            if (! is_file($manifest) || ! is_file($lockFile)) {
                return $this->failure('Host composer.json or composer.lock is missing.');
            }
            $lockData = json_decode((string) file_get_contents($lockFile), true);
            if (! is_array($lockData) || ! isset($lockData['packages']) || ! is_array($lockData['packages'])) {
                return $this->failure('Host composer.lock is malformed or incomplete.');
            }
            $composer = (string) config('opconsole.security.composer', 'composer');
            if ($composer === '' || str_contains($composer, "\0")) {
                return $this->failure('Composer executable is not configured.');
            }
            $versionProcess = new Process([$composer, '--version', '--no-ansi'], $root);
            $versionProcess->setTimeout(10);
            $versionProcess->run();
            if (! $versionProcess->isSuccessful() || ! preg_match('/Composer version (\d+)\.(\d+)/', $versionProcess->getOutput(), $vm) || (int) $vm[1] < 2 || ((int) $vm[1] === 2 && (int) $vm[2] < 4)) {
                return $this->failure('Composer 2.4 or later is required for JSON audit output.');
            }

            $args = [$composer, 'audit', '--format=json', '--locked', '--no-interaction', '--no-plugins', '--no-scripts'];
            if (! config('opconsole.security.include_dev', false)) {
                $args[] = '--no-dev';
            }
            $process = new Process($args, $root);
            $process->setTimeout(max(10, (int) config('opconsole.security.timeout', 120)));
            $tooLarge = false;
            $process->run(function () use ($process, &$tooLarge): void {
                if (strlen($process->getOutput()) + strlen($process->getErrorOutput()) > self::MAX_OUTPUT) {
                    $tooLarge = true;
                    $process->stop(0);
                }
            });
            $output = $process->getOutput();
            if ($tooLarge || strlen($output) + strlen($process->getErrorOutput()) > self::MAX_OUTPUT) {
                return $this->failure('Composer audit output exceeded the configured safety limit.');
            }
            $data = json_decode($output, true);
            if (! is_array($data) || ! array_key_exists('advisories', $data)) {
                return $this->failure($process->isSuccessful() ? 'Composer returned invalid or incomplete JSON.' : 'Composer audit failed (exit code '.$process->getExitCode().'); process diagnostics were omitted from the report.');
            }
            // Composer 2.4+ JSON output uses a package-name => list-of-advisories map.
            if (! is_array($data['advisories'])) {
                return $this->failure('Composer returned an unsupported audit JSON schema.');
            }
            if (! empty($data['unreachable-repositories'])) {
                return $this->failure('One or more advisory repositories were unavailable; this result is incomplete.');
            }
            $packages = array_merge($lockData['packages'], config('opconsole.security.include_dev', false) ? ($lockData['packages-dev'] ?? []) : []);
            $locked = [];
            foreach ($packages as $package) {
                if (is_array($package) && isset($package['name'], $package['version'])) {
                    $locked[$package['name']] = $package['version'];
                }
            }
            $installed = class_exists(InstalledVersions::class) ? InstalledVersions::getInstalledPackages() : [];
            $versionMismatches = [];
            foreach ($locked as $packageName => $lockedVersion) {
                if (! in_array($packageName, $installed, true)) {
                    continue;
                }
                $installedVersion = InstalledVersions::getPrettyVersion($packageName) ?: InstalledVersions::getVersion($packageName);
                if (ltrim((string) $installedVersion, 'v') !== ltrim((string) $lockedVersion, 'v')) {
                    $versionMismatches[] = ['package' => $packageName, 'locked_version' => $lockedVersion, 'installed_version' => $installedVersion];
                }
            }
            $advisories = [];
            $ignored = [];
            foreach ($data['advisories'] as $name => $items) {
                if (! is_array($items)) {
                    return $this->failure('Composer returned malformed advisory records.');
                }
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $link = $this->validLink($item['link'] ?? null);
                    $row = [
                        'package' => (string) $name, 'version' => $locked[$name] ?? null,
                        'installed_version' => in_array($name, $installed, true) ? (InstalledVersions::getPrettyVersion($name) ?: InstalledVersions::getVersion($name)) : null,
                        'version_mismatch' => isset($locked[$name]) && in_array($name, $installed, true) && ltrim((string) InstalledVersions::getPrettyVersion($name), 'v') !== ltrim((string) $locked[$name], 'v'),
                        'title' => $this->clean($item['title'] ?? 'Untitled advisory'),
                        'identifier' => $this->clean($item['cve'] ?? $item['ghsaId'] ?? $item['advisoryId'] ?? $item['id'] ?? null),
                        'identifiers' => $this->identifiers($item),
                        'severity' => $this->severity($item['severity'] ?? null),
                        'affected' => $this->clean($item['affectedVersions'] ?? null),
                        'published' => $this->clean($item['reportedAt'] ?? $item['publishedAt'] ?? null),
                        'link' => $link,
                        'remediation' => $this->clean($item['remediation'] ?? $item['notes'] ?? null),
                        'ignored' => ! empty($item['ignored']) || ! empty($item['ignoredReason']),
                        'ignored_reason' => $this->clean($item['ignoredReason'] ?? null),
                    ];
                    if ($row['ignored']) {
                        $ignored[] = $row;
                    } else {
                        $advisories[] = $row;
                    }
                }
            }
            foreach (($data['ignored-advisories'] ?? []) as $name => $items) {
                if (! is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $ignored[] = [
                        'package' => (string) $name,
                        'version' => $locked[$name] ?? null,
                        'title' => $this->clean($item['title'] ?? 'Advisory ignored by Composer policy'),
                        'identifier' => $this->clean($item['cve'] ?? $item['advisoryId'] ?? $item['id'] ?? null),
                        'identifiers' => $this->identifiers($item),
                        'ignored' => true,
                        'ignored_reason' => $this->clean($item['ignoreReason'] ?? null),
                    ];
                }
            }
            $abandoned = [];
            foreach (($data['abandoned'] ?? []) as $name => $record) {
                $abandoned[] = ['package' => (string) $name, 'replacement' => is_array($record) ? ($record['replacement'] ?? null) : $record];
            }
            if (! $process->isSuccessful() && $advisories === [] && $ignored === [] && $abandoned === []) {
                return $this->failure('Composer audit exited unsuccessfully without a complete finding report.');
            }
            $now = now()->toIso8601String();
            $report = ['status' => 'complete', 'scanned_at' => $now, 'last_success_at' => $now, 'last_failure' => null, 'scope' => $this->scope(), 'source' => 'Composer audit JSON (configured Composer repositories; Packagist by default)', 'manifest_hash' => $this->manifestHash(), 'manifest_changed' => false, 'version_mismatches' => $versionMismatches, 'advisories' => $advisories, 'ignored' => $ignored, 'abandoned' => $abandoned, 'config_warnings' => $this->configurationWarnings()];
            Cache::store(config('opconsole.security.store'))->forever(self::REPORT_KEY, $report);

            return $report;
        } catch (Throwable $e) {
            return $this->failure('Security scan failed with '.class_basename($e).'; process diagnostics were omitted from the report.');
        } finally {
            $lock->release();
        }
    }

    public function configurationWarnings(): array
    {
        $prod = app()->isProduction();
        $warnings = [];
        $add = function ($id, $condition, $message, $fix) use (&$warnings) {
            if ($condition) {
                $warnings[] = compact('id', 'message', 'fix');
            }
        };
        $add('debug', $prod && config('app.debug'), 'Debug mode is enabled in production.', 'Set APP_DEBUG=false and rebuild cached configuration.');
        $add('app_key', ! filled(config('app.key')), 'The application encryption key is missing.', 'Set APP_KEY securely and run php artisan key:generate before deployment. The key is never shown here.');
        $add('session_secure', $prod && config('session.secure') !== true && config('session.secure') !== 1, 'Secure session cookies are not explicitly enabled in production.', 'Set SESSION_SECURE_COOKIE=true.');
        $add('session_httponly', config('session.http_only') !== true && config('session.http_only') !== 1, 'HttpOnly is disabled for session cookies.', 'Set SESSION_HTTP_ONLY=true.');
        $add('session_samesite', ! in_array(strtolower((string) config('session.same_site')), ['lax', 'strict'], true), 'Session SameSite policy is missing or permissive.', 'Set SESSION_SAME_SITE=lax (or strict if compatible).');
        $add('https', $prod && ! str_starts_with((string) config('app.url'), 'https://') && ! config('opconsole.security.trusted_proxy_https', false), 'Production HTTPS could not be confirmed from APP_URL or the trusted proxy setting.', 'Use an https APP_URL and configure Laravel trusted proxies; set trusted_proxy_https=true only when the edge enforces HTTPS.');
        $viewGate = config('opconsole.authorization.view_gate');
        $runGate = config('opconsole.authorization.run_gate');
        $authorizationInvalid = ! is_string($viewGate) || trim($viewGate) === '' || ! Gate::has($viewGate)
            || ! is_string($runGate) || trim($runGate) === '' || ! Gate::has($runGate);
        $add('authorization', $authorizationInvalid, 'OpConsole view or action authorization Gate is missing or undefined.', 'Configure named view and run Gates and define them in the host application.');
        foreach (['clear', 'optimize', 'migrate', 'down', 'up', 'storage_link'] as $action) {
            $add('action_'.$action, (bool) config('opconsole.actions.'.$action, false) && in_array($action, (array) config('opconsole.security.risky_actions', ['migrate', 'down']), true), 'Risky OpConsole action is enabled: '.$action.'.', 'Disable it when unnecessary or restrict its action Gate to trusted operators.');
        }

        return $warnings;
    }

    private function failure(string $message): array
    {
        $store = Cache::store(config('opconsole.security.store'));
        $previous = $store->get(self::REPORT_KEY, []);
        $previous = is_array($previous) ? $previous : [];
        $previous['status'] = 'failed';
        $previous['last_failure'] = ['at' => now()->toIso8601String(), 'message' => $this->clean($message)];
        $previous['manifest_changed'] = ! hash_equals((string) ($previous['manifest_hash'] ?? ''), $this->manifestHash());
        $previous['config_warnings'] = $this->configurationWarnings();
        $store->forever(self::REPORT_KEY, $previous);

        return $previous;
    }

    private function manifestHash(): string
    {
        return hash('sha256', (string) @file_get_contents(base_path('composer.json'))."\0".(string) @file_get_contents(base_path('composer.lock')));
    }

    private function scope(): string
    {
        return 'composer.lock · '.(config('opconsole.security.include_dev', false) ? 'production and development' : 'production dependencies').'; lock versions are authoritative';
    }

    private function validLink($link): ?string
    {
        if (! is_string($link) || strlen($link) > 2048) {
            return null;
        } $p = parse_url($link);

        return is_array($p) && strtolower($p['scheme'] ?? '') === 'https' && isset($p['host']) && ! isset($p['user']) && ! isset($p['pass']) ? $link : null;
    }

    private function severity($value): ?string
    {
        $v = is_string($value) ? strtolower($value) : '';

        return in_array($v, ['critical', 'high', 'medium', 'moderate', 'low'], true) ? ($v === 'moderate' ? 'medium' : $v) : null;
    }

    private function identifiers(array $item): array
    {
        $values = [];
        foreach (['cve', 'ghsaId', 'advisoryId', 'id'] as $key) {
            if (isset($item[$key]) && is_string($item[$key])) {
                $values[] = $item[$key];
            }
        }
        foreach (($item['sources'] ?? []) as $source) {
            if (is_array($source) && isset($source['remoteId']) && is_string($source['remoteId'])) {
                $values[] = $source['remoteId'];
            }
        }

        return array_values(array_unique(array_filter(array_map($this->clean(...), $values))));
    }

    private function clean($value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr(strip_tags(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? ''), 0, 1000);
    }
}

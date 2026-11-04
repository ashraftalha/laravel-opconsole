<?php

namespace AshrafTalha\OpConsole\Support;

use Throwable;

/**
 * Every probe of the PHP runtime and Laravel Octane that the Octane checks need, in one
 * replaceable class. Tests bind a fake so they can describe Octane, Swoole, RoadRunner or
 * FrankenPHP setups without installing any of them.
 */
class Runtime
{
    public function octaneInstalled(): bool
    {
        return class_exists('Laravel\Octane\Octane') && is_array(config('octane'));
    }

    /** Octane sets LARAVEL_OCTANE in the server variables of every request its workers handle. */
    public function servingViaOctane(): bool
    {
        return ! empty($_SERVER['LARAVEL_OCTANE']) || ! empty($_ENV['LARAVEL_OCTANE']);
    }

    public function octaneServer(): ?string
    {
        $server = config('octane.server');

        return is_string($server) && $server !== '' ? strtolower($server) : null;
    }

    /** True while handling a web request inside a Swoole (or OpenSwoole) worker. */
    public function insideSwooleWorker(): bool
    {
        return $this->servingViaOctane() && $this->octaneServer() === 'swoole';
    }

    public function extensionLoaded(string $name): bool
    {
        return extension_loaded($name);
    }

    public function extensionVersion(string $name): ?string
    {
        $version = phpversion($name);

        return $version === false ? null : $version;
    }

    public function fileExists(string $path): bool
    {
        return is_file($path);
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    public function ini(string $key): string
    {
        $value = ini_get($key);

        return $value === false ? '' : (string) $value;
    }

    public function cpuCount(): ?int
    {
        if (function_exists('swoole_cpu_num')) {
            return max(1, (int) swoole_cpu_num());
        }

        $count = @shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n hw.ncpu 2>/dev/null' : 'nproc 2>/dev/null');

        return is_string($count) && (int) $count > 0 ? (int) $count : null;
    }

    /**
     * Whether the Octane server process is running, using Octane's own inspector for the
     * configured server. Null when it cannot be determined (unknown server, inspector missing,
     * or the inspector failed).
     */
    public function octaneServerRunning(): ?bool
    {
        $inspector = match ($this->octaneServer()) {
            'swoole' => 'Laravel\Octane\Swoole\ServerProcessInspector',
            'roadrunner' => 'Laravel\Octane\RoadRunner\ServerProcessInspector',
            'frankenphp' => 'Laravel\Octane\FrankenPhp\ServerProcessInspector',
            default => null,
        };

        // Octane is an optional dependency, absent when this package is analysed, hence the ignore.
        // @phpstan-ignore function.impossibleType
        if ($inspector === null || ! class_exists($inspector)) {
            return null;
        }

        try {
            return (bool) app($inspector)->serverIsRunning();
        } catch (Throwable) {
            return null;
        }
    }
}

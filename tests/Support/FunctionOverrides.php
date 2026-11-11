<?php

namespace AshrafTalha\OpConsole\Tests\Support {
    /** Scoped OS/runtime fault injection; every unconfigured call uses PHP's real implementation. */
    final class FunctionOverrides
    {
        public static array $callbacks = [];

        public static function call(string $name, array $arguments): mixed
        {
            return isset(self::$callbacks[$name])
                ? (self::$callbacks[$name])(...$arguments)
                : $name(...$arguments);
        }
    }
}

namespace AshrafTalha\OpConsole\Support {
    use AshrafTalha\OpConsole\Tests\Support\FunctionOverrides;

    function function_exists(...$arguments): mixed
    {
        return FunctionOverrides::call('function_exists', $arguments);
    }

    function method_exists(...$arguments): mixed
    {
        return FunctionOverrides::call('method_exists', $arguments);
    }

    function swoole_cpu_num(...$arguments): mixed
    {
        return FunctionOverrides::call('swoole_cpu_num', $arguments);
    }

    function filemtime(...$arguments): mixed
    {
        return FunctionOverrides::call('filemtime', $arguments);
    }

    function filesize(...$arguments): mixed
    {
        return FunctionOverrides::call('filesize', $arguments);
    }

    function fopen(...$arguments): mixed
    {
        return FunctionOverrides::call('fopen', $arguments);
    }

    function unlink(...$arguments): mixed
    {
        return FunctionOverrides::call('unlink', $arguments);
    }

    function preg_split(...$arguments): mixed
    {
        return FunctionOverrides::call('preg_split', $arguments);
    }

    function disk_free_space(...$arguments): mixed
    {
        return FunctionOverrides::call('disk_free_space', $arguments);
    }

    function disk_total_space(...$arguments): mixed
    {
        return FunctionOverrides::call('disk_total_space', $arguments);
    }

    function ini_get(...$arguments): mixed
    {
        return FunctionOverrides::call('ini_get', $arguments);
    }

    function opcache_get_status(...$arguments): mixed
    {
        return FunctionOverrides::call('opcache_get_status', $arguments);
    }

    function is_link(...$arguments): mixed
    {
        return FunctionOverrides::call('is_link', $arguments);
    }

    function realpath(...$arguments): mixed
    {
        return FunctionOverrides::call('realpath', $arguments);
    }

    function dirname(...$arguments): mixed
    {
        return FunctionOverrides::call('dirname', $arguments);
    }

    function class_exists(...$arguments): mixed
    {
        return FunctionOverrides::call('class_exists', $arguments);
    }
}

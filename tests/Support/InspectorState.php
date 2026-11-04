<?php

namespace AshrafTalha\OpConsole\Tests\Support;

/** Shared switch for every stub inspector: true/false to answer, a Throwable to fail. */
final class InspectorState
{
    public static bool|\Throwable $running = true;

    /** @var list<string> */
    public static array $called = [];

    public static function answer(string $server): bool
    {
        self::$called[] = $server;

        if (self::$running instanceof \Throwable) {
            throw self::$running;
        }

        return self::$running;
    }

    public static function reset(): void
    {
        self::$running = true;
        self::$called = [];
    }
}

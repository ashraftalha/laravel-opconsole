<?php

/*
 * Minimal stand-ins for Laravel Octane classes, using the exact names OpConsole looks up.
 * They let the real Runtime class be tested as if Octane were installed, without requiring
 * laravel/octane or a Swoole/RoadRunner/FrankenPHP server.
 *
 * Excluded from the classmap (composer.json autoload-dev) and loaded on demand with
 * require_once. Harmless for other tests: Runtime only reports Octane as installed when
 * config('octane') is also an array, which tests set explicitly.
 */

namespace Laravel\Octane {
    if (! class_exists(Octane::class, false)) {
        class Octane {}
    }
}

namespace Laravel\Octane\Swoole {
    use AshrafTalha\OpConsole\Tests\Support\InspectorState;

    if (! class_exists(ServerProcessInspector::class, false)) {
        class ServerProcessInspector
        {
            public function serverIsRunning(): bool
            {
                return InspectorState::answer('swoole');
            }
        }
    }
}

namespace Laravel\Octane\RoadRunner {
    use AshrafTalha\OpConsole\Tests\Support\InspectorState;

    if (! class_exists(ServerProcessInspector::class, false)) {
        class ServerProcessInspector
        {
            public function serverIsRunning(): bool
            {
                return InspectorState::answer('roadrunner');
            }
        }
    }
}

namespace Laravel\Octane\FrankenPhp {
    use AshrafTalha\OpConsole\Tests\Support\InspectorState;

    if (! class_exists(ServerProcessInspector::class, false)) {
        class ServerProcessInspector
        {
            public function serverIsRunning(): bool
            {
                return InspectorState::answer('frankenphp');
            }
        }
    }
}

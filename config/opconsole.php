<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    | Kill-switch. When false, OpConsole registers no routes at all.
    */
    'enabled' => (bool) env('OPCONSOLE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    */
    'route' => [
        'prefix' => env('OPCONSOLE_ROUTE_PREFIX', 'admin/opconsole'),
        'name_prefix' => 'opconsole.',
        // Deliberately omits Laravel's 'auth' alias: that middleware calls route('login')
        // unconditionally and throws (-> HTTP 500) on a host with no route named "login".
        // OpConsole's own middleware performs the guest check instead; see "authentication" below.
        'middleware' => ['web'],
        'domain' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    | OpConsole checks this guard itself rather than relying on Laravel's
    | 'auth' middleware alias, so a host with no named "login" route gets a
    | controlled 401 instead of an uncaught RouteNotFoundException (HTTP 500).
    | Set login_route to null to always return 401 to guests (no redirect).
    */
    'authentication' => [
        'guard' => null,
        'login_route' => 'login',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    | Four distinct abilities:
    | - view_gate:     view the dashboard, health checks, audit history, and log tail
    | - run_gate:      execute allow-listed Artisan maintenance operations
    | - download_gate: download full raw log files (defaults to view_gate if not explicitly defined)
    | - delete_gate:   permanently delete rotated log files (defaults to run_gate if not explicitly defined)
    |
    | Define these Gates in your AppServiceProvider. If you do not define
    | view_gate or run_gate, OpConsole registers a fail-closed default that
    | denies access everywhere outside the "local" environment.
    */
    'authorization' => [
        'view_gate' => 'viewOpConsole',
        'run_gate' => 'runOpConsoleActions',
        'download_gate' => 'downloadOpConsoleLogs',
        'delete_gate' => 'deleteOpConsoleLogs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrency
    |--------------------------------------------------------------------------
    | The lock guarding the three Artisan actions uses an isolated cache store,
    | independent of cache.default: `optimize:clear` runs `cache:clear`, which
    | flushes the *default* store outright. OpConsole registers a dedicated
    | "opconsole" file lock store (storage/framework/cache/opconsole) so locks
    | survive `optimize:clear` even when CACHE_STORE=file. For multi-server
    | deployments, point this at an isolated Redis or database lock store.
    | Unsafe or unavailable lock stores fail closed and block execution.
    */
    'concurrency' => [
        'lock_store' => env('OPCONSOLE_LOCK_STORE', 'opconsole'),
        'lock_seconds' => (int) env('OPCONSOLE_LOCK_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Heartbeats
    |--------------------------------------------------------------------------
    | The scheduler writes a timestamp every minute so the dashboard can tell
    | whether `schedule:run` is actually firing (and dispatches a queued job
    | whose worker writes another). The store must be shared by
    | every server (null uses cache.default); `optimize:clear` flushes
    | cache.default, so the check reads "unknown" for up to a minute after it.
    | Max ages are in seconds.
    */
    'heartbeats' => [
        'store' => env('OPCONSOLE_HEARTBEAT_STORE'),
        'scheduler' => (bool) env('OPCONSOLE_HEARTBEAT_SCHEDULER', true),
        'scheduler_max_age' => (int) env('OPCONSOLE_HEARTBEAT_SCHEDULER_MAX_AGE', 120),
        // The scheduler dispatches a tiny job every minute; a worker processing it proves the queue is consumed.
        'queue' => (bool) env('OPCONSOLE_HEARTBEAT_QUEUE', true),
        'queue_max_age' => (int) env('OPCONSOLE_HEARTBEAT_QUEUE_MAX_AGE', 300),
        'queue_connection' => env('OPCONSOLE_HEARTBEAT_QUEUE_CONNECTION'),
        'queue_name' => env('OPCONSOLE_HEARTBEAT_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Disk space
    |--------------------------------------------------------------------------
    | files.disk_space warns below 1 GB free or below this share of the volume,
    | and is critical below 256 MB.
    */
    'disk' => [
        'min_free_percent' => (float) env('OPCONSOLE_DISK_MIN_FREE_PERCENT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health endpoint
    |--------------------------------------------------------------------------
    | GET {prefix}/health for uptime monitors. Off by default and also off
    | while no token is set. Send `Authorization: Bearer <token>`. Returns
    | statuses and values only (no hints or fixes); HTTP 503 when any check
    | is critical. Runs without session or CSRF middleware.
    */
    'health_endpoint' => [
        'enabled' => (bool) env('OPCONSOLE_HEALTH_ENDPOINT', false),
        'token' => env('OPCONSOLE_HEALTH_TOKEN'),
        'rate_limit' => (int) env('OPCONSOLE_HEALTH_RATE_LIMIT', 60), // requests per minute
    ],

    /*
    |--------------------------------------------------------------------------
    | Artisan actions
    |--------------------------------------------------------------------------
    | Closed allow-list. Set any entry to false to hide and disable it.
    | You cannot add new commands here — only disable the built-in ones.
    | `down` generates a one-time bypass secret shown in the output panel.
    */
    'actions' => [
        'clear' => true,
        'optimize' => true,
        'migrate' => true,
        'down' => true,
        'up' => true,
        'storage_link' => (bool) env('OPCONSOLE_ACTION_STORAGE_LINK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode
    |--------------------------------------------------------------------------
    | Keeps the OpConsole routes reachable (still behind authentication and
    | Gates) while the application is down, so an admin can bring it back up.
    */
    'maintenance' => [
        'allow_console' => (bool) env('OPCONSOLE_MAINTENANCE_ALLOW_CONSOLE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    */
    'logs' => [
        'path' => storage_path('logs'),
        'allow_download' => true,
        'allow_delete' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom health checks
    |--------------------------------------------------------------------------
    | Class names implementing AshrafTalha\OpConsole\Contracts\HealthCheck,
    | resolved from the container. Register your host-specific checks here.
    */
    'checks' => [],

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    | Null uses the package's own standalone layout. Set to a view name to
    | render inside your own layout instead (it must @yield('content') and
    | provide @stack('styles') / @stack('scripts')).
    */
    'layout' => null,

    /*
    |--------------------------------------------------------------------------
    | Back link
    |--------------------------------------------------------------------------
    | Optional link back to your own admin dashboard. Hidden when null.
    */
    'back_url' => null,
    'back_label' => 'Dashboard',

    /*
    |--------------------------------------------------------------------------
    | Audit table
    |--------------------------------------------------------------------------
    | history_limit: how many recent operations the dashboard shows (1-100).
    */
    'table' => env('OPCONSOLE_TABLE', 'opconsole_runs'),
    'connection' => env('OPCONSOLE_CONNECTION'),
    'history_limit' => (int) env('OPCONSOLE_HISTORY_LIMIT', 15),

];

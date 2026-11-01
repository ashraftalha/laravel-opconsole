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
    | Artisan actions
    |--------------------------------------------------------------------------
    | Closed allow-list. Set any entry to false to hide and disable it.
    | You cannot add new commands here — only disable the three built in.
    */
    'actions' => [
        'clear' => true,
        'optimize' => true,
        'migrate' => true,
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
    | Assets
    |--------------------------------------------------------------------------
    | OpConsole ships completely self-contained CSS, JS, and inline SVG icons
    | (publish with --tag=opconsole-assets; served via an authenticated package
    | fallback route if not published yet). No third-party CDN assets are
    | required or loaded by default. Set use_cdn to true only if a custom view
    | override explicitly expects Bootstrap 5 / Unicons from external URLs.
    */
    'assets' => [
        'use_cdn' => (bool) env('OPCONSOLE_USE_CDN', false),
        'bootstrap_css' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
        'bootstrap_js' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
        'icons_css' => 'https://cdn.jsdelivr.net/npm/@iconscout/unicons@4.0.8/css/line.css',
    ],

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
    */
    'table' => env('OPCONSOLE_TABLE', 'opconsole_runs'),
    'connection' => env('OPCONSOLE_CONNECTION'),

];

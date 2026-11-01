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
        'middleware' => ['web', 'auth'],
        'domain' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    | Two separate abilities: one to view the dashboard/logs, one to run
    | Artisan actions and delete logs. Define these Gates in your own
    | AppServiceProvider. If you do not, OpConsole registers a fail-closed
    | default: allowed only in the "local" environment.
    */
    'authorization' => [
        'view_gate' => 'viewOpConsole',
        'run_gate' => 'runOpConsoleActions',
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
    | The package ships its own CSS/JS (publish with --tag=opconsole-assets).
    | The standalone layout also needs Bootstrap 5 + Unicons for styling;
    | by default these are loaded from a CDN. Set use_cdn to false and point
    | these at self-hosted copies for a restrictive CSP / offline use.
    */
    'assets' => [
        'use_cdn' => true,
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

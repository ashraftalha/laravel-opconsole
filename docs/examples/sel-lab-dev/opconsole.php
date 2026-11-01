<?php

/**
 * Side-by-side dev/staging integration config for sel-lab.
 *
 * Purpose: let OpConsole run alongside the existing /admin/maintenance
 * implementation, at a *different* URL, so it can be exercised and compared
 * without touching or replacing anything sel-lab already has. This is a
 * starting point for local/staging verification, not a production config —
 * see docs/SEL-LAB-INTEGRATION.md for the full switch-over plan that
 * eventually preserves the /admin/maintenance URL.
 *
 * Usage: copy this file to sel-lab's config/opconsole.php after
 * `composer require` + `vendor:publish --tag=opconsole-config`, or diff
 * against it. Do not apply it automatically; sel-lab is not modified by
 * this package's own repository or tooling.
 */

return [

    'enabled' => true,

    'route' => [
        // Deliberately NOT 'admin/maintenance': the existing controller stays live and
        // reachable at its current URL. This mounts OpConsole next to it, not over it.
        'prefix' => 'admin/opconsole',
        'name_prefix' => 'opconsole.',
        // sel-lab already has a named "login" route and uses the 'auth' middleware
        // pattern elsewhere; OpConsole's own guard check (not Laravel's 'auth' alias)
        // still redirects there correctly when a login route exists.
        'middleware' => ['web', 'auth'],
        'domain' => null,
    ],

    'authentication' => [
        'guard' => null,
        'login_route' => 'login',
    ],

    /*
     * Preserves today's sel-lab policy ("any authenticated user") for this
     * side-by-side verification phase only. Define the matching Gates below
     * in a dev-only service provider — do not add them to sel-lab's real
     * AppServiceProvider as a production decision without the sign-off this
     * loosens; see docs/SEL-LAB-INTEGRATION.md step 3.
     */
    'authorization' => [
        'view_gate' => 'viewOpConsole',
        'run_gate' => 'runOpConsoleActions',
    ],

    'concurrency' => [
        'lock_store' => 'file',
        'lock_seconds' => 30,
    ],

    'actions' => [
        'clear' => true,
        'optimize' => true,
        'migrate' => true,
    ],

    'logs' => [
        'path' => storage_path('logs'),
        'allow_download' => true,
        'allow_delete' => true,
    ],

    /*
     * sel-lab's lab-specific checks (hero image, upload limits, timezone copy)
     * are intentionally left out of this example. Register them here only
     * once they've been extracted into App\Health\* classes per
     * docs/FEATURE-PARITY.md — do not re-create business logic inside the
     * package. Left empty so this file works unmodified for a first smoke test.
     */
    'checks' => [],

    'layout' => null,

    'assets' => [
        'use_cdn' => true,
        'bootstrap_css' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
        'bootstrap_js' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
        'icons_css' => 'https://cdn.jsdelivr.net/npm/@iconscout/unicons@4.0.8/css/line.css',
    ],

    // Points back at the existing dashboard, not at /admin/maintenance — this is a
    // separate, additional console during the verification phase, not a replacement.
    'back_url' => '/admin/dashboard',
    'back_label' => 'Dashboard',

    'table' => 'opconsole_runs',
    'connection' => null,

];

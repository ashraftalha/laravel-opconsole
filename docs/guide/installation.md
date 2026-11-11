---
title: Installation
---

# Installation Guide

Laravel OpConsole installs into any Laravel 12 or Laravel 13 application running PHP `^8.3` with zero external frontend build steps or third-party JavaScript/CSS CDN dependencies.

::: tip Start here
Use the **[5-minute setup](/guide/quick-start)** for a first dashboard. For production, begin with the **[read-only recipe](/advanced/production-recipes#read-only-production-recommended)** and add execution privileges only when needed.
:::

## Shared-hosting and no-SSH deployments

OpConsole can reduce routine SSH use **after installation**. It does not install dependencies, create your application's login, run deployment migrations automatically, or turn restricted web PHP into a command-line runtime.

| Host capability | What to confirm before installing | If unavailable |
|---|---|---|
| Composer and Artisan deployment access | SSH, a control-panel terminal/task, CI/CD, or a provider-managed deployment can install dependencies, run reviewed migrations, and rebuild caches | Ask the provider for a deployment path. Uploading the package alone is not a complete installation. |
| Web PHP `pcntl` functions | Availability in the actual web SAPI, not just `php -m` in a terminal | Timer-bound browser actions are disabled; use read-only diagnostics and provider/deployment tooling for maintenance. |
| Subprocesses and Composer audit | Composer 2.4+, `composer.json`, `composer.lock`, process execution, and advisory-repository network access | Security scans cannot run there; do not assume the Security tab has verified dependencies. |
| Cron and queue workers | A per-minute scheduler and a persistent worker for asynchronous queues | Heartbeats remain unknown or become stale; OpConsole does not start those services for you. |
| Writable/readable application storage | Laravel can write storage/cache and read the configured log directory | Resolve permissions with the host; filesystem-dependent checks and logs may be unavailable. |

A terminal with `pcntl` does not prove the web runtime has it. Check the disabled card's explanation and [execution environments](/reference/configuration#execution-environments). Keep production Gates in place; use [read-only access](/advanced/production-recipes#read-only-production-recommended) when hosting restrictions prevent operations.

---

## 1. Requirements

| Requirement | Supported Version |
|---|---|
| PHP | `^8.3` (`8.3`, `8.4`, `8.5`) with `mbstring`, `openssl`, `tokenizer`, `ctype`, `json`, and your database PDO extension |
| Laravel Framework | `^12.0` or `^13.0` |
| Browser-triggered actions | `pcntl` functions available to the **web** SAPI (PHP-FPM / mod_php). Without them `security_scan` and `octane_reload` remain available from the browser when enabled and their prerequisites are met; see [Execution environments](/reference/configuration#execution-environments). |

---

## 2. Install via Composer

```bash
composer require ashraftalha/laravel-opconsole
```

Laravel's package auto-discovery automatically registers `AshrafTalha\OpConsole\OpConsoleServiceProvider`.

### Local or Private Evaluation (Path / VCS Repository)

If you are evaluating a local checkout or private fork in an application such as `ExampleApplication` before installing from Packagist:

```bash
# Local directory evaluation (do not commit local filesystem paths to production composer.json)
composer config repositories.opconsole '{"type": "path", "url": "/path/to/laravel-opconsole", "options": {"symlink": false}}'
composer require ashraftalha/laravel-opconsole:@dev
```

For staging or production deployments from a VCS repository, always pin an explicit tagged release rather than a development branch.

---

## 3. Publish Configuration, Publish Assets, and Migrate

Review `php artisan migrate:status` and all pending migrations first: `migrate` applies pending application migrations as well as package migrations. Back up production data before applying reviewed schema changes.

```bash
php artisan vendor:publish --tag=opconsole-config
php artisan vendor:publish --tag=opconsole-assets
php artisan migrate
```

| Publish Tag | Target Path | Required? |
|---|---|---|
| `opconsole-config` | `config/opconsole.php` | Recommended |
| `opconsole-assets` | `public/vendor/opconsole/opconsole.{css,js,svg}` | Recommended (falls back to authenticated route `opconsole.asset` if omitted) |
| `opconsole-views` | `resources/views/vendor/opconsole/` | Optional |
| `opconsole-lang` | `lang/vendor/opconsole/` | Optional |
| `opconsole-migrations` | `database/migrations/` | Optional (`loadMigrationsFrom` runs automatically) |

> **Tip:** Add `@php artisan vendor:publish --tag=opconsole-assets --ansi --force` to your application's `post-update-cmd` array in `composer.json` so published CSS and JS stay synchronized across package updates.

---

## 4. Configure Authorization Gates

OpConsole is **fail-closed outside the `local` environment**. In `production`, `staging`, or `testing`, authenticated browser requests are denied with HTTP `403` until explicit Gates are registered. Guests redirect to login or receive `401`; the optional health endpoint uses separate bearer-token authentication.

Start with read-only access in `App\Providers\AppServiceProvider`. Replace the example email with a trusted operator; for execution privileges, use the [limited maintenance recipe](/advanced/production-recipes#limited-maintenance-administrators):

```php
<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewOpConsole', function (User $user): bool {
            return $user->email === 'operator@example.com';
        });

        Gate::define('runOpConsoleActions', fn (User $user): bool => false);

        Gate::define('downloadOpConsoleLogs', fn (User $user): bool => false);
        Gate::define('deleteOpConsoleLogs', fn (User $user): bool => false);
    }
}
```

Keep `authorization.action_gates` empty for this read-only policy. Viewing includes unredacted log tails; see the [complete production recipe](/advanced/production-recipes#read-only-production-recommended).

---

## 5. Registered Routes

With default configuration (`route.prefix = 'admin/opconsole'`, `route.name_prefix = 'opconsole.'`), OpConsole registers seven routes:

| Method | URI | Route Name | Middleware |
|---|---|---|---|
| `GET` | `/admin/opconsole` | `opconsole.index` | `web`, `opconsole.authorize:view` |
| `GET` | `/admin/opconsole/assets/{asset}` | `opconsole.asset` | `web`, `opconsole.authorize:view` |
| `GET` | `/admin/opconsole/logs/{file}` | `opconsole.log` | `web`, `opconsole.authorize:download`, `throttle:30,1,opconsole-download` |
| `DELETE` | `/admin/opconsole/logs/{file}` | `opconsole.log.delete` | `web`, `opconsole.authorize:delete`, `throttle:6,1,opconsole-delete` |
| `POST` | `/admin/opconsole/failed-jobs/{id}/retry` | `opconsole.failed_jobs.retry` | `web`, `opconsole.authorize:run`, `throttle:20,1,opconsole-retry` |
| `GET` | `/admin/opconsole/health` | `opconsole.health` | `throttle:60,1,opconsole-health` (bearer token; disabled by default) |
| `POST` | `/admin/opconsole/{action}` | `opconsole.run` | `web`, `opconsole.authorize:run`, `throttle:6,1,opconsole-run` |

If your application already has an existing maintenance or status page at another URL, keep `route.prefix` set to `'admin/opconsole'` (or any non-colliding prefix via `OPCONSOLE_ROUTE_PREFIX`) so both routes can run side by side during staging verification without route or route-name collisions.

---

## 6. Deployment & Caching Verification

OpConsole is fully compatible with Laravel's production optimization commands:

```bash
php artisan migrate --force
php artisan vendor:publish --tag=opconsole-assets --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Verification Checklist

1. Unauthenticated request to `/admin/opconsole` redirects to your named `login` route (or returns HTTP `401` when `authentication.login_route` is `null` or absent).
2. An authenticated user outside the view policy receives HTTP `403`.
3. The authorized operator loads `/admin/opconsole` (`HTTP 200`), views health checks, log tails, and any existing audit history, but cannot run actions, retry jobs, download full logs, or delete logs.

---

## 7. Disabling or Uninstalling

### Temporary Kill-Switch

Set `OPCONSOLE_ENABLED=false` in `.env` and run `php artisan optimize:clear`. OpConsole registers zero routes while disabled.

### Remove the package while retaining audit history

Package removal does not require rolling back migrations. Keep the audit table by default so incident history and retention obligations survive uninstalling.

Before removing configuration, record `opconsole.connection` and `opconsole.table` (default `opconsole_runs`) and export or back up the audit data according to your retention policy. Disable access with the kill-switch above, then run:

```bash
php artisan optimize:clear
composer remove ashraftalha/laravel-opconsole
```

Remove package-specific Gate definitions, imports, scheduled commands, and Composer publish hooks from your application. Review and remove only the published files you no longer need: `config/opconsole.php`, `public/vendor/opconsole/`, `resources/views/vendor/opconsole/`, and `lang/vendor/opconsole/`. If you published package migrations into `database/migrations/`, archive and remove those package files after deciding how to retain or clean up their schema; otherwise future migrations on a fresh database can recreate the audit table. Rebuild your normal deployment caches after cleanup.

### Optional destructive audit-table cleanup

::: danger Audit history will be permanently deleted
The table-creation migration's `down()` method drops the configured audit table and all its rows. The duration migration's `down()` method removes recorded durations. Only perform cleanup after retention approval and a verified backup/export; ordinary package removal does not need this step.
:::

Prepare cleanup while the package migration files and configuration are still available, or retain reviewed copies for a later maintenance operation:

1. Confirm the exact database connection and table, including any customized configuration. Review the actual schema and both package migrations' `down()` methods.
2. Run `php artisan migrate:status` and inspect the migration repository's names and batches on the relevant connection. Laravel rollback normally selects the last batch; `--path` alone does **not** mean “roll back every migration belonging to this package.” Check published copies as well as vendor files, and ensure no application migrations or dependent tables are affected.
3. Rehearse the exact rollback/cleanup plan on a restored copy of the database. A `--pretend` preview can help inspect SQL after reviewing the migration code, but is not a substitute for that rehearsal or a backup.
4. Only execute the reviewed, explicitly scoped cleanup during an approved maintenance window. Verify the retained export, remaining application schema, and migration repository state afterward.

There is intentionally no unconditional rollback command in the uninstall sequence. If the package is already removed, do not run a generic rollback against the application's latest batch; use the reviewed migration copies or a dedicated cleanup migration targeting the confirmed audit table.

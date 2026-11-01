# Installation Guide

Laravel OpConsole installs into any Laravel 12 or Laravel 13 application running PHP `^8.3` with zero external frontend build steps or third-party JavaScript/CSS CDN dependencies.

---

## 1. Requirements

| Requirement | Supported Version |
|---|---|
| PHP | `^8.3` (`8.3`, `8.4`, `8.5`) with `mbstring`, `openssl`, `tokenizer`, `ctype`, `json`, and your database PDO extension |
| Laravel Framework | `^12.0` or `^13.0` |

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

```bash
php artisan vendor:publish --tag=opconsole-config
php artisan vendor:publish --tag=opconsole-assets
php artisan migrate
```

| Publish Tag | Target Path | Required? |
|---|---|---|
| `opconsole-config` | `config/opconsole.php` | Recommended |
| `opconsole-assets` | `public/vendor/opconsole/opconsole.{css,js}` | Recommended (falls back to authenticated route `opconsole.asset` if omitted) |
| `opconsole-views` | `resources/views/vendor/opconsole/` | Optional |
| `opconsole-lang` | `lang/vendor/opconsole/` | Optional |
| `opconsole-migrations` | `database/migrations/` | Optional (`loadMigrationsFrom` runs automatically) |

> **Tip:** Add `@php artisan vendor:publish --tag=opconsole-assets --ansi --force` to your application's `post-update-cmd` array in `composer.json` so published CSS and JS stay synchronized across package updates.

---

## 4. Configure Authorization Gates

OpConsole is **fail-closed outside the `local` environment**. In `production`, `staging`, or `testing`, every request returns HTTP `403` until explicit Gates are registered.

Register your authorization policy in `App\Providers\AppServiceProvider`:

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
            return $user->isAdmin();
        });

        Gate::define('runOpConsoleActions', function (User $user): bool {
            return $user->isAdmin();
        });

        // Optional granular log capabilities (when omitted, download delegates to view_gate
        // and delete delegates to run_gate):
        Gate::define('downloadOpConsoleLogs', fn (User $user): bool => $user->isAdmin());
        Gate::define('deleteOpConsoleLogs', fn (User $user): bool => $user->isAdmin());
    }
}
```

---

## 5. Registered Routes

With default configuration (`route.prefix = 'admin/opconsole'`, `route.name_prefix = 'opconsole.'`), OpConsole registers five routes:

| Method | URI | Route Name | Middleware |
|---|---|---|---|
| `GET` | `/admin/opconsole` | `opconsole.index` | `web`, `opconsole.authorize:view` |
| `GET` | `/admin/opconsole/assets/{asset}` | `opconsole.asset` | `web`, `opconsole.authorize:view` |
| `GET` | `/admin/opconsole/logs/{file}` | `opconsole.log` | `web`, `opconsole.authorize:download`, `throttle:30,1,opconsole-download` |
| `DELETE` | `/admin/opconsole/logs/{file}` | `opconsole.log.delete` | `web`, `opconsole.authorize:delete`, `throttle:6,1,opconsole-delete` |
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
2. Authenticated non-admin user receives HTTP `403`.
3. Authorized administrator loads `/admin/opconsole` (`HTTP 200`), views grouped health checks, inspects logs, and sees audited entries in **Operation history** (`opconsole_runs`).

---

## 7. Disabling or Uninstalling

### Temporary Kill-Switch

Set `OPCONSOLE_ENABLED=false` in `.env` and run `php artisan optimize:clear`. OpConsole registers zero routes while disabled.

### Complete Removal

```bash
php artisan optimize:clear
php artisan migrate:rollback --path=vendor/ashraftalha/laravel-opconsole/database/migrations --force
composer remove ashraftalha/laravel-opconsole
rm -rf config/opconsole.php public/vendor/opconsole resources/views/vendor/opconsole lang/vendor/opconsole
```

Remove the `Gate::define(...)` calls from `App\Providers\AppServiceProvider`.

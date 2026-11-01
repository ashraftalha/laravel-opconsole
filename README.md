# Laravel OpConsole

**Monitor. Diagnose. Maintain.**

Laravel OpConsole is a self-contained administrative operations console that lets authorized developers and administrators monitor, diagnose, and maintain a Laravel application through a secure web interface: application health checks, three allow-listed Artisan maintenance operations (`optimize:clear`, `optimize`, `migrate --force --isolated`), an audited operation history, and a bounded log viewer.

> **Independent package notice:** Laravel OpConsole is an independent, third-party open-source Composer package. It is not an official Laravel product and is not affiliated with or endorsed by Laravel LLC.

---

## Supported Versions

| Dependency | Supported Constraint | Verified Matrix |
|---|---|---|
| PHP | `^8.3` | PHP 8.3, 8.4, 8.5 |
| Laravel Framework | `^12.0 \| ^13.0` | Laravel 12 (Orchestra Testbench 10) & Laravel 13 (Orchestra Testbench 11) |

---

## Quick Start

### 1. Install via Composer

```bash
composer require ashraftalha/laravel-opconsole
```

`AshrafTalha\OpConsole\OpConsoleServiceProvider` is registered automatically via Laravel package auto-discovery.

### 2. Publish Configuration & Assets, Then Migrate

```bash
php artisan vendor:publish --tag=opconsole-config
php artisan vendor:publish --tag=opconsole-assets
php artisan migrate
```

Optional publish tags:
- `opconsole-views` — override Blade templates under `resources/views/vendor/opconsole/`
- `opconsole-lang` — customize flash messages under `lang/vendor/opconsole/`
- `opconsole-migrations` — copy the `opconsole_runs` migration into your application's `database/migrations/`

> **Zero-CDN & asset fallback:** OpConsole ships a dependency-free stylesheet (`public/opconsole.css`), lightweight vanilla JS (`public/opconsole.js`), and inline SVG icons (`assets.use_cdn = false` by default). If `public/vendor/opconsole/` has not been published yet, OpConsole automatically serves its CSS and JS through an authenticated fallback route (`GET /admin/opconsole/assets/{asset}`).

### 3. Define Authorization Gates (Required Outside `local`)

OpConsole is **fail-closed (deny-by-default) everywhere outside the `local` environment**. Visiting `/admin/opconsole` in `production`, `staging`, or `testing` without explicit Gates returns HTTP `403`.

Define your policy in `App\Providers\AppServiceProvider::boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewOpConsole', fn (User $user): bool => $user->isAdmin());
    Gate::define('runOpConsoleActions', fn (User $user): bool => $user->isAdmin() && $user->canDeploy());

    // Optional granular log Gates (when omitted, download delegates to view_gate and delete delegates to run_gate):
    Gate::define('downloadOpConsoleLogs', fn (User $user): bool => $user->isAdmin());
    Gate::define('deleteOpConsoleLogs', fn (User $user): bool => $user->isAdmin() && $user->canDeploy());
}
```

Open `/admin/opconsole` while signed in as an authorized user.

---

## Permissions & Authentication Model

### Four Distinct Authorization Capabilities

| Capability | Config Key | Default Gate Name | Fallback If Undefined | Endpoints Controlled |
|---|---|---|---|---|
| View console & logs | `authorization.view_gate` | `viewOpConsole` | `local` only (`403` elsewhere) | `GET /admin/opconsole`, `GET /admin/opconsole/assets/{asset}` |
| Run Artisan actions | `authorization.run_gate` | `runOpConsoleActions` | `local` only (`403` elsewhere) | `POST /admin/opconsole/{action}` |
| Download raw log files | `authorization.download_gate` | `downloadOpConsoleLogs` | Delegates to `view_gate` | `GET /admin/opconsole/logs/{file}` |
| Delete rotated log files | `authorization.delete_gate` | `deleteOpConsoleLogs` | Delegates to `run_gate` | `DELETE /admin/opconsole/logs/{file}` |

- Host-defined Gates always take precedence over default Gates, even in `local`.
- Setting any Gate config key to `null`, `''`, or an undefined custom Gate name fails closed (`403`) in all environments.

### Guests, Guards, and Missing `login` Routes

OpConsole authenticates requests through `AuthorizeOpConsole` rather than Laravel's `auth` middleware alias:

- **`authentication.guard`:** When set (for example `'admin'`), OpConsole authenticates against that guard, binds `Auth::shouldUse($guard)` for the request, evaluates `Gate::forUser($guardUser)`, and attributes `opconsole_runs` audit rows to that guard's user. Invalid guard names fail closed (`403`).
- **`authentication.login_route`:** Guests are redirected to `route('login')` when that route exists and requires no URL parameters. If the host has no `login` route, disables `login_route` (`null`), or uses a parameterized route (e.g., `/{locale}/login`), guests receive a clean HTTP `401` instead of an uncaught `RouteNotFoundException` / `UrlGenerationException` (`500`).
- **Keep `route.middleware` as `['web']`:** Do not add `'auth'` to `route.middleware`. Even if `route.middleware` is overridden, OpConsole always keeps `'web'` active so session state and CSRF protection cannot be accidentally stripped.

---

## UI Overview

OpConsole renders a focused, responsive developer console at `/admin/opconsole` without relying on a host admin theme:

1. **Header & Environment Badge:** Displays the active environment (`production`, `staging`, `local`) and highlights `debug` mode warnings, alongside a `Refresh status` action and optional `back_url` link.
2. **At-a-Glance Summary Tiles:** Three status cards for **Database** (driver, connection health, applied/pending migrations), **Health checks** (verified healthy count vs critical/warning/unknown counts), and **Errors in the last 24 hours** (scoped to the newest valid log file).
3. **Prioritized Operational Recommendations:** Actionable banners ordered critical-first (`APP_DEBUG=true` in production, database connection failures, pending migrations, uncached production config/routes, recent errors).
4. **Maintenance Operations:** Cards for `Clear caches` (`Low risk · Cache flush`), `Optimize performance` (`Low risk · Compile caches`), and `Run migrations` (`High risk · Schema change`). High-risk actions carry distinct visual treatment, explicit disabled explanations (e.g. read-only user or zero pending migrations), native `<dialog>` modal confirmations showing the exact Artisan command, and sanitized console output panels.
5. **Health Checks (`#checks`):** Grouped diagnostics (`Security`, `Files and folders`, `PHP settings`, `Database and queues`, `URL and email`, plus custom groups). Auto-expands whenever critical or warning issues exist and distinguishes `Healthy`, `Warning`, `Critical`, and `Unknown` (unverified) states.
6. **Operation History (`#history`):** Bounded table of the 15 most recent audited runs showing operation, status (`Succeeded`, `Failed`, `Started`, or `Interrupted / Unknown`), actor, relative + UTC start timestamp, execution duration, and sanitized details.
7. **System Information:** Collapsible runtime and driver summary with one-click `Copy` for support tickets (no raw credentials or `.env` secrets).
8. **Application Logs (`#logs`):** File selector (newest first, total disk usage, delete button on non-active rotated files), environment chips, log-level chips, case-insensitive text search with XSS-safe `<mark>` highlighting, collapsible repeated-error groups, expandable stack traces, and per-entry copy buttons. Supports both standard multiline Laravel/Monolog text logs and NDJSON (`JsonFormatter`) log lines.

---

## Configuration Reference

All options live in `config/opconsole.php` and contain zero closures (`php artisan config:cache` compatible):

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `env('OPCONSOLE_ENABLED', true)` | Master switch; when `false`, registers zero routes |
| `route.prefix` | `'admin/opconsole'` | URL prefix |
| `route.name_prefix` | `'opconsole.'` | Named route prefix (`index`, `asset`, `log`, `log.delete`, `run`) |
| `route.middleware` | `['web']` | Additional middleware (`'web'` is always enforced) |
| `route.domain` | `null` | Optional subdomain restriction |
| `authentication.guard` | `null` | Auth guard name (`null` uses Laravel's default guard) |
| `authentication.login_route` | `'login'` | Named login route for guests, or `null` to always return `401` |
| `authorization.view_gate` | `'viewOpConsole'` | Gate for viewing dashboard & logs |
| `authorization.run_gate` | `'runOpConsoleActions'` | Gate for running allow-listed Artisan commands |
| `authorization.download_gate` | `'downloadOpConsoleLogs'` | Gate for downloading raw `.log` files |
| `authorization.delete_gate` | `'deleteOpConsoleLogs'` | Gate for deleting older `.log` files |
| `concurrency.lock_store` | `env('OPCONSOLE_LOCK_STORE', 'opconsole')` | Isolated atomic lock store (must not flush with `cache.default`) |
| `concurrency.lock_seconds` | `30` | Lock TTL in seconds (minimum `5`) |
| `actions.clear` | `true` | Enable `php artisan optimize:clear` |
| `actions.optimize` | `true` | Enable `php artisan optimize` |
| `actions.migrate` | `true` | Enable `php artisan migrate --force --isolated` |
| `logs.path` | `storage_path('logs')` | Directory scanned for `^[\w.-]+\.log$` files |
| `logs.allow_download` | `true` | Permit raw log downloads (still requires `download_gate`) |
| `logs.allow_delete` | `true` | Permit deleting non-newest logs (still requires `delete_gate`) |
| `checks` | `[]` | List of custom `AshrafTalha\OpConsole\Contracts\HealthCheck` classes |
| `layout` | `null` | Optional host Blade layout to `@extends(...)` |
| `assets.use_cdn` | `false` | Optional legacy Bootstrap/Unicons CDN loading (off by default) |
| `back_url` / `back_label` | `null` / `'Dashboard'` | Optional header link back to your main admin panel |
| `table` / `connection` | `'opconsole_runs'` / `null` | Audit table name and database connection |

---

## Custom Health Checks

Register custom checks implementing `AshrafTalha\OpConsole\Contracts\HealthCheck` in `config/opconsole.php`:

```php
// config/opconsole.php
'checks' => [
    App\Health\ExampleHealthCheck::class,
],
```

```php
namespace App\Health;

use AshrafTalha\OpConsole\Contracts\HealthCheck;

final class ExampleHealthCheck implements HealthCheck
{
    public function run(): array
    {
        return [
            'id' => 'worker-heartbeat', // optional stable slug (prefixed as custom.worker-heartbeat)
            'group' => 'Workers',
            'label' => 'Background worker heartbeat',
            'ok' => true,
            'status' => 'healthy',      // optional: 'healthy' | 'warning' | 'critical' | 'unknown'
            'severity' => 'warning',    // 'danger' | 'warning' | 'info'
            'hint' => 'Checks the cached worker heartbeat timestamp.',
            'value' => 'active',
            'fix' => 'php artisan queue:work',
        ];
    }
}
```

- Custom check classes are resolved through the Laravel service container.
- Exceptions, invalid return shapes, duplicate check classes, or checks colliding with built-in `(group, label)` pairs are logged and skipped without breaking the dashboard.

---

## Artisan Operations & Locking Guarantees

OpConsole maintains a **closed allow-list** of three commands (`ActionRunner::ACTIONS`):

1. `optimize:clear`
2. `optimize`
3. `migrate --force --isolated`

You can disable any command via `config('opconsole.actions')`, and you can never add arbitrary commands or pass user-controlled arguments.

### Fail-Closed Action Locking

Running `optimize:clear` invokes `cache:clear`, which flushes `cache.default`. If OpConsole stored its mutual-exclusion lock in `cache.default`, `optimize:clear` would delete its own lock mid-execution.

To prevent this:
- OpConsole auto-registers an isolated file lock store named `opconsole` (`storage/framework/cache/opconsole`) and defaults `concurrency.lock_store` to `'opconsole'`.
- Before running any command, `ActionRunner::validateLockStore()` verifies that the store exists, implements `LockProvider`, does not return `NoLock`, does not use `ArrayStore` outside unit tests, and does not share flushable storage with `cache.default`.
- If validation or lock acquisition fails, OpConsole **fails closed**: Artisan is never invoked, a failed audit record is written, and the operator receives a clear diagnostic message.
- **Multi-server note:** Filesystem locks coordinate processes on a **single server** sharing local disk. Load-balanced multi-server deployments should configure `OPCONSOLE_LOCK_STORE` to an isolated `redis` or `database` cache store with dedicated lock storage.

---

## Troubleshooting

| Symptom | Cause | Resolution |
|---|---|---|
| `403 Forbidden` on `/admin/opconsole` | Running outside `local` without `viewOpConsole` Gate defined, or signed-in user failed Gate check | Define `viewOpConsole` (and `runOpConsoleActions`) in `AppServiceProvider::boot()` |
| `401 Unauthorized` as a guest instead of redirect | Host app has no named `login` route, `login_route` is `null`, or `login` route requires URL parameters | Set `opconsole.authentication.login_route` to a parameterless named login route, or sign in first |
| `"Action blocked: lock store [...] is unavailable or unsafe"` | `OPCONSOLE_LOCK_STORE` points to `null`, `array`, a missing store, or overlaps `cache.default` | Set `OPCONSOLE_LOCK_STORE=opconsole` (single server) or an isolated `redis`/`database` store |
| `"Audit table not migrated"` in Operation history | `php artisan migrate` has not been run yet | Run `php artisan migrate` to create `opconsole_runs` |
| Queue / disk check shows `Unknown` | External broker (`redis`/`sqs`) is not probed on web requests, `failed_jobs` table is absent, or `disk_free_space()` is disabled by PHP | Expected fail-honest status; verify external brokers via CLI/worker monitoring |

---

## Security Limitations

- **Application log contents are not redacted:** `storage/logs/*.log` may contain SQL parameters, emails, tokens, or stack traces written by the host application. Grant `viewOpConsole` and `downloadOpConsoleLogs` only to trusted administrators, and set `logs.allow_download => false` if raw exports are unnecessary.
- **Synchronous request lifecycle:** Artisan actions execute synchronously within the HTTP request. Applications with long-running migrations that exceed PHP-FPM or reverse-proxy timeouts should disable `actions.migrate` (`false`) and run migrations from CI/CD or SSH. If a request is terminated mid-run by PHP/web server timeout, its `started` row in `opconsole_runs` will automatically surface as `Interrupted / Unknown` once the lock window expires.
- **Not Laravel Maintenance Mode:** OpConsole does not run `php artisan down` or `php artisan up`.

See [`docs/SECURITY.md`](docs/SECURITY.md) for the full threat model and [`docs/FINAL-ENGINEERING-AUDIT.md`](docs/FINAL-ENGINEERING-AUDIT.md) for the complete security & engineering audit.

---

## Upgrading & Uninstalling

### Upgrading

See [`docs/UPGRADE.md`](docs/UPGRADE.md) for full upgrade instructions:

```bash
composer update ashraftalha/laravel-opconsole
php artisan vendor:publish --tag=opconsole-assets --force
php artisan optimize:clear
```

### Disabling Temporarily

Set `OPCONSOLE_ENABLED=false` in `.env` and run `php artisan optimize:clear` — zero routes will be registered.

### Uninstalling Completely

```bash
php artisan optimize:clear
php artisan migrate:rollback --path=vendor/ashraftalha/laravel-opconsole/database/migrations --force
composer remove ashraftalha/laravel-opconsole
rm -rf config/opconsole.php public/vendor/opconsole resources/views/vendor/opconsole lang/vendor/opconsole
```

Remove any `Gate::define('viewOpConsole', ...)` / `runOpConsoleActions` / `downloadOpConsoleLogs` / `deleteOpConsoleLogs` definitions from your `AppServiceProvider`.

---

## Additional Documentation

- [`docs/INSTALLATION.md`](docs/INSTALLATION.md) — Step-by-step installation, publishing, and route overview
- [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) — Complete configuration reference, read-only preset, and custom health checks
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — Component boundaries and request flows
- [`docs/SECURITY.md`](docs/SECURITY.md) — Threat model, fail-closed locking, and authorization design
- [`docs/UPGRADE.md`](docs/UPGRADE.md) — Upgrade path from `v0.1.0`
- [`docs/RELEASE-CHECKLIST.md`](docs/RELEASE-CHECKLIST.md) — Pre-release verification checklist
- [`docs/FINAL-ENGINEERING-AUDIT.md`](docs/FINAL-ENGINEERING-AUDIT.md) — Evidence-based engineering audit and benchmarks
- [`docs/INDEPENDENCE-AUDIT.md`](docs/INDEPENDENCE-AUDIT.md) — Repository cleanup and standalone independence verification
- [`docs/ASHRAFTALHA-INTEGRATION.md`](docs/ASHRAFTALHA-INTEGRATION.md) — Read-only rollout & integration guide for `ashraftalha.com`

## License

MIT.

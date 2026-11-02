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

> **Self-contained assets:** OpConsole ships dependency-free local CSS (`public/opconsole.css`), vanilla JS (`public/opconsole.js`), and inline SVG icons with zero third-party CDN requests. If `public/vendor/opconsole/` has not been published yet, OpConsole serves its CSS and JS automatically through an authenticated fallback route (`GET /admin/opconsole/assets/{asset}`).

### 3. Define Authorization Gates (Required Outside `local`)

OpConsole is **fail-closed (deny-by-default) everywhere outside the `local` environment**. Visiting `/admin/opconsole` in `production`, `staging`, or `testing` without explicit Gates returns HTTP `403`.

Define your authorization policy in `App\Providers\AppServiceProvider::boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewOpConsole', fn (User $user): bool => $user->isAdmin());
    Gate::define('runOpConsoleActions', fn (User $user): bool => $user->isAdmin() && $user->canDeploy());

    // Optional granular log Gates (default to view_gate and run_gate when omitted):
    Gate::define('downloadOpConsoleLogs', fn (User $user): bool => $user->isAdmin());
    Gate::define('deleteOpConsoleLogs', fn (User $user): bool => $user->isAdmin() && $user->canDeploy());
}
```

Open `/admin/opconsole` while signed in as an authorized user.

---

## Capabilities Overview

1. **Summary Tiles & Recommendations:** At-a-glance status for database connectivity/migrations, grouped health checks, and 24-hour error counts, paired with prioritized operational advice.
2. **Controlled Artisan Operations:** Closed allow-list of `optimize:clear`, `optimize`, and `migrate --force --isolated` (`ActionRunner::ACTIONS`). Protected by risk-graded native `<dialog>` confirmations, an isolated fail-closed atomic lock store (`cache.stores.opconsole`), and credential/path-redacted console output.
3. **4-State Health Checks (`#checks`):** Built-in diagnostics (`Security`, `Files and folders`, `PHP settings`, `Database and queues`, `URL and email`) plus custom `AshrafTalha\OpConsole\Contracts\HealthCheck` classes, distinguishing `Healthy`, `Warning`, `Critical`, and `Unknown` (unverified) states.
4. **Operation History (`#history`):** Bounded table of the 15 most recent audited runs (`Succeeded`, `Failed`, `Started`, `Interrupted / Unknown`) with actor attribution, UTC timestamps, and execution duration.
5. **System Information:** Collapsible runtime and driver summary with one-click clipboard copy and zero raw secrets.
6. **Bounded Log Viewer (`#logs`):** Reads the latest `2 MB` tail of `storage/logs/*.log` (multiline Monolog text and NDJSON `JsonFormatter`), supporting environment/level chips, XSS-safe `<mark>` search highlighting, repeated-error grouping, raw download, and rotated-file deletion (active newest file protected).

---

## Security & Operational Guardrails

- **Four distinct authorization Gates:** `viewOpConsole`, `runOpConsoleActions`, `downloadOpConsoleLogs`, and `deleteOpConsoleLogs`, evaluated against `config('opconsole.authentication.guard')`. Undefined custom Gate names, empty Gate names, or invalid guard names fail closed (`403`).
- **Safe guest handling:** `AuthorizeOpConsole` handles guest checks directly rather than relying on Laravel's `auth` middleware alias, returning a clean `401` whenever a named `login` route is absent, disabled (`null`), parameterized, or requested as JSON.
- **Mandatory CSRF & session preservation:** `'web'` middleware remains active even when `config('opconsole.route.middleware')` is customized.
- **Fail-closed lock isolation:** `ActionRunner::validateLockStore()` blocks Artisan execution if the lock store is missing, non-locking, ephemeral outside unit tests, or shares flushable storage with `cache.default`. Multi-server deployments should set `OPCONSOLE_LOCK_STORE` to an isolated `redis` or `database` lock store.
- **Log sensitivity & request timeouts:** Raw log lines are not redacted; grant `viewOpConsole` and `downloadOpConsoleLogs` only to trusted administrators. Artisan commands run synchronously within the HTTP request (disable `actions.migrate` if your application runs long schema migrations over slow web timeouts). OpConsole does not toggle `php artisan down` / `up`.

---

## Troubleshooting

| Symptom | Cause | Resolution |
|---|---|---|
| `403 Forbidden` on `/admin/opconsole` | Running outside `local` without `viewOpConsole` Gate defined, or user failed Gate check | Define `viewOpConsole` (and `runOpConsoleActions`) in `AppServiceProvider::boot()` |
| `401 Unauthorized` as a guest instead of redirect | Host has no parameterless named `login` route, `login_route` is `null`, or request expects JSON | Set `opconsole.authentication.login_route` to a parameterless named route, or sign in first |
| `"Operation blocked: action lock store [...] is unsafe or unavailable"` | `OPCONSOLE_LOCK_STORE` points to `null`, `array`, a missing store, or overlaps `cache.default` | Set `OPCONSOLE_LOCK_STORE=opconsole` (single server) or an isolated `redis`/`database` store |
| `"Audit table not migrated"` in Operation history | `php artisan migrate` has not been run yet | Run `php artisan migrate` to create `opconsole_runs` |
| Queue / disk check shows `Unknown` | External broker (`redis`/`sqs`) is not probed during web requests, `failed_jobs` table is absent, or `disk_free_space()` is unavailable | Expected fail-honest status; verify external queue workers via CLI monitoring |

---

## Documentation

- [`docs/INSTALLATION.md`](docs/INSTALLATION.md) — Step-by-step installation, publishing, routes, caching, and uninstallation
- [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) — Complete `config/opconsole.php` reference, read-only preset, and custom `HealthCheck` guide
- [`docs/SECURITY.md`](docs/SECURITY.md) — Threat model, fail-closed locking, and authorization design
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — Component boundaries and HTTP request flows
- [`docs/UPGRADE.md`](docs/UPGRADE.md) — Upgrade guide from `v0.1.0`
- [`docs/RELEASE-CHECKLIST.md`](docs/RELEASE-CHECKLIST.md) — Pre-release verification checklist

## License

MIT.

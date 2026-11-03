# Configuration Guide

Publish the package configuration file to `config/opconsole.php`:

```bash
php artisan vendor:publish --tag=opconsole-config
```

`config/opconsole.php` contains plain arrays and scalars only (zero closures), so `php artisan config:cache` works without modification.

---

## 1. Complete Option Reference

### Master Switch

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `enabled` | `OPCONSOLE_ENABLED` | `true` | When `false`, OpConsole skips route registration completely. |

### Routing (`route.*`)

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `route.prefix` | `OPCONSOLE_ROUTE_PREFIX` | `'admin/opconsole'` | URL prefix for all OpConsole endpoints. |
| `route.name_prefix` | — | `'opconsole.'` | Prefix prepended to `index`, `asset`, `log`, `log.delete`, and `run`. |
| `route.middleware` | — | `['web']` | Middleware applied to the route group. `'web'` is always kept active to preserve session and CSRF protection. Do **not** add `'auth'` here; `AuthorizeOpConsole` handles guest authentication safely. |
| `route.domain` | — | `null` | Optional subdomain constraint (e.g. `'ops.example.com'`). |

### Authentication (`authentication.*`)

| Key | Default | Description |
|---|---|---|
| `authentication.guard` | `null` | Guard used to authenticate requests, bind `Auth::shouldUse($guard)`, evaluate `Gate::forUser($user)`, and attribute `opconsole_runs` rows. `null` uses Laravel's default guard; invalid guard names fail closed (`403`). |
| `authentication.login_route` | `'login'` | Named parameterless login route for guest redirects. Set to `null` (or if the host has no parameterless route with this name) to return HTTP `401` instead of redirecting. |

### Authorization (`authorization.*`)

| Key | Default | Description |
|---|---|---|
| `authorization.view_gate` | `'viewOpConsole'` | Gate required to view `/admin/opconsole` and fallback assets. |
| `authorization.run_gate` | `'runOpConsoleActions'` | Gate required to execute enabled Artisan operations. |
| `authorization.download_gate` | `'downloadOpConsoleLogs'` | Gate required to download raw `.log` files (delegates to `view_gate` if not explicitly defined by the host). |
| `authorization.delete_gate` | `'deleteOpConsoleLogs'` | Gate required to delete older `.log` files (delegates to `run_gate` if not explicitly defined by the host). |

### Concurrency & Action Locking (`concurrency.*`)

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `concurrency.lock_store` | `OPCONSOLE_LOCK_STORE` | `'opconsole'` | Cache store used for mutual-exclusion locking across Artisan runs. Must support atomic locks and must not share flushable storage with `cache.default`. |
| `concurrency.lock_seconds` | `OPCONSOLE_LOCK_SECONDS` | `30` | Lock TTL in seconds (enforced minimum: `5`). |

- **Single-server deployments:** Keep `'opconsole'`. `OpConsoleServiceProvider` registers `cache.stores.opconsole` automatically under `storage/framework/cache/opconsole`, isolated from `storage/framework/cache/data` so `optimize:clear` (`cache:clear`) cannot wipe an active lock.
- **Multi-server deployments:** Configure a dedicated `redis` or `database` cache store with separate lock storage (`lock_connection` or `cache_locks` table) and set `OPCONSOLE_LOCK_STORE` to that store name.

### Allow-Listed Artisan Actions (`actions.*`)

| Key | Default | Artisan Command Executed |
|---|---|---|
| `actions.clear` | `true` | `php artisan optimize:clear` |
| `actions.optimize` | `true` | `php artisan optimize` |
| `actions.migrate` | `true` | `php artisan migrate --force --isolated` |

Setting an action to `false` hides its card in the UI and causes `POST /admin/opconsole/{action}` to return `404`. Arbitrary commands cannot be added.

### Log Viewer (`logs.*`)

| Key | Default | Description |
|---|---|---|
| `logs.path` | `storage_path('logs')` | Directory scanned for readable `^[\w.-]+\.log$` files (enforced with `realpath()` containment). |
| `logs.allow_download` | `true` | When `false`, hides download buttons and aborts `GET /admin/opconsole/logs/{file}` with `403`. |
| `logs.allow_delete` | `true` | When `false`, hides delete buttons and aborts `DELETE /admin/opconsole/logs/{file}` with `403`. Even when `true`, the newest valid log file can never be deleted. |

### UI, Layout & Audit Storage

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `checks` | — | `[]` | Array of class names implementing `AshrafTalha\OpConsole\Contracts\HealthCheck`. |
| `layout` | — | `null` | `null` renders OpConsole's standalone layout. Set to a host Blade view name (must provide `@yield('content')`, `@stack('styles')`, and `@stack('scripts')`) to embed inside an existing admin shell. |
| `back_url` | — | `null` | Optional URL rendered as a back link in the console header (hidden when `null`). |
| `back_label` | — | `'Dashboard'` | Label text for `back_url`. |
| `table` | `OPCONSOLE_TABLE` | `'opconsole_runs'` | Database table name for operation audit records. |
| `connection` | `OPCONSOLE_CONNECTION` | `null` | Database connection name for `opconsole_runs` (`null` uses the application's default connection). |
| `history_limit` | `OPCONSOLE_HISTORY_LIMIT` | `15` | Number of recent operations shown in Operation history (clamped to `1`–`100`). |

---

## 2. Read-Only Diagnostic Preset

For conservative production rollouts where operators should inspect health checks, operation history, and in-browser log tails without running Artisan commands or exporting/deleting log files:

```php
// config/opconsole.php
'actions' => [
    'clear' => false,
    'optimize' => false,
    'migrate' => false,
],

'logs' => [
    'path' => storage_path('logs'),
    'allow_download' => false,
    'allow_delete' => false,
],
```

---

## 3. Registering Custom Health Checks

Implement `AshrafTalha\OpConsole\Contracts\HealthCheck` in your application (for example `App\Health\ExampleHealthCheck`) and list the class in `config('opconsole.checks')`:

```php
// config/opconsole.php
'checks' => [
    App\Health\ExampleHealthCheck::class,
],
```

```php
<?php

namespace App\Health;

use AshrafTalha\OpConsole\Contracts\HealthCheck;

final class ExampleHealthCheck implements HealthCheck
{
    public function run(): array
    {
        $healthy = cache()->get('worker:heartbeat') !== null;

        return [
            'id' => 'worker-heartbeat',  // optional stable slug (stored as custom.worker-heartbeat)
            'group' => 'Workers',
            'label' => 'Background worker heartbeat',
            'ok' => $healthy,
            'status' => $healthy ? 'healthy' : 'warning', // optional: 'healthy' | 'warning' | 'critical' | 'unknown'
            'severity' => 'warning',                      // 'danger' | 'warning' | 'info'
            'hint' => 'Verifies that the scheduled worker updated its heartbeat within the expected window.',
            'value' => $healthy ? 'recent' : 'stale',
            'fix' => $healthy ? null : 'php artisan queue:work',
        ];
    }
}
```

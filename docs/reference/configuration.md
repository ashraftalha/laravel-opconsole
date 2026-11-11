---
title: Configuration Reference
description: "Every Laravel OpConsole configuration key in config/opconsole.php, with its environment variable, default value and what it controls."
---

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
| `route.name_prefix` | — | `'opconsole.'` | Prefix prepended to `index`, `asset`, `log`, `log.delete`, `failed_jobs.retry`, `run`, and `health`. |
| `route.middleware` | — | `['web']` | Middleware applied to browser routes; the bearer-token health endpoint is outside this group. `'web'` is always kept active to preserve session and CSRF protection. Do **not** add `'auth'` here; `AuthorizeOpConsole` handles guest authentication safely. |
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
| `authorization.action_gates` | `[]` | Optional map from action keys (`clear`, `optimize`, `migrate`, `down`, `up`, `storage_link`, `security_scan`, `octane_reload`) to independent Gate names. Missing keys inherit `run_gate`; configured but undefined/empty Gates deny access. `queue_retry` can additionally restrict retries, which also require `run_gate` at the route middleware. |

**Least privilege in production:** by default, everyone who passes `run_gate` can also run the high-risk actions (`migrate`, `down`) and delete logs. Log deletion destroys forensic evidence. Define `deleteOpConsoleLogs` explicitly, and map `migrate`/`down` to a narrower Gate in `action_gates`, as in the example below.

### Security audit (`security.*`)

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `security.composer` | `OPCONSOLE_COMPOSER` | `composer` | Composer executable used by the CLI audit command. |
| `security.timeout` | `OPCONSOLE_SECURITY_TIMEOUT` | `120` | Maximum audit process runtime in seconds. |
| `security.max_age_seconds` | `OPCONSOLE_SECURITY_MAX_AGE` | `604800` | Maximum age in seconds before a successful report is shown as stale. |
| `security.include_dev` | `OPCONSOLE_SECURITY_INCLUDE_DEV` | `false` | Include `require-dev` lock entries in the normal scan. CLI `--include-dev` enables it for one run. |
| `security.store` | `OPCONSOLE_SECURITY_STORE` | `opconsole` | Cache store for reports and scan concurrency lock; use shared persistent locking cache across servers. |
| `security.schedule` | `OPCONSOLE_SECURITY_SCHEDULE` | `false` | Schedule a daily `opconsole:security` scan. Laravel scheduler must be running. |
| `security.trusted_proxy_https` | `OPCONSOLE_SECURITY_TRUSTED_PROXY_HTTPS` | `false` | Assert that correctly configured trusted proxies and the TLS edge enforce HTTPS. |
| `security.risky_actions` | — | `['migrate', 'down']` | Enabled OpConsole actions listed as security configuration warnings. |

See [Security](/reference/security) for Composer prerequisites, data sent to advisory sources, report freshness, and failure behavior.

For example, let support staff clear caches while only administrators can migrate or change maintenance mode:

```php
// AppServiceProvider::boot(); import App\Models\User and Illuminate\Support\Facades\Gate.
// hasRole() and is_admin must be provided by your application.
Gate::define('clearOpConsoleCaches', fn (User $user) => $user->hasRole('support') || $user->is_admin);
Gate::define('manageOpConsole', fn (User $user) => $user->is_admin);
```

Merge these keys into the existing authorization configuration; keep your view, fallback run, and log Gates defined.

```php
// config/opconsole.php
'authorization' => [
    'run_gate' => 'runOpConsoleActions', // compatibility fallback for unmapped actions
    'action_gates' => [
        'clear' => 'clearOpConsoleCaches',
        'migrate' => 'manageOpConsole',
        'down' => 'manageOpConsole',
        'up' => 'manageOpConsole',
    ],
],
```

### Concurrency & Action Locking (`concurrency.*`)

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `concurrency.lock_store` | `OPCONSOLE_LOCK_STORE` | `'opconsole'` | Cache store used for mutual-exclusion locking across Artisan runs. Must support atomic locks and must not share flushable storage with `cache.default`. |
| `concurrency.lock_seconds` | `OPCONSOLE_LOCK_SECONDS` | `1800` | Initial lock lease. It is raised to at least the maximum runtime plus 60 seconds, so the lease always outlasts the runtime budget. |
| `concurrency.max_runtime_seconds` | `OPCONSOLE_MAX_RUNTIME_SECONDS` | `1500` | Maximum synchronous command runtime, enforced with a `pcntl` alarm. A command that exceeds it is interrupted (it may have partially applied), recorded as `failed`, and its lock released. See *Execution environments* below. |

- **Single-server deployments:** Keep `'opconsole'`. `OpConsoleServiceProvider` registers `cache.stores.opconsole` automatically under `storage/framework/cache/opconsole`, isolated from `storage/framework/cache/data` so `optimize:clear` (`cache:clear`) cannot wipe an active lock.
- **Multi-server deployments:** Configure a dedicated `redis` or `database` cache store with separate lock storage (`lock_connection` or `cache_locks` table) and set `OPCONSOLE_LOCK_STORE` to that store name.
- **Lease renewal:** while a command runs, the owner renews its lease every 10 seconds on drivers that support `refresh()` (Laravel 12.63+ / 13.16+). Older releases run on the initial lease alone, which is safe because the lease always exceeds `max_runtime_seconds`. A renewal reporting that another owner holds the lock interrupts the command; a transient store error during renewal does not.
- **Process death:** a PHP fatal error (for example memory exhaustion) closes the audit row as `failed` and releases the lock from a shutdown hook. A hard kill (`SIGKILL`, PHP-FPM `request_terminate_timeout`) leaves a `started` row, shown as *Interrupted / Unknown*, and the lock expires with its lease.

#### Execution environments

Actions run synchronously inside the HTTP request that triggered them.

| Runtime | Supported actions |
|---|---|
| PHP CLI, or PHP-FPM / mod_php with the `pcntl` functions available | All enabled actions. |
| PHP-FPM / mod_php **without** `pcntl` (Debian/Ubuntu `php.ini` disables `pcntl_*` for web SAPIs by default) | `security_scan` (Composer subprocess timeouts) and `octane_reload` (signals the running Octane server), when enabled and their prerequisites are met. Timer-bound actions are refused before taking the lock or running; their cards are disabled, the reason is shown, and refusals are audited. Run the equivalent `php artisan` command from the CLI or your deploy pipeline, or enable `pcntl` for the web SAPI. |
| Windows | `pcntl` does not exist; as above. |

While a timer-bound action runs, OpConsole calls `ignore_user_abort(true)` so a browser or proxy disconnect cannot abort a migration halfway. It raises a lower non-zero `max_execution_time` to `max_runtime_seconds + 30` so its own timeout, not a PHP fatal error, ends an over-long command; it never lowers an existing limit. Web server and proxy timeouts (for example nginx `fastcgi_read_timeout`) may still end the HTTP response earlier. The command keeps running, and its outcome appears in Operation history.

### Audit Policy (`audit.*`)

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `audit.policy` | `OPCONSOLE_AUDIT_POLICY` | `'best_effort'` | `best_effort`: an unavailable audit table never blocks an operation; the result shows a warning. `required`: Artisan actions, log deletion, and log downloads are refused unless their audit row is written **before** the operation starts. Any value other than unset or `best_effort` is treated as `required`. |

Under both policies, a command that has already run keeps its real outcome when only the final audit update fails: success stays success, with an "audit record could not be saved" warning. Execution failures, audit persistence failures, and interrupted runs (*Interrupted / Unknown*) are reported separately.

### Heartbeats (`heartbeats.*`)

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `heartbeats.store` | `OPCONSOLE_HEARTBEAT_STORE` | `null` | Cache store for heartbeat timestamps (`null` uses `cache.default`). Must be shared by every server. |
| `heartbeats.scheduler` | `OPCONSOLE_HEARTBEAT_SCHEDULER` | `true` | Registers a scheduled callback that writes `opconsole:heartbeat:scheduler` every minute and enables the `scheduler.heartbeat` check. |
| `heartbeats.scheduler_max_age` | `OPCONSOLE_HEARTBEAT_SCHEDULER_MAX_AGE` | `120` | Seconds before the scheduler heartbeat counts as stale (enforced minimum: `60`). Stale is a warning outside production and critical in production. |
| `heartbeats.queue` | `OPCONSOLE_HEARTBEAT_QUEUE` | `true` | The scheduler dispatches `OpConsoleHeartbeatJob` every minute; a worker processing it writes `opconsole:heartbeat:queue` and enables the `queue.worker_heartbeat` check. Works with any driver, including Redis and SQS. |
| `heartbeats.queue_max_age` | `OPCONSOLE_HEARTBEAT_QUEUE_MAX_AGE` | `300` | Seconds before the queue heartbeat counts as stale (enforced minimum: `60`). |
| `heartbeats.queue_connection` | `OPCONSOLE_HEARTBEAT_QUEUE_CONNECTION` | `null` | Queue connection for the heartbeat job (`null` uses `queue.default`). Skipped with an info status when its driver is `sync` or `null`. |
| `heartbeats.queue_name` | `OPCONSOLE_HEARTBEAT_QUEUE_NAME` | `null` | Queue name for the heartbeat job (`null` uses the connection's default queue). |

- `optimize:clear` flushes `cache.default`, so with the default store the heartbeat checks read `unknown` for up to a minute afterwards.
- `queue.worker_heartbeat` reads `unknown` while the scheduler heartbeat is missing or stale (no job can be expected) and for the first `queue_max_age` seconds after the first dispatch.
- Array and null cache drivers never persist between processes, so the checks stay `unknown` with those drivers.

### Disk Space (`disk.*`)

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `disk.min_free_percent` | `OPCONSOLE_DISK_MIN_FREE_PERCENT` | `10` | `files.disk_space` warns when free space drops below this share of the volume, in addition to the absolute limits (warning under 1 GB, critical under 256 MB). |

### Health Endpoint (`health_endpoint.*`)

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `health_endpoint.enabled` | `OPCONSOLE_HEALTH_ENDPOINT` | `false` | Enables `GET {prefix}/health`. Returns `404` while disabled or while no token is set. |
| `health_endpoint.token` | `OPCONSOLE_HEALTH_TOKEN` | `null` | Bearer token, compared with `hash_equals`. A wrong or missing token returns `401`. |
| `health_endpoint.rate_limit` | `OPCONSOLE_HEALTH_RATE_LIMIT` | `60` | Requests per minute (route-level `throttle`). |

The endpoint runs without the `web` middleware group (no session, cookies, or CSRF) and responds with `{status, checked_at, checks: [{id, group, label, status, value}]}`: no hints or fixes. HTTP `200` unless a check is critical, then `503`.

```bash
curl -H "Authorization: Bearer $OPCONSOLE_HEALTH_TOKEN" https://example.com/admin/opconsole/health
```

### Health Command

```bash
php artisan opconsole:health                    # table: id, status, label, value, fix
php artisan opconsole:health --json             # machine-readable
php artisan opconsole:health --fail-on=warning  # exit 1 on warnings too (default: critical)
```

The command, the endpoint, and the dashboard share one runner (`ConsoleStatus::health()`). Unknown checks remain healthy for overall status by default to preserve monitoring integrations. `health.unknown_policy` can promote unknown checks to warning or critical, and IDs in `health.required_checks` are always critical if unknown. A required `custom.*` ID that is missing from the report (the check threw, so it was reported under a class-derived fallback ID, or the ID is misspelled) adds a critical `Required check …` entry. Built-in IDs are exempt from this rule because several appear only conditionally (for example `database.connection` appears only when the connection fails).

Each request runs every check synchronously, including database, cache, and table probes plus your custom checks. Keep custom checks fast and give any network calls they make their own short timeouts, because OpConsole cannot interrupt them.

**Kubernetes:** use the endpoint as a **readiness** probe or for external uptime monitoring, not as a **liveness** probe. It returns `503` when a dependency such as the database is critical, and restarting the pod does not fix a database outage. A liveness probe should only check that PHP answers.

### Health Notifications

Notifications are disabled unless explicitly enabled. Delivery is evaluated by `opconsole:health --notify`; schedule that command from the host application's scheduler (for example, `$schedule->command('opconsole:health --notify')->everyFiveMinutes();`). OpConsole does not create a schedule automatically. Queue workers are not required because delivery is synchronous; a scheduler outage prevents monitoring runs and therefore notifications. Configure mail normally through Laravel. Webhooks are HTTP POSTs; `slack` targets accept Slack incoming webhook URLs. Delivery errors are logged and do not fail the dashboard or health endpoint.

```php
'notifications' => [
    'enabled' => true,
    'channels' => [
        'email' => ['on-call@example.com'],
        'slack' => ['https://hooks.slack.com/services/...'],
        'webhooks' => ['https://monitor.example.net/opconsole'],
    ],
    'cooldown_seconds' => 3600,
    'state_store' => 'opconsole', // persistent, shared cache store
],
```

Alerts cover the first unhealthy state, worsening status, and recovery. A cache lock prevents concurrent duplicate alerts. Only last status and cooldown metadata are stored; use a persistent shared store so restarts and multiple app servers preserve transitions. A `state_store` without lock support disables delivery and logs a warning.

Delivery is isolated per channel and per destination. A failing mailer does not stop the webhooks, and one failing webhook does not stop the others. A transition counts as sent once at least one channel delivers it. If every channel fails, the transition is retried on the next run; there is no tight retry loop. Webhooks accept only `http`/`https` URLs, never follow redirects, and treat any non-2xx response as a failure (3 s connect timeout, 5 s total). Failure logs record the channel, destination index, and HTTP status or exception class. They never record the URL or the transport message, because webhook URLs such as Slack's are credentials. Repeated healthy ↔ unhealthy flapping is reported truthfully, one alert per transition per run.

### Allow-Listed Artisan Actions (`actions.*`)

| Key | Default | Artisan Command Executed |
|---|---|---|
| `actions.clear` | `true` | `php artisan optimize:clear` |
| `actions.optimize` | `true` | `php artisan optimize` |
| `actions.migrate` | `true` | `php artisan migrate --force --isolated` |
| `actions.down` | `true` | `php artisan down --secret=<random>`; the bypass URL is shown once in the output panel and never logged or audited. |
| `actions.up` | `true` | `php artisan up` |
| `actions.storage_link` | `false` (`OPCONSOLE_ACTION_STORAGE_LINK`) | `php artisan storage:link` (optional, low risk; disabled while the link is already correct). |
| `actions.security_scan` | `true` (`OPCONSOLE_ACTION_SECURITY_SCAN`) | `php artisan opconsole:security` (read-only; findings do not mark the action as failed, see the Security tab). |
| `actions.octane_reload` | `true` (`OPCONSOLE_ACTION_OCTANE_RELOAD`) | `php artisan octane:reload` (only shown when Laravel Octane is installed; disabled while no Octane server is running). |

### Laravel Octane (`octane.*`)

When Laravel Octane is installed, the Health tab gains an **Octane and runtime** group, System information gains an **Octane** card, Runtime status gains an **Octane server** row, and Operations gains **Reload Octane workers**. Apps without Octane see none of this.

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `octane.checks` | `OPCONSOLE_OCTANE_CHECKS` | `auto` | `auto` runs the Octane checks only when Octane is installed; `false` turns them off. |
| `octane.expected_in_production` | `OPCONSOLE_OCTANE_EXPECTED` | `false` | When `true`, a production request not served by Octane is a warning and a stopped Octane server is critical. Otherwise both are advisories. |
| `octane.allow_actions_in_swoole` | `OPCONSOLE_OCTANE_ALLOW_ACTIONS` | `false` | Timed operations enforce their limit with `SIGALRM`, which inside a Swoole worker would interrupt the worker itself. They are refused there by default with an explanation; the short, self-bounded security scan and Octane reload still run. RoadRunner and FrankenPHP are not affected. |

**Checks:** server dependency installed (Swoole/OpenSwoole extension, `rr` or `frankenphp` binary), requests served by Octane, server process running (via Octane's own process inspector), `octane.garbage` below `memory_limit`, `octane.https` matching an `https` `APP_URL`, and `opcache.enable_cli` for workers. With the `swoole` server also: `package_max_length` at least as large as PHP's upload limits (Swoole ignores `upload_max_filesize` and `post_max_size`), worker count against CPU cores, Xdebug loaded alongside Swoole, and a writable Swoole log file.

After **Clear caches**, **Optimize** or **Run migrations**, the output panel reminds you to reload Octane workers, because they keep the old configuration in memory.

### Maintenance Mode (`maintenance.*`)

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `maintenance.allow_console` | `OPCONSOLE_MAINTENANCE_ALLOW_CONSOLE` | `true` | Adds the OpConsole route prefix to `PreventRequestsDuringMaintenance::except()` so the console (still behind authentication and Gates) stays reachable while the application is down. Ignored when `route.prefix` is empty: exempting `/*` would exempt the entire application, so a console mounted at the root follows maintenance mode like every other route. |

If you published `config/opconsole.php` before these actions existed, your `actions` array replaces the package default, so `down` and `up` stay hidden until you add them.

Setting an action to `false` hides its card in the UI and causes `POST /admin/opconsole/{action}` to return `404`. Arbitrary commands cannot be added.

### Log Viewer (`logs.*`)

| Key | Default | Description |
|---|---|---|
| `logs.path` | `storage_path('logs')` | Directory scanned for readable `^[\w.-]+\.log$` files (enforced with `realpath()` containment). |
| `logs.allow_download` | `true` | When `false`, hides download buttons and aborts `GET /admin/opconsole/logs/{file}` with `403`. |
| `logs.allow_delete` | `true` | When `false`, hides delete buttons and aborts `DELETE /admin/opconsole/logs/{file}` with `403`. Even when `true`, the newest valid log file (or any symlink resolving to it) can never be deleted. Deleting a symlink removes only the link, never its target. |

### Failed Jobs (`failed_jobs.*`)

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `failed_jobs.enabled` | `OPCONSOLE_FAILED_JOBS` | `true` | Shows the **Failed jobs** tab with the newest failed queue jobs: job name, connection, queue, failure time and the first line of the sanitised exception. Payloads are never shown. When `false`, the tab is hidden and retry requests return `403`. |
| `failed_jobs.limit` | `OPCONSOLE_FAILED_JOBS_LIMIT` | `50` | How many jobs to list (1–500). Database providers are queried with this limit, so a large store is never loaded in full. |
| `failed_jobs.allow_retry` | `OPCONSOLE_FAILED_JOBS_RETRY` | `true` | Shows a **Retry** button per job: `POST /admin/opconsole/failed-jobs/{id}/retry` runs `queue:retry {id}`. It needs the run Gate and, when configured, the Gate mapped to `queue_retry` in `authorization.action_gates`, is rate limited to 20 per minute, asks for confirmation, and is audited as `queue.retry`. Under `audit.policy=required`, a retry whose audit row cannot be written is refused. |

The list reads Laravel's configured failed-job store (`queue.failed.driver`). With the `null` driver, the tab explains that failed jobs are not being stored.

### UI, Layout & Audit Storage

| Key | Environment Variable | Default | Description |
|---|---|---|---|
| `checks` | — | `[]` | Array of class names implementing `AshrafTalha\OpConsole\Contracts\HealthCheck`. |
| `layout` | — | `null` | `null` renders OpConsole's standalone layout. Set to a host Blade view name (must provide `@yield('content')`, `@stack('styles')`, and `@stack('scripts')`) to embed inside an existing admin shell. |
| `theme` | `OPCONSOLE_THEME` | `null` | Color theme: `auto` (follow the system light/dark setting), `light`, or `dark`. `null` uses `auto` for the standalone layout and `light` when `layout` is set. |
| `theme_toggle` | `OPCONSOLE_THEME_TOGGLE` | `true` | Shows a light/dark switch in the header. The choice is saved per browser in the `opconsole_theme` cookie and overrides `theme`. |
| `web_fonts` | `OPCONSOLE_WEB_FONTS` | `false` | Loads IBM Plex Sans, Plex Mono and Plex Sans Arabic from Google Fonts. Off by default so the console makes no external requests; when on, allow `fonts.googleapis.com` (`style-src`) and `fonts.gstatic.com` (`font-src`) in your Content-Security-Policy. When off, the system font stack is used. |
| `back_url` | — | `null` | Optional URL rendered as a back link in the console header (hidden when `null`). |
| `back_label` | — | `'Dashboard'` | Label text for `back_url`. |
| `table` | `OPCONSOLE_TABLE` | `'opconsole_runs'` | Database table name for operation audit records. |
| `connection` | `OPCONSOLE_CONNECTION` | `null` | Database connection name for `opconsole_runs` (`null` uses the application's default connection). |
| `history_limit` | `OPCONSOLE_HISTORY_LIMIT` | `15` | Number of recent operations shown in Operation history (clamped to `1`–`100`). |

When embedding OpConsole in a layout with a fixed header, set `--oc-tabs-offset` (for example `.oc-embedded-shell { --oc-tabs-offset: 64px; }` in your stylesheet) so the sticky section tabs stop below the header.

---

## 2. Read-Only Diagnostic Preset

Use the complete **[read-only production recipe](/advanced/production-recipes#read-only-production-recommended)** for copyable Gates, action settings, retry restrictions, and verification steps. It explicitly denies execution and clears action-specific overrides.

For conservative production rollouts where operators should inspect health checks, operation history, and in-browser log tails without running Artisan commands or exporting/deleting log files, replace these sections in the published config:

```php
// config/opconsole.php
'actions' => [
    'clear' => false,
    'optimize' => false,
    'migrate' => false,
    'down' => false,
    'up' => false,
    'storage_link' => false,
    'security_scan' => false,
    'octane_reload' => false,
],

'failed_jobs' => [
    'enabled' => true,
    'limit' => 50,
    'allow_retry' => false,
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
            'hint' => 'Checks for a heartbeat key; the worker must write it with a short expiry.',
            'value' => $healthy ? 'recent' : 'stale',
            'fix' => $healthy ? null : 'php artisan queue:work',
        ];
    }
}
```

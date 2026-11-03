# Upgrade Guide

This document covers upgrades to the current `v1.7.1` release. The later sections retain migration notes for older installations.

## Upgrade from v1.7.0 to v1.7.1

This patch fixes the blank System information tab, duplicate standalone page title, and extra spaces in dashboard values. No configuration changes or database migrations are required.

```bash
composer update ashraftalha/laravel-opconsole
php artisan view:clear
```

If package views were published or customized, merge the changes in `resources/views/index.blade.php` and `resources/views/layout.blade.php` into those copies. Older published configurations still need the keys described in the migration notes below; installing this patch does not overwrite host configuration.

---

## Upgrade from v1.6.x to v1.7.0

Laravel 12 and 13 and PHP `^8.3` remain supported. The release adds an optional host Composer dependency security audit (`opconsole:security`) and a dashboard Security tab. No database migrations are required.

1. Update the package:

   ```bash
   composer update ashraftalha/laravel-opconsole
   ```

2. If you publish `config/opconsole.php`, merge the new `security` key (`composer`, `timeout`, `max_age_seconds`, `include_dev`, `store`, `schedule`, `trusted_proxy_https`, `risky_actions`). The audit stays disabled until you run `php artisan opconsole:security`; scheduling stays off until you set `OPCONSOLE_SECURITY_SCHEDULE=true`.

3. Run an initial scan so the Security tab has a report instead of showing an unverified state:

   ```bash
   php artisan opconsole:security
   ```

4. Clear and rebuild optimized caches after updating package routes, views, or config:

   ```bash
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

5. If package assets were published, refresh them with `php artisan vendor:publish --tag=opconsole-assets --force`.

See [CONFIGURATION.md](CONFIGURATION.md) for all `security.*` options and [SECURITY.md](SECURITY.md) for scan scope, advisory network requests, exit codes, and remediation guidance.

---

## Upgrade from v1.5.x to v1.6.0

Laravel 12 and 13 and PHP `^8.3` remain supported. The release adds per-action authorization, bounded owner-renewed action locks, configurable health status policy, connection-aware database checks, and optional health transition notifications.

1. Update the package and apply its new audit duration migration:

   ```bash
   composer update ashraftalha/laravel-opconsole
   php artisan migrate --force
   ```

2. If you publish `config/opconsole.php`, merge the new keys from the package config: `authorization.action_gates`, `concurrency.max_runtime_seconds`, `health.required_checks`, `health.unknown_policy`, and `notifications`. Keep the default lock store isolated (`opconsole`). Notifications stay disabled until explicitly enabled and scheduled.

3. Review any custom database, session, or queue table connections. Health checks now inspect the connection and table configured by each service.

4. Clear and rebuild optimized caches after updating package routes, views, or config:

   ```bash
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

5. If package assets were published, refresh them with `php artisan vendor:publish --tag=opconsole-assets --force`.

For action-specific permissions, map action keys to host-defined Gates in `authorization.action_gates`. For alerts, configure `notifications.channels` and a persistent shared `notifications.state_store`, then schedule `php artisan opconsole:health --notify`. See [CONFIGURATION.md](CONFIGURATION.md) for all options and behavior.

---

## Summary of Compatibility

- **Supported PHP:** `^8.3` (unchanged)
- **Supported Laravel:** `^12.0 | ^13.0` (unchanged)
- **Database Schema (`opconsole_runs`):** Unchanged — no new database migrations are required.
- **Existing 2-Gate Setups (`viewOpConsole`, `runOpConsoleActions`):** 100% backward-compatible. Even if you keep an older published `config/opconsole.php` without `download_gate` and `delete_gate`, OpConsole automatically defaults `download_gate` to `downloadOpConsoleLogs` (delegating to your `view_gate`) and `delete_gate` to `deleteOpConsoleLogs` (delegating to your `run_gate`).

### Reliability, action permissions, and notifications

- Copy the new `concurrency.max_runtime_seconds`, `health`, and `notifications` keys into published configurations. Existing unknown statuses remain non-failing unless required or `unknown_policy` is changed.
- Existing `run_gate` remains the permission fallback for every action. Add only the `authorization.action_gates` entries you want to separate. Explicit mappings must name a Gate defined by the host.
- Notifications remain disabled by default. Schedule `opconsole:health --notify` only after configuring channels and a persistent shared `state_store`.
- The runtime timeout uses PHP's `pcntl` alarm support. When it is unavailable, maintenance actions fail closed rather than running without a bounded lease. This matters on some Windows and restricted shared-hosting setups.
- Cache/database/queue checks now inspect each configured service connection. If those services use separate databases, ensure the configured tables exist on those connections.

---

## Notable Behavioral Changes

### 1. Action Lock Store Defaults to Isolated `'opconsole'` Store (Fail-Closed)

Previously, `config('opconsole.concurrency.lock_store')` defaulted to `'file'`. On applications where `CACHE_STORE=file` (Laravel's `file` cache driver uses `storage/framework/cache/data` for both cache items and lock files), running `optimize:clear` (`cache:clear`) deleted active lock files mid-run.

**What changed:**
- `OpConsoleServiceProvider` now auto-registers an isolated file cache store named `'opconsole'` at `storage/framework/cache/opconsole`.
- `opconsole.concurrency.lock_store` now defaults to `'opconsole'`.
- `ActionRunner` validates the lock store **fail-closed**: if `lock_store` is missing, does not implement atomic locks (`null`), uses `array` outside unit tests, or shares storage with `cache.default` without separate lock storage (`ActionRunner::hasSeparateLockStorage()` across Laravel 12 and Laravel 13), actions refuse to execute rather than silently falling back to `Cache::lock()`.

**Action required if you published `config/opconsole.php`:**
Update `'lock_store'` in your published `config/opconsole.php` (or `.env`):

```php
'concurrency' => [
    'lock_store' => env('OPCONSOLE_LOCK_STORE', 'opconsole'),
    'lock_seconds' => (int) env('OPCONSOLE_LOCK_SECONDS', 1800),
    'max_runtime_seconds' => (int) env('OPCONSOLE_MAX_RUNTIME_SECONDS', 1500),
],
```

The timeout requires PHP `pcntl` signal support. Lock leases are renewed during execution, and the audit view classifies runs as abandoned only after twice the effective lease window.

*(Multi-server applications using Redis or database locks should point `OPCONSOLE_LOCK_STORE` to an isolated Redis or database store where `hasSeparateLockStore()` is true.)*

### 2. Self-Contained Assets (Legacy `assets.*` CDN Keys Removed)

Previously, `config/opconsole.php` included an `'assets'` array (`use_cdn`, `bootstrap_css`, `bootstrap_js`, `icons_css`) that loaded Bootstrap 5 CSS/JS and Iconscout Unicons from `cdn.jsdelivr.net`.

**What changed:**
- `public/opconsole.css`, `public/opconsole.js`, and inline SVG icons (`opconsole::partials.icon`) are completely self-contained, dependency-free, scoped under `oc-*` classes, and strict-CSP compatible (zero inline `<script>` tags or inline `style=` attributes).
- The obsolete `assets.*` CDN configuration keys and CDN `<link>`/`<script>` loaders have been removed. If an older published `config/opconsole.php` still contains `'assets'`, those unused keys are harmlessly ignored and can be deleted.
- An authenticated fallback asset route (`GET /admin/opconsole/assets/opconsole.{css,js}`, named `opconsole.asset`) serves the package CSS/JS automatically if `public/vendor/opconsole/` has not been published yet.

**Action required:**
Republish package assets on upgrade so `public/vendor/opconsole/opconsole.{css,js}` receives the self-contained stylesheet and dialog script:

```bash
php artisan vendor:publish --tag=opconsole-assets --force
php artisan view:clear
```

### 3. Granular 4-Gate Authorization (`view`, `run`, `download`, `delete`)

`config/opconsole.php` now supports four distinct Gate keys:

```php
'authorization' => [
    'view_gate' => 'viewOpConsole',
    'run_gate' => 'runOpConsoleActions',
    'download_gate' => 'downloadOpConsoleLogs',
    'delete_gate' => 'deleteOpConsoleLogs',
],
```

- If you do **not** define `downloadOpConsoleLogs` or `deleteOpConsoleLogs` yourself, `downloadOpConsoleLogs` delegates to your `view_gate` and `deleteOpConsoleLogs` delegates to your `run_gate`, preserving v0.1.0 behavior.
- If you define `downloadOpConsoleLogs` or `deleteOpConsoleLogs` in `AppServiceProvider`, your custom policy takes precedence in all environments.
- Note: if you configure a **custom** gate name (for example `'view_gate' => 'canAccessOps'`), you **must** define that Gate; custom undefined gate names now fail closed (`403`) even in `local`.

### 4. Mandatory `web` Middleware Preservation

`routes/web.php` now guarantees `'web'` remains active even if `config('opconsole.route.middleware')` is overridden with custom middleware (such as `['verified']`), preventing accidental removal of session state and CSRF protection. Keep `'middleware' => ['web']` (do not add `'auth'`).

### 5. Custom `HealthCheck` Contract Enhancements (Backward-Compatible)

`AshrafTalha\OpConsole\Contracts\HealthCheck::run()` continues to accept the existing 7-key array (`group`, `label`, `ok`, `severity`, `hint`, `value`, `fix`) and now optionally accepts:

- `'id'` (`non-empty-string`): stable identifier (prefixed automatically as `custom.<slug>`).
- `'status'` (`'healthy' | 'warning' | 'critical' | 'unknown'`): explicit 4-state status when a check cannot be verified (for example, an unprobed external service).

Existing custom checks require **no code changes**. Note that duplicate custom check classes in `config('opconsole.checks')` and custom checks attempting to overwrite a built-in `(group, label)` pair are now skipped with a warning log.

---

## Upgrade Commands

```bash
composer update ashraftalha/laravel-opconsole
php artisan vendor:publish --tag=opconsole-assets --force
php artisan optimize:clear
```

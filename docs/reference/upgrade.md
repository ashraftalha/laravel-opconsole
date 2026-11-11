---
title: Upgrade Guide
description: "Step-by-step upgrade notes for each Laravel OpConsole release: config keys, assets to republish, migrations and permission changes to review."
---

# Upgrade Guide

This document covers upgrades to the current `v1.12.2` release. The later sections retain migration notes for older installations.

## Upgrade from v1.12.1 to v1.12.2

No database migrations are required, and no routes, Gates, config keys, views or assets changed.

1. **Update the package:**
   ```bash
   composer update ashraftalha/laravel-opconsole
   php artisan config:clear
   ```
2. **Security scan:** **Run security scan** now works from the Operations tab under PHP-FPM, Herd and `artisan serve`. When the web server provides neither `HOME` nor `COMPOSER_HOME`, Composer uses `storage/framework/cache/opconsole/composer`, which must be writable.
3. **Published config:** if your published `config/opconsole.php` predates the scan, add `'security_scan' => (bool) env('OPCONSOLE_ACTION_SECURITY_SCAN', true),` to its `actions` list; otherwise the card stays hidden.

## Upgrade from v1.12.0 to v1.12.1

No database migrations are required, and no routes, Gates or config keys changed. Existing configuration keeps working.

1. **Update the package:**
   ```bash
   composer update ashraftalha/laravel-opconsole
   php artisan config:clear
   ```
2. **Published assets:** the stylesheet changed (the tab bar no longer widens the page on narrow screens). Republish assets with `php artisan vendor:publish --tag=opconsole-assets --force`. Views did not change.
3. **Permissions to review:** failed-job retries now always require `run_gate` at the route. An `authorization.action_gates` entry keyed `''` no longer replaces it; remove any such entry. The Retry button is hidden from users the run Gate denies, matching the endpoint. See the [permission matrix](/guide/authentication#effective-permission-matrix).
4. **Octane:** **Reload Octane workers** now works when the dashboard is served by Octane. No configuration is needed.

## Upgrade from v1.11.0 to v1.12.0

No database migrations are required, and no routes, Gates or config keys changed. Existing configuration keeps working.

1. **Update the package:**

   ```bash
   composer update ashraftalha/laravel-opconsole
   php artisan config:clear
   ```

2. **Published assets or views:** the Run migrations card changed (full-width list with a **Run only this** button per pending migration), and the stylesheet changed. Republish assets with `php artisan vendor:publish --tag=opconsole-assets --force`. If you customised views, merge the changes from the package's `resources/views/index.blade.php`.

3. **Permissions to review:** anyone allowed to run `migrate` can now apply a single pending migration, including one ahead of earlier pending files. The confirmation warns about this. The same Gate applies as before (`migrate` in `authorization.action_gates`, or the run Gate).

::: warning Migration order is not enforced for individual runs
**Use Run all by default** to apply pending migrations in Laravel's normal order. If running one at a time, execute the earliest pending migration first and continue sequentially. **Run only this can skip earlier pending migrations**, and OpConsole does not detect schema or data dependencies. A later migration may fail or leave the database incompatible with the application. The confirmation is a warning, not a dependency check.

Use an out-of-order run only after reviewing the migration and its dependencies, testing that exact sequence against a representative database, and confirming a recoverable backup.
:::

If a migration fails or is interrupted, assume it may have partially applied. Inspect migration status and the actual database before retrying; OpConsole does not automatically roll back. Follow [Migration recovery](/features/artisan-actions#recovery-after-a-failed-or-interrupted-migration) before applying more migrations.

## Upgrade from v1.10.0 to v1.11.0

No database migrations are required, and no routes, Gates or existing config keys changed. Existing configuration keeps working.

1. **Update the package:**

   ```bash
   composer update ashraftalha/laravel-opconsole
   php artisan config:clear
   ```

2. **Published assets or views:** the views gained a Failed jobs tab and an Octane section, and the stylesheet and script changed. Republish assets with `php artisan vendor:publish --tag=opconsole-assets --force`. If you customised views, merge the changes from the package's `resources/views/index.blade.php`.

3. **New optional config keys** (published configs without them keep the defaults):
   - `failed_jobs.enabled` (`OPCONSOLE_FAILED_JOBS`, default `true`), `failed_jobs.limit` (`OPCONSOLE_FAILED_JOBS_LIMIT`, `50`), `failed_jobs.allow_retry` (`OPCONSOLE_FAILED_JOBS_RETRY`, `true`).
   - `octane.checks` (`OPCONSOLE_OCTANE_CHECKS`, `auto`), `octane.expected_in_production` (`OPCONSOLE_OCTANE_EXPECTED`, `false`), `octane.allow_actions_in_swoole` (`OPCONSOLE_OCTANE_ALLOW_ACTIONS`, `false`).
   - `actions.octane_reload` (`OPCONSOLE_ACTION_OCTANE_RELOAD`, `true`; only shown when Octane is installed).

4. **Permissions to review:**
   - Anyone with the run Gate can now retry a failed job, which re-runs it with its original payload. To restrict that, map `queue_retry` to its own Gate in `authorization.action_gates`, or set `OPCONSOLE_FAILED_JOBS_RETRY=false`.
   - The Failed jobs tab is visible to anyone with the view Gate. It shows job class names, queues and the first line of each exception (sanitised), never payloads. Set `OPCONSOLE_FAILED_JOBS=false` to hide it.

5. **Behaviour you may notice:**
   - If you serve the console with Laravel Octane on Swoole, timed operations (clear caches, optimize, migrate, maintenance on/off, storage link) are now refused from the browser, because their timeout signal would interrupt the Swoole worker itself. Run them from the CLI, or set `OPCONSOLE_OCTANE_ALLOW_ACTIONS=true` to accept that risk. The security scan and Octane reload still run. RoadRunner, FrankenPHP and PHP-FPM are unaffected.
   - The Health and History tabs no longer open empty when every check is healthy.

## Upgrade from v1.9.0 to v1.10.0

No database migrations are required, and no routes, Gates, form actions or CSRF behaviour changed. Existing configuration keeps working.

1. **Update the package:**

   ```bash
   composer update ashraftalha/laravel-opconsole
   php artisan config:clear
   ```

2. **Published assets:** the dashboard was redesigned around the OpConsole logo (new colours, health score, command palette, log timeline). If you published assets, republish them:

   ```bash
   php artisan vendor:publish --tag=opconsole-assets --force
   ```

   The package now also serves `opconsole.svg` (the favicon). Republishing copies it to `public/vendor/opconsole/`.

3. **Published views:** `index.blade.php` was rewritten and new partials were added (`logo`, `segbar`, `fonts`). If you customised views, diff `resources/views/vendor/opconsole` against the package and merge the changes, or republish with `--tag=opconsole-views --force`. A customised view keeps the old markup and will look broken with the new stylesheet.

4. **Renamed CSS classes** (only if you wrote CSS against them or kept old published views):

   | Before | After |
   |---|---|
   | `oc-btn-soft-primary` | `oc-btn-accent` |
   | `oc-btn-soft-success` | `oc-btn-success` |
   | `oc-btn-soft-warning` | `oc-btn-warning` |
   | `oc-btn-soft-secondary` | `oc-btn-secondary` |
   | `oc-card-body` | `oc-card-content` |

5. **New optional key:** `web_fonts` (`OPCONSOLE_WEB_FONTS`, default `false`) loads IBM Plex from Google Fonts. Leave it off to keep the console free of external requests. If you turn it on, allow `fonts.googleapis.com` (`style-src`) and `fonts.gstatic.com` (`font-src`) in your Content-Security-Policy.

6. **Behaviour you may notice:**
   - Health filter chips update `?check_status=` in place instead of reloading the page.
   - Security advisories now keep the full affected version range. Earlier scans stored a truncated range such as `>=12.0.0,`. Run `php artisan opconsole:security` once to refresh stored results.
   - `LogViewer::read()` returns an additional `timeline` key. Existing keys are unchanged.

## Upgrade from v1.8.0 to v1.9.0

No database migrations are required, and existing configuration keeps working.

1. **Composer metadata:** the package now requires `laravel/framework` instead of individual `illuminate/*` components, because it uses classes that only ship in the framework (`Illuminate\Foundation`). Every working installation already has it, so no action is needed.
2. **New optional key:** `audit.policy` (`OPCONSOLE_AUDIT_POLICY`, default `best_effort`, the current behaviour). Set it to `required` to refuse actions, log deletes, and log downloads that cannot be audited first. Published configs without the key keep the default.
3. **Dashboard redesign:** `public/opconsole.css`, `public/opconsole.js`, and the Blade views were restyled (new design tokens, tactile buttons, hold-to-confirm for high-risk actions, new summary tiles, a restructured Security tab). No routes, Gates, form actions, or CSRF behaviour changed.
   - If you published assets (`--tag=opconsole-assets`), republish them with `--force` to pick up the new styles and script: `php artisan vendor:publish --tag=opconsole-assets --force`.
   - If you published or customized views (`--tag=opconsole-views`), diff `resources/views/vendor/opconsole` against the package's `resources/views` and merge the changes you want; a customized view otherwise keeps rendering with the old markup and the new stylesheet, which will look inconsistent.
   - High-risk confirmations (currently only `migrate`) now require a press-and-hold or a second press instead of a single click; this is client-side only and has no server-side effect to account for.
4. **Behaviour you may notice:**
   - On a PHP runtime without `pcntl` (common for PHP-FPM), every action was already refused, but only after the request had taken the lock and written a `started` row. Those actions are now disabled on the dashboard with the reason shown, and `security_scan` (bounded by its own subprocess timeouts) now runs. Run the others from the CLI, or enable `pcntl` for the web SAPI.
   - On Laravel < 12.63 / < 13.16, actions running longer than 10 seconds (for example migrations) were interrupted by a failed lease renewal. They now run to completion within `max_runtime_seconds`.
   - A required `custom.*` health check that throws now makes the report `critical`; previously it was silently omitted.
   - With an empty `route.prefix`, maintenance mode now applies to the console too, instead of the whole application ignoring maintenance mode.
   - Deleting a log symlink removes the link, not its target.
   - Notification failures no longer log transport messages (which could contain webhook URLs).

## Upgrade from v1.7.x to v1.8.0

Laravel 12 and 13 and PHP `^8.3` remain supported. The release adds an on-demand security scan action and redesigns the Logs tab. No database migrations are required.

1. Update the package:

   ```bash
   composer update ashraftalha/laravel-opconsole
   ```

2. If you publish `config/opconsole.php`, add the new `actions.security_scan` key (default `true`; `OPCONSOLE_ACTION_SECURITY_SCAN`). It runs `php artisan opconsole:security` read-only from the Operations tab; set it to `false` to hide the button.

3. If you publish or customize package views, merge the Logs tab and System information markup changes in `resources/views/index.blade.php`, and the corresponding styles in `public/opconsole.css` (`php artisan vendor:publish --tag=opconsole-assets --force` if you have not customized them).

4. Clear and rebuild optimized caches:

   ```bash
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

See [Configuration Reference](/reference/configuration) for the `actions.security_scan` option.

---

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

See [Configuration Reference](/reference/configuration) for all `security.*` options and [Security](/reference/security) for scan scope, advisory network requests, exit codes, and remediation guidance.

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

For action-specific permissions, map action keys to host-defined Gates in `authorization.action_gates`. For alerts, configure `notifications.channels` and a persistent shared `notifications.state_store`, then schedule `php artisan opconsole:health --notify`. See [Configuration Reference](/reference/configuration) for all options and behavior.

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

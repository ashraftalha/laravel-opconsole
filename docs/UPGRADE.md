# Upgrade Guide

This document covers upgrading existing installations or integration branches from the initial `v0.1.0` baseline to the hardened `v0.2.0` configuration and runtime behavior.

---

## Summary of Compatibility

- **Supported PHP:** `^8.3` (unchanged)
- **Supported Laravel:** `^12.0 | ^13.0` (unchanged)
- **Database Schema (`opconsole_runs`):** Unchanged — no new database migrations are required.
- **Existing 2-Gate Setups (`viewOpConsole`, `runOpConsoleActions`):** 100% backward-compatible. Even if you keep an older published `config/opconsole.php` without `download_gate` and `delete_gate`, OpConsole automatically defaults `download_gate` to `downloadOpConsoleLogs` (delegating to your `view_gate`) and `delete_gate` to `deleteOpConsoleLogs` (delegating to your `run_gate`).

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
    'lock_seconds' => 30,
],
```

*(Multi-server applications using Redis or database locks should point `OPCONSOLE_LOCK_STORE` to an isolated Redis or database store where `hasSeparateLockStore()` is true.)*

### 2. Self-Contained Assets by Default (`assets.use_cdn = false`)

Previously, `config('opconsole.assets.use_cdn')` defaulted to `true` and loaded Bootstrap 5 CSS/JS and Iconscout Unicons from `cdn.jsdelivr.net`.

**What changed:**
- `public/opconsole.css`, `public/opconsole.js`, and inline SVG icons (`opconsole::partials.icon`) are now completely self-contained, dependency-free, and strict-CSP compatible (zero inline `<script>` tags or inline `style=` attributes).
- `assets.use_cdn` now defaults to `false`.
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

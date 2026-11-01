# Integrating Laravel OpConsole into `ashraftalha.com`

> **Status:** Integration plan only. `ashraftalha.com` has **not** been modified, deployed, or connected during package hardening. Follow these steps in a dedicated branch of `ashraftalha.com` locally first, then on staging, before considering production.

---

## 1. Compatibility Preflight

Run these checks inside the `ashraftalha.com` repository root before adding the package:

```bash
php -v
php -m | grep -E 'mbstring|openssl|tokenizer|ctype|json|pdo_'
php artisan --version
composer check-platform-reqs
php artisan route:list --path=admin/opconsole
```

Confirm all preflight criteria hold:

| Requirement | Expected |
|---|---|
| PHP runtime | `^8.3` (`8.3`, `8.4`, or `8.5`) — verify CLI **and** PHP-FPM / cPanel MultiPHP match |
| Laravel framework | `^12.0` or `^13.0` |
| Route collision | `route:list --path=admin/opconsole` returns zero existing routes |
| Named login route | Verify whether `route('login')` exists (`php artisan route:list --name=login`) and whether it requires URL parameters |
| Auth guard | Confirm which guard authenticates administrators (default `web` vs custom `admin` guard) |
| Cache & session drivers | Inspect `CACHE_STORE` and `SESSION_DRIVER` in `.env` (`file`, `database`, or `redis`) |

---

## 2. Local Installation via Composer Path Repository

For local verification before tagging or publishing `ashraftalha/laravel-opconsole`, attach the local package checkout as a Composer `path` repository in a feature branch of `ashraftalha.com`:

```bash
git checkout -b feat/opconsole-readonly-eval

composer config repositories.opconsole '{"type": "path", "url": "/path/to/laravel-opconsole", "options": {"symlink": false}}'
composer require ashraftalha/laravel-opconsole:@dev
```

> **Note:** Setting `"symlink": false` mirrors a real archive install and avoids cPanel/deployment symlink surprises. Do **not** commit a local filesystem `path` repository to production `composer.json`; switch to a pinned VCS tag (for example `^0.2.0` from GitHub) before staging or production deployment.

Verify package auto-discovery registered `AshrafTalha\OpConsole\OpConsoleServiceProvider`:

```bash
php artisan package:discover
php artisan about
```

---

## 3. Initial Read-Only Configuration (Recommended First Rollout)

Publish the configuration file:

```bash
php artisan vendor:publish --tag=opconsole-config
```

Edit `ashraftalha.com/config/opconsole.php` to start in **strict read-only diagnostic mode** — dashboard, health checks, and in-browser log viewing enabled, while log downloads, log deletions, and all Artisan maintenance commands remain disabled:

```php
<?php

return [

    'enabled' => (bool) env('OPCONSOLE_ENABLED', true),

    'route' => [
        'prefix' => 'admin/opconsole',
        'name_prefix' => 'opconsole.',
        // Keep only 'web'. Never add Laravel's 'auth' middleware alias here;
        // AuthorizeOpConsole checks the configured guard safely itself.
        'middleware' => ['web'],
        'domain' => null,
    ],

    'authentication' => [
        // Set to 'admin' if ashraftalha.com uses a dedicated admin guard; leave null for default 'web'.
        'guard' => env('OPCONSOLE_AUTH_GUARD'),
        'login_route' => 'login',
    ],

    'authorization' => [
        'view_gate' => 'viewOpConsole',
        'run_gate' => 'runOpConsoleActions',
        'download_gate' => 'downloadOpConsoleLogs',
        'delete_gate' => 'deleteOpConsoleLogs',
    ],

    'concurrency' => [
        // Isolated file lock store at storage/framework/cache/opconsole (safe on single-server / shared hosting).
        // Never set this to the same store name as CACHE_STORE unless using database/redis with separate lock storage.
        'lock_store' => env('OPCONSOLE_LOCK_STORE', 'opconsole'),
        'lock_seconds' => 30,
    ],

    // Phase 1 rollout: all Artisan write operations disabled.
    'actions' => [
        'clear' => false,
        'optimize' => false,
        'migrate' => false,
    ],

    // Phase 1 rollout: in-page log inspection enabled; raw file downloads and deletions disabled.
    'logs' => [
        'path' => storage_path('logs'),
        'allow_download' => false,
        'allow_delete' => false,
    ],

    'checks' => [
        // Register ashraftalha.com-specific checks implementing AshrafTalha\OpConsole\Contracts\HealthCheck here.
    ],

    'layout' => null,

    'assets' => [
        // Self-contained local CSS/JS + inline SVG icons; zero external CDN dependencies.
        'use_cdn' => false,
        'bootstrap_css' => null,
        'bootstrap_js' => null,
        'icons_css' => null,
    ],

    'back_url' => '/admin',
    'back_label' => 'Admin',

    'table' => 'opconsole_runs',
    'connection' => null,

];
```

---

## 4. Administrator Authorization Integration

OpConsole is **deny-by-default outside `local`**. Define explicit Gates in `ashraftalha.com`'s `AppServiceProvider::boot()` (or dedicated `AuthServiceProvider`):

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewOpConsole', function (User $user): bool {
        return (bool) $user->is_admin; // Adapt to ashraftalha.com's exact admin attribute/role check
    });

    // Keep mutating & export gates explicitly false during initial read-only rollout:
    Gate::define('runOpConsoleActions', fn (User $user): bool => false);
    Gate::define('downloadOpConsoleLogs', fn (User $user): bool => false);
    Gate::define('deleteOpConsoleLogs', fn (User $user): bool => false);
}
```

Security invariants to verify:

- Do **not** return `true` unconditionally for any authenticated user if `ashraftalha.com` allows non-admin user accounts.
- If `ashraftalha.com` authenticates admins on a separate guard (e.g., `auth.guards.admin`), set `OPCONSOLE_AUTH_GUARD=admin`; `AuthorizeOpConsole` binds that guard for both Gate evaluation and audit attribution.
- Because `actions.*`, `logs.allow_download`, and `logs.allow_delete` are set to `false` **and** their Gates return `false`, write/export endpoints are doubly locked (disabled action routes return `404`; disabled log download/delete routes return `403`).

---

## 5. Safe Cache and Lock-Store Selection

Even while Artisan actions are disabled in Phase 1, configure `concurrency.lock_store` properly now so the **Action lock store isolation** health check passes and future maintenance operations are safe:

- **Single-server VPS or cPanel / shared hosting (`CACHE_STORE=file` or `CACHE_STORE=database`):**
  Keep the default `'lock_store' => 'opconsole'`. `OpConsoleServiceProvider` automatically registers `cache.stores.opconsole` pointing to `storage/framework/cache/opconsole`, isolated from `storage/framework/cache/data` so `php artisan optimize:clear` cannot delete active lock files.
- **Multi-server / load-balanced deployment:**
  Filesystem locks only coordinate processes sharing the same local disk. On multi-node deployments, configure a dedicated Redis or database lock store (and ensure `cache_locks` exists if using `database`).
- **Never** point `lock_store` at `array` or `null`, and never point a `file` lock store at `storage/framework/cache/data`. OpConsole validates this fail-closed and flags misconfigurations in the `Security` health check group.

---

## 6. Database Migrations and Assets

Publish static assets and run the package migration:

```bash
php artisan vendor:publish --tag=opconsole-assets --force
php artisan migrate
```

- **Migration (`opconsole_runs`):** Creates a single self-contained table with no foreign keys to `users`. Even if you postpone running `php artisan migrate`, the dashboard still loads cleanly in read-only mode and displays `"Audit table not migrated"` under **Operation history**.
- **Assets (`public/vendor/opconsole/opconsole.{css,js}`):** Publishing places the stylesheet and script under `public/vendor/opconsole/` for direct web-server delivery. If `public/vendor/opconsole/` is ever missing on a host, OpConsole automatically falls back to its authenticated `/admin/opconsole/assets/{asset}` route.
- Add asset publishing to `composer.json` `post-update-cmd` in `ashraftalha.com` so updates stay in sync:
  ```json
  "@php artisan vendor:publish --tag=opconsole-assets --ansi --force"
  ```

---

## 7. Local Testing Checklist

Run through every check locally before pushing a branch:

1. **Guest access:** Open `/admin/opconsole` in an incognito window → confirm redirect to `/login` (or HTTP `401` if `login_route` is `null`).
2. **Non-admin user (if applicable):** Sign in as a non-admin user → confirm `GET /admin/opconsole` returns HTTP `403`.
3. **Fail-closed production simulation:** Temporarily set `APP_ENV=production` with Gates commented out → confirm `GET /admin/opconsole` returns `403`. Restore Gates afterward.
4. **Authorized admin view:** Sign in as admin → confirm `/admin/opconsole` returns `200`, styles and SVG icons render with zero external CDN requests in browser DevTools Network tab, health checks group accurately, and **Operation history** renders.
5. **Read-only enforcement:**
   - Confirm no maintenance action buttons (`Clear caches`, `Optimize performance`, `Run migrations`) appear.
   - Confirm `POST /admin/opconsole/clear` returns `404` (or `403`).
   - Confirm `Download` and `Delete` controls do not appear on log files, and direct requests to `GET /admin/opconsole/logs/laravel.log` and `DELETE /admin/opconsole/logs/laravel.log` return `403`.
6. **Log viewer diagnostics:** Trigger a test `Log::warning('OpConsole smoke check')`, reload `/admin/opconsole`, and verify level filtering, search highlighting, and copy-to-clipboard work.
7. **Framework cache compatibility:**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan optimize:clear
   ```

---

## 8. Staging Verification

Before production deployment:

1. Replace the local `path` repository in `ashraftalha.com/composer.json` with the tagged GitHub VCS repository (or Packagist release) and run `composer update ashraftalha/laravel-opconsole`.
2. Deploy to staging with `APP_ENV=staging` and `APP_DEBUG=false`.
3. Repeat the guest (`302`/`401`), unauthorized (`403`), authorized (`200`), and read-only (`403`/`404` on write/download routes) checks over HTTPS.
4. Inspect the **Security** health check group (`HTTPS in production`, `Secure session cookies`, `Debug mode disabled in production`, `Action lock store isolation`, `Persistent cache and session drivers`).
5. Confirm Content-Security-Policy (CSP) headers on `ashraftalha.com` raise zero console violations on `/admin/opconsole` (OpConsole emits no inline `<script>` blocks, no inline `style=` attributes, and no third-party CDN URLs).

---

## 9. Shared Hosting / cPanel Considerations

If `ashraftalha.com` runs on cPanel or shared hosting:

1. **Document root (`public_html` vs `public/`):**
   - If `public_html` is a symlink to Laravel's `public/`, `vendor:publish --tag=opconsole-assets` works out of the box.
   - If `public_html` is separate from `public/` (or `public_path()` is not customized), either copy `public/vendor/opconsole/` into `public_html/vendor/opconsole/` during deploy **or** rely on OpConsole's automatic `/admin/opconsole/assets/opconsole.{css,js}` fallback route.
2. **Writable directories:**
   - Ensure `storage/framework/cache/opconsole` (created automatically on first lock validation/use if `storage/framework/cache` is writable) has `0755`/`0775` permissions owned by the PHP user.
3. **`disk_free_space` restrictions:**
   - Some shared hosts disable `disk_free_space()` via `disable_functions`. OpConsole handles this gracefully and reports disk availability as `Unknown` rather than crashing or falsely reporting `Healthy`.
4. **Synchronous web request timeouts (if enabling actions later):**
   - Shared-hosting LiteSpeed/Apache timeouts (typically 30–60s) apply to synchronous HTTP requests. Keep `migrate` disabled in web UI if migrations are large, and run `php artisan migrate --force` via SSH/cron deploy hooks instead.
5. **Sensitive data in logs:**
   - Verify `APP_DEBUG=false` and `LOG_LEVEL=warning` (or `info` without request payload dumps) so `storage/logs/` does not accumulate credentials or PII on shared storage.

---

## 10. Deployment Guidance

Standard deployment sequence for `ashraftalha.com`:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan vendor:publish --tag=opconsole-assets --force
php artisan migrate --force
php artisan optimize
```

If you later decide to enable `clear` or `optimize` from the web UI after Phase 1 verification succeeds:

1. Enable one capability at a time in `config/opconsole.php` (e.g., `'clear' => true`).
2. Update `runOpConsoleActions` Gate to restrict execution strictly to your primary administrator account.
3. Re-cache configuration (`php artisan config:cache`).

---

## 11. Rollback Procedure

### Instant kill-switch (no code deploy required)

Set in `ashraftalha.com/.env`:

```dotenv
OPCONSOLE_ENABLED=false
```

Then clear config/route caches:

```bash
php artisan optimize:clear
php artisan optimize
```

When `OPCONSOLE_ENABLED=false`, OpConsole registers **zero routes** and `/admin/opconsole` immediately returns `404`.

### Full package removal

```bash
php artisan optimize:clear
php artisan migrate:rollback --path=vendor/ashraftalha/laravel-opconsole/database/migrations --force
composer remove ashraftalha/laravel-opconsole
rm -rf config/opconsole.php public/vendor/opconsole
```

Remove the four `Gate::define(...)` lines from `AppServiceProvider`, then re-run `php artisan optimize`. The `opconsole_runs` table has no foreign-key dependencies on any host table, so rolling back or dropping it never affects application data.

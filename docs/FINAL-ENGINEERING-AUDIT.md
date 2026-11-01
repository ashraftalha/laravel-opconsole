# Final Engineering Audit — Laravel OpConsole

**Package:** `ashraftalha/laravel-opconsole`  
**Audit Baseline Commit:** `c87d8f5` (`main`, v0.1.0 baseline)  
**Enhancement Branch:** `feat/production-hardening`  
**Audit Environment:** macOS (Darwin), PHP 8.5.12, Composer 2.10.3, Laravel 13.39.0 / Orchestra Testbench 11.3.0 (plus cross-version verification on Laravel 12 / Orchestra Testbench 10)

---

## 1. Baseline Verification Results (Pre-Change)

All historical claims were independently re-verified on the unmodified working tree before any changes were made:

| Check | Command | Baseline Result |
|---|---|---|
| Git working tree | `git status -sb` | Clean on `main...origin/main` (`c87d8f5`) |
| PHPUnit test suite | `composer test` | **PASS** — 65 tests, 168 assertions, 0.62s |
| Code style (Pint) | `vendor/bin/pint --test` | **PASS** |
| Static analysis (Larastan L5) | `composer stan` | **PASS** — 0 errors across 10 files |
| Composer manifest | `composer validate --strict` | **PASS** — `./composer.json is valid` |
| Platform requirements | `composer check-platform-reqs` | **PASS** on PHP 8.5.12 |
| Config / Route / View cache | `testbench config:cache && route:cache && view:cache` | **PASS** |

### Baseline Performance Measurements (Pre-Change)

Measured locally on PHP 8.5.12 with SQLite `:memory:` and a 5 MB synthetic Laravel log file containing multiline exception stack traces:

| Metric | Baseline Measurement | Bottleneck Identified |
|---|---|---|
| `public/opconsole.css` | 13,236 bytes | Missing standalone layout/button rules (`.btn-soft-*`) |
| `public/opconsole.js` | 2,770 bytes | Hard runtime dependency on global `bootstrap.Modal` |
| `GET /admin/opconsole` (warm, empty logs) | 2.46 ms, **6 SQL queries** | Duplicate `migrations` & `failed_jobs` queries; unbounded `SELECT * FROM opconsole_runs` |
| `LogViewer::read()` (5 MB log, 2 MB tail, 4,865 entries) | 16.19 ms | Single-call baseline |
| `GET /admin/opconsole?q=Gateway` (warm, 5 MB log) | **35.73 ms**, 6 SQL queries, 36.02 MB peak | Reads 2 MB tail & regex-parses 4,865 entries **2–3× per request**; globs log dir 4× |

---

## 2. Summary of Verified Findings

Only confirmed, reproducible defects and concrete engineering gaps are listed below.

| ID | Severity | Area | Summary | Status |
|---|---|---|---|---|
| **F-01** | **P0** | Auth / Security | Non-default `authentication.guard` is not activated (`Auth::shouldUse`), causing Gate evaluation and `$request->user()` to use the wrong guard | Resolved |
| **F-02** | **P0** | Routing / CSRF | Overriding `route.middleware` can strip the `'web'` middleware group, silently disabling CSRF and session protection on mutating endpoints | Resolved |
| **F-03** | **P0** | Concurrency / Locking | `ActionRunner::lock()` fails open to `cache.default` on error, accepts no-op `NullStore`/`ArrayStore` locks, and collides with `CACHE_STORE=file` out of the box | Resolved |
| **F-04** | **P1** | Auth / Reliability | Parameterized `login_route` throws HTTP 500 for guests; non-string Gate config throws `TypeError` instead of failing closed with 403 | Resolved |
| **F-05** | **P1** | Authorization | Log download and log deletion lack distinct Gate permissions separate from dashboard viewing and Artisan execution | Resolved |
| **F-06** | **P1** | Artisan Execution | Exceptions thrown by `Artisan::call()` (e.g., failed SQL migration or uncacheable closure route) cause HTTP 500 and leave audit rows stuck in `started` | Resolved |
| **F-07** | **P1** | Audit Integrity | Premature `log.download` audit before 404 check, misleading `log.delete` errors, unguarded `VARCHAR(255)` lengths, and no UI visibility into audit history or interrupted runs | Resolved |
| **F-08** | **P1** | Log Security & Reliability | `LogViewer::files()` lists unvalidated `*.log` entries (including external symlinks or invalid names), crashing the dashboard (HTTP 500) and allowing newest-log deletion bypass | Resolved |
| **F-09** | **P1** | Health Checks | False Critical alert on MariaDB (`pdo_mariadb`), false Healthy status on unverified disk/queue checks, hardcoded table/channel names, and unvalidated custom check value types | Resolved |
| **F-10** | **P1** | UI / UX / CSP | Mandatory third-party CDNs, broken `use_cdn=false` mode (disables confirmation modal!), missing `.btn-soft-*` styles, inline script CSP violation, and WCAG AA contrast failures | Resolved |
| **F-11** | **P2** | Log Filtering | `PagePresenter::log()` uses `array_filter()` without callback, silently dropping search query `?q=0` across filter/pagination links | Resolved |
| **F-12** | **P2** | Performance | Unbounded `opconsole_runs` query (`NO LIMIT`), duplicate 2 MB log reads/parses within one request, and duplicate schema/migration queries | Resolved |
| **F-13** | **P2** | Packaging & CI | Undeclared direct `illuminate/*` dependencies in `composer.json`, single-version `clean-install` CI job, and contradictory `'auth'` middleware in integration examples | Resolved |

---

## 3. Detailed Findings

### F-01 — Non-default `authentication.guard` evaluates Gates and audit identity against the default guard
- **Severity:** P0 (Security / Authentication & Authorization)
- **Affected file & symbol:** `src/Http/Middleware/AuthorizeOpConsole.php` (`AuthorizeOpConsole::handle()`)
- **Evidence:**
  Lines 28–36 check `Auth::guard(config('opconsole.authentication.guard'))->check()`, then immediately invoke `Gate::allows($gate)` without calling `Auth::shouldUse($guardName)` or `Gate::forUser($guard->user())`. Downstream controller actions call `$request->user()` / `request()->user()` without specifying the guard.
- **Impact:**
  1. When `opconsole.authentication.guard` is set to a separate guard (e.g., `'admin'`), an administrator authenticated only on `'admin'` has `Auth::user() === null` on the default `'web'` guard, causing `Gate::allows()` to deny every request with HTTP 403 and `$request->user()` to record `null` in `opconsole_runs`.
  2. If two different accounts are simultaneously logged into `'web'` and `'admin'` in the same browser session, `AuthorizeOpConsole` authenticates `'admin'` but authorizes and audits against the `'web'` user object (cross-guard identity confusion).
- **Reproduction method:**
  Configure a second session guard `'admin'`, set `opconsole.authentication.guard = 'admin'`, authenticate an authorized admin on `'admin'` while leaving `'web'` unauthenticated (or authenticated as an unauthorized user), and send `GET /admin/opconsole`.
- **Recommended resolution:**
  When `$guardName = config('opconsole.authentication.guard')` is configured and `$guard->check()` succeeds, call `Auth::shouldUse($guardName)` (matching `Illuminate\Auth\Middleware\Authenticate::authenticate()`) and evaluate `Gate::forUser($guard->user())->allows($gate)`.
- **Regression-test requirement:**
  Test authenticating exclusively on a custom `'admin'` guard (verifying 200 + correct `user_id`/`user_label` in `opconsole_runs`) and test dual-guard sessions where `'web'` is authorized/unauthorized differently from `'admin'`.

---

### F-02 — `route.middleware` override can strip `'web'` middleware and disable CSRF protection
- **Severity:** P0 (Security / CSRF & Session Integrity)
- **Affected file & symbol:** `routes/web.php`, `config/opconsole.php`
- **Evidence:**
  `Route::middleware(config('opconsole.route.middleware', ['web']))` passes the configured value directly without ensuring `'web'` remains active.
- **Impact:**
  If a host overrides `opconsole.route.middleware` (e.g., `['verified']`, `['throttle:30,1']`, or `[]`) intending to add middleware, Laravel's `'web'` group (`StartSession`, `ValidateCsrfToken` / `PreventRequestForgery`) is omitted, leaving `POST /admin/opconsole/{action}` and `DELETE /admin/opconsole/logs/{file}` unprotected against CSRF or breaking session flashing.
- **Reproduction method:**
  Set `opconsole.route.middleware = []` in a non-testing environment and submit a cross-site `POST /admin/opconsole/clear` without `_token`.
- **Recommended resolution:**
  Normalize route middleware so `'web'` is always prepended (`array_values(array_unique(['web', ...]))`), while still appending any additional host-configured middleware.
- **Regression-test requirement:**
  Test setting `opconsole.route.middleware = []` and `['throttle:30,1']` and assert `'web'` (and `opconsole.authorize:*`) remain attached to every OpConsole route and CSRF still rejects forged POST/DELETE requests with HTTP 419.

---

### F-03 — `ActionRunner::lock()` fails open to `cache.default`, accepts no-op `NullStore`/`ArrayStore`, and collides with `CACHE_STORE=file`
- **Severity:** P0 (Security & Operational Safety / Concurrency Locking)
- **Affected file & symbol:** `src/Support/ActionRunner.php` (`ActionRunner::lock()`, `ActionRunner::run()`), `config/opconsole.php`, `src/OpConsoleServiceProvider.php`
- **Evidence:**
  1. `ActionRunner::lock()` lines 86–90 catch `Throwable` and execute `return Cache::lock(self::LOCK_KEY, $seconds);` — silently falling back to `cache.default`.
  2. `Illuminate\Cache\NullStore` implements `LockProvider` and returns `NoLock`, whose `get()` unconditionally returns `true` (zero locking).
  3. Outside unit tests, `Illuminate\Cache\ArrayStore` only holds locks inside the current PHP request's memory, offering zero cross-request mutual exclusion.
  4. Laravel's default `cache.stores.file` sets both `path` and `lock_path` to `storage/framework/cache/data` (`FileStore::hasSeparateLockStore() === false`). When a host uses `CACHE_STORE=file` and OpConsole uses its default `lock_store = 'file'`, `optimize:clear` (`cache:clear`) deletes `storage/framework/cache/data/*`, wiping OpConsole's active lock file mid-execution.
  5. Runtime exceptions thrown during `$lock->get()` or `$lock->release()` are uncaught (HTTP 500).
- **Impact:**
  Unsafe, misconfigured, or `CACHE_STORE=file` environments silently execute Artisan maintenance operations without effective mutual exclusion, allowing concurrent `optimize` / `optimize:clear` / `migrate` runs to race.
- **Reproduction method:**
  - Set `opconsole.concurrency.lock_store = 'nonexistent'` or `'null'`, or set both `cache.default = 'file'` and `opconsole.concurrency.lock_store = 'file'`.
  - Observe `ActionRunner::run('clear', $user)` either acquires a no-op lock, falls back to `cache.default`, or flushes its own lock during `cache:clear`.
- **Recommended resolution:**
  1. Auto-register a dedicated isolated file cache store `cache.stores.opconsole` (`storage/framework/cache/opconsole`) in `OpConsoleServiceProvider::register()` and default `opconsole.concurrency.lock_store` to `'opconsole'`.
  2. Remove the fallback to `Cache::lock()` — fail closed whenever the lock store cannot be resolved, does not implement `LockProvider`, is `NullStore` (`NoLock`), is `ArrayStore` outside unit tests, shares the same store as `cache.default` without `CanFlushLocks::hasSeparateLockStore()`, or shares the same effective filesystem directory as a `FileStore` `cache.default`.
  3. Wrap lock validation, acquisition, and release in structured exception handling so lock failures never execute `Artisan::call()`, write a failed audit/log record, and surface an explicit error message.
- **Regression-test requirement:**
  Cover all 7 required locking invariants: external lock contention, cross-action contention, `optimize:clear` preservation (including when `cache.default = 'file'`), fail-closed rejection on `null` / invalid / non-`LockProvider` / same-store unseparated `file` configs, lock acquisition exception handling, expired lock recovery, and concurrent protection.

---

### F-04 — Uncaught HTTP 500 on parameterized `login_route` and non-string Gate names
- **Severity:** P1 (Reliability & Security / Fail-Closed Auth)
- **Affected file & symbol:** `src/Http/Middleware/AuthorizeOpConsole.php` (`handle()`, `unauthenticated()`)
- **Evidence:**
  1. `if ($login && Route::has($login) && ! $request->expectsJson()) { return redirect()->guest(route($login)); }` — if the host's `login` route requires a route parameter (e.g., `/{locale}/login`), `Route::has('login')` is `true` while `route('login')` throws `UrlGenerationException` (HTTP 500).
  2. `$gate = config('opconsole.authorization.'.$ability.'_gate'); abort_unless($gate && Gate::allows($gate), 403);` — if `$gate` is an array/integer or an unregistered gate name, `Gate::allows()` can throw `TypeError` instead of cleanly aborting with 403.
- **Impact:**
  Localized Laravel apps with parameterized login routes return HTTP 500 to guests; malformed Gate config causes HTTP 500 instead of 403.
- **Reproduction method:**
  Register `Route::get('/{locale}/login', fn () => 'login')->name('login')` and send an unauthenticated `GET /admin/opconsole`.
- **Recommended resolution:**
  Guard `route($login)` inside `try { ... } catch (Throwable)` falling back to `abort(401)`, and require `is_string($gate) && $gate !== '' && Gate::has($gate)` before evaluating `Gate::forUser(...)`.
- **Regression-test requirement:**
  Test guest request against a parameterized `login` route (expect 401, not 500) and test non-string/unregistered Gate configs (expect 403).

---

### F-05 — Missing distinct Gate permissions for log downloading and log deletion
- **Severity:** P1 (Security / Least Privilege)
- **Affected file & symbol:** `config/opconsole.php`, `routes/web.php`, `src/OpConsoleServiceProvider.php`, `src/Http/Middleware/AuthorizeOpConsole.php`, `src/Support/PagePresenter.php`
- **Evidence:**
  `GET logs/{file}` uses `opconsole.authorize:view` (and has no rate throttle), and `DELETE logs/{file}` uses `opconsole.authorize:run`.
- **Impact:**
  Hosts cannot allow an operator to view the dashboard and 2 MB log tail without also permitting full raw multi-MB log file downloads, nor allow cache clearing without also permitting permanent log deletion.
- **Reproduction method:**
  Grant `viewOpConsole` to a user; observe they can download full raw log files unless `logs.allow_download` is disabled globally for all administrators.
- **Recommended resolution:**
  Introduce `authorization.download_gate` (`downloadOpConsoleLogs`) and `authorization.delete_gate` (`deleteOpConsoleLogs`) enforced on the server via `opconsole.authorize:download` (+ `throttle:30,1`) and `opconsole.authorize:delete` (+ `throttle:6,1`). Preserve 100% backwards compatibility by registering default gates that delegate `downloadOpConsoleLogs` → `view_gate` and `deleteOpConsoleLogs` → `run_gate` whenever the host has not explicitly defined those two gates, while failing closed (403) if explicitly set to `null`/`''`. Reflect per-ability authorization in `PagePresenter` so unauthorized buttons/controls are disabled or hidden in the UI.
- **Regression-test requirement:**
  Test all 4 distinct abilities (`view`, `run`, `download`, `delete`) independently, plus backwards-compatible fallback when only `viewOpConsole` and `runOpConsoleActions` are defined, plus legacy published v0.1.0 configs missing the new keys vs explicit `null` config.

---

### F-06 — Uncaught exceptions from `Artisan::call()` cause HTTP 500 and leave audit rows stuck in `started`
- **Severity:** P1 (Reliability & Audit Accuracy)
- **Affected file & symbol:** `src/Support/ActionRunner.php` (`ActionRunner::run()`)
- **Evidence:**
  `Illuminate\Foundation\Console\Kernel::call()` runs commands with `catchExceptions = false`. In `ActionRunner::run()`, `$exitCode = Artisan::call($command, $options)` is inside `try { ... } finally { $lock->release(); }` with **no `catch` block**.
- **Impact:**
  When `migrate` fails with a `QueryException` or `optimize` (`route:cache`) fails with `LogicException` (e.g., host closure route), the request crashes with HTTP 500 instead of displaying the error in OpConsole, and `recordFinish()` never executes — leaving the `opconsole_runs` row permanently in `status = 'started'`.
- **Reproduction method:**
  Throw a `RuntimeException` from `Artisan::call()` during `POST /admin/opconsole/migrate`; observe HTTP 500 and `opconsole_runs.status === 'started'` with `finished_at === null`.
- **Recommended resolution:**
  Catch `Throwable` around `Artisan::call()`, capture partial `Artisan::output()` + sanitized exception summary, bound output length (max 16 KB), call `recordFinish($run, false, 1, $error)`, and return `['ok' => false, ...]` cleanly.
- **Regression-test requirement:**
  Test `Artisan::call()` throwing an exception: assert redirect with `opconsole_error` flash, sanitized exception text in `opconsole_output`, lock released, and `opconsole_runs` row updated to `status = 'failed'`.

---

### F-07 — Audit integrity gaps: premature download audit, misleading delete error, `VARCHAR` overflow, and invisible audit history / stale `started` runs
- **Severity:** P1 (Security & Reliability / Audit Trail)
- **Affected file & symbol:** `src/Http/Controllers/OpConsoleController.php`, `src/Support/ActionRunner.php`, `src/Support/ConsoleStatus.php`, `src/Support/LogViewer.php`, `src/Support/PagePresenter.php`, `resources/views/index.blade.php`
- **Evidence:**
  1. `OpConsoleController::downloadLog()` calls `$this->actions->record('log.download', ..., true, $file)` *before* `$this->logs->download($file)` validates the file path (which aborts 404 on missing/symlink files).
  2. `OpConsoleController::deleteLog()` flashes `log_delete_latest_blocked` (*"Cannot delete the latest log file..."*) whenever `LogViewer::delete()` returns `false`, even if the file was missing, rotated, a symlink escape, or unwritable; and `LogViewer::delete()` calls unguarded `unlink($path)` which emits a PHP warning leaking absolute paths on permission failure.
  3. `ActionRunner` does not truncate `user_id`, `user_label`, `subject`, or `error` to fit `VARCHAR(255)` columns, risking silent insert failures on strict MySQL/PostgreSQL.
  4. `ConsoleStatus` only queries `status IN ('succeeded', 'failed')` for `lastRuns()` and renders no operation history panel; `started` rows interrupted by PHP timeouts/crashes are hidden completely.
- **Impact:**
  Inaccurate audit records, misleading operator errors, potential path disclosure in PHP warnings, and no visibility into operation history or interrupted runs.
- **Reproduction method:**
  - `GET /admin/opconsole/logs/missing.log` → returns 404, yet `opconsole_runs` contains `action = 'log.download', status = 'succeeded', subject = 'missing.log'`.
  - `DELETE /admin/opconsole/logs/missing.log` → flashes *"Cannot delete the latest log file because it is still being written."*
- **Recommended resolution:**
  1. Validate `$this->logs->path($file)` before recording `log.download` (abort 404 without writing a false `succeeded` row).
  2. Return structured delete outcomes (`deleted`, `latest_blocked`, `not_found`, `failed`) using `@unlink($path)` so 404s and filesystem failures are reported and audited accurately without leaking filesystem paths.
  3. Bound string fields (`Str::limit(..., 250)`) and sanitize paths/credentials before writing to `opconsole_runs`.
  4. Surface `audited: bool` when an action runs while the audit table is unavailable.
  5. Add a dedicated **Operation history** section displaying Action, Actor, Status (`Succeeded`, `Failed`, `Started`, and `Interrupted / Unknown` for stale `started` rows past the lock window), Start time, Duration, and Details.
- **Regression-test requirement:**
  Test 404 download produces no `succeeded` audit row; test deleting missing vs newest vs older log files; test >255-char error/user labels; test stale `started` rows render as `Interrupted / Unknown` while fresh `started` rows render as `Started`.

---

### F-08 — `LogViewer::files()` lists unvalidated `*.log` entries, causing dashboard HTTP 500 and newest-file deletion bypass
- **Severity:** P1 (Security & Reliability / Log Viewer)
- **Affected file & symbol:** `src/Support/LogViewer.php` (`files()`, `recentErrors()`, `tail()`, `parse()`, `delete()`)
- **Evidence:**
  `LogViewer::files()` runs `glob($this->directory().'/*.log')` without checking `$this->path(basename($path))`.
  If the newest `*.log` entry in `storage/logs` is an external symlink (`escape.log -> /outside/secret.log`), a subdirectory (`archive.log/`), or a filename outside `^[\w.-]+\.log$` (`my app.log`):
  - `recentErrors()` executes `$this->tail($this->path($newest))` where `$this->path($newest)` returns `null`, throwing `TypeError` (HTTP 500 on dashboard load).
  - `OpConsoleController::log()` selects `files()[0]['name']` and calls `$this->logs->read($file)`, throwing `InvalidArgumentException` (HTTP 500).
  - `delete()` checks `$name !== ($this->files()[0]['name'] ?? null)`, allowing the real newest valid log file (`laravel.log`) to be deleted.
  Additionally, `tail()` calls `stream_get_contents($handle)` without a byte cap and does not scrub split multi-byte UTF-8 sequences at the `-2 MB` seek boundary.
- **Impact:**
  A single stray symlink or space-named `.log` file in `storage/logs` completely breaks the OpConsole dashboard with HTTP 500 and defeats newest-file deletion protection.
- **Reproduction method:**
  Create a symlink `storage/logs/escape.log` pointing outside `storage/logs` with a current timestamp, then request `GET /admin/opconsole`.
- **Recommended resolution:**
  Validate every entry in `LogViewer::files()` via `$this->path(basename($candidate))`, guard `@filesize` / `@filemtime` / `@fopen`, pass `self::TAIL_BYTES` to `stream_get_contents()`, scrub UTF-8 boundary bytes with `mb_scrub()`, guard `preg_split() === false`, and add clean NDJSON (Monolog `JsonFormatter`) log parsing fallback.
- **Regression-test requirement:**
  Test dashboard rendering and newest-file delete protection when an external `.log` symlink or space-named `.log` file has the newest `mtime`; test split multi-byte UTF-8 at the 2 MB tail boundary; test NDJSON log parsing.

---

### F-09 — Health checks report false Critical on MariaDB, false Healthy on unverified services, hardcode table/channel names, and lack type validation
- **Severity:** P1 (Correctness & Reliability / Health Dashboard)
- **Affected file & symbol:** `src/Support/HealthChecks.php`, `src/Support/ConsoleStatus.php`, `src/Support/PagePresenter.php`, `src/Contracts/HealthCheck.php`
- **Evidence:**
  1. Line 33 builds `'pdo_'.$driver`. When `database.default` uses Laravel's built-in `'mariadb'` driver, OpConsole demands PHP extension `pdo_mariadb` (which does not exist; MariaDB uses `pdo_mysql`).
  2. Line 45 marks `Sufficient disk space` as `ok: true` (Healthy) when `disk_free_space()` returns `false`.
  3. Lines 92–99 mark `No stuck queue jobs` and `No failed jobs` as `ok: true` ("0 stuck" / "0 failed") when the `jobs` or `failed_jobs` tables do not exist (e.g., `QUEUE_CONNECTION=redis`, `sqs`, or `sync`).
  4. Lines 84–90 hardcode table names `'sessions'`, `'cache'`, `'jobs'` instead of reading `config('session.table')`, `config('cache.stores.database.table')`, `config('queue.connections.database.table')`, and unconditionally require `failed_jobs` even when `queue.failed.driver` is `'null'`.
  5. Line 38 hardcodes `logging.channels.daily.level` regardless of `logging.default`.
  6. `assertShape()` does not verify value types (non-string `label`/`group`/`hint` crashes Blade with HTTP 500), does not deduplicate custom check classes, and allows custom checks to collide with core check labels/IDs.
  7. Missing explicit health check for OpConsole's own action lock store isolation and production cache/session ephemeral drivers.
- **Impact:**
  Inaccurate operational diagnostics on MariaDB, Redis/SQS/sync queues, shared hosting with disabled `disk_free_space()`, and custom table names.
- **Reproduction method:**
  Configure `database.connections.testing.driver = 'mariadb'` (or `queue.default = 'redis'` without a `jobs` table) and run `HealthChecks::run()`.
- **Recommended resolution:**
  1. Map PDO extensions accurately (`mysql`/`mariadb` → `pdo_mysql`, `pgsql` → `pdo_pgsql`, `sqlite` → `pdo_sqlite`, `sqlsrv` → `pdo_sqlsrv`).
  2. Implement a consistent 4-state status model (`healthy`, `warning`, `critical`, `unknown`) while maintaining full backwards compatibility with `Contracts\HealthCheck` (`ok` + `severity`). Report `unknown` when disk free space or non-database queue depth cannot be verified locally without external probes.
  3. Read configured session/cache/queue/lock tables and active log channel levels dynamically; check cache/session production drivers and OpConsole lock store health.
  4. Enforce strict scalar type validation in `assertShape()`, assign stable check IDs, deduplicate custom check classes, and protect core check IDs/labels from being overwritten.
  5. Sanitize `ConsoleStatus::database()` connection error strings (redacting DSN passwords and `base_path()`).
- **Regression-test requirement:**
  Unit tests for MariaDB driver mapping, `unknown` status on external queue / unavailable disk stats, configured database table names, lock-store health check, type-invalid custom checks, duplicate custom checks, and core check collision protection.

---

### F-10 — Mandatory third-party CDNs, broken `use_cdn=false` mode, missing `.btn-soft-*` CSS, inline script CSP violation, and WCAG AA contrast failures
- **Severity:** P1 (UI/UX, Accessibility & CSP Security)
- **Affected file & symbol:** `resources/views/layout.blade.php`, `resources/views/index.blade.php`, `resources/views/partials/confirm-modal.blade.php`, `public/opconsole.css`, `public/opconsole.js`, `config/opconsole.php`
- **Evidence:**
  1. `layout.blade.php` loads Bootstrap 5 and Unicons from `cdn.jsdelivr.net` by default, and wraps those tags in `@if (config('opconsole.assets.use_cdn'))` — so setting `use_cdn = false` omits all grid/button/modal/icon CSS & JS entirely.
  2. `opconsole.js` line 12 exits early (`if (!modalEl || typeof bootstrap === 'undefined') return;`), meaning when `use_cdn = false` or CDN JS is blocked by CSP/offline, forms with `data-confirm` submit **immediately without any confirmation dialog**.
  3. `.btn-soft-primary`, `.btn-soft-success`, `.btn-soft-secondary`, and `.btn-icon` are used throughout `index.blade.php` and `confirm-modal.blade.php` but do not exist in Bootstrap 5.3.3 or `opconsole.css`.
  4. If `vendor:publish --tag=opconsole-assets` has not been run yet, `/vendor/opconsole/opconsole.css` and `opconsole.js` return 404.
  5. `index.blade.php` includes an inline `<script>` block (lines 255–266) and inline `style="--c: ..."` attributes (line 26), violating strict `Content-Security-Policy: default-src 'self'`.
  6. `#94a3b8` text on `#ffffff` background (contrast 2.56:1) fails WCAG 2.1 AA (minimum 4.5:1).
  7. Maintenance operations lack risk-level badges/styling (`migrate --force --isolated` looks identical to `optimize:clear`), `<details id="checks">` defaults closed even when critical failures exist, and copy buttons lack screen-reader feedback.
- **Impact:**
  Broken standalone styling, unconfirmed destructive operations when offline/CSP-restricted, CSP failures, and accessibility barriers.
- **Recommended resolution:**
  1. Make `opconsole.css` and `opconsole.js` 100% self-contained (standalone layout/grid/button/alert/badge/modal styles + inline SVG icons + dependency-free accessible `<dialog>` confirmation modal with keyboard focus management and native `window.confirm` safety net if JS modal markup is absent). Default `assets.use_cdn` to `false` while preserving optional `bootstrap_css`/`bootstrap_js`/`icons_css` loading when configured.
  2. Serve package fallback asset routes (`opconsole.asset`) when published `public/vendor/opconsole/*` files are absent so fresh installs work immediately without 404s.
  3. Remove all inline `<script>` and inline `style` attributes for strict CSP compatibility.
  4. Add explicit risk badges/styling and disabled-state explanations on maintenance actions, auto-expand Health Checks when problems exist, upgrade muted text contrast to WCAG AA (`#475569` / `#64748b`), and add `aria-live` + visible feedback on copy actions.
- **Regression-test requirement:**
  Test standalone dashboard HTML has no mandatory CDN links by default, no inline `<script>` tags, serves fallback CSS/JS assets cleanly, renders risk indicators, auto-opens failing health checks, and renders accessible landmarks/dialogs.

---

### F-11 — `PagePresenter::log()` strips query parameter `?q=0`
- **Severity:** P2 (Correctness / Log Search)
- **Affected file & symbol:** `src/Support/PagePresenter.php` (`PagePresenter::log()`)
- **Evidence:**
  Line 159 calls `array_filter($params + [...])` without a predicate callback. In PHP, `array_filter(['q' => '0'])` removes `'0'`.
- **Impact:**
  Searching logs for `'0'` loses the search term when clicking level chips or *"Show more"*.
- **Recommended resolution:**
  Pass `fn ($value) => $value !== null && $value !== ''` to `array_filter()`.
- **Regression-test requirement:**
  Unit/feature test verifying `?q=0` is preserved in generated level chip and pagination URLs.

---

### F-12 — Unbounded `opconsole_runs` table scan, duplicate 2 MB log reads/parses, and duplicate schema queries
- **Severity:** P2 (Performance & Scalability)
- **Affected file & symbol:** `src/Support/ConsoleStatus.php`, `src/Support/HealthChecks.php`, `src/Support/LogViewer.php`, `src/Http/Controllers/OpConsoleController.php`, `src/Support/PagePresenter.php`
- **Evidence:**
  Verified by baseline query log and profiler (`bench.php`):
  1. `ConsoleStatus::lastRuns()` executes `SELECT action, status, user_label, finished_at FROM opconsole_runs WHERE action IN (...) AND status IN (...) ORDER BY id DESC` with **no `LIMIT`**, loading every historical row into memory.
  2. `OpConsoleController::index()` triggers `LogViewer::files()` 4× and reads/parses the newest 2 MB log tail 2–3× per request (`recentErrors()` + `log()` + environment-fallback re-read). `recentErrors()` also runs `Carbon::parse()` on every historical error entry from oldest to newest.
  3. `migrations` table existence + `getRan()` and `failed_jobs` table existence are each queried twice per request; `PagePresenter::actions()` runs twice when action output is flashed.
- **Impact:**
  Unnecessary latency (35.7 ms on a 5 MB log) and unbounded DB memory growth as `opconsole_runs` accumulates rows over months of operation.
- **Recommended resolution:**
  1. Query `lastRuns()` using `WHERE id IN (SELECT MAX(id) ... GROUP BY action)` (returns ≤ 3 rows regardless of table size) and query `recentRuns()` with `LIMIT 15`.
  2. Cache `LogViewer::files()` and parsed tail result within the `LogViewer` request lifecycle (invalidated on `delete()`), handle environment fallback in a single `read()` pass without re-reading disk, and scan `recentErrors()` newest-first with early cutoff once entries precede the 24-hour window.
  3. Eliminate duplicate `Migrator` and `Schema::hasTable()` calls and compute `PagePresenter::actions()` once.
- **Regression-test requirement:**
  Query-count assertion on dashboard load and large-table `opconsole_runs` test proving bounded query results.

---

### F-13 — Undeclared direct `illuminate/*` packages in `composer.json`, single-version `clean-install` CI job, and `'auth'` middleware in obsolete integration examples
- **Severity:** P2 (Package Engineering, CI & Documentation)
- **Affected file & symbol:** `composer.json`, `.github/workflows/tests.yml`, legacy integration example files (removed during repository independence cleanup)
- **Evidence:**
  1. `composer.json` declares `illuminate/{console,contracts,database,support}` but `src/` directly imports `illuminate/{auth,cache,http,routing,view}`.
  2. `.github/workflows/tests.yml` `clean-install` runs only against default `laravel/laravel` (Laravel 13) rather than both Laravel 12 and Laravel 13, and omits `composer validate --strict` and `php artisan view:cache`.
  3. Legacy integration example files set `'middleware' => ['web', 'auth']`, contradicting `README.md` and `docs/SECURITY.md`.
- **Impact:**
  Incomplete Composer component declarations, incomplete CI smoke coverage for Laravel 12 clean installs, and misleading example config.
- **Recommended resolution:**
  Declare all directly imported `illuminate/*` components at `^12.0|^13.0` in `composer.json`, expand `.github/workflows/tests.yml` `clean-install` and `static-analysis` jobs to cover both Laravel 12 and Laravel 13 plus `view:cache` and `composer validate --strict`, and remove obsolete reference-project integration examples in favor of standalone documentation (`docs/INSTALLATION.md`, `docs/CONFIGURATION.md`).
- **Regression-test requirement:**
  `composer validate --strict`, full test suite + Larastan on both Laravel 12 and Laravel 13, and local disposable clean-install tests on both Laravel 12 and Laravel 13.

---

## 4. Additional Cross-Version & Runtime Defects Caught During Hardening

1. **Laravel 12 vs 13 lock-separation contract difference:** `Illuminate\Contracts\Cache\CanFlushLocks` exists in Laravel 13 (`v13.x`) and is absent in Laravel 12 (`v12.x`). Implemented `ActionRunner::hasSeparateLockStorage()` using `method_exists($underlyingStore, 'hasSeparateLockStore')` on Laravel 13 with explicit `DatabaseStore` (`lock_table` / `lock_connection`), `RedisStore` (`lock_connection`), and `FileStore` (`lock_path`) inspection on Laravel 12.
2. **Symlinked parent directory resolution in `ActionRunner::normalizePath()`:** On macOS (`/var/folders` → `/private/var/folders`) and zero-downtime deployment hosts where `storage/` is a symlink, `realpath()` returns `false` for not-yet-created subdirectories while resolving existing parent directories. Updated `normalizePath()` to walk parent segments until `realpath()` succeeds so overlapping `FileStore` paths are detected reliably before first write.
3. **Shared rate-limiter bucket starvation in `routes/web.php`:** Unnamed Laravel `throttle:maxAttempts,decayMinutes` definitions without a 3rd `$prefix` parameter share a single counter per user/IP, meaning 6 log downloads (`throttle:30,1`) would exhaust the `throttle:6,1` budget for Artisan actions and log deletions. Isolated all three buckets via `throttle:30,1,opconsole-download`, `throttle:6,1,opconsole-delete`, and `throttle:6,1,opconsole-run`.

---

## 5. Post-Hardening Verification & Benchmark Comparison

### Automated Verification Matrix (Actual Local Execution on PHP 8.5.12)

| Verification Gate | Laravel 12.70.2 / Testbench 10.12.0 | Laravel 13.39.0 / Testbench 11.3.0 |
|---|---|---|
| `composer test` (PHPUnit) | **PASS** — 98 tests, 398 assertions (1.03s) | **PASS** — 98 tests, 398 assertions (0.93s) |
| `phpstan analyse --memory-limit=1G` (Larastan L5) | **PASS** — 0 errors | **PASS** — 0 errors |
| `vendor/bin/pint --test` | **PASS** — 0 style issues | **PASS** — 0 style issues |
| `composer validate --strict` | **PASS** | **PASS** |
| `testbench config:cache && route:cache && view:cache` | **PASS** | **PASS** |
| Disposable clean-app install, publish, migrate/rollback, guest `401`, prod `403`, auth `200`, file lock + `clear`, cache compilation, disable switch & `composer remove` | **PASS** (`/tmp/opconsole-clean-l12`) | **PASS** (`/tmp/opconsole-clean-l13`) |

*(Note: GitHub Actions remote matrix execution across PHP 8.3 and PHP 8.4 remains unverified locally until this branch is pushed to GitHub.)*

### Performance Before vs. After (Same Local Benchmark Harness, PHP 8.5.12)

| Metric | Baseline (v0.1.0) | Post-Hardening | Delta / Impact |
|---|---|---|---|
| `GET /admin/opconsole?q=Gateway` (warm, 5 MB log) | **35.73 ms**, 6 SQL queries | **18.27 ms**, 4 SQL queries | **-48.9% latency** (single request-scoped 2 MB tail parse instead of 2–3×) |
| `GET /admin/opconsole` SQL queries | **6 queries** (unbounded `opconsole_runs` scan + duplicate `migrations` & `failed_jobs` checks) | **4 bounded queries** (`MAX(id) GROUP BY action` + `LIMIT 15` history; zero duplicate schema queries) | **-33% query count** & `O(1)` bounded audit memory regardless of table size |
| Peak PHP memory (5 MB log search) | 36.02 MB (`TAIL_BYTES = 2 MB`) | 36.02 MB (`TAIL_BYTES = 2 MB` strictly capped) | Constant bounded memory |
| Local CSS + JS assets | 16,006 B + **~310 KB mandatory external CDN** (Bootstrap 5 + Unicons) | **34,171 B total** (`26,240 B` CSS + `7,931 B` JS) + **0 B external CDN** | **~90% less total network payload**, zero third-party requests, strict-CSP clean |

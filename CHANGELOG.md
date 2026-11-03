# Changelog

All notable changes to Laravel OpConsole are documented here. This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.5.0] - 2026-11-03

### Added
- **Theme toggle (`theme_toggle`, `OPCONSOLE_THEME_TOGGLE`):** A light/dark switch in the header. The choice is saved per browser in the plain `opconsole_theme` cookie (excluded from cookie encryption) and rendered server-side, so the page loads in the chosen theme with no flash and no inline script. Enabled by default.

## [1.4.0] - 2026-11-03

### Added
- **Section tabs:** The dashboard groups its sections into Actions, Health, History, Logs, and System tabs below the status tiles. Tabs carry attention badges (pending migrations or a failed last action, health checks needing attention, recent operations, errors in the last 24 hours) with screen-reader text. Actions opens first after an operation runs, Health opens first when a check is critical, and otherwise the last tab used is remembered. Existing anchors (`#checks`, `#history`, `#logs`, `#action-*`) switch to the right tab, log filters stay on Logs, and arrow/Home/End keys move between tabs. Without JavaScript every section still renders in page order.
- **Dark mode (`theme`, `OPCONSOLE_THEME`):** `auto` follows the system setting, `light` or `dark` force one. Defaults to `auto` for the standalone layout and `light` when embedded in a host layout. All colors are now CSS custom properties.
- **`--oc-tabs-offset` CSS variable:** Host layouts with a fixed header can set it so the sticky tab bar sits below their header.
- New language strings: `tabs_label`, `tab_*`, `tab_badge_*`.

## [1.2.0] - 2026-11-03

### Added
- **Scheduler heartbeat (`scheduler.heartbeat`):** The service provider schedules a callback every minute that writes `opconsole:heartbeat:scheduler` to the heartbeat cache store. The new "Scheduler and workers" check is healthy within `heartbeats.scheduler_max_age` (default 120 s), a warning when stale outside production, critical in production, and `unknown` until the first beat.
- **Queue worker heartbeat (`queue.worker_heartbeat`):** The scheduler dispatches `OpConsoleHeartbeatJob` every minute on a configurable connection and queue; the worker writes `opconsole:heartbeat:queue`. Healthy within `heartbeats.queue_max_age` (default 300 s), warning/critical when stale with fix `php artisan queue:work`, `unknown` while the scheduler is not confirmed running, and skipped for `sync`/`null` drivers. Unlike `queue.stuck_jobs`, it works with Redis, SQS, and any other driver.
- **Cache round trip (`cache.round_trip`):** Writes a random value under a unique key with a 10-second TTL, reads it back, and forgets it. Critical on mismatch or exception; the value shows the store name and time taken. Skipped with an info status for `array`/`null` drivers outside production.
- **Production caches built (`performance.caches_built`):** In production, warns when configuration, routes, or events are not cached, lists which are missing, and suggests `php artisan optimize`. Informational outside production.
- **Maintenance mode (`app.maintenance_mode`, `down` and `up` actions):** A warning check while the application is down (fix `php artisan up`). New `down` ("Medium risk · Site offline") and `up` action cards use the run Gate, the shared action lock, the audit history, and a confirmation dialog. `down` passes a random `--secret` and shows the bypass URL once in the output panel; the secret is never logged or audited. The console's own routes stay reachable during maintenance (`maintenance.allow_console`).
- **Storage link (`files.storage_link`, optional `storage_link` action):** When a `public` disk exists, warns if `public/storage` is missing or does not resolve to `storage/app/public` (fix `php artisan storage:link`). An optional low-risk action runs `storage:link`; it is off by default (`OPCONSOLE_ACTION_STORAGE_LINK=true` to enable).
- **`opconsole:health` command:** `{--json} {--fail-on=critical}` prints a table (id, status, label, value, fix) or JSON and exits `1` when any check is at or above the `--fail-on` level (`warning` or `critical`).
- **Health endpoint (`GET {prefix}/health`):** Disabled by default (`health_endpoint.enabled`); bearer token from `OPCONSOLE_HEALTH_TOKEN` compared with `hash_equals` (`404` while disabled or tokenless, `401` on a bad token). Responds with `{status, checked_at, checks: [{id, group, label, status, value}]}`, HTTP `503` when any check is critical, rate limited, and without session or CSRF middleware. The command, endpoint, and dashboard share `ConsoleStatus::health()`.
- **Dashboard polish:** The Health checks header shows "Last checked {time}". `files.disk_space` also warns below `disk.min_free_percent` (default 10%) free and shows the percentage. Check groups follow a fixed order (Security, Database and queues, Scheduler and workers, Cache and performance, Files and folders, PHP settings, URL and email, then custom groups), still with problem groups first. New language strings: `down_*`, `up_*`, `storage_link_*`, `bypass_url`, `last_checked`.

### Fixed
- **Accurate action durations:** Operation history showed `0 ms` because `started_at`/`finished_at` are second-precision. A new migration adds a nullable `duration_ms` column; `ActionRunner` measures Artisan calls with `hrtime(true)` and stores `0` for instant operations. Older rows fall back to the timestamp difference, and audit writes keep working until the host runs the new migration.

## [1.0.0] - 2026-11-03

### Security
- **Configured authentication guard enforcement:** `AuthorizeOpConsole` enforces `config('opconsole.authentication.guard')` end-to-end via `Auth::shouldUse($guardName)` and `Gate::forUser($guard->user())`, attributing `OpConsoleRun` audit rows to the configured guard's user and failing closed (`403`) on invalid guard names.
- **Mandatory `web` middleware preservation:** `routes/web.php` always retains `'web'` middleware even when `config('opconsole.route.middleware')` is overridden, preserving session state and CSRF protection on `POST`/`DELETE` endpoints.
- **Fail-closed isolated action lock store:** Added automatic registration of an isolated file lock store (`cache.stores.opconsole` at `storage/framework/cache/opconsole`) and updated `opconsole.concurrency.lock_store` default to `'opconsole'`. Removed silent fallback to `Cache::lock()`; `ActionRunner::validateLockStore()` rejects missing stores, `NullStore`/`NoLock`, `ArrayStore` outside unit tests, `lock_store === cache.default` without separate lock storage (across Laravel 12 and 13), and overlapping `FileStore` directories.
- **Parameterized login route & Gate config hardening:** Guests on hosts whose named `login` route requires URL parameters receive a controlled `401` instead of an uncaught `UrlGenerationException` (`500`). Non-string or undefined custom Gate names fail closed (`403`) in all environments.
- **Granular 4-Gate authorization & isolated rate-limit buckets:** Added `authorization.download_gate` (`downloadOpConsoleLogs`, `throttle:30,1,opconsole-download`) and `authorization.delete_gate` (`deleteOpConsoleLogs`, `throttle:6,1,opconsole-delete`) alongside `view_gate` and `run_gate` (`throttle:6,1,opconsole-run`), with full backward compatibility for existing 2-gate setups.
- **Credential & path redaction:** `ActionRunner::sanitizeText()` strips ANSI escape codes, DSN passwords, `password=`/`secret=`/`token=`/`api_key=` values, and `base_path()` prefixes before displaying errors/output or persisting `opconsole_runs` rows.
- **Strict log boundary & audit integrity:** `LogViewer::files()` filters directory entries strictly through `LogViewer::path()` so external symlinks or invalid filenames never crash `recentErrors()` or allow deletion of the newest valid log file. `OpConsoleController::downloadLog()` validates file existence before recording a `log.download` audit row.

### Added
- **4-state health check model:** Health checks report `healthy`, `warning`, `critical`, or `unknown` with stable check IDs (`id`), distinguishing unverified external queue brokers (`redis`/`sqs`), unmigrated `failed_jobs`, or unavailable `disk_free_space()` from verified healthy states.
- **Built-in security checks:** Added `Action lock store isolation` (`security.lock_store`) and `Persistent cache and session drivers` (`security.cache_session_persistence`).
- **Operation history panel (`#history`):** Added bounded recent operation history table showing operation, status (`Succeeded`, `Failed`, `Started`, `Interrupted / Unknown`), actor, relative + UTC start time, duration, and details.
- **Monolog NDJSON log parsing:** `LogViewer` parses JSON-formatted Monolog lines (`JsonFormatter`) alongside standard multiline Laravel logs.
- **Authenticated asset fallback route:** Added `GET {prefix}/assets/{asset}` (`opconsole.asset`) with `X-Content-Type-Options: nosniff` so standalone CSS/JS work immediately even before `vendor:publish --tag=opconsole-assets`.
- **Standalone documentation:** Added `docs/INSTALLATION.md`, `docs/CONFIGURATION.md`, `docs/UPGRADE.md`, and `docs/RELEASE-CHECKLIST.md`.

### Changed
- **Self-contained dependency-free UI:** Removed Bootstrap 5 and Iconscout Unicons CDN dependencies and obsolete `assets.*` CDN configuration keys in favor of a self-contained stylesheet (`public/opconsole.css`), scoped `oc-*` UI controls, inline SVG icons (`opconsole::partials.icon`), native `<dialog>` confirmation modal (`public/opconsole.js`), strict-CSP compatibility (zero inline `<script>` or `style=` attributes), and WCAG AA contrast.
- **Performance optimizations:** Registered `LogViewer` as a request-scoped service with parsed-entry caching and single-pass `fallbackToAllEnvs`, eliminated duplicate `Migrator` and `Schema::hasTable` queries, and replaced unbounded `OpConsoleRun` queries with indexed `MAX(id) GROUP BY action` + `LIMIT 15` queries.
- **Composer metadata & CI:** Declared all directly imported `illuminate/{auth,cache,http,routing,view}` packages (`^12.0|^13.0`), added repository homepage/support metadata and `.gitattributes` `export-ignore` rules, and expanded CI `static-analysis` and `clean-install` jobs across Laravel 12 and Laravel 13.

### Fixed
- Fixed `ActionRunner::run()` catching thrown `Artisan::call()` exceptions cleanly, updating `started` audit rows to `failed`, and flashing `opconsole_warning` when audit persistence is unavailable.
- Fixed `HealthChecks` mapping Laravel's `mariadb` database driver to PHP's `pdo_mysql` extension.
- Fixed `LogViewer` capping tail reads strictly to `TAIL_BYTES` and scrubbing split multi-byte UTF-8 sequences at the `-2 MB` seek boundary.
- Fixed `PagePresenter` preserving literal `'0'` (`?q=0`) in log filter URLs.

---

## [0.1.0] - Baseline

Initial baseline.

### Added
- System health dashboard: Laravel/PHP version, environment, database connectivity, pending migrations, cache state, filesystem permissions, security basics, database/queue tables, mail/URL checks.
- Three allow-listed Artisan operations: `optimize:clear`, `optimize`, `migrate --force --isolated`, each individually disable-able, serialized with a cache lock, and audited.
- Log viewer: level/environment/text filtering, repeated-error grouping, download, delete with symlink/path-traversal protection.
- `AshrafTalha\OpConsole\Contracts\HealthCheck` extension point for host-specific checks.
- Deny-by-default authorization via configurable Gates, with a fail-closed fallback outside the `local` environment.
- `opconsole_runs` audit table recording started/succeeded/failed status for every action and log delete/download.
- Testbench test suite, Larastan level 5, Pint, and Laravel 12/13 CI matrix.

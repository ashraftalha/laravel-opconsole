# Changelog

All notable changes to Laravel OpConsole are documented here. This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased] (Recommended: `0.2.0`)

### Security
- **Configured authentication guard enforcement (`F-01`):** `AuthorizeOpConsole` now enforces `config('opconsole.authentication.guard')` end-to-end via `Auth::shouldUse($guardName)` and `Gate::forUser($guard->user())`, ensuring Gate evaluation and `OpConsoleRun` audit attribution always resolve the configured guard's user and failing closed (`403`) on invalid guard names.
- **Mandatory `web` middleware preservation (`F-02`):** `routes/web.php` always retains `'web'` middleware even when `config('opconsole.route.middleware')` is overridden, preventing accidental removal of session state and CSRF protection on mutating `POST`/`DELETE` routes.
- **Fail-closed isolated action lock store (`F-03`):** Added automatic registration of an isolated file lock store (`cache.stores.opconsole` at `storage/framework/cache/opconsole`) and updated `opconsole.concurrency.lock_store` default to `'opconsole'`. Removed silent fallback to `Cache::lock()`; `ActionRunner::validateLockStore()` now rejects missing stores, `NullStore`/`NoLock`, `ArrayStore` outside unit tests, `lock_store === cache.default` without separate lock storage (across both Laravel 12 and Laravel 13), and overlapping `FileStore` directories (including symlinked parent paths).
- **Parameterized login route & Gate config hardening (`F-04`):** Guests on hosts whose named `login` route requires URL parameters (e.g. `/{locale}/login`) now receive a controlled `401` instead of an uncaught `UrlGenerationException` (`500`). Non-string or undefined custom Gate names now fail closed (`403`) in all environments.
- **Granular 4-Gate authorization & isolated rate-limit buckets (`F-05`):** Added `authorization.download_gate` (`downloadOpConsoleLogs`, `throttle:30,1,opconsole-download`) and `authorization.delete_gate` (`deleteOpConsoleLogs`, `throttle:6,1,opconsole-delete`) alongside `view_gate` and `run_gate` (`throttle:6,1,opconsole-run`), with full backward compatibility for existing 2-gate setups.
- **Credential & path redaction (`F-06`, `F-07`):** `ActionRunner::sanitizeText()` strips ANSI escape codes, DSN passwords, `password=`/`secret=`/`token=`/`api_key=` values, and `base_path()` prefixes before displaying errors/output or persisting `opconsole_runs` rows.
- **Strict log boundary & audit integrity (`F-07`, `F-08`):** `LogViewer::files()` filters directory entries strictly through `LogViewer::path()` so external symlinks or invalid filenames can never crash `recentErrors()` or allow deletion of the newest valid log file. `OpConsoleController::downloadLog()` validates file existence before recording a `log.download` audit row.

### Added
- **4-state health check model (`F-09`):** Health checks now report `healthy`, `warning`, `critical`, or `unknown` with stable check IDs (`id`), distinguishing unverified external queue brokers (`redis`/`sqs`), unmigrated `failed_jobs`, or unavailable `disk_free_space()` from verified healthy states.
- **New built-in security checks:** Added `Action lock store isolation` (`security.lock_store`) and `Persistent cache and session drivers` (`security.cache_session_persistence`).
- **Operation history panel (`#history`):** Added bounded recent operation history table showing operation, status (`Succeeded`, `Failed`, `Started`, `Interrupted / Unknown`), actor, relative + UTC start time, duration, and details.
- **Monolog NDJSON log parsing:** `LogViewer` now parses JSON-formatted Monolog lines (`JsonFormatter`) alongside standard multiline Laravel logs.
- **Authenticated asset fallback route (`F-11`):** Added `GET {prefix}/assets/{asset}` (`opconsole.asset`) with `X-Content-Type-Options: nosniff` so standalone CSS/JS work immediately even before `vendor:publish --tag=opconsole-assets`.
- **Documentation:** Added standalone `docs/INSTALLATION.md`, `docs/CONFIGURATION.md`, `docs/UPGRADE.md`, `docs/RELEASE-CHECKLIST.md`, `docs/FINAL-ENGINEERING-AUDIT.md`, `docs/INDEPENDENCE-AUDIT.md`, and `docs/ASHRAFTALHA-INTEGRATION.md`, and removed obsolete reference-project integration documents.

### Changed
- **Self-contained dependency-free UI (`F-10`):** Replaced mandatory Bootstrap 5 and Iconscout Unicons CDN dependencies (`assets.use_cdn` now defaults to `false`) with a complete self-contained stylesheet (`public/opconsole.css`), inline SVG icons (`opconsole::partials.icon`), native `<dialog>` confirmation modal (`public/opconsole.js`), zero inline `<script>`/`style=` attributes (strict-CSP compatible), WCAG AA contrast, risk badges, and screen-reader live regions.
- **Performance optimizations (`F-12`):** Registered `LogViewer` as a request-scoped service with parsed-entry caching and single-pass `fallbackToAllEnvs` (~49% faster 5 MB log search requests), eliminated duplicate `Migrator` and `Schema::hasTable` queries, and replaced unbounded `OpConsoleRun::all()` scans with indexed `MAX(id) GROUP BY action` + `LIMIT 15` queries (reducing dashboard SQL queries from 6 unbounded to 4 bounded).
- **Composer metadata (`F-13`):** Declared all directly imported `illuminate/{auth,cache,http,routing,view}` packages (`^12.0|^13.0`) in `composer.json` and expanded CI `static-analysis` and `clean-install` jobs to cover both Laravel 12 and Laravel 13.

### Fixed
- Fixed `ActionRunner::run()` catching thrown `Artisan::call()` exceptions cleanly, updating `started` audit rows to `failed`, and flashing `opconsole_warning` when audit persistence is unavailable (`F-06`, `F-07`).
- Fixed `HealthChecks` mapping Laravel's `mariadb` database driver to PHP's `pdo_mysql` extension (`F-09`).
- Fixed `LogViewer` capping tail reads strictly to `TAIL_BYTES` and scrubbing split multi-byte UTF-8 sequences at the `-2 MB` seek boundary (`F-08`).
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

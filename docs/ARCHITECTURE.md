# Architecture

One service provider, one controller, five focused `Support` classes, one Eloquent model, and one guard-aware Gate middleware. No facades, no repository layer, no DTO hierarchy, and no per-command action classes — keeping the package lightweight, readable, and trivial to audit.

```
src/
├── OpConsoleServiceProvider.php              Config merge, isolated lock store registration, routes, views, translations, migrations, publishing, default Gates
├── Http/
│   ├── Controllers/OpConsoleController.php   5 actions: index, asset, downloadLog, deleteLog, run
│   └── Middleware/AuthorizeOpConsole.php     Configured guard check + Gate::forUser() enforcement, fail-closed
├── Models/OpConsoleRun.php                   Audit & history row: started / succeeded / failed
├── Contracts/HealthCheck.php                 The single host extension contract
└── Support/
    ├── ConsoleStatus.php                     Read model: database/migrator snapshot, caches, checks, system info, advice, bounded audit history
    ├── ActionRunner.php                      Write model: renewable owner-bound lock, bounded synchronous Artisan execution, allow-list, sanitization, audit writes
    ├── HealthChecks.php                      Built-in 4-state diagnostics + container-resolved config('opconsole.checks')
    ├── HealthNotifier.php                    Opt-in transition evaluation with minimal cache state and duplicate suppression
    ├── LogViewer.php                         Request-scoped bounded tail reader (2 MB), text & NDJSON parser, filter/group, safe download/delete
    └── PagePresenter.php                     View presenter: overview/readiness, risk-graded action cards, sorted checks, operation history, filter URLs, XSS-safe highlighting
```

---

## Key Architectural Decisions

- **`ConsoleStatus` vs `ActionRunner` separation:** Reads and writes have distinct risk profiles, rate limits, and authorization Gates (`view_gate` vs `run_gate`). Separating them ensures diagnostic page loads never touch Artisan execution paths.
- **Isolated `opconsole` lock store:** `OpConsoleServiceProvider` registers `cache.stores.opconsole` (`storage/framework/cache/opconsole`) automatically when not overridden by the host. `ActionRunner::validateLockStore()` verifies lock isolation fail-closed across both Laravel 12 and Laravel 13 so `optimize:clear` (`cache:clear`) can never flush an active maintenance lock.
- **Request-scoped `LogViewer`:** Registered via `$this->app->scoped(LogViewer::class)` so `files()` and the 2 MB tail parse run at most once per HTTP request (reused across `ConsoleStatus::recentErrors()` and `OpConsoleController::index()`) while never leaking stale state across Laravel Octane / long-lived worker requests.
- **`PagePresenter` owns all presentation logic:** Blade templates (`layout.blade.php`, `index.blade.php`, and `partials/*`) only print pre-formatted arrays and inline SVG icons. Grouping, severity ordering (`critical` → `warning` → `unknown` → `healthy`), stale-run classification (`Interrupted / Unknown`), URL generation, and raw-before-escape `<mark>` highlighting all live in `PagePresenter`.
- **Overview communicates verification limits:** The first section distinguishes verified critical/warning results from unknown checks, shows scheduler, worker, maintenance and last-check status, and links to details. Recent error totals are explicitly sampled from the bounded tail of the newest readable log; zero means no sampled errors, not proof that every log is clear.
- **Progressive disclosure and navigation:** Overview, Health, Operations, Logs, History, and System information share a keyboard-operable tab bar. Hash targets and query filters survive navigation and refresh; check details and per-run details remain collapsible.
- **Self-contained, zero-CDN frontend:** `public/opconsole.css` and `public/opconsole.js` have zero runtime dependencies on Bootstrap, jQuery, or external icon fonts, use scoped `oc-*` classes to avoid colliding with host admin stylesheets, and emit zero inline `<script>` or `style=` attributes for strict Content-Security-Policy compatibility. If assets have not been published to `public/vendor/opconsole/` yet, `OpConsoleController::asset()` serves them behind `opconsole.authorize:view`.
- **`HealthCheck` is the sole callable extension point:** `config/opconsole.php` contains zero closures (`config:cache` safe). Host applications register class names in `config('opconsole.checks')`; `HealthChecks` resolves each via the container, validates scalar types and shape, deduplicates repeated classes, and blocks collisions with built-in checks.
- **Bounded synchronous execution:** The seven allow-listed Artisan operations run in the request without queue dispatch. A pcntl alarm enforces `max_runtime_seconds` and renews the owner-bound lease where the driver supports `refresh()`. The lease always exceeds the budget, so renewal is a safety margin rather than a requirement. Runtimes without pcntl refuse every timer-bound action before locking; only the subprocess-bounded `security_scan` runs. Fatal errors release the lock from a shutdown hook, and killed processes release it by lease expiry. Queue workers are deliberately not involved: queue workers stop processing while the application is down, so a queued `up` could never run.
- **One health report policy:** Dashboard, CLI, and HTTP endpoint consume the same check list and unknown-status policy. Notifications run only when explicitly requested from `opconsole:health --notify`, so health reads have no delivery side effects.
- **Bounded `OpConsoleRun` queries:** `ConsoleStatus` fetches the latest completed run per action via `SELECT MAX(id) ... GROUP BY action` and the 15 most recent audit rows via `ORDER BY id DESC LIMIT 15`, keeping SQL query count and memory constant regardless of historical table size. `user_id` is stored as a nullable string without foreign keys so integer, UUID, and ULID user primary keys all work portably across SQLite, MySQL/MariaDB, PostgreSQL, and SQL Server.

---

## Request Flow

```
GET    {prefix}/              → web + opconsole.authorize:view
                              → ConsoleStatus::status() + LogViewer::files()/read() → PagePresenter::page() → Blade view

GET    {prefix}/assets/{asset} → web + opconsole.authorize:view
                              → OpConsoleController::asset() (serves opconsole.css / opconsole.js with nosniff)

GET    {prefix}/logs/{file}   → web + opconsole.authorize:download + throttle:30,1,opconsole-download
                              → LogViewer::path() → ActionRunner::record('log.download') → response()->download()

DELETE {prefix}/logs/{file}   → web + opconsole.authorize:delete + throttle:6,1,opconsole-delete
                              → LogViewer::deleteOutcome() → ActionRunner::record('log.delete') → redirect

POST   {prefix}/{action}      → web + opconsole.authorize:run + throttle:6,1,opconsole-run
                              → ActionRunner::run() (validateLockStore → lock->get → recordStart → Artisan::call → recordFinish → lock->release) → redirect
```

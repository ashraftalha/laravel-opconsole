# Security

Reporting a vulnerability: open a private security advisory on the repository, or contact the maintainer directly. Do not open a public issue for exploitable vulnerabilities.

---

## Threat Model and Mitigations

| Threat / Risk | Mitigation |
|---|---|
| Unauthorized authenticated users accessing the console | Four console Gates plus optional per-action Gate overrides (`action_gates`). Action overrides are enforced in middleware and reflected in action buttons. Missing action entries retain `run_gate`; explicitly mapped empty or undefined Gates fail closed (`403`). |
| Multi-guard privilege confusion | `AuthorizeOpConsole` authenticates against `config('opconsole.authentication.guard')`, calls `Auth::shouldUse($guardName)`, and evaluates `Gate::forUser($guard->user())` so both Gate authorization and `OpConsoleRun` audit attribution use the configured guard's user. Invalid guard names fail closed (`403`). |
| Guest crash (`HTTP 500`) on hosts without a standard `login` route | `AuthorizeOpConsole` checks authentication directly instead of using Laravel's `auth` middleware alias. Guests redirect to `route($login)` when valid and parameterless, or receive a controlled `401` when `login_route` is `null`, nonexistent, or requires URL parameters (catching `UrlGenerationException`). |
| Accidental CSRF removal via `route.middleware` overrides | `routes/web.php` prepends `'web'` unconditionally (`array_unique(['web', ...$extraMiddleware])`) so overriding `route.middleware` can never strip session or CSRF middleware from `POST`/`DELETE` endpoints. |
| Arbitrary command execution via HTTP | Closed constant allow-list of six operations: cache clear, optimize, forced isolated migrations, maintenance down/up, and optional storage link. Route constraints and `ActionRunner::run()` reject anything else; request input never supplies command arguments. |
| Self-invalidating or ineffective action locks | `OpConsoleServiceProvider` registers an isolated file cache store (`opconsole` at `storage/framework/cache/opconsole`), separate from `cache.default`. `ActionRunner::validateLockStore()` rejects missing stores, `NullStore`/`NoLock`, `ArrayStore` outside unit tests, `lock_store === cache.default` without separate lock storage, and overlapping `FileStore` directories (resolving symlinked parents). Any lock validation or acquisition error **fails closed** without running Artisan. |
| Concurrent or abandoned maintenance operations | A single owner-bound atomic lock covers every Artisan action. A pcntl alarm renews it during synchronous work and enforces the configured runtime limit; process death leaves the lease to expire. Redis storage identity is compared by endpoint and database, even across differently named stores, because cache clearing flushes the whole database. |
| Path traversal or symlink escape in log viewer/download/delete | Both route constraints and `LogViewer::path()` enforce `^[\w.-]+\.log$`, resolve `realpath()`, and verify containment inside `realpath(config('opconsole.logs.path'))`. `LogViewer::files()` filters strictly through `path()` so external symlinks cannot appear, be read, or manipulate newest-file delete protection. |
| Memory exhaustion or split UTF-8 corruption on large logs | `LogViewer` seeks to the last `2 MB` (`TAIL_BYTES`), caps `stream_get_contents()` strictly to `TAIL_BYTES`, scrubs split multi-byte sequences with `mb_scrub(..., 'UTF-8')`, and guards `preg_split()` against `false`. |
| Stored/reflected XSS via log entries, search terms, or command output | Blade auto-escapes all metadata and outputs; `PagePresenter::highlight()` splits raw text on `preg_quote($search, '/')` **before** escaping each segment with `e()`, preventing entity corruption (`&amp;`, `&lt;`). Standalone templates emit zero inline `<script>` blocks and zero inline `style=` attributes. |
| Credential or filesystem path leakage in error messages & audit rows | `ActionRunner::sanitizeText()` redacts URI credentials (`scheme://user:[REDACTED]@host`), key-value secrets (`password=`, `secret=`, `token=`, `api_key=`), and `base_path()` prefixes before flashing output or writing `opconsole_runs.error` / `ConsoleStatus` DB errors. |
| Rate-limit bucket starvation or brute-force action spam | Distinct named rate-limit prefixes isolate endpoint budgets: `throttle:30,1,opconsole-download` on log downloads, `throttle:6,1,opconsole-delete` on log deletions, and `throttle:6,1,opconsole-run` on Artisan actions. |
| Audit trail integrity vs command outcome | `ActionRunner` records `started` before `Artisan::call()`, catches `Throwable` to update the row to `failed`, and classifies abandoned rows as `Interrupted / Unknown` (never `Succeeded`). If the audit table is unavailable, the command outcome remains primary and a warning is shown. |

---

## Default Gate Behavior

```php
Gate::define($viewGate, fn ($user = null) => app()->environment('local'));
Gate::define($runGate, fn ($user = null) => app()->environment('local'));
```

- Registered only when `view_gate` / `run_gate` retain their default names (`viewOpConsole` / `runOpConsoleActions`) and the host has not already defined them.
- Default `downloadOpConsoleLogs` delegates to `AuthorizeOpConsole::allows('view', $user)` and default `deleteOpConsoleLogs` delegates to `AuthorizeOpConsole::allows('run', $user)` unless the host defines those Gates explicitly.
- Custom Gate names configured in `config/opconsole.php` never receive an automatic `local` fallback — the host must define them explicitly or access is denied (`403`).

---

## Locking Scope and Multi-Server Limitations

- The default `opconsole` lock store uses Laravel's atomic `FileStore` lock (`flock` / lock files) inside `storage/framework/cache/opconsole`. This coordinates concurrent PHP-FPM / web requests **on a single server sharing the same local filesystem**, and survives `php artisan optimize:clear` because `cache:clear` only flushes `cache.default` (`storage/framework/cache/data` or `cache` table).
- **Distributed deployments:** Local filesystem locks cannot coordinate across multiple load-balanced application servers (and NFS locks are not atomic across hosts). On multi-server deployments, set `OPCONSOLE_LOCK_STORE` to a dedicated `redis` or `database` cache store that uses separate lock storage (`lock_connection` or `cache_locks`).

---

## Sensitive Data in Application Logs (Operator Responsibility)

- OpConsole **does not redact raw log lines** rendered in `#logs` or downloaded via `GET {prefix}/logs/{file}`, because redacting arbitrary stack traces and payloads destructively hides diagnostic evidence.
- Treat `viewOpConsole` and `downloadOpConsoleLogs` as high-trust administrative permissions.
- In high-compliance environments or conservative initial rollouts (see the Read-Only Diagnostic Preset in [`docs/CONFIGURATION.md`](CONFIGURATION.md)), set `'allow_download' => false` and `'allow_delete' => false` in `config/opconsole.php` and ensure `APP_DEBUG=false` and `LOG_LEVEL=warning` (or higher) in production.

---

## Explicit Non-Features

- Does **not** read, display, or edit `.env`.
- Does **not** accept arbitrary Artisan command names, flags, or shell arguments from HTTP requests.
- Does **not** grant access via IP allow-lists, query tokens, or debug backdoors.
- Does **not** load third-party CDN scripts, stylesheets, or external icon fonts.

## Host application security audit

OpConsole combines runtime Laravel configuration checks with an explicit host application dependency audit. The dashboard only reads the last stored report; it never launches Composer while rendering a page.

## Dependency scan

Prerequisites: Composer 2.4 or newer and a valid host `composer.json` plus `composer.lock`. Composer 2.4 introduced the supported `audit --format=json` command. OpConsole requests `composer audit --format=json --locked --no-interaction --no-plugins --no-scripts`; production dependencies are included by default. `--include-dev` (or `OPCONSOLE_SECURITY_INCLUDE_DEV=true`) includes development dependencies. The audit covers transitive packages. Lock versions are the scanned versions; when Composer's installed package metadata is available, OpConsole flags installed/locked version differences. A lock-based scan does not claim that installed files match the lock when Composer metadata is absent.

Run `php artisan opconsole:security` or `php artisan opconsole:security --json`. OpConsole uses stable exit status `0` for a complete scan without vulnerability/configuration findings and `1` for findings or scan failure; inspect JSON `status` to distinguish failure from findings. Composer's own audit exit codes have changed across Composer releases, so OpConsole validates the JSON result and applies its documented mapping instead of passing through Composer's code. A failed scan preserves the last successful report and records the failure time/message. The dashboard labels changed-manifest and older reports as stale and never presents them as clean.

Set `OPCONSOLE_COMPOSER` to the configured Composer executable, `OPCONSOLE_SECURITY_TIMEOUT` to the process timeout in seconds, and `OPCONSOLE_SECURITY_STORE` to a shared lock-capable cache store. Enable the optional daily schedule with `OPCONSOLE_SECURITY_SCHEDULE=true` and run Laravel's scheduler. Concurrent scans are suppressed by a cache lock. Audit is read-only; dependency updates remain developer-reviewed actions.

Composer contacts Packagist by default, or the advisory repositories configured in the host `composer.json`. The advisory request identifies package names and locked versions so repositories can match advisories. Composer authentication is used only by Composer for configured repositories and is never copied into the report. Reports retain titles, identifiers, severity only when supplied, affected constraints, dates, validated HTTPS advisory links, and source-provided remediation when present. Missing data is labeled as unavailable. Ignored records remain visible with Composer's reason where supplied. Abandoned packages are listed separately from vulnerabilities.

The scan command can fail when Composer is unavailable/too old, lock files are missing or malformed, a repository is unreachable, output is invalid/incomplete, output exceeds the safety bound, the process times out, or another scan holds the lock. The last success remains visible alongside the failure. Reports are stored in the configured cache store; use a shared persistent store for multi-server deployments. Vulnerability findings feed the existing Health check and can use its opt-in `opconsole:health --notify` transition notifications, including configured cooldown and duplicate suppression.

## Runtime configuration

Checks use Laravel's runtime `config()` values and therefore work with cached configuration. They cover production debug mode, presence (never value) of `APP_KEY`, session Secure/HttpOnly/SameSite, HTTPS including an explicit trusted-proxy edge assertion, OpConsole view/run Gate configuration, and configured risky actions. Configure Laravel trusted proxies correctly before setting `OPCONSOLE_SECURITY_TRUSTED_PROXY_HTTPS=true`; that option asserts the TLS edge enforces HTTPS.

Dependency updates should be reviewed and applied by developers through the normal Composer workflow, then deployed and scanned again. For example, review the advisory's affected range and patched release, update the affected direct dependency or constraint, run the package/application test suite, commit the resulting manifest and lock changes, and deploy through the usual release process.

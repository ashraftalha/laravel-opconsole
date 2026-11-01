# Security

Reporting a vulnerability: open a private security advisory on the repository, or contact the maintainer directly. Do not open a public issue for exploitable vulnerabilities.

---

## Threat Model and Mitigations

| Threat / Risk | Mitigation |
|---|---|
| Unauthorized authenticated users accessing the console | Four configurable Gates (`view_gate`, `run_gate`, `download_gate`, `delete_gate`), fail-closed (`403`) outside `local` whenever the host has not defined them. Host-defined Gates always override package defaults in every environment, and `null`/empty/undefined custom Gate names fail closed (`403`). |
| Multi-guard privilege confusion | `AuthorizeOpConsole` authenticates against `config('opconsole.authentication.guard')`, calls `Auth::shouldUse($guardName)`, and evaluates `Gate::forUser($guard->user())` so both Gate authorization and `OpConsoleRun` audit attribution use the configured guard's user. Invalid guard names fail closed (`403`). |
| Guest crash (`HTTP 500`) on hosts without a standard `login` route | `AuthorizeOpConsole` checks authentication directly instead of using Laravel's `auth` middleware alias. Guests redirect to `route($login)` when valid and parameterless, or receive a controlled `401` when `login_route` is `null`, nonexistent, or requires URL parameters (catching `UrlGenerationException`). |
| Accidental CSRF removal via `route.middleware` overrides | `routes/web.php` prepends `'web'` unconditionally (`array_unique(['web', ...$extraMiddleware])`) so overriding `route.middleware` can never strip session or CSRF middleware from `POST`/`DELETE` endpoints. |
| Arbitrary command execution via HTTP | Closed constant allow-list (`ActionRunner::ACTIONS`) of exactly three commands: `optimize:clear`, `optimize`, and `migrate --force --isolated`. Route `whereIn('action', ...)` and `ActionRunner::run()` reject anything else; zero request parameters are passed to `Artisan::call()`. |
| Self-invalidating or ineffective action locks | `OpConsoleServiceProvider` registers an isolated file cache store (`opconsole` at `storage/framework/cache/opconsole`), separate from `cache.default`. `ActionRunner::validateLockStore()` rejects missing stores, `NullStore`/`NoLock`, `ArrayStore` outside unit tests, `lock_store === cache.default` without separate lock storage, and overlapping `FileStore` directories (resolving symlinked parents). Any lock validation or acquisition error **fails closed** without running Artisan. |
| Path traversal or symlink escape in log viewer/download/delete | Both route constraints and `LogViewer::path()` enforce `^[\w.-]+\.log$`, resolve `realpath()`, and verify containment inside `realpath(config('opconsole.logs.path'))`. `LogViewer::files()` filters strictly through `path()` so external symlinks cannot appear, be read, or manipulate newest-file delete protection. |
| Memory exhaustion or split UTF-8 corruption on large logs | `LogViewer` seeks to the last `2 MB` (`TAIL_BYTES`), caps `stream_get_contents()` strictly to `TAIL_BYTES`, scrubs split multi-byte sequences with `mb_scrub(..., 'UTF-8')`, and guards `preg_split()` against `false`. |
| Stored/reflected XSS via log entries, search terms, or command output | Blade auto-escapes all metadata and outputs; `PagePresenter::highlight()` splits raw text on `preg_quote($search, '/')` **before** escaping each segment with `e()`, preventing entity corruption (`&amp;`, `&lt;`). Standalone templates emit zero inline `<script>` blocks and zero inline `style=` attributes. |
| Credential or filesystem path leakage in error messages & audit rows | `ActionRunner::sanitizeText()` redacts URI credentials (`scheme://user:[REDACTED]@host`), key-value secrets (`password=`, `secret=`, `token=`, `api_key=`), and `base_path()` prefixes before flashing output or writing `opconsole_runs.error` / `ConsoleStatus` DB errors. |
| Rate-limit bucket starvation or brute-force action spam | Distinct named rate-limit prefixes isolate endpoint budgets: `throttle:30,1,opconsole-download` on log downloads, `throttle:6,1,opconsole-delete` on log deletions, and `throttle:6,1,opconsole-run` on Artisan actions. |
| Audit trail integrity vs command outcome | `ActionRunner` records `started` before `Artisan::call()`, catches any `Throwable` inside `Artisan::call()` to update the row to `failed`, and classifies abandoned `started` rows older than `2 × lock_seconds` as `Interrupted / Unknown` (never `Succeeded`). If `opconsole_runs` is unmigrated or unavailable, the real command exit status is still reported alongside a distinct `opconsole_warning` flash notice. |

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
- In high-compliance environments or initial rollouts (see [`docs/ASHRAFTALHA-INTEGRATION.md`](ASHRAFTALHA-INTEGRATION.md)), set `'allow_download' => false` and `'allow_delete' => false` in `config/opconsole.php` and ensure `APP_DEBUG=false` and `LOG_LEVEL=warning` (or higher) in production.

---

## Explicit Non-Features

- Does **not** read, display, or edit `.env`.
- Does **not** accept arbitrary Artisan command names, flags, or shell arguments from HTTP requests.
- Does **not** grant access via IP allow-lists, query tokens, or debug backdoors.
- Does **not** load mandatory third-party CDN scripts or stylesheets by default (`assets.use_cdn = false`).

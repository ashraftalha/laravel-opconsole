# Security

Reporting a vulnerability: open a private security advisory on the repository, or email the maintainer directly. Do not open a public issue for anything exploitable.

## Threat model and mitigations

| Risk | Mitigation |
|---|---|
| Any authenticated user gets admin access | Two Gates (`view_gate`, `run_gate`), fail-closed outside `local` when the host hasn't defined them. The host is never assumed to be "trusted because local" in a way that overrides its own Gate — a defined Gate always wins. |
| Arbitrary command execution via HTTP | Closed allow-list of exactly three Artisan commands (`ActionRunner::ACTIONS`). Config can only disable entries, never add one. No user input reaches `Artisan::call()`. |
| Information disclosure (PHP/OS/DB versions, `.env`-adjacent facts, full log content including stack traces) | Gated behind `view_gate`. Logs may contain tokens, emails, SQL, or other sensitive values written by the application itself — this package does not redact log content; treat `view_gate` as sensitive. `logs.allow_download` / `logs.allow_delete` can disable those specific capabilities. |
| Path traversal / symlink escape reading or deleting log files | Filenames are constrained to `^[\w.-]+\.log$` by both the route `where()` and `LogViewer::path()`. The resolved path is additionally checked with `realpath()` against the configured log directory, so a `.log` symlink that points outside it is refused. |
| XSS via log content or search terms | `PagePresenter::highlight()` escapes text first, then wraps the search match; search terms that happen to look like HTML entities (`amp`, `lt`, `quot`) cannot wedge a `<mark>` inside an already-emitted entity, because highlighting runs on the raw text before escaping each segment, not on the already-escaped string. |
| CSRF on mutating requests | All mutating routes are POST/DELETE inside the `web` middleware group; Blade forms include `@csrf` / `@method('DELETE')`. |
| Concurrent `optimize` / `optimize:clear` racing, or interleaving with `migrate --isolated` | All three actions are serialized behind a single `Cache::lock('opconsole:action-lock', 30)`, held in `config('opconsole.concurrency.lock_store')` (default `file`), deliberately **not** `cache.default`. `optimize:clear` runs `cache:clear`, which flushes the default store outright; a lock living there would be deleted by its own action mid-run, letting a second action start concurrently (self-invalidating lock). Verified by `ActionsTest::test_flushing_the_default_cache_store_does_not_release_the_action_lock` and confirmed against the real `file` driver in a clean Laravel 13 install (external process holds the lock → a concurrent HTTP request is correctly told "Another operation is already running"). A busy run returns that message instead of racing. |
| Unauthenticated request to a host with no `login` route | OpConsole checks the configured guard itself (`AuthorizeOpConsole`) instead of delegating to Laravel's `auth` middleware alias, which calls `route('login')` unconditionally and throws an uncaught `RouteNotFoundException` (HTTP 500) when that route doesn't exist. A guest now gets a redirect when `authentication.login_route` resolves to a real route, or a controlled `401` otherwise. Do not add `auth`/`auth:guard` to `route.middleware`; it reintroduces the crash this exists to avoid. |
| Unaudited destructive actions | Every Artisan action and every log delete/download writes an `opconsole_runs` row. A persistence failure (e.g. the table isn't migrated yet) never turns an already-successful command into a reported failure — the command's real exit code is always what's shown to the user. |
| Long-running actions inside the web request | `migrate`/`optimize` run synchronously and can exceed `max_execution_time` or a proxy timeout. A `started` row is written before the command runs, so an interrupted request is visible in the audit trail even without a `finished` row. There is no queue-based execution in v1 — see Known Limitations in the README. |
| Throttling | `throttle:6,1` on both the action routes and the log-delete route. |

## Gate defaults, precisely

```php
Gate::define($name, fn ($user = null) => app()->environment('local'));
```

This is only registered if the host has **not** already defined `view_gate` / `run_gate`. It exists so that a careless `composer require` doesn't silently expose a production admin console — but it is not a substitute for defining your own Gate, which this README and the integration docs both ask you to do before deploying anywhere but a local sandbox.

## A real bug found and fixed while hardening the lock (post-mortem)

The first attempt at isolating the action lock from `cache.default` checked `Cache::store($store) instanceof LockProvider` before calling `->lock()`. That check is always false: `Cache::store()` returns an `Illuminate\Cache\Repository` *wrapper*, which doesn't implement `LockProvider` itself — it only forwards `lock()` to the underlying store via `__call()`. The check always threw, and the code **silently fell back to the default store on every single call**, meaning the lock was never actually isolated at all — the exact self-invalidating-lock bug the isolation was meant to prevent, just hidden behind a check that looked like it was guarding against it. Fixed by checking `Cache::store($store)->getStore() instanceof LockProvider` instead, and calling `lock()` on that underlying store directly. Caught by `ActionsTest::test_a_different_action_is_also_rejected_while_the_lock_is_held`, which asserts the real Artisan kernel is never invoked while a lock taken out through the public API is held — a silent fallback to a different store makes that assertion fail. Re-verified against the real `file` driver end-to-end in a clean install, not just the test double.

## What this package deliberately does not do

- It does not read or write `.env`.
- It does not accept command names, arguments, or shell input from the request.
- It does not grant access based on IP address, a magic query string, or any other "developer convenience" bypass.
- It does not redact secrets that might appear inside application log lines — that is the application's responsibility; OpConsole only controls *who* can read the logs.

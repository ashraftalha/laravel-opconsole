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
| Concurrent `optimize` / `optimize:clear` racing, or interleaving with `migrate --isolated` | All three actions are serialized behind a single `Cache::lock('opconsole:action-lock', 30)`. A busy run returns a message instead of racing. |
| Unaudited destructive actions | Every Artisan action and every log delete/download writes an `opconsole_runs` row. A persistence failure (e.g. the table isn't migrated yet) never turns an already-successful command into a reported failure — the command's real exit code is always what's shown to the user. |
| Long-running actions inside the web request | `migrate`/`optimize` run synchronously and can exceed `max_execution_time` or a proxy timeout. A `started` row is written before the command runs, so an interrupted request is visible in the audit trail even without a `finished` row. There is no queue-based execution in v1 — see Known Limitations in the README. |
| Throttling | `throttle:6,1` on both the action routes and the log-delete route. |

## Gate defaults, precisely

```php
Gate::define($name, fn ($user = null) => app()->environment('local'));
```

This is only registered if the host has **not** already defined `view_gate` / `run_gate`. It exists so that a careless `composer require` doesn't silently expose a production admin console — but it is not a substitute for defining your own Gate, which this README and the integration docs both ask you to do before deploying anywhere but a local sandbox.

## What this package deliberately does not do

- It does not read or write `.env`.
- It does not accept command names, arguments, or shell input from the request.
- It does not grant access based on IP address, a magic query string, or any other "developer convenience" bypass.
- It does not redact secrets that might appear inside application log lines — that is the application's responsibility; OpConsole only controls *who* can read the logs.

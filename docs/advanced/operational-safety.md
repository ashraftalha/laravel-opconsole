---
title: Operational Safety
---

# Operational Safety

OpConsole's operational model is built around one idea: an authorized user should never be able to do more damage through the dashboard than the fixed allow-list permits, and every action taken should be bounded, serialized, and recorded.

## The allow-list is closed, not configurable-open

`ActionRunner::ACTIONS` is a fixed constant list. Route constraints and `ActionRunner::run()` reject anything not on it, and HTTP requests cannot supply arbitrary arguments or flags. A migration name may select one pending file; its path is resolved from Laravel's migrator. You can disable entries in `config/opconsole.php`, but you cannot add new ones — see [Allowlisted Artisan Actions](/features/artisan-actions).

## Confirmation and hold-to-confirm

Every operation requires confirmation first, naming the environment and the exact command about to run. **High-risk operations** (currently `migrate`) require a press-and-hold of about 1.2 seconds, or two presses — a single click never triggers them. This respects `prefers-reduced-motion` and works via keyboard (holding Space/Enter).

## Single-flight execution

Only one of the eight `ActionRunner` operations runs at a time, enforced by an owner-bound, renewable lock using the isolated `opconsole` lock store (see [Permissions & Security Policies](/advanced/permissions-security-policies)). Every driver's `release()` checks ownership before releasing, so one request can never release another's lock.

## Bounded runtime

Timer-bound Artisan actions use `concurrency.max_runtime_seconds` (default 1500s), enforced with a `pcntl` alarm. When signal-based timeouts are unavailable, the security scan uses its own subprocess timeouts and Octane reload signals the server without waiting for jobs to finish. Failed-job retry and log operations do not use this action lock or timeout wrapper.

- The lock lease is always raised to exceed the runtime budget, so the lease itself never expires mid-run under normal conditions.
- On Laravel 12.63+ / 13.16+, the lease is renewed roughly every 10 seconds while the command runs, on drivers that support `refresh()`. Older releases run safely on the single initial lease, since it already outlasts the runtime budget.
- A renewal that reports another owner holds the lock interrupts the command — this guards against clock skew or an unexpected concurrent holder, not against normal operation.
- While a timer-bound action runs, OpConsole calls `ignore_user_abort(true)` so a browser or proxy disconnect can't abort a migration halfway, and raises (never lowers) `max_execution_time` to `max_runtime_seconds + 30`, so its own timeout — not a PHP fatal error — ends an over-long command. Web server/proxy timeouts (for example nginx `fastcgi_read_timeout`) may still end the *HTTP response* earlier; the command itself keeps running server-side, and its outcome lands in History.

## Failure modes and what they leave behind

| Failure | What happens |
|---|---|
| Command throws | Audit row updated to `failed`, lock released. |
| PHP fatal error (for example, memory exhaustion) | Audit row closed as `failed` and lock released from a shutdown hook. |
| Hard kill (`SIGKILL`, PHP-FPM `request_terminate_timeout`) | Audit row stays `started`, shown as **Interrupted / Unknown** (never `Succeeded`); the lock expires naturally with its lease. |
| Runtime has no `pcntl` | Timer-bound actions are refused **before** taking the lock or running anything — see [Execution environments](/reference/configuration#execution-environments). |
| Inside a Swoole worker | Timer-bound actions refused before locking, by default — see [Octane & Swoole Environments](/advanced/octane-swoole-environments). |

## Output sanitization

Command output and exception messages are sanitized before being flashed to the UI or written to the audit row: URI credentials (`scheme://user:[REDACTED]@host`), key-value secrets (`password=`, `secret=`, `token=`, `api_key=`), and `base_path()` filesystem prefixes are redacted.

## Migrations only ever move forward

The `migrate` action runs `migrate --force --isolated`. Use **Run all**, or run individual migrations sequentially from the earliest pending file. **Run only this** permits out-of-order execution without checking dependencies. There is no automatic undo, rollback, or reset action; a failed or interrupted migration may have partially applied. Review the [migration-order warning and recovery procedure](/features/artisan-actions#running-one-migration-at-a-time) before using individual runs.

## Why queue workers aren't involved

Allow-listed operations run synchronously inside the HTTP request, not dispatched to a queue. From [Architecture](/reference/architecture): queue workers stop processing while the application is down, so a queued `up` operation could never actually run — the one case where this matters most is exactly the case where a queue-based design would fail.

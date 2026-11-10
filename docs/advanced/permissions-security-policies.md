---
title: Permissions & Security Policies
---

# Permissions & Security Policies

This page is the operational companion to [Security Model](/reference/security) — it focuses on how to configure the policies, not just what they guard against.

## Four Gates, not one

| Gate | Allows | Falls back to |
|---|---|---|
| `viewOpConsole` | View dashboard, health checks, history, log tail | Local-only default |
| `runOpConsoleActions` | Run operations | Local-only default |
| `downloadOpConsoleLogs` | Download raw log files | `viewOpConsole` |
| `deleteOpConsoleLogs` | Delete rotated log files | `runOpConsoleActions` |

The fallback behavior only applies when the Gate **keeps its default name** and your application hasn't defined it. A custom Gate name you configure but never define fails closed everywhere — including `local` — so renaming a Gate is itself a safety-relevant action, not just cosmetic.

## Per-action Gates for least privilege

```php
'authorization' => [
    'run_gate' => 'runOpConsoleActions',
    'action_gates' => [
        'clear' => 'clearOpConsoleCaches',
        'migrate' => 'manageOpConsole',
        'down' => 'manageOpConsole',
        'up' => 'manageOpConsole',
        'queue_retry' => 'retryOpConsoleJobs',
    ],
],
```

A sensible production split: let a broader "support" group clear caches and retry jobs, while migrations and maintenance mode stay with a narrower "admin" group — and deleting logs (destroys forensic evidence) stays narrower still.

## Multi-guard setups

```php
'authentication' => [
    'guard' => 'admin', // null uses the default guard
],
```

OpConsole authenticates against this specific guard end-to-end: `Auth::shouldUse($guardName)`, `Gate::forUser($guard->user())`, and `OpConsoleRun` audit attribution all use it. An invalid guard name fails closed with `403` rather than silently falling back to the default guard.

## The action lock: single-server vs. distributed

The default `opconsole` lock store is a Laravel atomic `FileStore` lock under `storage/framework/cache/opconsole`, isolated from `cache.default` specifically so `php artisan optimize:clear` (which flushes `cache.default`) can never delete an active lock mid-run.

This coordinates concurrent requests **on one server sharing one filesystem**. It does **not** coordinate across multiple load-balanced application servers — local filesystem locks (and NFS locks, which aren't atomic across hosts) can't do that.

For a multi-server deployment:

```dotenv
OPCONSOLE_LOCK_STORE=redis
```

Point it at a dedicated Redis or database cache store with separate lock storage (`lock_connection` or a dedicated `cache_locks` table). `ActionRunner::validateLockStore()` fails closed — without running any Artisan command — if the configured store is missing, doesn't support atomic locks (`NullStore`/`NoLock`), is `ArrayStore` outside unit tests, or is the same store as `cache.default` without separate lock storage.

## Audit policy: best-effort vs. required

```dotenv
OPCONSOLE_AUDIT_POLICY=best_effort
```

- **`best_effort`** (default): an unavailable audit table never blocks an operation — you get a warning instead of a refusal.
- **`required`**: Artisan actions, log deletes, and log downloads are refused unless their audit row is written *before* the operation starts. Any unrecognized value is treated as `required` — this fails closed, not open.

Choose `required` when a compliance requirement mandates that every operational action be logged before it happens, and you're confident the audit table's availability matches your operational uptime needs.

## A conservative starting policy

For an initial production rollout, combine the [Read-only diagnostic preset](/reference/configuration#_2-read-only-diagnostic-preset) (disable `clear`, `optimize`, `migrate`; disable log download/delete) with a narrow `viewOpConsole` Gate. Expand access and the action allow-list incrementally once you're comfortable with who's using it and how.

## Logs are high-trust, not redacted

OpConsole does not redact raw log content — see [Log Viewer](/features/log-viewer#security-considerations) for why. Treat `viewOpConsole` and `downloadOpConsoleLogs` as administrative-grade permissions, not general support-tier access, unless your logs are already scrubbed at the source.

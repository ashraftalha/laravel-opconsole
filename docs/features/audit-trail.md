---
title: Operational Audit Trail
---

# Operational Audit Trail

[View audit history screenshot](/screenshots/history-light.png) · [Browse all feature screenshots](/guide/introduction#feature-screenshot-directory)

## What it does

Every operation run through OpConsole — Artisan actions, log downloads, log deletions, failed-job retries — is recorded in the `opconsole_runs` table and shown in the **History** tab, grouped by day: who ran what, when, how long it took, and any error.

<figure class="oc-shot">
  <img src="/screenshots/history-light.png" alt="OpConsole History tab: operations grouped by day with status, user, exit code and duration" width="1920" height="1245" loading="lazy">
  <figcaption>The History tab: every operation grouped by day, with who ran it, the outcome, exit code and duration.</figcaption>
</figure>

## Why it is useful

When something changes in production, "who ran what and when" is the first question during a postmortem. This answers it without grepping deploy logs or asking around.

## How to configure it

```php
// config/opconsole.php
'table' => env('OPCONSOLE_TABLE', 'opconsole_runs'),
'connection' => env('OPCONSOLE_CONNECTION'),
'history_limit' => (int) env('OPCONSOLE_HISTORY_LIMIT', 15),
'audit' => [
    'policy' => env('OPCONSOLE_AUDIT_POLICY', 'best_effort'),
],
```

| Key | Default | Description |
|---|---|---|
| `table` | `opconsole_runs` | Database table for audit records. |
| `connection` | `null` | Connection for that table (`null` uses the app's default). |
| `history_limit` | `15` | Recent operations shown in the dashboard (clamped 1–100). |
| `audit.policy` | `best_effort` | `best_effort`: an unavailable audit table never blocks an operation, shown as a warning instead. `required`: actions, log deletes, and log downloads are refused unless their audit row is written first. |

## How to use it

Open the **History** tab. Runs are grouped by day with inline error details, duration bars, and status filter chips.

## Security considerations

- Rows record `started` before the command runs, then update to `succeeded`/`failed` — a process that's hard-killed leaves a `started` row, shown as **Interrupted / Unknown**, never as `Succeeded`.
- A PHP fatal error (for example memory exhaustion) closes the row as `failed` from a shutdown hook.
- `user_id` is stored as a nullable string without foreign keys, so integer, UUID, and ULID primary keys all work portably across SQLite, MySQL/MariaDB, PostgreSQL, and SQL Server.
- Exception messages and command output are sanitized before being written — URI credentials, `password=`/`secret=`/`token=`/`api_key=` key-value pairs, and `base_path()` filesystem prefixes are redacted.
- Under `audit.policy=required`, losing only the *final* update after a command already succeeded never changes its reported outcome — you'd see "succeeded, audit record could not be saved" rather than a false failure.

## Limitations

- History queries are bounded for performance: the dashboard fetches the latest completed run per action via a grouped `MAX(id)` query and the most recent `history_limit` rows overall — SQL query count and memory stay constant regardless of how large the table grows, but very old runs simply aren't shown in the UI (they remain in the database).
- The audit trail records outcomes and sanitized errors, not full command output for every historical run.

## Troubleshooting

- **History tab empty despite running actions:** confirm the `opconsole_runs` migration actually ran (`php artisan migrate`), and that `table`/`connection` point at where you expect.
- **Runs show "Interrupted / Unknown":** the process was killed (SIGKILL, PHP-FPM `request_terminate_timeout`) before it could record a final status — check for infrastructure-level timeouts shorter than `max_runtime_seconds`.

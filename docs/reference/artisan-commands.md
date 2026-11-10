---
title: Artisan Commands
---

# Artisan Commands

OpConsole ships two first-party Artisan commands, in addition to the allow-listed commands it runs internally through the dashboard.

## `opconsole:health`

```text
opconsole:health
    {--json}               Print machine-readable JSON
    {--notify}              Evaluate and send configured health transition notifications
    {--fail-on=critical}    Exit 1 when any check is at or above this level (warning or critical)
```

```bash
php artisan opconsole:health
php artisan opconsole:health --fail-on=warning
php artisan opconsole:health --json
php artisan opconsole:health --notify
```

Without `--json`, prints a table (ID, status, label, value, fix) and a one-line overall status. With `--json`, prints `{status, checked_at, checks: [{id, group, label, status, value, hint, fix}]}` — `fix` is `null` for healthy checks.

`--notify` only sends notifications when `notifications.enabled` is also `true` in config; otherwise the flag is a no-op. This command, the JSON health endpoint, and the dashboard all share the same underlying report (`ConsoleStatus::health()`), so results are always consistent across all three.

Exit code is `1` when any check is at or above the `--fail-on` threshold (default `critical`), otherwise `0` — suitable for CI/CD gating.

## `opconsole:security`

```text
opconsole:security
    {--json}          Print machine-readable JSON
    {--include-dev}   Include development dependencies
```

```bash
php artisan opconsole:security
php artisan opconsole:security --include-dev
php artisan opconsole:security --json
```

Runs the host Composer dependency audit (production dependencies by default) and Laravel runtime configuration checks, then stores the result in the configured `security.store` cache store for the dashboard to read. See [Security Checks](/features/security-checks) for scope and [Security Model](/reference/security) for exit code semantics.

## Commands executed internally by Operations

These are never typed by a user — they're what the **Operations** tab's allow-listed buttons run on your behalf. See [Allowlisted Artisan Actions](/features/artisan-actions) for the full table and [Configuration Reference](/reference/configuration#allow-listed-artisan-actions-actions) for enabling/disabling each one.

| Action key | Command |
|---|---|
| `clear` | `optimize:clear` |
| `optimize` | `optimize` |
| `migrate` | `migrate --force --isolated` |
| `down` | `down --secret=<random>` |
| `up` | `up` |
| `storage_link` | `storage:link` |
| `security_scan` | `opconsole:security` |
| `octane_reload` | `octane:reload` |
| (failed-job retry) | `queue:retry {id}` |

## Standard Laravel commands you'll still use

OpConsole doesn't wrap every operational command you need — it deliberately covers a narrow set. You'll still reach for the CLI directly for anything not listed above (for example `migrate:rollback`, `queue:restart`, or inspecting `queue:failed` beyond what the Failed jobs tab shows).

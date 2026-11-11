---
title: Allowlisted Artisan Actions
---

# Allowlisted Artisan Actions

[View artisan actions screenshot](/screenshots/operations-light.png) · [Browse all feature screenshots](/guide/introduction#feature-screenshot-directory)

## What it does

The **Operations** tab exposes a fixed, closed allow-list of Artisan operations, split into a **Safe** group and a **Danger zone**. There is no way to run an arbitrary command — the list below is exhaustive.

<figure class="oc-shot">
  <img src="/screenshots/operations-light.png" alt="OpConsole Operations tab: safe operations, and a danger zone with the Run migrations card listing each pending migration" width="1920" height="1770" loading="lazy">
  <figcaption>The Operations tab: safe tasks, the danger zone, and the Run migrations card, where you run all pending migrations or one at a time.</figcaption>
</figure>

| Operation | Command | Risk | On by default |
|---|---|---|---|
| Clear caches | `optimize:clear` | Low | Yes |
| Optimize performance | `optimize` | Low | Yes |
| Bring online | `up` | Low | Yes |
| Run security scan | `opconsole:security` | Low (read-only) | Yes |
| Storage link | `storage:link` | Low | No (`OPCONSOLE_ACTION_STORAGE_LINK=true`) |
| Maintenance mode | `down --secret=…` | Medium | Yes |
| Run migrations | `migrate --force --isolated` | High | Yes |
| Reload Octane workers | `octane:reload` | Low | Yes, only when Laravel Octane is installed |
| Retry a failed job | `queue:retry {id}` | Medium | Yes (`OPCONSOLE_FAILED_JOBS_RETRY`), from the Failed jobs tab |

## Why it is useful

The operations that actually matter during an incident — clear a stale cache, bring the app out of maintenance, run a pending migration — are also the ones people resort to copy-pasting from a runbook. This replaces that with confirmed, rate-limited, audited buttons restricted to exactly these commands.

## How to configure it

```php
// config/opconsole.php
'actions' => [
    'clear' => true,
    'optimize' => true,
    'migrate' => true,
    'down' => true,
    'up' => true,
    'storage_link' => false,
    'security_scan' => true,
    'octane_reload' => true, // only shown when Octane is installed
],
```

You can disable any entry — it disappears from the UI and `POST {prefix}/{action}` returns `404` — but you cannot add new commands. See [Allow-listed Artisan actions](/reference/configuration#allow-listed-artisan-actions-actions) for every key.

## How to use it

Every operation asks for confirmation first, naming the environment and showing the exact command. **High-risk operations** (currently `migrate`) require a press-and-hold (about 1.2 seconds) or two presses — never a single click — and respect `prefers-reduced-motion`.

## Security considerations

- **Only one operation runs at a time**, enforced by a dedicated lock store that survives `optimize:clear`. See [Permissions & Security Policies](/advanced/permissions-security-policies).
- Every run is bounded by `OPCONSOLE_MAX_RUNTIME_SECONDS` (default 1500s), enforced with a `pcntl` alarm.
- Every run is recorded in the audit history. With `OPCONSOLE_AUDIT_POLICY=required`, a run is refused if its audit row can't be written first.
- Per-operation Gates (`authorization.action_gates`) let you separate low-risk actions (cache clear) from high-risk ones (migrate, maintenance mode) — see [Authentication & Authorization](/guide/authentication#per-operation-permissions).
- Rate limiting: `throttle:6,1,opconsole-run` on the run endpoint.

## Limitations

- **Runtimes without `pcntl`** (common on PHP-FPM) refuse every timer-bound action from the browser — only the self-bounded `security_scan` still runs. See [Execution environments](/reference/configuration#execution-environments).
- There is no way to pass arguments or flags to these commands from the UI; they always run with the exact flags shown in the table above. The one exception is choosing a single pending migration (below).
- `migrate` only ever runs **forward** — there are no resets and no rollbacks available from the dashboard.

## Running one migration at a time

::: warning Migration order is not enforced for individual runs
**Use Run all by default** to apply pending migrations in Laravel's normal order. If running one at a time, execute the earliest pending migration first and continue sequentially. **Run only this can skip earlier pending migrations**, and OpConsole does not detect schema or data dependencies. A later migration may fail or leave the database incompatible with the application. The confirmation is a warning, not a dependency check.

Use an out-of-order run only after reviewing the migration and its dependencies, testing that exact sequence against a representative database, and confirming a recoverable backup.
:::

The **Run migrations** card spans the full row and lists every pending migration in the order `migrate` would apply them. Use **Run all** to apply them all, or **Run only this** on a row to apply just that file (`migrate --force --isolated --path=… --realpath`). The rest stay pending.

- The request only carries the migration's name. The file path always comes from Laravel's migrator, and a name that is not currently pending is refused without running anything.
- Running a later file ahead of earlier ones is allowed; the confirmation warns you when it does, so you can check it doesn't depend on them.
- The audit row records the migration name as its subject, and the output panel title shows it.

### Recovery after a failed or interrupted migration

1. Stop further migration attempts. Save the command output and audit entry; a failed, timed-out, or **Interrupted / Unknown** result does not prove that no changes were applied.
2. Inspect `php artisan migrate:status`, Laravel's migration repository, and the actual schema and data on the affected connection. Database DDL and custom migration side effects may not be transactional; earlier migrations in a run may already have completed.
3. Have the migration owner choose and test a recovery plan: a corrective forward migration, a carefully reviewed CLI rollback if its `down()` method is safe, or restoration from a verified backup. Do not blindly rerun a partially applied migration or mark it as completed to suppress the failure.
4. Verify application behavior and data integrity, then resume migrations in order. Restrict traffic or use maintenance mode when the current schema is incompatible with the running application.

OpConsole does not automatically undo changes or provide a rollback/reset button. Recovery is an operator-managed database operation; retain the audit history and record the recovery steps.

## Troubleshooting

- **A card is disabled with a runtime explanation:** your web SAPI lacks `pcntl`. Run the equivalent `php artisan` command from the CLI, or enable `pcntl` for the web SAPI.
- **An action is missing entirely:** check whether it's set to `false` in `config('opconsole.actions')`, or — for `octane_reload` — whether Laravel Octane is actually installed.
- **"Another operation is already running":** a lock from a previous run hasn't expired yet; wait for its lease, or check for a stuck process if this persists.

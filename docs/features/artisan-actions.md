---
title: Allowlisted Artisan Actions
---

# Allowlisted Artisan Actions

## What it does

The **Operations** tab exposes a fixed, closed allow-list of Artisan operations, split into a **Safe** group and a **Danger zone**. There is no way to run an arbitrary command — the list below is exhaustive.

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

The **Run migrations** card spans the full row and lists every pending migration in the order `migrate` would apply them. Use **Run all** to apply them all, or **Run only this** on a row to apply just that file (`migrate --force --isolated --path=… --realpath`). The rest stay pending.

- The request only carries the migration's name. The file path always comes from Laravel's migrator, and a name that is not currently pending is refused without running anything.
- Running a later file ahead of earlier ones is allowed; the confirmation warns you when it does, so you can check it doesn't depend on them.
- The audit row records the migration name as its subject, and the output panel title shows it.

## Troubleshooting

- **A card is disabled with a runtime explanation:** your web SAPI lacks `pcntl`. Run the equivalent `php artisan` command from the CLI, or enable `pcntl` for the web SAPI.
- **An action is missing entirely:** check whether it's set to `false` in `config('opconsole.actions')`, or — for `octane_reload` — whether Laravel Octane is actually installed.
- **"Another operation is already running":** a lock from a previous run hasn't expired yet; wait for its lease, or check for a stuck process if this persists.

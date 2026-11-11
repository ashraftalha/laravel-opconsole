---
title: Troubleshooting
---

# Troubleshooting

A consolidated list of issues covered in more depth on their feature pages, gathered here for quick lookup.

## Access and actions decision tree

Start with the URL and observed response. Follow the matching branch, then use the steps beneath it. These checks do not require granting execution access.

```text
Which URL fails?
├─ /admin/opconsole/health (machine endpoint)
│  ├─ 401 → Check bearer token → [A]
│  ├─ 404 → Check enabled flag and configured token → [A]
│  └─ 503 → Read the critical checks in the JSON → [A]
└─ /admin/opconsole (dashboard or operation)
   ├─ 401 or login redirect → Check session and guard → [B]
   ├─ 403 → Check the exact Gate and signed-in user → [C]
   ├─ 404 → Check package, route prefix, and action switch → [D]
   └─ Dashboard opens, but action is unavailable
      ├─ Missing card → Check actions config / optional feature → [D]
      ├─ Permission message → Check run or per-action Gate → [C]
      ├─ pcntl or Swoole message → Check web runtime → [E]
      ├─ No pending work / stopped server → Check action state → [E]
      └─ Lock or audit error after click → Check safety storage → [F]
```

### A. Machine health endpoint

Check `OPCONSOLE_ENABLED`, `OPCONSOLE_HEALTH_ENDPOINT`, the configured route prefix, and a non-empty `OPCONSOLE_HEALTH_TOKEN`. Supply the exact token in the `Authorization: Bearer …` header; browser login and dashboard Gates do not authenticate this endpoint. A `503` means health policy classified the report as critical: inspect the JSON checks and repair the failing dependency. See [health monitoring recipe](/advanced/production-recipes#health-monitoring-without-dashboard-access).

### B. Session or guard

1. Sign in through the host application's login using the same browser and application domain.
2. Check `authentication.guard`: `null` uses the application's default guard; a named guard must exist and authenticate the intended user. An invalid configured guard is denied with `403`.
3. Check `authentication.login_route`: it must be an existing, parameterless named route for automatic redirects. A `401` is expected when that route is unavailable. Do not add Laravel's generic `auth` middleware just to hide the response.

After login, follow [C](#c-gate-or-permission) if access becomes `403`.

### C. Gate or permission

1. For dashboard access, inspect `authorization.view_gate` and confirm that the exact Gate is defined and allows this user. Default Gates are local-only; a custom undefined Gate denies access in every environment.
2. For an operation, look up its key in `authorization.action_gates` first. If mapped, that exact Gate must be defined and allow the user; otherwise `authorization.run_gate` applies. Failed-job retries also require the run Gate at the route middleware, even when `queue_retry` is mapped; the retry example additionally requires `retryOpConsoleJobs` to be defined.
3. For raw log access, inspect the separate download/delete Gates and `logs.allow_download` / `logs.allow_delete` switches. Read-only policies deliberately deny these capabilities.
4. Rebuild cached configuration and routes through deployment tooling after edits, and reload long-lived workers if applicable. Check the deployed config rather than assuming an edited `.env` was loaded.

Use [read-only production](/advanced/production-recipes#read-only-production-recommended) to prove viewing works before granting operations. Never switch production to `APP_ENV=local` or replace the Gates with unconditional `true` to diagnose access.

### D. Disabled route or feature

Check `OPCONSOLE_ENABLED`, `route.prefix`, and the relevant `actions` entry in the published PHP config. Disabled actions are absent and their action URLs return `404`. A read-only recipe intentionally hides all Artisan actions. Octane reload also requires an Octane installation; job retries require `failed_jobs.enabled` and `failed_jobs.allow_retry`. Use `php artisan route:list --path=admin/opconsole` through your deployment tooling, substituting your configured prefix.

### E. Runtime or action state

Read the disabled card's explanation:

- **No `pcntl`:** confirm the web SAPI's capabilities with your host. CLI extensions may differ. Use CLI/deployment tooling for timer-bound commands; the browser cannot enable missing extensions.
- **Swoole guard:** perform timed maintenance through deployment tooling. Do not enable `OPCONSOLE_OCTANE_ALLOW_ACTIONS` merely to dismiss the warning; review the [worker-safety implications](/features/octane-swoole#security-considerations-the-swoole-guard).
- **Nothing pending / already configured / server stopped:** check migration status, the storage link, or the Octane process as indicated. Those cards are unavailable when there is no applicable operation. A failed database connection also disables migration actions.

See [shared-hosting limitations](/guide/installation#shared-hosting-and-no-ssh-deployments) if the host controls these capabilities.

### F. Lock or audit storage

For a busy lock, verify whether an operation is still running before intervening; do not forcibly unlock a live migration. For an unsafe/unavailable store, use a lock-capable store isolated from `cache.default`, shared between servers when needed. With `audit.policy=required`, check the configured audit database/table and migrations before retrying. Retain the policy while repairing storage; see [lock and audit policies](/advanced/permissions-security-policies).

If a migration timed out or failed, follow [migration recovery](/features/artisan-actions#recovery-after-a-failed-or-interrupted-migration) before rerunning it.

## Access and authentication

| Symptom | Cause | Fix |
|---|---|---|
| Guest gets HTTP `500` visiting the console | Laravel's `auth` middleware alias was added to `route.middleware`, and it called `route('login')` on a host with no such route | Remove `auth` from `route.middleware`; let `AuthorizeOpConsole` handle the guest check — see [Authentication & Authorization](/guide/authentication) |
| Everyone gets `403` in production | No `viewOpConsole`/`runOpConsoleActions` Gate defined — the fail-closed default only allows `local` | Define the Gates in `AppServiceProvider::boot()` |
| A specific custom Gate name never passes, even in `local` | Custom Gate names (anything other than the defaults) never get the automatic local fallback | Define that exact Gate name explicitly |
| Guest redirect loops or 401 instead of redirect | `authentication.login_route` is `null`, missing, or requires parameters | Expected behavior — point it at a valid, parameterless named route, or accept the `401` |

## Operations

| Symptom | Cause | Fix |
|---|---|---|
| An action card is disabled with a runtime explanation | Web SAPI lacks `pcntl` | Run the equivalent `php artisan` command from the CLI, or enable `pcntl` for the web SAPI — see [Execution environments](/reference/configuration#execution-environments) |
| Action refused inside Swoole, "would interrupt the worker" | Octane on Swoole blocks `SIGALRM`-based timed actions by default | Run from CLI, or set `OPCONSOLE_OCTANE_ALLOW_ACTIONS=true` if you accept the risk |
| "Another operation is already running" persists | A lock hasn't expired, or a prior process was killed before releasing it | Wait for the lease to expire, or investigate a stuck process if it recurs |
| Actions fail immediately with a lock error | The configured lock store failed validation (missing, unsafe, or shares storage with `cache.default`) | Fix `concurrency.lock_store` — see [Permissions & Security Policies](/advanced/permissions-security-policies) |
| An action is missing from the UI | Disabled in `config('opconsole.actions')`, or (for `octane_reload`) Octane isn't installed | Check the config key |

## Health checks

| Symptom | Cause | Fix |
|---|---|---|
| Heartbeat checks stuck at `unknown` | Scheduler/worker not actually running, or `heartbeats.store` isn't shared across servers | Confirm cron and `queue:work` are running; use a shared cache store |
| `opconsole:health` fails CI unexpectedly | `--fail-on` defaults to `critical` | Pass `--fail-on=warning` only if warnings should fail the build |
| Health endpoint returns `404` | `health_endpoint.enabled` is `false`, or no token is set | Set both `OPCONSOLE_HEALTH_ENDPOINT=true` and `OPCONSOLE_HEALTH_TOKEN` |
| Health endpoint returns `401` | Missing or incorrect bearer token | Send `Authorization: Bearer <token>` matching `OPCONSOLE_HEALTH_TOKEN` exactly |

## Security audit

| Symptom | Cause | Fix |
|---|---|---|
| Security tab shows "unverified" | No scan has run yet | Run `php artisan opconsole:security`, or press **Run security scan** |
| Report marked "stale" | Older than `security.max_age_seconds`, or `composer.json`/`composer.lock` changed since the last scan | Run the scan again |
| Scan fails immediately | Composer missing, too old (needs 2.4+), or `composer.lock` missing/malformed | Verify `security.composer` points at a valid Composer 2.4+ executable |

## Logs

| Symptom | Cause | Fix |
|---|---|---|
| No log files listed | `logs.path` doesn't exist, isn't readable, or filenames don't match `^[\w.-]+\.log$` | Verify the path and filenames |
| Download/delete buttons missing | `logs.allow_download`/`logs.allow_delete` is `false`, or missing the relevant Gate | Check config and Gates |
| Can't delete the active log file | The newest valid log file (or a symlink resolving to it) can never be deleted — by design | Not a bug; rotate logs externally if you need to remove the active file |

## After an upgrade

| Symptom | Cause | Fix |
|---|---|---|
| Dashboard styling looks broken | Published assets are stale, or a customized view wasn't merged with upstream changes | `php artisan vendor:publish --tag=opconsole-assets --force`; diff customized views against the package — see [Upgrade Guide](/reference/upgrade) |
| New tab or section missing after updating | Published views weren't updated | Re-publish or merge `resources/views/vendor/opconsole` |

If an issue isn't listed here, check the [FAQ](/reference/faq), the relevant feature page, or open an issue on [GitHub](https://github.com/ashraftalha/laravel-opconsole/issues).

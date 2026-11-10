---
title: Troubleshooting
---

# Troubleshooting

A consolidated list of issues covered in more depth on their feature pages, gathered here for quick lookup.

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

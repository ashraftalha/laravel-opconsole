---
title: Health Checks & Health Score
---

# Health Checks & Health Score

## What it does

OpConsole runs a set of built-in diagnostics, plus any checks you register, and reports each as one of four statuses: `healthy`, `warning`, `critical`, or `unknown`. The same report feeds the dashboard's **Health** tab, the `opconsole:health` Artisan command, and the optional JSON health endpoint — there is one health report policy, not three.

Checks are grouped by area, in a fixed order with problem groups surfaced first: Security, Database and queues, Scheduler and workers, Cache and performance, Files and folders, PHP settings, URL and email, then custom groups.

## Why it is useful

A single source of truth means your uptime monitor, your CI pipeline, and the page a human looks at never disagree about whether the application is healthy.

## How to use it

**From the dashboard:** open the **Health** tab. Filter by status chips (with counts) or by name; filters update `?check_status=` in place. Expand any check to see its current value and fix command.

**From the command line or CI:**

```bash
php artisan opconsole:health                  # table output, exits 1 on critical
php artisan opconsole:health --fail-on=warning # exit 1 on warnings too
php artisan opconsole:health --json            # machine-readable
```

### The health endpoint

**For uptime monitors:**

```dotenv
OPCONSOLE_HEALTH_ENDPOINT=true
OPCONSOLE_HEALTH_TOKEN=change-me-to-a-long-random-string
```

```bash
curl -H "Authorization: Bearer $OPCONSOLE_HEALTH_TOKEN" https://your-app.test/admin/opconsole/health
```

The endpoint is off by default, and also off while no token is set. It responds with `{status, checked_at, checks: [{id, group, label, status, value}]}` — no hints or fixes — and returns HTTP `503` when any check is critical, otherwise `200`. It runs outside the `web` middleware group entirely: no session, no cookies, no CSRF, rate limited by `health_endpoint.rate_limit` (default 60/minute).

::: tip Kubernetes
Use the endpoint as a **readiness** probe or for external uptime monitoring, not a **liveness** probe. It returns `503` when a dependency like the database is critical, and restarting the pod won't fix a database outage. A liveness probe should only check that PHP answers.
:::

## Configuration

| Key | Env | Default | Description |
|---|---|---|---|
| `health.required_checks` | — | `[]` | IDs that are always critical when unknown — use this for checks you depend on. |
| `health.unknown_policy` | — | `healthy` | What an unknown status counts as overall: `healthy`, `warning`, or `critical`. Defaults to `healthy` for monitoring-integration compatibility. |
| `health_endpoint.enabled` | `OPCONSOLE_HEALTH_ENDPOINT` | `false` | Enables `GET {prefix}/health`. |
| `health_endpoint.token` | `OPCONSOLE_HEALTH_TOKEN` | `null` | Bearer token, compared with `hash_equals`. |
| `health_endpoint.rate_limit` | `OPCONSOLE_HEALTH_RATE_LIMIT` | `60` | Requests per minute. |

See the full [Configuration Reference](/reference/configuration#health-command) for every key, and [Custom Health Checks](/advanced/custom-health-checks) for registering your own.

## Alerts (health transition notifications)

1. Enable `notifications` in the config.
2. Point `notifications.state_store` at a persistent, shared cache store.
3. Schedule `php artisan opconsole:health --notify` from your own scheduler — OpConsole does not add this schedule automatically.

You're notified on the first unhealthy transition, on a worsening transition, and on recovery. Delivery is synchronous (no queue worker needed), and isolated per channel — a failing mailer never blocks webhooks, and one failing webhook never blocks another. See [Health Notifications](/reference/configuration#health-notifications) for channel configuration, cooldown behavior, and what failure logs do (and never) contain.

## Security considerations

- The JSON endpoint deliberately omits hints and fix commands — only status and value are exposed to a bearer-token client.
- A required `custom.*` check that throws is reported as a critical `Required check …` entry rather than silently disappearing from the report.
- Built-in check IDs are exempt from the "required-but-missing" rule, because some only appear conditionally (for example `database.connection` appears only when the connection fails).

## Limitations

- Every request runs every check synchronously, including database/cache/table probes and your custom checks. Keep custom checks fast, and give any network calls they make their own short timeouts — OpConsole cannot interrupt a slow check.
- Array and null cache drivers never persist between processes, so heartbeat-based checks stay `unknown` with those drivers.

## Troubleshooting

- **A check always reads `unknown`:** confirm the backing service (scheduler, queue worker) is actually running, and that any shared heartbeat cache store is reachable from every server.
- **`opconsole:health` exits 1 unexpectedly in CI:** check `--fail-on` — it defaults to `critical`; pass `--fail-on=warning` only if you want warnings to fail the build too.

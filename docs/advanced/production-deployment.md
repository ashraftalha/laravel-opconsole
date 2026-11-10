---
title: Production Deployment
description: "Run Laravel OpConsole in production: config and route caching, Gates, a dedicated lock store, the audit policy, heartbeats and health endpoint monitoring."
---

# Production Deployment

## Before you deploy

1. **Define the Gates.** OpConsole is fail-closed outside `local` — see [Authentication & Authorization](/guide/authentication).
2. **Point the lock store at shared storage if you run more than one server.** The default file lock only coordinates a single server — see [Permissions & Security Policies](/advanced/permissions-security-policies).
3. **Decide your audit policy.** `best_effort` (default) never blocks an operation on an audit failure; `required` refuses actions, log deletes, and log downloads unless their audit row is written first.
4. **Review the action allow-list.** Disable anything you don't want available in this environment (for example, leave `storage_link` off unless needed).

## Deployment and caching

OpConsole is fully compatible with Laravel's production optimization commands:

```bash
php artisan migrate --force
php artisan vendor:publish --tag=opconsole-assets --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`config/opconsole.php` contains plain arrays and scalars only — no closures — so `config:cache` never needs special handling.

## Verification checklist

After deploying:

1. An unauthenticated request to `/admin/opconsole` redirects to your named `login` route, or returns HTTP `401` when `authentication.login_route` is `null` or absent — never a `500`.
2. An authenticated non-admin user receives HTTP `403`.
3. An authorized administrator loads `/admin/opconsole` (`200`), views grouped health checks, inspects logs, and sees entries appear in **History** after running an operation.

## Multi-server considerations

- **Action lock store:** point `OPCONSOLE_LOCK_STORE` at a dedicated Redis or database store with separate lock storage. See [Permissions & Security Policies](/advanced/permissions-security-policies).
- **Heartbeat store:** `heartbeats.store` must be a cache store shared by every server, or the scheduler/queue heartbeat checks become meaningless.
- **Security scan store:** `security.store` should be a shared, lock-capable cache store too, so concurrent scans across servers are still serialized.

## Monitoring integration

- Schedule `php artisan opconsole:health --fail-on=warning` (or `critical`) in CI/CD to gate a deploy on application health.
- Enable the JSON health endpoint for external uptime monitors — see [Health Checks & Health Score](/features/health-checks#the-health-endpoint). Use it as a **readiness**, not **liveness**, probe in Kubernetes.
- Enable `notifications` and schedule `opconsole:health --notify` for health-transition alerts (mail, Slack-compatible webhook, generic webhook).

## Rolling back or disabling quickly

```dotenv
OPCONSOLE_ENABLED=false
```

```bash
php artisan optimize:clear
```

This registers zero OpConsole routes without a code change or a deploy — useful if you need to pull the console during an incident investigation into itself.

## Updating the package

```bash
composer update ashraftalha/laravel-opconsole
php artisan migrate
php artisan config:clear
```

Composer never touches files you've published. If you published assets, views, or config, see the republishing guidance in the [Upgrade Guide](/reference/upgrade) for each specific version jump.

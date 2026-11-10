---
title: Initial Configuration
---

# Initial Configuration

This page covers the handful of settings worth reviewing right after install. For every available key, see the [Configuration Reference](/reference/configuration).

## Publish the config file

```bash
php artisan vendor:publish --tag=opconsole-config
```

This copies the package defaults to `config/opconsole.php`. It's optional — OpConsole works with its built-in defaults — but recommended so you can see and adjust every option. The file contains plain arrays and scalars only (no closures), so `php artisan config:cache` works without modification.

## The kill switch

```dotenv
OPCONSOLE_ENABLED=true
```

Set to `false` to register zero OpConsole routes at all — a quick way to disable the console entirely in an environment without touching code.

## Route prefix

```dotenv
OPCONSOLE_ROUTE_PREFIX=admin/opconsole
```

The console is mounted at this prefix. Change it if `admin/opconsole` collides with an existing route in your application. If your app already has a maintenance or status page at another URL, keep the prefixes distinct so both can run side by side during a staging rollout.

## The audit table

```bash
php artisan migrate
```

OpConsole records every operation (who ran what, when, how long, and any error) in an `opconsole_runs` table. This migration is required for the [History](/features/audit-trail) tab and for `audit.policy=required` enforcement to have anywhere to write.

## The action lock store

```dotenv
OPCONSOLE_LOCK_STORE=opconsole
```

On a single server, leave this as the default — OpConsole registers an isolated file-based lock store automatically so `optimize:clear` can never wipe an in-progress action's lock. On a multi-server deployment, point this at a shared Redis or database store. See [Permissions & Security Policies](/advanced/permissions-security-policies) for the full explanation.

## Scheduler and queue worker

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

```bash
php artisan queue:work
```

These aren't OpConsole-specific, but the [Queue Worker & Scheduler](/features/queue-scheduler) heartbeat checks need both running to report anything other than `unknown`.

## Next: authentication and authorization

Before anything in this list matters outside local development, you need Gates. Continue to [Authentication & Authorization](/guide/authentication).

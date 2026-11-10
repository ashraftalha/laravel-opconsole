---
title: Queue Worker & Scheduler Monitoring
---

# Queue Worker & Scheduler Monitoring

## What it does

Two heartbeat checks answer a question logs can't: is the scheduler actually firing every minute, and is a worker actually consuming the queue right now?

- **Scheduler heartbeat (`scheduler.heartbeat`):** the service provider schedules a callback every minute that writes `opconsole:heartbeat:scheduler` to the heartbeat cache store.
- **Queue worker heartbeat (`queue.worker_heartbeat`):** the scheduler dispatches `OpConsoleHeartbeatJob` every minute on a configurable connection and queue; the worker that processes it writes `opconsole:heartbeat:queue`. This works with Redis, SQS, and any other driver — unlike a "stuck jobs" check, it directly proves a worker is consuming jobs.

## Why it is useful

A cron entry that silently stopped running, or a `queue:work` process that died hours ago, usually isn't discovered until something downstream fails. These heartbeats catch that directly instead of inferring it from side effects.

## How to configure it

```php
// config/opconsole.php
'heartbeats' => [
    'store' => env('OPCONSOLE_HEARTBEAT_STORE'),              // null uses cache.default
    'scheduler' => true,
    'scheduler_max_age' => 120,                                // seconds
    'queue' => true,
    'queue_max_age' => 300,                                    // seconds
    'queue_connection' => env('OPCONSOLE_HEARTBEAT_QUEUE_CONNECTION'),
    'queue_name' => env('OPCONSOLE_HEARTBEAT_QUEUE_NAME'),
],
```

The heartbeat store **must be shared by every server** in a multi-server deployment — a per-server cache store makes the heartbeat check meaningless. See the full key list in the [Configuration Reference](/reference/configuration#heartbeats-heartbeats).

## How to use it

Look at "Scheduler and workers" in the **Health** tab, or the Queue worker / Scheduler summary tiles on **Overview**.

- `scheduler.heartbeat`: healthy within `scheduler_max_age` (default 120s), a warning when stale outside production, critical in production, `unknown` until the first beat.
- `queue.worker_heartbeat`: healthy within `queue_max_age` (default 300s), warning/critical when stale (fix: `php artisan queue:work`), `unknown` while the scheduler heartbeat itself isn't confirmed running, and skipped for `sync`/`null` drivers.

## Security considerations

Heartbeat values are timestamps only — no job payloads or application data pass through this mechanism.

## Limitations

- `optimize:clear` flushes `cache.default`, so with the default heartbeat store the checks read `unknown` for up to a minute after any cache clear.
- Array and null cache drivers never persist between processes — heartbeats stay `unknown` with those drivers regardless of whether the scheduler or worker is actually running.
- `queue.worker_heartbeat` depends on the scheduler heartbeat being healthy first (the scheduler is what dispatches the heartbeat job) — a dead scheduler reads as `unknown` for the queue check too, not as a separate failure.

## Troubleshooting

- **Both heartbeats stuck at `unknown` forever:** confirm `heartbeats.store` resolves to a cache store that's actually shared and reachable, and that `php artisan schedule:run` is wired into real cron (not just run manually once).
- **Queue heartbeat stale but scheduler heartbeat is healthy:** a worker isn't running, or it's pointed at a different connection/queue than `heartbeats.queue_connection` / `heartbeats.queue_name` configure. Run `php artisan queue:work` against the same connection and queue.

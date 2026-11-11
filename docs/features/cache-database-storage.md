---
title: Cache, Database & Storage Monitoring
---

# Cache, Database & Storage Monitoring

[View cache, database, and storage checks screenshot](/screenshots/health-light.png) · [Browse all feature screenshots](/guide/introduction#feature-screenshot-directory)

## What it does

OpConsole probes the services your application actually depends on, using your configured connections and drivers — not generic defaults.

- **Database:** inspects the configured connection, including driver and server version, and (for service-specific tables) checks that expected tables exist on that connection.
- **Cache round trip (`cache.round_trip`):** writes a random value under a unique key with a 10-second TTL, reads it back, and forgets it. Critical on mismatch or exception; the value shows the store name and time taken. Skipped with an info status for `array`/`null` drivers outside production, since those drivers can't prove anything useful.
- **Production caches built (`performance.caches_built`):** in production, warns when configuration, routes, or events aren't cached, lists which are missing, and suggests `php artisan optimize`. Informational outside production.
- **Disk space (`files.disk_space`):** warns below 1 GB free or below `disk.min_free_percent` (default 10%) of the volume, critical below 256 MB.
- **Storage link (`files.storage_link`):** when a `public` disk exists, warns if `public/storage` is missing or doesn't resolve to `storage/app/public`, with fix `php artisan storage:link`.

<figure class="oc-shot">
  <img src="/screenshots/health-light.png" alt="OpConsole cache, database, and storage checks in the Health tab" width="1920" height="1245" loading="lazy">
  <figcaption>The Health tab groups these checks with their status and suggested fixes. Sample data.</figcaption>
</figure>

## Why it is useful

These are the dependencies that silently degrade an application without a hard crash — a stale OPcache after deploy, a disk slowly filling with logs, a cache store that accepts writes but can't be read back. Catching them here is faster than waiting for a support ticket.

## How to configure it

```dotenv
OPCONSOLE_DISK_MIN_FREE_PERCENT=10
```

Disk space is the only tunable in this group — database and cache checks read your application's own connection configuration directly, so there's nothing OpConsole-specific to set.

## How to use it

These appear in the **Health** tab under "Database and queues", "Cache and performance", and "Files and folders", and as summary tiles on the **Overview** tab (Database, Cache, Disk space).

## Security considerations

Database checks report driver, server version, and table existence — never credentials, connection strings, or row data.

## Limitations

- The cache round-trip check can't distinguish "store is slow" from "store is briefly unavailable" beyond its own exception handling; a timeout from the driver surfaces as critical either way.
- Disk space is measured for the filesystem, not your application's specific data directories — a large unrelated volume on the same mount can mask a smaller partition filling up if your paths don't match that assumption.

## Troubleshooting

- **`files.storage_link` stays critical after running `php artisan storage:link`:** confirm the symlink target is literally `storage/app/public`; a manually created symlink pointing elsewhere still fails the check.
- **Cache check is critical only in production:** some drivers (`array`, `null`) are intentionally skipped as informational outside production but not in it — that's deliberate, since those drivers are a real hazard in production.

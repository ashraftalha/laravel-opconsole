---
title: Performance Considerations
---

# Performance Considerations

## Health checks run synchronously, every time

Every dashboard load, every `opconsole:health` invocation, and every hit to the JSON health endpoint runs the full check list synchronously — built-in checks plus any you've registered. There's no caching layer between a request and the checks themselves.

**Implication:** keep custom [health checks](/advanced/custom-health-checks) fast, and give any network call inside one an explicit, short timeout. OpConsole cannot interrupt a slow check — a check that hangs makes every page load that includes it hang too.

## Bounded history results {#bounded-constant-cost-history-queries}

`ConsoleStatus` fetches the latest completed run per action via a grouped `SELECT MAX(id) ... GROUP BY action` query, and the most recent `history_limit` rows (default 15, clamped 1–100) via `ORDER BY id DESC LIMIT`. The number of queries and returned rows is bounded; database execution cost is not guaranteed to be constant. It depends on table size, indexes, and the database query plan. Monitor query performance and apply an audit-retention policy as history grows.

## Log viewer: bounded tail reads

`LogViewer` seeks to the last 2 MB of a log file for the in-browser tail view rather than reading the whole file into memory — this bounds memory use regardless of how large a log file has grown. Downloading a file still serves the complete file.

## Request-scoped log parsing under Octane

`LogViewer` is registered with `$this->app->scoped()`, so its file listing and tail parse run at most once per request even though the result is reused in multiple places during that request (the Overview's recent-errors sample and the Logs tab itself). Under Octane's long-lived workers, this also prevents stale per-request state from leaking into the next request on the same worker — see [Octane & Swoole Environments](/advanced/octane-swoole-environments).

## The security audit never runs on a page load

The dependency security scan (`opconsole:security`) is relatively expensive — it shells out to Composer. The dashboard **only reads the last stored report**; no page render ever launches a scan. Run it explicitly (CLI, the Operations tab button, or the optional daily schedule) and let the dashboard read the cached result.

## Zero frontend build cost

The console's CSS and JavaScript are static, dependency-free files with zero runtime dependencies on Bootstrap, jQuery, or external icon fonts. There's no asset pipeline to run, no CDN round-trip, and no client-side framework to hydrate — the page is fast by not having much to load.

## Octane and worker reloads

Under Octane, configuration and service bindings are cached in worker memory between requests. After cache-clearing, optimizing, or migrating, OpConsole reminds you to reload workers — skipping that reload doesn't just mean stale behavior, it means the application is running on configuration that no longer matches what's on disk. See [Octane & Swoole Environments](/advanced/octane-swoole-environments).

## What this page is not

This page documents OpConsole's own performance characteristics and the knobs it exposes. It is not a general Laravel performance tuning guide — for that, see Laravel's own deployment and optimization documentation.

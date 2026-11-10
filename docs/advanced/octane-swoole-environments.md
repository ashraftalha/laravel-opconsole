---
title: Octane & Swoole Environments
---

# Octane & Swoole Environments

This page covers operational guidance for running OpConsole under Laravel Octane. For what the checks themselves cover, see [Octane & Swoole Monitoring and Worker Reload](/features/octane-swoole).

## Request-scoped state and long-lived workers

`LogViewer` is registered with `$this->app->scoped(LogViewer::class)`, so its file listing and 2 MB tail parse run at most once per HTTP request — and that state does not leak across requests inside the same long-lived Octane worker. This matters specifically because Octane keeps the application booted between requests; a naive singleton binding would otherwise serve stale log data to a different request on the same worker.

## The Swoole signal guard

Inside a Swoole worker, OpConsole refuses every timer-bound action (clear caches, optimize, migrate, maintenance on/off, storage link) by default, because their runtime budget is enforced with a `SIGALRM` signal that would interrupt the **worker process itself**, not an isolated child process. The read-only security scan and the Octane reload operation — both self-bounded, not signal-based — still run normally.

```dotenv
OPCONSOLE_OCTANE_ALLOW_ACTIONS=true
```

Only set this if you've accepted that risk, or route those specific operations through the CLI instead (deploy pipeline, SSH) rather than the browser when running on Swoole. RoadRunner and FrankenPHP are not affected — the signal-based timeout mechanism is a Swoole-specific concern.

## Reload workers after every config-affecting operation

Octane workers hold configuration, service bindings, and bootstrapped state in memory. After **Clear caches**, **Optimize**, or **Run migrations**, the old worker state is still live until reloaded. OpConsole's output panel reminds you of this after each such operation — press **Reload Octane workers** (or run `php artisan octane:reload` yourself) to actually apply the change.

## Production expectations

```dotenv
OPCONSOLE_OCTANE_EXPECTED=true
```

If your production deployment is Octane-only (no requests should ever fall back to plain PHP-FPM), set this so a production request not served by Octane — or a stopped Octane server — becomes a warning/critical finding instead of a quiet advisory. Leave it at the default if mixed routing (some requests via Octane, some via FPM) is expected in your setup.

## Checklist for an Octane deployment

1. Confirm `octane.checks` is `auto` (default) so the Octane health group appears automatically once Octane is detected.
2. Decide whether `octane.allow_actions_in_swoole` should be `true` for your risk tolerance, if you're on the Swoole server specifically.
3. Set `octane.expected_in_production` to `true` if Octane should always be in front of production traffic.
4. Train operators to reload workers after cache/optimize/migrate operations — the dashboard reminder helps, but it's not automatic.
5. Monitor the **Octane and runtime** health group alongside the rest of the Health tab, especially `package_max_length` vs. upload limits if you're on Swoole and accept large uploads.

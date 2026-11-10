---
title: Octane & Swoole Monitoring and Worker Reload
description: "Laravel Octane and Swoole support in OpConsole: runtime health checks, an Octane card in System information, and a Reload Octane workers operation."
---

# Octane & Swoole Monitoring and Worker Reload

## What it does

When [Laravel Octane](https://laravel.com/docs/octane) is installed, OpConsole adds:

- An **Octane and runtime** health group
- An **Octane** card in System information
- An **Octane server** row in Runtime status
- A **Reload Octane workers** operation (`octane:reload`)

Without Octane installed, none of this appears — nothing changes for a non-Octane application.

**Checks performed:**

- The configured server (Swoole, RoadRunner, or FrankenPHP) is installed
- Requests are actually being served by Octane
- The server process is running (via Octane's own process inspector)
- `octane.garbage` stays below `memory_limit`
- `octane.https` matches an `https` `APP_URL`
- `opcache.enable_cli` is on for workers

**With the Swoole server specifically, also:**

- `package_max_length` covers your upload size (Swoole ignores `upload_max_filesize` and `post_max_size`)
- Worker count against CPU core count
- Xdebug loaded alongside Swoole
- The Swoole log file is writable

## Why it is useful

Octane workers keep the application booted in memory between requests. That's a performance win, but it means configuration changes, cache clears, and migrations don't take effect until workers are reloaded — a frequent source of "I deployed but nothing changed" confusion that these checks and reminders target directly.

## How to configure it

```dotenv
OPCONSOLE_OCTANE_CHECKS=auto
OPCONSOLE_OCTANE_EXPECTED=false
OPCONSOLE_OCTANE_ALLOW_ACTIONS=false
OPCONSOLE_ACTION_OCTANE_RELOAD=true
```

| Key | Default | Description |
|---|---|---|
| `octane.checks` | `auto` | `auto` adds the checks only when Octane is installed; `false` turns them off entirely. |
| `octane.expected_in_production` | `false` | When `true`, a production request not served by Octane (or a stopped server) becomes a warning/critical instead of an advisory. |
| `octane.allow_actions_in_swoole` | `false` | See the Swoole guard below. |
| `actions.octane_reload` | `true` | Shows **Reload Octane workers** (only when Octane is installed). |

## How to use it

After **Clear caches**, **Optimize**, or **Run migrations**, the output panel reminds you to reload workers, since they keep the old configuration in memory. Press **Reload Octane workers** to apply it.

## Security considerations: the Swoole guard

Timed operations (clear caches, optimize, migrate, maintenance on/off, storage link) enforce their runtime budget with a `SIGALRM` signal. Inside a Swoole worker, that signal would interrupt the **worker process itself** rather than a separate child process — so by default, OpConsole **refuses these operations entirely when running inside a Swoole worker**, with an explanation shown before any lock is taken. The read-only security scan and the Octane reload operation (both self-bounded, not signal-based) still run normally.

Set `OPCONSOLE_OCTANE_ALLOW_ACTIONS=true` to override this and accept the risk. RoadRunner, FrankenPHP, and plain PHP-FPM are not affected by this guard.

## Limitations

- These checks and the reload action only exist when Octane is actually installed — there's no way to preview or test them without it.
- The guard above is Swoole-specific; it does not generalize to other long-running-process concerns you might have outside Octane.

## Troubleshooting

- **Actions disabled, says refused inside Swoole worker:** this is the guard above, working as intended. Either run the action from the CLI, or set `OPCONSOLE_OCTANE_ALLOW_ACTIONS=true` if you've accepted the risk of interrupting a worker.
- **Octane section missing even though Octane is installed:** check `octane.checks` isn't set to `false`.
- **"Not served by Octane" warning in production:** confirm your Octane server process is actually running and in front of these requests; if this is expected (for example, mixed FPM/Octane routing), leave `octane.expected_in_production` at its default so it stays an advisory.

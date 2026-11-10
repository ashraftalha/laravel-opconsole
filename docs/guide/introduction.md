---
title: Introduction
---

# Introduction

Laravel OpConsole is an administrative operations console for Laravel applications. It adds one page to your app — `/admin/opconsole` by default — that answers "is anything wrong?" at a glance, shows the fix for every problem it finds, and lets authorized people run a small set of maintenance tasks without SSH access.

It ships its own CSS and JavaScript, needs no frontend build step, and makes no CDN requests, so it works the same way behind a strict Content-Security-Policy as it does on a fresh install.

## What it is for

- **Operators and on-call engineers** who need to see the state of an application — database, cache, queue, scheduler, disk, security advisories — without a terminal.
- **Teams who want safe, auditable maintenance actions** (clearing caches, running migrations, toggling maintenance mode) gated behind Laravel's own authorization system, not a separate login.
- **Anyone who wants uptime-monitor-friendly health data**, either as a CLI command for CI/CD pipelines or a token-protected JSON endpoint for external monitors.

## What it is not

- It is **not** a general-purpose admin panel, ORM browser, or CRUD scaffold.
- It does **not** accept arbitrary Artisan commands, shell arguments, or `.env` edits — see [Explicit non-features](/reference/security#explicit-non-features).
- It does **not** replace your application's authentication — it reuses it, enforced through Laravel [Gates](/guide/authentication).

## The eight tabs

| Tab | What you get |
|---|---|
| [Overview](/features/health-dashboard) | Overall state with a status bar, summary cards, issues needing attention with copyable fix commands, runtime status, and recent activity. |
| [Health](/features/health-checks) | Every check grouped by area, critical issues first, filterable by status or name. |
| [Security](/features/security-checks) | Vulnerable Composer packages by severity, scan-freshness meter, and Laravel configuration warnings. |
| [Operations](/features/artisan-actions) | Maintenance tasks split into a Safe group and a Danger zone. |
| [Failed jobs](/features/failed-jobs) | The newest failed queue jobs, with a per-job Retry button. |
| [History](/features/audit-trail) | An audit trail grouped by day: who ran what, when, how long it took, and any error. |
| System | Application, PHP, performance, extensions, server, database and cache settings, with production-risk flags. |
| [Logs](/features/log-viewer) | Log file list, an entries-per-hour chart, filters, search with highlighting, download and delete. |

Everything on this site documents real, shipped behavior — nothing here describes a planned or aspirational feature. If a capability isn't covered, it isn't implemented.

## Next steps

1. [Check requirements and compatibility](/guide/requirements)
2. [Install the package](/guide/installation)
3. [Configure authentication and authorization](/guide/authentication)
4. [Run through the quick start](/guide/quick-start)

---
layout: home

title: Laravel OpConsole
titleTemplate: Monitor. Diagnose. Maintain.

hero:
  name: "Laravel OpConsole"
  text: "Monitor. Diagnose. Maintain."
  tagline: An operations console for Laravel — health checks, safe Artisan operations, a security audit and a log viewer, all behind Gates. No build step, no CDN requests.
  image:
    light: /logo-mark.svg
    dark: /logo-mark-dark.svg
    alt: OpConsole logo
  actions:
    - theme: brand
      text: 5-minute setup
      link: /guide/quick-start
    - theme: alt
      text: Read-only production
      link: /advanced/production-recipes#read-only-production-recommended
    - theme: alt
      text: View on GitHub
      link: https://github.com/ashraftalha/laravel-opconsole

features:
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 14 4-4" /><path d="M3.34 19a10 10 0 1 1 17.32 0" /></svg>'
    title: One page, every answer
    link: /features/health-dashboard
    linkText: View feature and screenshot
    details: A 0–100 health score, environment badge, and Laravel/PHP versions above eight tabs — Overview, Health, Security, Operations, Failed jobs, History, System, and Logs.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>'
    title: Fail-closed by default
    link: /guide/authentication
    linkText: View access policies
    details: Outside the local environment, every request is denied until you explicitly define Gates. Four distinct abilities — view, run, download, delete — with per-action overrides.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="4 17 10 11 4 5" /><line x1="12" y1="19" x2="20" y2="19" /></svg>'
    title: Safe Artisan operations
    link: /features/artisan-actions
    linkText: View feature and screenshot
    details: A closed allow-list of operations (cache clear, optimize, migrate, maintenance mode, storage link, security scan, Octane reload) — never arbitrary commands. Confirmed, rate-limited, and audited.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2" /></svg>'
    title: Real signal, not guesses
    link: /features/health-checks
    linkText: View feature and screenshot
    details: Scheduler and queue-worker heartbeats, cache round-trips, disk space, and production-cache checks — backed by the same report the CLI and JSON endpoint use.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z" /><path d="m9 12 2 2 4-4" /></svg>'
    title: Built-in security audit
    link: /features/security-checks
    linkText: View feature and screenshot
    details: Scans your locked Composer dependencies, including transitive packages, for known advisories, with scan-freshness tracking and copyable update commands.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /></svg>'
    title: A real log viewer
    link: /features/log-viewer
    linkText: View feature and screenshot
    details: File list, entries-per-hour chart, level/environment filters, search with highlighting, repeat counts, and stack traces with your app's frames highlighted.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z" /></svg>'
    title: Octane & Swoole aware
    link: /features/octane-swoole
    linkText: View feature and screenshot
    details: Detects Laravel Octane, adds dedicated health checks, and safely refuses signal-based timeouts inside a Swoole worker instead of corrupting it.
  - icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>'
    title: Full audit trail
    link: /features/audit-trail
    linkText: View feature and screenshot
    details: Every operation is recorded — who ran what, when, how long it took, and any error — browsable by day in the History tab.
---

<script setup>
import { useData } from 'vitepress'

const slides = [
  { src: '/screenshots/overview-light.png', alt: 'OpConsole Overview tab: health score, status summary, issues with fix commands, and runtime status', caption: 'Overview: health score, status summary, and fix commands' },
  { src: '/screenshots/health-light.png', alt: 'OpConsole Health tab: checks grouped by area with status filters and fix commands', caption: 'Health: every check grouped by area, filterable by status' },
  { src: '/screenshots/security-light.png', alt: 'OpConsole Security tab: advisories by severity with update commands', caption: 'Security: advisories by severity and scan freshness' },
  { src: '/screenshots/operations-light.png', alt: 'OpConsole Operations tab: safe operations and a danger zone, with each pending migration runnable on its own', caption: 'Operations: safe tasks, a danger zone, and run one migration or all', height: 1770 },
  { src: '/screenshots/jobs-light.png', alt: 'OpConsole Failed jobs tab: failed queue jobs with their error and a Retry button', caption: 'Failed jobs: errors at a glance, retry one job' },
  { src: '/screenshots/history-light.png', alt: 'OpConsole History tab: operations grouped by day with durations', caption: 'History: every operation grouped by day' },
  { src: '/screenshots/system-light.png', alt: 'OpConsole System information tab: application, PHP, performance, extensions, server and database settings', caption: 'System information: runtime and service settings, with a support summary' },
  { src: '/screenshots/logs-light.png', alt: 'OpConsole Logs tab: file list, entries per hour chart and filters', caption: 'Logs: hourly chart, filters, and repeat counts' },
]
</script>

<div class="opconsole-landing">

## Your first dashboard in five minutes

```bash
composer require ashraftalha/laravel-opconsole
php artisan vendor:publish --tag=opconsole-config
php artisan migrate
```

For an existing Laravel app with a working login and deployment access, follow the **[5-minute setup](/guide/quick-start)** to install, grant read-only access, and verify the dashboard. Production users should start with the **[read-only configuration recipe](/advanced/production-recipes#read-only-production-recommended)**. The estimate excludes provisioning a server, setting up authentication, and reviewing application migrations.

No SSH? Check [shared-hosting limits](/guide/installation#shared-hosting-and-no-ssh-deployments) first. Already installed but blocked? Follow the [401/403 and disabled-action decision tree](/reference/troubleshooting#access-and-actions-decision-tree).

## See it in action

[Browse every feature and its screenshot](/guide/introduction#feature-screenshot-directory).

<ScreenshotSlider :slides="slides" />

<p class="opconsole-shots-note">Screenshots use sample data.</p>

## Compatibility

| Requirement | Supported |
|---|---|
| PHP | `^8.3` (tested on 8.3, 8.4, 8.5) |
| Laravel | `^12.0` or `^13.0` |
| Browser-triggered actions | Needs `pcntl` on the web SAPI; without it, everything still works except timer-bound actions (read [why](/reference/configuration#execution-environments)) |
| Security audit | Composer 2.4+ and a `composer.lock` in your app |

Verified in CI across PHP 8.3–8.5 × Laravel 12/13 × `prefer-stable`/`prefer-lowest`, plus a clean-install smoke test against a fresh Laravel application.

## Where to go next

<div class="opconsole-next">
  <a href="/guide/introduction"><strong>Getting Started</strong><span>Install, configure Gates, and open the console for the first time.</span></a>
  <a href="/features/health-dashboard"><strong>Core Features</strong><span>What every tab does, and why it's useful.</span></a>
  <a href="/advanced/custom-health-checks"><strong>Advanced Topics</strong><span>Custom health checks, extension points, and production deployment.</span></a>
  <a href="/reference/configuration"><strong>Reference</strong><span>Every configuration key, Artisan command, and route.</span></a>
</div>

</div>

<style>
.opconsole-landing { max-width: 960px; margin: 0 auto; padding: 0 24px 64px; }
.opconsole-shots-note { font-size: 13px; color: var(--vp-c-text-3); text-align: center; margin-top: 4px; }
.opconsole-next { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.opconsole-next a { display: flex; flex-direction: column; gap: 4px; padding: 16px; border: 1px solid var(--vp-c-divider); border-radius: 8px; text-decoration: none; color: inherit; }
.opconsole-next a:hover { border-color: var(--vp-c-brand-1); }
.opconsole-next strong { color: var(--vp-c-brand-1); }
.opconsole-next span { font-size: 14px; color: var(--vp-c-text-2); }
@media (max-width: 640px) {
  .opconsole-next { grid-template-columns: 1fr; }
}
</style>

---
layout: home

title: Laravel OpConsole
titleTemplate: Monitor. Diagnose. Maintain.

hero:
  name: "Laravel OpConsole"
  text: "Monitor. Diagnose. Maintain."
  tagline: An operations console for Laravel — health checks, safe Artisan operations, a security audit and a log viewer, all behind Gates. No build step, no CDN requests.
  image:
    src: /logo.svg
    alt: Laravel OpConsole
  actions:
    - theme: brand
      text: Get Started
      link: /guide/introduction
    - theme: alt
      text: Quick Start
      link: /guide/quick-start
    - theme: alt
      text: View on GitHub
      link: https://github.com/ashraftalha/laravel-opconsole

features:
  - icon: 🩺
    title: One page, every answer
    details: A 0–100 health score, environment badge, and Laravel/PHP versions above eight tabs — Overview, Health, Security, Operations, Failed jobs, History, System, and Logs.
  - icon: 🔒
    title: Fail-closed by default
    details: Outside the local environment, every request is denied until you explicitly define Gates. Four distinct abilities — view, run, download, delete — with per-action overrides.
  - icon: 🧰
    title: Safe Artisan operations
    details: A closed allow-list of operations (cache clear, optimize, migrate, maintenance mode, storage link, security scan, Octane reload) — never arbitrary commands. Confirmed, rate-limited, and audited.
  - icon: 📈
    title: Real signal, not guesses
    details: Scheduler and queue-worker heartbeats, cache round-trips, disk space, and production-cache checks — backed by the same report the CLI and JSON endpoint use.
  - icon: 🛡️
    title: Built-in security audit
    details: Scans your locked Composer dependencies, including transitive packages, for known advisories, with scan-freshness tracking and copyable update commands.
  - icon: 📜
    title: A real log viewer
    details: File list, entries-per-hour chart, level/environment filters, search with highlighting, repeat counts, and stack traces with your app's frames highlighted.
  - icon: ⚡
    title: Octane & Swoole aware
    details: Detects Laravel Octane, adds dedicated health checks, and safely refuses signal-based timeouts inside a Swoole worker instead of corrupting it.
  - icon: 🧾
    title: Full audit trail
    details: Every operation is recorded — who ran what, when, how long it took, and any error — browsable by day in the History tab.
---

<script setup>
import { useData } from 'vitepress'
</script>

<div class="opconsole-landing">

## Install in a minute

```bash
composer require ashraftalha/laravel-opconsole
php artisan vendor:publish --tag=opconsole-config
php artisan migrate
```

Then sign in and open `/admin/opconsole`. Works immediately with `APP_ENV=local`; everywhere else, access is denied until you [define the Gates](/guide/authentication).

## See it in action

<div class="opconsole-shots">
  <figure>
    <img src="/screenshots/overview-light.png" alt="OpConsole Overview tab: health score, status summary, issues with fix commands, and runtime status" loading="lazy">
    <figcaption>Overview — health score, status summary, and fix commands</figcaption>
  </figure>
  <figure>
    <img src="/screenshots/health-light.png" alt="OpConsole Health tab: checks grouped by area with status filters and fix commands" loading="lazy">
    <figcaption>Health — every check grouped by area, filterable by status</figcaption>
  </figure>
  <figure>
    <img src="/screenshots/operations-light.png" alt="OpConsole Operations tab: safe operations and a danger zone" loading="lazy">
    <figcaption>Operations — safe tasks and a separate danger zone</figcaption>
  </figure>
  <figure>
    <img src="/screenshots/security-light.png" alt="OpConsole Security tab: advisories by severity with update commands" loading="lazy">
    <figcaption>Security — advisories by severity, scan freshness</figcaption>
  </figure>
  <figure>
    <img src="/screenshots/logs-dark.png" alt="OpConsole Logs tab in dark mode: file list, entries per hour chart and filters" loading="lazy">
    <figcaption>Logs — hourly chart, filters, repeat counts (dark theme)</figcaption>
  </figure>
  <figure>
    <img src="/screenshots/jobs-light.png" alt="OpConsole Failed jobs tab: failed queue jobs with their error and a Retry button" loading="lazy">
    <figcaption>Failed jobs — errors at a glance, retry one job</figcaption>
  </figure>
</div>

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
.opconsole-shots { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin: 24px 0; }
.opconsole-shots figure { margin: 0; }
.opconsole-shots img { width: 100%; border-radius: 8px; border: 1px solid var(--vp-c-divider); display: block; }
.opconsole-shots figcaption { font-size: 13px; color: var(--vp-c-text-2); margin-top: 6px; text-align: center; }
.opconsole-shots-note { font-size: 13px; color: var(--vp-c-text-3); text-align: center; margin-top: -8px; }
.opconsole-next { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.opconsole-next a { display: flex; flex-direction: column; gap: 4px; padding: 16px; border: 1px solid var(--vp-c-divider); border-radius: 8px; text-decoration: none; color: inherit; }
.opconsole-next a:hover { border-color: var(--vp-c-brand-1); }
.opconsole-next strong { color: var(--vp-c-brand-1); }
.opconsole-next span { font-size: 14px; color: var(--vp-c-text-2); }
@media (max-width: 640px) {
  .opconsole-shots { grid-template-columns: 1fr; }
  .opconsole-next { grid-template-columns: 1fr; }
}
</style>

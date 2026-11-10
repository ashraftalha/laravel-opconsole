---
title: Health Dashboard
---

# Health Dashboard

## What it does

The dashboard is a single page at `/admin/opconsole` (by default) organized into eight tabs, with a header showing a **0–100 health score**, the environment badge, and the Laravel and PHP versions.

<figure class="oc-shot">
  <img src="/screenshots/overview-light.png" alt="OpConsole Overview tab: health score, status summary, issues with fix commands and runtime status" width="1920" height="1245" loading="lazy">
  <figcaption>The Overview tab with the health score in the header. Click any screenshot to enlarge it.</figcaption>
</figure>

| Tab | What you get |
|---|---|
| **Overview** | Overall state with a status bar, summary cards (database, health, log errors, cache, queue, scheduler, disk, security), issues needing attention with copyable fix commands, runtime status, and a recent-activity feed. |
| **Health** | Every check grouped by area, critical issues first. Filter by status chips or by name; expand any check for its current value and fix. Filters stay in the URL (`?check_status=`), so a shared link opens the same view. |
| **Security** | Vulnerable Composer packages by severity, a scan-freshness meter, and Laravel configuration warnings. |
| **Operations** | Maintenance tasks split into a **Safe** group and a **Danger zone**. The last run's output appears first. |
| **Failed jobs** | The newest failed queue jobs with queue, failure time and error (never the payload), a filter, and a **Retry** button per job. |
| **History** | An audit trail grouped by day: who ran what, when, how long it took, and any error. |
| **System** | Application, PHP, performance (OPcache, JIT, Xdebug), extensions, server, database, service and cache settings, with production-risk flags and "Copy summary for support". |
| **Logs** | Log file list, an entries-per-hour chart, level/environment filters, search with highlighting, repeat counts, and stack traces with your app's frames highlighted. |

### Every tab at a glance

<div class="oc-shot-grid">
  <figure class="oc-shot">
    <img src="/screenshots/overview-light.png" alt="OpConsole Overview tab" width="1920" height="1245" loading="lazy">
    <figcaption>Overview</figcaption>
  </figure>
  <figure class="oc-shot">
    <img src="/screenshots/health-light.png" alt="OpConsole Health tab" width="1920" height="1245" loading="lazy">
    <figcaption>Health</figcaption>
  </figure>
  <figure class="oc-shot">
    <img src="/screenshots/security-light.png" alt="OpConsole Security tab" width="1920" height="1245" loading="lazy">
    <figcaption>Security</figcaption>
  </figure>
  <figure class="oc-shot">
    <img src="/screenshots/operations-light.png" alt="OpConsole Operations tab" width="1920" height="1770" loading="lazy">
    <figcaption>Operations</figcaption>
  </figure>
  <figure class="oc-shot">
    <img src="/screenshots/jobs-light.png" alt="OpConsole Failed jobs tab" width="1920" height="1245" loading="lazy">
    <figcaption>Failed jobs</figcaption>
  </figure>
  <figure class="oc-shot">
    <img src="/screenshots/history-light.png" alt="OpConsole History tab" width="1920" height="1245" loading="lazy">
    <figcaption>History</figcaption>
  </figure>
  <figure class="oc-shot">
    <img src="/screenshots/system-light.png" alt="OpConsole System information tab" width="1920" height="1245" loading="lazy">
    <figcaption>System information</figcaption>
  </figure>
  <figure class="oc-shot">
    <img src="/screenshots/logs-light.png" alt="OpConsole Logs tab" width="1920" height="1245" loading="lazy">
    <figcaption>Logs</figcaption>
  </figure>
</div>

<p class="oc-shot-note">Screenshots use sample data.</p>

## The health score

The ring in the header shows a 0–100 score. Any critical check caps it below 60; any warning caps it below 90.

## Why it is useful

Most of the time "is anything wrong?" requires either SSH access and a handful of memorized commands, or asking someone else. The dashboard answers that question from a browser, with the fix command already written out, for anyone your Gates allow.

## How to use it

- Press <kbd>Ctrl</kbd>/<kbd>⌘</kbd> <kbd>K</kbd> to open the command palette and jump to any tab, check, operation, or log file.
- Use <kbd>j</kbd> / <kbd>k</kbd> to move through log entries.
- Toggle light/dark theme from the header switch (if `theme_toggle` is enabled).
- The dashboard works on phones, and renders right-to-left for Arabic, Hebrew, Persian, and Urdu locales.

## Configuration

| Option | Env | Default | Effect |
|---|---|---|---|
| `theme` | `OPCONSOLE_THEME` | `auto` | `auto` follows the visitor's system setting; `light`/`dark` forces one. |
| `theme_toggle` | `OPCONSOLE_THEME_TOGGLE` | `true` | Shows the light/dark switch, remembered per browser in a plain cookie. |
| `web_fonts` | `OPCONSOLE_WEB_FONTS` | `false` | Loads IBM Plex from Google Fonts. Off by default — the console makes no external requests unless you opt in. |
| `layout` | — | `null` | Render inside your own Blade layout instead of the standalone page. |
| `back_url` / `back_label` | — | `null` / `Dashboard` | Adds a link back to your own admin panel. |

See the full list in the [Configuration Reference](/reference/configuration#ui-layout-audit-storage).

## Security considerations

- The dashboard is served entirely behind the `viewOpConsole` Gate (and its CSS/JS/SVG assets too, via the `opconsole.asset` route).
- It emits zero inline `<script>` blocks and zero inline `style=` attributes, so it works unmodified under a strict Content-Security-Policy.
- No third-party CDN requests happen by default. Enabling `web_fonts` is the one opt-in exception — see its CSP notes in the [Configuration Reference](/reference/configuration#ui-layout-audit-storage).

## Limitations

- The "recent errors" figure on the Overview tab is sampled from the bounded tail of the newest readable log file. A count of zero means no sampled errors in that tail, not proof every log is clean.
- Without JavaScript, every section still renders in page order (the tabs are a progressive enhancement), but the command palette and keyboard navigation require it.

## Troubleshooting

- **Blank or 403 page:** you're missing the `viewOpConsole` Gate, or it's evaluating to `false` for your user. See [Authentication & Authorization](/guide/authentication).
- **Styling looks broken after an upgrade:** republish assets with `php artisan vendor:publish --tag=opconsole-assets --force`, especially if you've customized views — see the [Upgrade Guide](/reference/upgrade).
- **Host layout's fixed header overlaps the sticky tab bar:** set the `--oc-tabs-offset` CSS variable in your own stylesheet, for example `.oc-embedded-shell { --oc-tabs-offset: 64px; }`.

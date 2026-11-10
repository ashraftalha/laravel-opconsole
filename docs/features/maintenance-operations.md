---
title: Maintenance Operations
description: "Take a Laravel site down and bring it back from the dashboard, with a one-time bypass URL and a console that stays reachable during maintenance."
---

# Maintenance Operations

## What it does

Two operations manage Laravel's maintenance mode directly from the dashboard:

- **Maintenance mode (`down`):** runs `php artisan down --secret=<random>`. A one-time bypass URL containing the secret is shown once in the output panel — it is never logged or audited.
- **Bring online (`up`):** runs `php artisan up`.

<figure class="oc-shot">
  <img src="/screenshots/operations-light.png" alt="OpConsole Operations tab with the Bring online card in the safe group and the Maintenance mode card in the danger zone" width="1920" height="1770" loading="lazy">
  <figcaption>Bring online sits in the safe group; Maintenance mode is in the danger zone, below Run migrations.</figcaption>
</figure>

While the application is down, a warning health check (`app.maintenance_mode`) stays visible, with fix `php artisan up`.

## Why it is useful

Taking a site down for a migration or a deploy, and bringing it back up, is one of the most common operational tasks — and one of the easiest to botch from a terminal under pressure (wrong secret, forgetting to note the bypass URL). This gives you both actions as confirmed buttons, with the bypass URL handed to you directly.

## How to configure it

```php
// config/opconsole.php
'actions' => [
    'down' => true,
    'up' => true,
],
'maintenance' => [
    'allow_console' => true,
],
```

`maintenance.allow_console` adds the OpConsole route prefix to Laravel's `PreventRequestsDuringMaintenance::except()` list, so the console itself (still behind authentication and Gates) stays reachable while the rest of the application is down — this is how you can press **Bring online** at all. It's ignored when `route.prefix` is empty, because exempting `/*` would exempt the entire application from maintenance mode; a console mounted at the root follows maintenance mode like every other route in that case.

## How to use it

Press **Maintenance mode**, confirm (medium risk — single confirmation, not hold-to-confirm), and copy the bypass URL from the output panel before navigating away; it's shown only once. Press **Bring online** when you're ready to restore traffic.

## Security considerations

- The secret is generated randomly per run and never persisted to the audit log or anywhere else readable later — if you lose it, generate a new one by running `down` again.
- Both actions go through the same Artisan action pipeline as every other operation: confirmed, locked, bounded, and audited (the *fact* that maintenance was toggled is audited; the secret itself is not).
- Map `down`/`up` to a narrower Gate than `runOpConsoleActions` if you want maintenance-mode control reserved for a smaller group — see [Authentication & Authorization](/guide/authentication#per-operation-permissions).

## Limitations

- Like every Artisan action, `down`/`up` are refused on a runtime without `pcntl` from the browser (see [Execution environments](/reference/configuration#execution-environments)) — run them from the CLI instead on such hosts.
- Inside a Swoole worker, these are refused by default because their timeout mechanism would interrupt the worker itself — see [Octane & Swoole](/features/octane-swoole).

## Troubleshooting

- **Site stays unreachable after pressing "Bring online":** confirm the operation actually succeeded in History — a runtime without `pcntl`, or a Swoole guard refusal, would leave the application down.
- **Lost the bypass secret:** there's no way to recover it; run **Maintenance mode** again to generate a fresh one (this also resets the down state, so only do this if the app is still down or you're fine toggling it).

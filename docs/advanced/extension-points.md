---
title: Extension Points & Contracts
---

# Extension Points & Contracts

OpConsole is deliberately narrow in what it lets a host application extend. This is a design choice, documented in [Architecture](/reference/architecture): "no facades, no repository layer, no DTO hierarchy, and no per-command action classes."

## What you can extend

| Extension point | Mechanism | Covered in |
|---|---|---|
| Health checks | Implement `AshrafTalha\OpConsole\Contracts\HealthCheck`, register the class in `config('opconsole.checks')` | [Custom Health Checks](/advanced/custom-health-checks) |
| Per-action authorization | Map action keys to Gate names in `authorization.action_gates` | [Authentication & Authorization](/guide/authentication) |
| Health transition notifications | Enable `notifications`, configure `channels` (mail, Slack-compatible webhook, generic webhook) | [Configuration Reference](/reference/configuration#health-notifications) |
| Visual/host integration | Set `layout` to embed inside your own Blade shell; `back_url`/`back_label` for a return link | [Configuration Reference](/reference/configuration#ui-layout-audit-storage) |
| Views and stylesheet | `php artisan vendor:publish --tag=opconsole-views` / `--tag=opconsole-assets` | [Installation](/guide/installation) |
| Translations | `php artisan vendor:publish --tag=opconsole-lang` | [Installation](/guide/installation) |

## What you cannot extend

- **You cannot add new Artisan actions.** `ActionRunner::ACTIONS` is a closed constant allow-list; the route itself constrains `{action}` to the known keys. This is a deliberate security boundary, not a current limitation waiting on a future release — see [Operational Safety](/advanced/operational-safety).
- **You cannot pass arbitrary arguments from HTTP requests into the underlying commands.** Actions use fixed flags, except that a pending migration name can select a file resolved by Laravel's migrator; failed-job retry accepts a validated job ID.
- **There is no plugin or hook system** beyond the `HealthCheck` contract and the configuration keys documented in the [Configuration Reference](/reference/configuration).

## Why the extension surface is this narrow

From [Architecture](/reference/architecture): `HealthCheck` is the sole callable extension point so that `config/opconsole.php` can contain zero closures (keeping it `config:cache`-safe), and so the package's attack surface — specifically, the set of things an authorized-but-compromised account could trigger — stays small and auditable. If you need operational capability beyond the allow-list, build it as your own Artisan command and run it through your own tooling, not through OpConsole.

## Embedding in a host layout

```php
// config/opconsole.php
'layout' => 'layouts.admin', // must @yield('content') and provide @stack('styles') / @stack('scripts')
```

When embedding inside a layout with its own fixed header, set `--oc-tabs-offset` in your stylesheet so OpConsole's sticky tab bar sits below it:

```css
.oc-embedded-shell {
    --oc-tabs-offset: 64px;
}
```

The `theme` config defaults to `light` (rather than `auto`) when `layout` is set, specifically so the console doesn't clash visually with a light host shell by surprise — set it explicitly if you want `auto` or `dark` instead.

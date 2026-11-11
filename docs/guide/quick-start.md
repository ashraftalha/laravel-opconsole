---
title: Quick Start
---

# Quick Start

## 5-minute setup

Aim for a first read-only dashboard in about five minutes on an existing Laravel 12 or 13 app with PHP 8.3+, a working login, and Composer/Artisan access through a terminal or deployment pipeline. Hosting setup and migration review take additional time.

| Time | Step | Checkpoint |
|---|---|---|
| Minute 1 | Install and publish config (steps 1–2) | Package is installed and configurable |
| Minute 2 | Review and run pending migrations (step 3) | Audit storage is ready |
| Minutes 3–4 | Apply the read-only Gates (step 4) | A trusted operator can view but cannot execute |
| Minute 5 | Open the console and verify access (step 5 and checklist below) | First dashboard is visible |

**Production:** use the complete [read-only recipe](/advanced/production-recipes#read-only-production-recommended) for both Gates and disabled capabilities. **Shared hosting:** read [what needs deployment access](/guide/installation#shared-hosting-and-no-ssh-deployments) before installing. Do not change `APP_ENV` to `local` to bypass production authorization.

## 1. Install the package

```bash
composer require ashraftalha/laravel-opconsole
```

Laravel's package auto-discovery registers `AshrafTalha\OpConsole\OpConsoleServiceProvider` automatically.

## 2. Publish the config

```bash
php artisan vendor:publish --tag=opconsole-config
```

Optional, but recommended so you can see every option in `config/opconsole.php`.

## 3. Create the audit table

Review pending migrations first: this command applies **all** pending application and package migrations. Use your normal production migration approval and backup process; allow extra time if review is needed.

```bash
php artisan migrate:status
php artisan migrate
```

For production, run the reviewed migration plan through your deployment tooling. The [migration-order warning](/features/artisan-actions#running-one-migration-at-a-time) also applies to later browser operations.

## 4. Set read-only access

Start with a trusted read-only operator. Import `Gate` at the top of `app/Providers/AppServiceProvider.php`, then define the Gates inside `boot()`:

```php
use Illuminate\Support\Facades\Gate;

// Inside boot(): replace this placeholder with a trusted operator's identity.
Gate::define('viewOpConsole', fn ($user) => $user->email === 'operator@example.com');
Gate::define('runOpConsoleActions', fn ($user) => false);
Gate::define('downloadOpConsoleLogs', fn ($user) => false);
Gate::define('deleteOpConsoleLogs', fn ($user) => false);
```

This permits dashboard and log-tail access but denies commands, job retries, full log downloads, and log deletion with the default configuration. Keep `authorization.action_gates` empty for this read-only policy: a mapped action Gate can independently grant execution. Log tails may contain sensitive data, so viewing still requires a trusted operator.

::: details Optional: add administrator execution later
When administrators need to execute actions, **replace** those definitions with a separate operator/admin policy:

```php
// Inside boot(): replace both placeholders with identities you control.
Gate::define('viewOpConsole', fn ($user) => in_array($user->email, [
    'operator@example.com',
    'admin@example.com',
], true));
Gate::define('runOpConsoleActions', fn ($user) => $user->email === 'admin@example.com');
Gate::define('downloadOpConsoleLogs', fn ($user) => $user->email === 'admin@example.com');
Gate::define('deleteOpConsoleLogs', fn ($user) => false);
```

The operator remains read-only; the administrator can run enabled actions (including migrations and job retries) and download logs. Log deletion stays disabled until explicitly granted. For an existing role or permission system, use equivalent permission checks instead of email addresses.

:::

See [Authentication & Authorization](/guide/authentication) for per-action Gates and a least-privilege production policy.

## 5. Open the console

Sign in to your app, then visit:

```text
https://your-app.test/admin/opconsole
```

With the Gates above, sign in as the allowed operator. Outside `local`, undefined Gates deny access; local development also works with the built-in local-only defaults.

## Verify your first dashboard

- Sign in as the allowed operator and open `/admin/opconsole`: expect `200` and the Overview tab.
- With the read-only recipe, verify that commands, retries, log downloads, and deletion are unavailable.
- Sign in as an unrelated account: expect `403`. Guests should reach your login route or receive a controlled `401`.
- `unknown` scheduler/worker checks are expected until those services run. A first dashboard does not require every health check to be green.

If access fails or an action is unexpectedly disabled, start at the [troubleshooting decision tree](/reference/troubleshooting#access-and-actions-decision-tree).

## 6. After first use: start the scheduler and a queue worker

The heartbeat checks need both running to report anything other than `unknown`:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

```bash
php artisan queue:work
```

## That's it

You now have:

- A health dashboard at `/admin/opconsole`
- An audit trail of every operation you run from it
- `php artisan opconsole:health` for CLI / CI health checks

## Where to go from here

- Walk through what each tab does: [Health Dashboard](/features/health-dashboard)
- Lock down production access further: [Permissions & Security Policies](/advanced/permissions-security-policies)
- Wire up uptime monitoring: [Health Checks & Health Score](/features/health-checks#the-health-endpoint)
- See every configuration key: [Configuration Reference](/reference/configuration)

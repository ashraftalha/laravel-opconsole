---
title: Quick Start
---

# Quick Start

This is the fastest path from zero to a working console in a Laravel 12 or 13 application.

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

```bash
php artisan migrate
```

## 4. Open the console

Sign in to your app, then visit:

```text
https://your-app.test/admin/opconsole
```

With `APP_ENV=local`, it works immediately. In every other environment, access is denied until you define the Gates in the next step.

## 5. Allow access in production

In `app/Providers/AppServiceProvider.php`, inside `boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewOpConsole', fn ($user) => $user->email === 'you@example.com');
Gate::define('runOpConsoleActions', fn ($user) => $user->email === 'you@example.com');
```

See [Authentication & Authorization](/guide/authentication) for per-action Gates and a least-privilege production policy.

## 6. Start the scheduler and a queue worker

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

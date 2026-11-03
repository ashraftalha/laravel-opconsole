<p align="center"><img src="art/logo.svg" width="300" alt="Laravel OpConsole"></p>

<p align="center">An operations console for Laravel: health checks, safe Artisan actions, and a log viewer behind Gates.</p>

<p align="center">
<a href="https://packagist.org/packages/ashraftalha/laravel-opconsole"><img src="https://img.shields.io/packagist/v/ashraftalha/laravel-opconsole" alt="Latest Version"></a>
<a href="https://packagist.org/packages/ashraftalha/laravel-opconsole"><img src="https://img.shields.io/packagist/dt/ashraftalha/laravel-opconsole" alt="Total Downloads"></a>
<a href="https://github.com/ashraftalha/laravel-opconsole/actions/workflows/tests.yml"><img src="https://github.com/ashraftalha/laravel-opconsole/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
<a href="LICENSE"><img src="https://img.shields.io/github/license/ashraftalha/laravel-opconsole" alt="License"></a>
</p>

## Features

- **Health checks:** files, PHP, security, database and queues, scheduler and queue worker heartbeats, cache round trip, production caches, maintenance mode, storage link. Anything not verified shows as `unknown`, never healthy.
- **Safe actions:** clear caches, optimize, run migrations, maintenance mode on/off (with a one-time bypass URL), and an optional storage link. Per-action Gates are available, and owner-renewed locks bound long-running commands.
- **Action-level permissions:** support staff can be granted cache clearing access while migrations and maintenance mode stay administrator-only.
- **Optional health alerts:** scheduled CLI monitoring can notify on unhealthy transitions, worsening status, and recovery without queue workers. Required checks and unknown-status policy are configurable.
- **Audit history:** who ran what, when, and how long it took.
- **Log viewer:** filter by environment and level, search, download, delete old files.
- **Monitoring:** `php artisan opconsole:health` for CI and cron, and an optional token-protected `/health` JSON endpoint for uptime monitors.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Quick Start

**Step 1. Install the package**

```bash
composer require ashraftalha/laravel-opconsole
```

**Step 2. Publish the config file**

```bash
php artisan vendor:publish --tag=opconsole-config
```

This creates `config/opconsole.php`, where you can change settings later.

**Step 3. Create the database table**

```bash
php artisan migrate
```

**Step 4. Log in to your app**

You must be signed in as a user.

**Step 5. Open the console**

Go to `http://your-app.test/admin/opconsole`

On your local machine (`APP_ENV=local`), it works right away.

**Step 6. Allow access on your live server**

Open `app/Providers/AppServiceProvider.php` and add this inside `boot()`. Replace the email with yours:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewOpConsole', fn ($user) => $user->email === 'you@example.com');
Gate::define('runOpConsoleActions', fn ($user) => $user->email === 'you@example.com');
```

- `viewOpConsole`: can see the dashboard and logs.
- `runOpConsoleActions`: can click the buttons (clear caches, optimize, run migrations).

Done.

## Updating

**Step 1. Update the package**

```bash
composer update ashraftalha/laravel-opconsole
```

**Step 2. Run new migrations**

```bash
php artisan migrate
```

**Step 3. Clear cached config**

```bash
php artisan config:clear
```

**Step 4. Refresh published files (only if you published them)**

Composer never changes files in your app. If you ran `vendor:publish` before:

- **Styles:** `php artisan vendor:publish --tag=opconsole-assets --force`
- **Config:** compare your `config/opconsole.php` with the [latest one](config/opconsole.php) and copy over any new keys (for example new `actions` entries). Re-publishing with `--force` also works, but it overwrites your changes.

See [CHANGELOG.md](CHANGELOG.md) for what changed in each version.

## Custom Health Checks (optional)

Create a class:

```php
namespace App\HealthChecks;

use AshrafTalha\OpConsole\Contracts\HealthCheck;
use Illuminate\Support\Facades\Storage;

class BackupDiskCheck implements HealthCheck
{
    public function run(): array
    {
        $ok = Storage::disk('local')->exists('backups');

        return [
            'group' => 'Backups',
            'label' => 'Backup folder',
            'ok' => $ok,
            'severity' => 'warning',
            'hint' => 'The backup folder must exist.',
            'value' => $ok ? 'Found' : 'Missing',
            'fix' => $ok ? null : 'Create storage/app/backups.',
        ];
    }
}
```

Register it in `config/opconsole.php`:

```php
'checks' => [
    App\HealthChecks\BackupDiskCheck::class,
],
```

## Configuration

Common options for your `.env` file:

```dotenv
OPCONSOLE_ENABLED=true
OPCONSOLE_ROUTE_PREFIX=admin/opconsole
OPCONSOLE_LOCK_STORE=opconsole
OPCONSOLE_HISTORY_LIMIT=15

# Optional uptime endpoint: GET /admin/opconsole/health with "Authorization: Bearer <token>"
OPCONSOLE_HEALTH_ENDPOINT=true
OPCONSOLE_HEALTH_TOKEN=change-me-to-a-long-random-string
```

Heartbeat checks need the scheduler running (`* * * * * php artisan schedule:run`) and, for the queue check, a worker (`php artisan queue:work`).

Use an isolated `redis` or `database` store for `OPCONSOLE_LOCK_STORE` on multi-server deployments. Set `OPCONSOLE_MAX_RUNTIME_SECONDS` to bound synchronous maintenance actions. For alerts, enable notifications in the published config, set a persistent shared state store, and schedule `php artisan opconsole:health --notify`. See [docs/CONFIGURATION.md](docs/CONFIGURATION.md) for every option and [docs/UPGRADE.md](docs/UPGRADE.md) for the 1.6.0 migration steps.

## Testing

```bash
composer test
```

## Roadmap

- [x] Accurate action durations
- [x] Scheduler and queue worker heartbeats
- [x] Cache round-trip and production cache checks
- [x] Maintenance mode check and down/up actions
- [x] Storage link check and action
- [x] `opconsole:health` command and JSON health endpoint
- [x] Last-checked time, disk percentage warning, group ordering

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).

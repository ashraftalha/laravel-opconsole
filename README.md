<p align="center"><img src="art/logo.svg" width="300" alt="Laravel OpConsole"></p>

<p align="center">An operations console for Laravel: health checks, safe Artisan actions, and a log viewer behind Gates.</p>

<p align="center">
<a href="https://packagist.org/packages/ashraftalha/laravel-opconsole"><img src="https://img.shields.io/packagist/v/ashraftalha/laravel-opconsole" alt="Latest Version"></a>
<a href="https://packagist.org/packages/ashraftalha/laravel-opconsole"><img src="https://img.shields.io/packagist/dt/ashraftalha/laravel-opconsole" alt="Total Downloads"></a>
<a href="https://github.com/ashraftalha/laravel-opconsole/actions/workflows/tests.yml"><img src="https://github.com/ashraftalha/laravel-opconsole/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
<a href="LICENSE"><img src="https://img.shields.io/packagist/l/ashraftalha/laravel-opconsole" alt="License"></a>
</p>

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
```

Use an isolated `redis` or `database` store for `OPCONSOLE_LOCK_STORE` on multi-server deployments. See [docs/CONFIGURATION.md](docs/CONFIGURATION.md) for every option.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).

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

## Installation

```bash
composer require ashraftalha/laravel-opconsole
php artisan migrate
```

The service provider is auto-discovered. The migration creates the `opconsole_runs` audit table.

## Usage

Open `/admin/opconsole`. Access is allowed only in the `local` environment until you define Gates.

Grant access in `App\Providers\AppServiceProvider::boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewOpConsole', fn (User $user): bool => in_array($user->email, ['admin@example.com'], true));
Gate::define('runOpConsoleActions', fn (User $user): bool => in_array($user->email, ['admin@example.com'], true));
```

`viewOpConsole` shows the dashboard and logs. `runOpConsoleActions` runs `optimize:clear`, `optimize`, and `migrate --force --isolated`. The optional `downloadOpConsoleLogs` and `deleteOpConsoleLogs` Gates fall back to these two.

Add a custom health check:

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

```bash
php artisan vendor:publish --tag=opconsole-config
```

Common options:

```dotenv
OPCONSOLE_ENABLED=true
OPCONSOLE_ROUTE_PREFIX=admin/opconsole
OPCONSOLE_LOCK_STORE=opconsole
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

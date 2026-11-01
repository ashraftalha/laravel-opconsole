# Laravel OpConsole

**Monitor. Diagnose. Maintain.**

Laravel OpConsole is an administrative operations console that lets authorized developers and administrators monitor, diagnose, and maintain a Laravel application through a secure web interface: health checks, three safe Artisan operations (`optimize:clear`, `optimize`, `migrate --force --isolated`), and a log viewer.

> **This is an independent, third-party Composer package.** It is not an official Laravel framework product and is not affiliated with Laravel LLC.

## Requirements

| | |
|---|---|
| PHP | ^8.3 |
| Laravel | ^13.0 |

Laravel 12 support has not been tested and is not claimed.

## Installation

```bash
composer require ashraftalha/laravel-opconsole
```

The service provider is auto-discovered. Publish and run the migration:

```bash
php artisan vendor:publish --tag=opconsole-config
php artisan vendor:publish --tag=opconsole-assets
php artisan migrate
```

Optional publish tags: `opconsole-views`, `opconsole-lang`, `opconsole-migrations` (if you'd rather own the migration file than let the package auto-load it).

## Authorization — read this before deploying

By default OpConsole is **deny-by-default everywhere except the `local` environment**. Anyone running the package in staging or production without defining Gates gets a 403.

Define two Gates in your own `AppServiceProvider` (or anywhere Gates are registered):

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewOpConsole', fn ($user) => $user->isAdmin());
Gate::define('runOpConsoleActions', fn ($user) => $user->isAdmin() && $user->canDeploy());
```

The names are configurable via `config('opconsole.authorization')`. `view_gate` controls the dashboard and log reading/downloading; `run_gate` controls the three Artisan actions and log deletion — treat it as the more sensitive one.

OpConsole does **not** assume an authenticated user is an administrator, and does **not** grant access just because the app is running locally in a way that bypasses your own Gate — if you define the Gate, your Gate is authoritative in every environment.

### Guests and the `login` route

OpConsole checks authentication itself (`config('opconsole.authentication')`) rather than relying on Laravel's `auth` middleware alias. That alias calls `route('login')` unconditionally and throws an uncaught `RouteNotFoundException` — an HTTP 500 — on any host with no route named `login`. A guest hitting OpConsole instead gets:

- redirected to `route('login')`, if `authentication.login_route` is set and that route exists;
- a plain `401`, if it's set to `null` or points at a route that doesn't exist.

Don't add Laravel's `auth` middleware alias to `route.middleware` expecting it to help here — it reintroduces the exact crash this is designed to avoid. The default `route.middleware` is just `['web']` for that reason.

## Configuration

See `config/opconsole.php` for the full set of options (route prefix/middleware, which of the three actions are enabled, log directory and download/delete flags, the `checks` extension point, layout override, asset URLs, back link, audit table name). The file has no closures, so `php artisan config:cache` and `route:cache` both work.

## Health checks

The package ships generic, host-agnostic checks (filesystem permissions, PHP version/extensions/memory, security basics, database/queue tables, mail/URL sanity). Add your own:

```php
// config/opconsole.php
'checks' => [
    App\Health\CustomCheck::class,
],
```

```php
use AshrafTalha\OpConsole\Contracts\HealthCheck;

class CustomCheck implements HealthCheck
{
    public function run(): array
    {
        return [
            'group' => 'Custom', 'label' => 'Something specific to this app',
            'ok' => true, 'severity' => 'info', 'hint' => '...', 'value' => null, 'fix' => null,
        ];
    }
}
```

Malformed results from custom checks are logged and skipped — they never break the dashboard.

## Artisan actions

A closed allow-list of exactly three commands: `optimize:clear`, `optimize`, `migrate --force --isolated`. You can disable any of them (`config('opconsole.actions.migrate', false)`), but you cannot add new ones through configuration — this is deliberate, so the package can never become a path to arbitrary command execution from an HTTP request.

Concurrent runs are serialized with a cache lock, held in its own store (`config('opconsole.concurrency.lock_store')`, default `file`) — deliberately **not** `cache.default`. `optimize:clear` runs `cache:clear`, which flushes the default store outright; a lock living there would delete its own lock mid-run and let a second action start concurrently. Don't point `lock_store` at the same value as `cache.default`.

## Audit trail

Every action (and every log delete/download) writes a row to `opconsole_runs` (table name configurable). Rows move through `started` → `succeeded`/`failed`. If the table isn't migrated yet, the dashboard and the actions still work — the audit write is best-effort and never turns a successful command into a reported failure.

## Logs

Reads `storage/logs/*.log` (or a configured directory) with level/environment/text filtering, repeated-error grouping, download, and delete (the newest file can never be deleted — it's still being written). Filenames are validated against `^[\w.-]+\.log$` and resolved with `realpath` so a `.log` symlink cannot escape the configured directory.

## Integrating into an existing app alongside another console

`docs/examples/sel-lab-dev/` has a worked example of mounting OpConsole at a separate URL (`/admin/opconsole`) in a host that already has its own admin console at a different path, so both can run side by side during evaluation without touching the existing implementation. See `docs/SEL-LAB-INTEGRATION.md` for the full plan, including the eventual switch-over.

## Known limitations

- No queue-based execution: all three actions run synchronously in the web request. Very large migrations can exceed your web server's timeout.
- No built-in localization beyond English; a `lang/en` file is provided for the few user-facing strings that go through `__()`.
- No real "maintenance mode" toggle (`artisan down`/`up`) — this package is unrelated to Laravel's maintenance mode despite the similar subject matter.

## License

MIT.

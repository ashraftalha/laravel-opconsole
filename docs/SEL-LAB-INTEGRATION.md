# Integrating OpConsole into sel-lab

This document describes how `sel-lab` would adopt this package to replace its own `/admin/maintenance`. **None of these steps have been run against `sel-lab`.** `sel-lab` is unmodified; its `/admin/maintenance` controller, services, views, and tests are all still in place. This is a plan to follow manually, in a separate branch, when `sel-lab` is ready to switch over.

## 0. Prerequisites

- This package has passed its own test suite, Larastan, Pint, and the clean-install smoke test (see the main `README.md` and CI workflow) on Laravel 13 / PHP 8.3+, matching `sel-lab`'s stack.
- `sel-lab`'s staging cache store supports atomic locks (required both for `migrate --isolated` and for OpConsole's own `Cache::lock` around actions). Verify `CACHE_STORE` in staging — the source analysis flagged this as unverified (`database` cache driver does support locks via a table; confirm the `cache_locks` table exists or will be created).

## 1. Private distribution

```bash
# from sel-lab's root
composer config repositories.opconsole vcs git@github.com:ashraftalha/laravel-opconsole.git
composer require ashraftalha/laravel-opconsole:^0.1
```

(A path repository, as used for the standalone smoke test, is only for local development — pin a VCS tag for anything deployed.)

## 2. Publish and configure

```bash
php artisan vendor:publish --tag=opconsole-config
php artisan vendor:publish --tag=opconsole-assets
```

`config/opconsole.php`, to preserve the current URL and behaviour exactly:

```php
'route' => [
    'prefix' => 'admin/maintenance',       // keeps the existing URL
    'name_prefix' => 'opconsole.',          // route names change — see "Route names" below
    'middleware' => ['web', 'auth'],
],

'authorization' => [
    'view_gate' => 'viewOpConsole',
    'run_gate' => 'runOpConsoleActions',
],

'back_url' => '/admin/dashboard',
'back_label' => 'Dashboard',

'checks' => [
    App\Health\HeroImageCheck::class,
    App\Health\ReportUploadLimitCheck::class,
    App\Health\TimezoneCheck::class,
],
```

## 3. Preserve today's authorization policy explicitly

`sel-lab` currently allows any authenticated user. Make that an explicit, greppable decision rather than relying on OpConsole's local-only fallback:

```php
// AppServiceProvider::boot()
Gate::define('viewOpConsole', fn ($user) => true);
Gate::define('runOpConsoleActions', fn ($user) => true);
```

Revisit this once `sel-lab` has more than one class of user.

## 4. Register the lab's own health checks

Move the business-specific checks out of the deleted `MaintenanceService::checks()` into standalone classes implementing `AshrafTalha\OpConsole\Contracts\HealthCheck`:

```php
namespace App\Health;

use AshrafTalha\OpConsole\Contracts\HealthCheck;

final class ReportUploadLimitCheck implements HealthCheck
{
    public function run(): array
    {
        $upload = /* same ini-parsing logic as before */;
        return [
            'group' => 'PHP settings', 'label' => 'Upload limits support PDF reports',
            'ok' => $upload >= 20 * 1024 * 1024, 'severity' => 'danger',
            'hint' => 'Reports accept PDFs up to 20 MB. Lower limits prevent large uploads.',
            'value' => 'upload_max_filesize='.ini_get('upload_max_filesize'),
            'fix' => 'php.ini: upload_max_filesize=20M  post_max_size=21M',
        ];
    }
}
```

Do the same for the hero-image check and the timezone check. Keep the label/hint text byte-identical to what the existing `MaintenanceTest` fixtures expect, if those assertions are being kept.

## 5. Migrate

```bash
php artisan migrate
```

This creates `opconsole_runs`. **Do not** attempt to migrate data out of `site_settings`'s `maintenance.last.*` rows — the source analysis explicitly recommends leaving them in place and letting them age out; `opconsole_runs` starts empty and "last run" will simply read "never" until each action is used once post-switch.

## 6. Route names — the one real breaking change

Old names: `admin.maintenance`, `admin.maintenance.log`, `admin.maintenance.log.delete`, `admin.maintenance.run`.

New names, with `name_prefix = 'opconsole.'`: `opconsole.index`, `opconsole.log`, `opconsole.log.delete`, `opconsole.run`.

Per the source analysis, only the host's own tests reference `route('admin.maintenance')` — update them to the new names. No redirect/alias route is needed; nothing else in the application reads these names.

## 7. Delete the old implementation — in the same commit as the switch

- `app/Http/Controllers/Admin/MaintenanceController.php`
- `app/Services/MaintenanceService.php`, `LogViewerService.php`, `MaintenanceViewService.php`
- `resources/views/admin/layouts/maintenance.blade.php`, `resources/views/admin/maintenance/*`
- The maintenance route block in `routes/web.php`
- The `.mt-*` / maintenance-specific rules in `public/dashboard/css/reports.css` (roughly lines 360–510, per the source analysis — re-check line numbers before deleting, the file may have changed)
- `tests/Feature/MaintenanceTest.php`, `tests/Feature/MaintenanceLogsTest.php` — replace with the thin regression test below

Leave `site_settings` and its table alone; it has other, unrelated uses.

## 8. Thin regression test (replaces the two deleted test files)

```php
test('guest is redirected from the operations console', function () {
    $this->get('/admin/maintenance')->assertRedirect('/login');
});

test('an authenticated user can reach the operations console and run the three actions', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/admin/maintenance')->assertOk();
    // ...assert the three POST routes respond and write an opconsole_runs row
});

test('the console is reached by URL only, not linked from the dashboard', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/admin/dashboard')->assertDontSee('admin/maintenance');
});
```

## 9. Feature-parity validation

Walk `docs/FEATURE-PARITY.md` in this package and confirm each row still holds against the live `sel-lab` page before removing the old code. Compare rendered HTML (whitespace-normalized diff) between the old and new page for a signed-in admin, on a seeded staging database with at least one log file and one pending migration, to catch anything visually missed.

## 10. Staging validation checklist

- [ ] `/admin/maintenance` resolves to the new package (check `artisan route:list`, no duplicate route names)
- [ ] Guest is redirected to `/login`
- [ ] Authenticated user sees the dashboard, tiles populate, health checks group correctly
- [ ] `optimize:clear`, `optimize`, `migrate` each run and record a row in `opconsole_runs`
- [ ] Log viewer shows existing `storage/logs/*.log`, filters work, download works, delete refuses the newest file
- [ ] `php artisan config:cache` and `php artisan route:cache` both succeed
- [ ] `vendor:publish --tag=opconsole-assets --force` runs as part of deploy (add to `composer.json`'s `post-update-cmd`, alongside the existing `vendor:publish --tag=laravel-assets`)

## 11. Production deployment checklist

- [ ] Staging validation (above) is green
- [ ] `composer.json` pins a specific tagged version, not a branch
- [ ] Gates from step 3 are present in the deployed `AppServiceProvider`
- [ ] `php artisan migrate` is part of the deploy pipeline (it already is for `sel-lab`)
- [ ] Asset publishing is part of the deploy pipeline
- [ ] The old `/admin/maintenance` files are confirmed deleted from the deployed build (not just locally)

## 12. Rollback

Revert the switch commit. `composer install` restores the local classes (no `composer.json` state to undo if the revert includes that file). The `opconsole_runs` table is additive and harmless to leave in place; `php artisan migrate:rollback --step=1` removes it if desired, but is not required for rollback to succeed.

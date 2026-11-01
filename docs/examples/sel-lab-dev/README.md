# sel-lab dev/staging side-by-side config

This directory is a prepared example, kept inside `laravel-opconsole`. **It is not applied to `sel-lab` by anything in this repository.** `sel-lab` has not been modified.

## What this is for

A first, low-risk step before the full switch-over in `docs/SEL-LAB-INTEGRATION.md`: install OpConsole into a `sel-lab` working copy (or a disposable clone of it) and run it **at a different URL** (`/admin/opconsole`) while the existing `/admin/maintenance` implementation keeps running completely untouched. This lets someone compare the two side by side on staging before committing to deleting anything.

## How to use it (manually, in a `sel-lab` working copy — not here)

1. In that `sel-lab` checkout:
   ```bash
   composer config repositories.opconsole path /path/to/laravel-opconsole   # or a VCS tag, once tagged
   composer require ashraftalha/laravel-opconsole:@dev
   php artisan vendor:publish --tag=opconsole-assets
   php artisan migrate
   ```
2. Copy `opconsole.php` from this directory to that checkout's `config/opconsole.php` (overwriting the one `vendor:publish --tag=opconsole-config` creates).
3. Add a **dev-only** service provider (do not touch `AppServiceProvider.php`) that defines the two Gates for this verification phase only, e.g. `app/Providers/OpConsoleDevProvider.php`, registered only when `app()->environment('local', 'staging')`:
   ```php
   namespace App\Providers;

   use Illuminate\Support\Facades\Gate;
   use Illuminate\Support\ServiceProvider;

   class OpConsoleDevProvider extends ServiceProvider
   {
       public function boot(): void
       {
           if (! $this->app->environment('local', 'staging')) {
               return;
           }

           Gate::define('viewOpConsole', fn ($user) => true);
           Gate::define('runOpConsoleActions', fn ($user) => true);
       }
   }
   ```
4. Visit `/admin/opconsole` and `/admin/maintenance` side by side. Both should work independently; neither should 404 or collide on route names (`opconsole.*` vs `admin.maintenance*`).
5. When satisfied, follow `docs/SEL-LAB-INTEGRATION.md` for the real switch-over (which does preserve the `/admin/maintenance` URL and removes the old implementation) — that is a separate, deliberate step, not an automatic next step from this one.

## What this config deliberately does not do

- It does not set `route.prefix` to `admin/maintenance` — that collision is handled only in the full switch-over plan, one step at a time.
- It does not register any of `sel-lab`'s lab-specific health checks (hero image, upload limits, timezone copy) — those stay out until extracted per `docs/FEATURE-PARITY.md`.
- It does not modify `AppServiceProvider` — the Gates above belong in a separate, environment-guarded provider precisely so this verification phase can be removed by deleting one file.

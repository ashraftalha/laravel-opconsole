<?php

namespace AshrafTalha\OpConsole;

use AshrafTalha\OpConsole\Console\HealthCommand;
use AshrafTalha\OpConsole\Console\SecurityCommand;
use AshrafTalha\OpConsole\Http\Middleware\AuthorizeOpConsole;
use AshrafTalha\OpConsole\Jobs\OpConsoleHeartbeatJob;
use AshrafTalha\OpConsole\Support\Heartbeat;
use AshrafTalha\OpConsole\Support\LogViewer;
use AshrafTalha\OpConsole\Support\PagePresenter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class OpConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/opconsole.php', 'opconsole');

        $this->app->scoped(LogViewer::class);

        if (! $this->app['config']->has('cache.stores.opconsole')) {
            $this->app['config']->set('cache.stores.opconsole', [
                'driver' => 'file',
                'path' => storage_path('framework/cache/opconsole'),
                'lock_path' => storage_path('framework/cache/opconsole'),
            ]);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'opconsole');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'opconsole');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app['router']->aliasMiddleware('opconsole.authorize', AuthorizeOpConsole::class);

        $this->registerDefaultGates();
        $this->registerHeartbeats();

        if (config('opconsole.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

            // Written by opconsole.js, so it can never carry Laravel's encryption.
            EncryptCookies::except([PagePresenter::THEME_COOKIE]);

            if (config('opconsole.maintenance.allow_console', true)) {
                $prefix = trim((string) config('opconsole.route.prefix', 'admin/opconsole'), '/');
                PreventRequestsDuringMaintenance::except([$prefix, $prefix.'/*']);
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([HealthCommand::class, SecurityCommand::class]);
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                if (config('opconsole.security.schedule', false)) {
                    $schedule->command('opconsole:security')->daily()->withoutOverlapping()->name('opconsole:security');
                }
            });

            $this->publishes([
                __DIR__.'/../config/opconsole.php' => config_path('opconsole.php'),
            ], 'opconsole-config');

            $this->publishes([
                __DIR__.'/../public' => public_path('vendor/opconsole'),
            ], 'opconsole-assets');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/opconsole'),
            ], 'opconsole-views');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/opconsole'),
            ], 'opconsole-lang');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'opconsole-migrations');
        }
    }

    private function registerHeartbeats(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (config('opconsole.heartbeats.scheduler', true)) {
                $schedule->call(fn () => app(Heartbeat::class)->beat(Heartbeat::SCHEDULER))
                    ->everyMinute()
                    ->name('opconsole:heartbeat:scheduler');
            }

            if (config('opconsole.heartbeats.queue', true)) {
                $schedule->call(function () {
                    $heartbeat = app(Heartbeat::class);
                    $heartbeat->beatOnce(Heartbeat::QUEUE_DISPATCHED);

                    OpConsoleHeartbeatJob::dispatch()
                        ->onConnection($heartbeat->queueConnection())
                        ->onQueue($heartbeat->queueName());
                })
                    ->everyMinute()
                    ->name('opconsole:heartbeat:queue');
            }
        });
    }

    /**
     * Fail-closed by default: if the host application has not defined the default
     * Gates itself, OpConsole only allows access in the "local" environment.
     * Log download/delete default Gates delegate to view/run unless defined
     * separately by the host; custom Gate names that are not defined fail closed.
     */
    private function registerDefaultGates(): void
    {
        $defaults = [
            'view' => 'viewOpConsole',
            'run' => 'runOpConsoleActions',
        ];

        foreach ($defaults as $ability => $defaultName) {
            $name = AuthorizeOpConsole::gateNameFor($ability);

            if ($name === $defaultName && ! Gate::has($name)) {
                Gate::define($name, fn ($user = null) => app()->environment('local'));
            }
        }

        $downloadGate = AuthorizeOpConsole::gateNameFor('download');
        if ($downloadGate === 'downloadOpConsoleLogs' && ! Gate::has($downloadGate)) {
            Gate::define($downloadGate, fn (?Authenticatable $user = null) => AuthorizeOpConsole::allows('view', $user));
        }

        $deleteGate = AuthorizeOpConsole::gateNameFor('delete');
        if ($deleteGate === 'deleteOpConsoleLogs' && ! Gate::has($deleteGate)) {
            Gate::define($deleteGate, fn (?Authenticatable $user = null) => AuthorizeOpConsole::allows('run', $user));
        }
    }
}

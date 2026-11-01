<?php

namespace AshrafTalha\OpConsole;

use AshrafTalha\OpConsole\Http\Middleware\AuthorizeOpConsole;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class OpConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/opconsole.php', 'opconsole');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'opconsole');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'opconsole');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app['router']->aliasMiddleware('opconsole.authorize', AuthorizeOpConsole::class);

        $this->registerDefaultGates();

        if (config('opconsole.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
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

    /**
     * Fail-closed by default: if the host application has not defined these
     * Gates itself, OpConsole only allows access in the "local" environment.
     * A host that wants different behaviour defines the Gate explicitly.
     */
    private function registerDefaultGates(): void
    {
        foreach (['view_gate', 'run_gate'] as $key) {
            $name = config('opconsole.authorization.'.$key);

            if ($name && ! Gate::has($name)) {
                Gate::define($name, fn ($user = null) => app()->environment('local'));
            }
        }
    }
}

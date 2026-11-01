<?php

use AshrafTalha\OpConsole\Http\Controllers\OpConsoleController;
use AshrafTalha\OpConsole\Support\ActionRunner;
use Illuminate\Support\Facades\Route;

$prefix = config('opconsole.route.name_prefix', 'opconsole.');
$configuredMiddleware = config('opconsole.route.middleware', ['web']);
$extraMiddleware = match (true) {
    is_array($configuredMiddleware) => array_filter($configuredMiddleware, fn ($item) => is_string($item) && $item !== ''),
    is_string($configuredMiddleware) && $configuredMiddleware !== '' => [$configuredMiddleware],
    default => [],
};
// Always keep 'web' active so session state and CSRF protection on POST/DELETE routes cannot be stripped accidentally.
$middleware = array_values(array_unique(['web', ...$extraMiddleware]));

Route::prefix(config('opconsole.route.prefix', 'admin/opconsole'))
    ->middleware($middleware)
    ->domain(config('opconsole.route.domain'))
    ->group(function () use ($prefix) {
        Route::get('/', [OpConsoleController::class, 'index'])
            ->middleware('opconsole.authorize:view')
            ->name($prefix.'index');

        Route::get('assets/{asset}', [OpConsoleController::class, 'asset'])
            ->whereIn('asset', ['opconsole.css', 'opconsole.js'])
            ->middleware('opconsole.authorize:view')
            ->name($prefix.'asset');

        Route::get('logs/{file}', [OpConsoleController::class, 'downloadLog'])
            ->where('file', '[\w.-]+\.log')
            ->middleware(['opconsole.authorize:download', 'throttle:30,1,opconsole-download'])
            ->name($prefix.'log');

        Route::delete('logs/{file}', [OpConsoleController::class, 'deleteLog'])
            ->where('file', '[\w.-]+\.log')
            ->middleware(['opconsole.authorize:delete', 'throttle:6,1,opconsole-delete'])
            ->name($prefix.'log.delete');

        Route::post('{action}', [OpConsoleController::class, 'run'])
            ->whereIn('action', array_keys(ActionRunner::ACTIONS))
            ->middleware(['opconsole.authorize:run', 'throttle:6,1,opconsole-run'])
            ->name($prefix.'run');
    });

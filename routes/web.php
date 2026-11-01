<?php

use AshrafTalha\OpConsole\Http\Controllers\OpConsoleController;
use AshrafTalha\OpConsole\Support\ActionRunner;
use Illuminate\Support\Facades\Route;

$prefix = config('opconsole.route.name_prefix', 'opconsole.');

Route::prefix(config('opconsole.route.prefix', 'admin/opconsole'))
    ->middleware(config('opconsole.route.middleware', ['web']))
    ->domain(config('opconsole.route.domain'))
    ->group(function () use ($prefix) {
        Route::get('/', [OpConsoleController::class, 'index'])
            ->middleware('opconsole.authorize:view')
            ->name($prefix.'index');

        Route::get('logs/{file}', [OpConsoleController::class, 'downloadLog'])
            ->where('file', '[\w.-]+\.log')
            ->middleware('opconsole.authorize:view')
            ->name($prefix.'log');

        Route::delete('logs/{file}', [OpConsoleController::class, 'deleteLog'])
            ->where('file', '[\w.-]+\.log')
            ->middleware(['opconsole.authorize:run', 'throttle:6,1'])
            ->name($prefix.'log.delete');

        Route::post('{action}', [OpConsoleController::class, 'run'])
            ->whereIn('action', array_keys(ActionRunner::ACTIONS))
            ->middleware(['opconsole.authorize:run', 'throttle:6,1'])
            ->name($prefix.'run');
    });

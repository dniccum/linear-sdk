<?php

declare(strict_types=1);

use Dniccum\Linear\Http\Controllers\ConnectionController;
use Dniccum\Linear\Http\Controllers\DestinationController;
use Dniccum\Linear\Http\Controllers\RetryController;
use Dniccum\Linear\Http\Controllers\SettingsController;
use Dniccum\Linear\Http\Controllers\TeamController;
use Dniccum\Linear\Http\Middleware\AuthorizeLinear;
use Dniccum\Linear\Http\Middleware\ForceJsonResponse;
use Dniccum\Linear\Linear;
use Dniccum\Linear\Support\Json;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Linear routes
|--------------------------------------------------------------------------
|
| See docs/http-contract.md. Each group is registered only when enabled in
| config('linear.routes') and not turned off with Linear::ignoreRoutes().
|
*/

$linear = app(Linear::class);

Route::prefix(config()->string('linear.path', 'linear'))
    ->middleware([...Json::strings(config('linear.middleware')), AuthorizeLinear::class])
    ->group(function () use ($linear): void {
        if ($linear->routesEnabled('ui')) {
            Route::get('/', [SettingsController::class, 'show'])->name('linear.settings');
        }

        if ($linear->routesEnabled('oauth')) {
            Route::get('connect', [ConnectionController::class, 'connect'])->name('linear.connect');
            Route::get('callback', [ConnectionController::class, 'callback'])->name('linear.callback');
            Route::post('api-key', [ConnectionController::class, 'storeApiKey'])->name('linear.api-key.store');
            Route::delete('/', [ConnectionController::class, 'destroy'])->name('linear.disconnect');
        }

        if ($linear->routesEnabled('api')) {
            Route::prefix('api')->middleware(ForceJsonResponse::class)->name('linear.api.')->group(function (): void {
                Route::get('teams', [TeamController::class, 'index'])->name('teams');
                Route::get('teams/{team}/options', [TeamController::class, 'options'])->name('team-options');
                Route::put('destination', [DestinationController::class, 'update'])->name('destination.update');
                Route::delete('destination', [DestinationController::class, 'destroy'])->name('destination.destroy');
                Route::post('issues/{link}/retry', RetryController::class)->name('issues.retry');
            });
        }
    });

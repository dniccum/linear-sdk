<?php

declare(strict_types=1);

use Dniccum\Linear\Facades\Linear;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

test('routes ignored from a service provider are never registered', function () {
    expect(Route::has('linear.settings'))->toBeFalse()
        ->and(Route::has('linear.connect'))->toBeFalse()
        ->and(Route::has('linear.api.teams'))->toBeFalse();
});

test('the headless API still works without the routes', function () {
    $user = User::factory()->create();

    expect(Linear::settingsFor($user)->toArray())->toHaveKey('configured')
        ->and(Linear::settingsFor($user)->urls->connect)->toBe('');
});

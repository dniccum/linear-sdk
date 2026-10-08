<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Settings\BackData;
use Dniccum\Linear\Facades\Linear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
});

/**
 * Register a named route after boot and make it resolvable by name.
 */
function registerBackRoute(string $uri, string $name): void
{
    Route::get($uri, fn () => 'back')->name($name);
    Route::getRoutes()->refreshNameLookups();
}

test('the back link goes to /dashboard and says "Back" by default', function () {
    $back = Linear::backFor($this->owner);

    expect($back)->toEqual(new BackData('Back', url('/dashboard')))
        ->and(Linear::settingsFor($this->owner)->toArray()['back'])->toBe([
            'label' => 'Back',
            'url' => url('/dashboard'),
        ]);
});

test('the label and the destination are configurable', function (string $url, string $expected) {
    registerBackRoute('/home', 'home');
    config(['linear.back.label' => 'Return to the app', 'linear.back.url' => $url]);

    // Relative expectations are resolved against whatever APP_URL the environment uses.
    $expected = str_starts_with($expected, 'http') ? $expected : url($expected);

    expect(Linear::backFor($this->owner))->toEqual(new BackData('Return to the app', $expected));
})->with([
    'route name' => ['home', '/home'],
    'path' => ['/account', '/account'],
    'url' => ['https://app.example.com/start', 'https://app.example.com/start'],
]);

test('the label is passed through the translator', function () {
    app('translator')->addLines(['linear.back' => 'Retour'], 'en');
    config(['linear.back.label' => 'linear.back']);

    expect(Linear::backFor($this->owner)?->label)->toBe('Retour');
});

test('a missing label falls back to "Back"', function () {
    config(['linear.back.label' => null]);

    expect(Linear::backFor($this->owner)?->label)->toBe('Back');
});

test('the back link can be disabled or have no usable destination', function (array $config) {
    config($config);

    expect(Linear::backFor($this->owner))->toBeNull()
        ->and(Linear::settingsFor($this->owner)->toArray()['back'])->toBeNull();
})->with([
    'disabled' => [['linear.back.enabled' => false]],
    'no url' => [['linear.back.url' => null]],
    'empty url' => [['linear.back.url' => '']],
    'unresolvable' => [['linear.back.url' => 'not-a-route']],
]);

test('backUsing decides the destination per owner and takes precedence over the config', function () {
    config(['linear.back.url' => '/ignored']);
    $seen = null;

    Linear::backUsing(function (Model $owner) use (&$seen): string {
        $seen = $owner;

        return '/teams/'.$owner->getKey();
    });

    expect(Linear::backFor($this->owner)?->url)->toBe(url('/teams/'.$this->owner->getKey()))
        ->and($seen?->is($this->owner))->toBeTrue();
});

test('backUsing may hide the link by returning null', function () {
    Linear::backUsing(fn (Model $owner): ?string => null);

    expect(Linear::backFor($this->owner))->toBeNull();
});

test('the server-rendered header shows the back link', function () {
    $html = view('linear::partials.header', [
        'brand' => Linear::settingsFor($this->owner)->brand,
        'back' => new BackData('Back to the app', 'https://app.example.com/dashboard'),
    ])->render();

    expect($html)->toContain('class="linear-back" href="https://app.example.com/dashboard">Back to the app</a>');
});

test('the page embeds the back link in the settings and the fallback markup', function () {
    $this->actingAs($this->owner)
        ->get(route('linear.settings'))
        ->assertOk()
        ->assertSee('class="linear-back"', false)
        ->assertSee('"back":{"label":"Back"', false);
});

test('the back link is omitted when it is disabled', function () {
    config(['linear.back.enabled' => false]);

    $this->actingAs($this->owner)
        ->get(route('linear.settings'))
        ->assertOk()
        ->assertDontSee('class="linear-back"', false)
        ->assertSee('"back":null', false);
});

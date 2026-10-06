<?php

declare(strict_types=1);

use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\Http\Middleware\AuthorizeLinear;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Tests\Fixtures\PlainModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

/**
 * @return array<string, list<string>>
 */
function linearRoutes(): array
{
    return [
        'ui' => ['linear.settings'],
        'oauth' => ['linear.connect', 'linear.callback', 'linear.api-key.store', 'linear.disconnect'],
        'api' => ['linear.api.teams', 'linear.api.team-options', 'linear.api.destination.update', 'linear.api.destination.destroy', 'linear.api.issues.retry'],
    ];
}

test('every route in the contract is registered with its method, path and name', function (string $name, string $method, string $uri) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain($method)
        ->and($route->uri())->toBe($uri);
})->with([
    ['linear.settings', 'GET', 'linear'],
    ['linear.connect', 'GET', 'linear/connect'],
    ['linear.callback', 'GET', 'linear/callback'],
    ['linear.api-key.store', 'POST', 'linear/api-key'],
    ['linear.disconnect', 'DELETE', 'linear'],
    ['linear.api.teams', 'GET', 'linear/api/teams'],
    ['linear.api.team-options', 'GET', 'linear/api/teams/{team}/options'],
    ['linear.api.destination.update', 'PUT', 'linear/api/destination'],
    ['linear.api.destination.destroy', 'DELETE', 'linear/api/destination'],
    ['linear.api.issues.retry', 'POST', 'linear/api/issues/{link}/retry'],
]);

test('each route group can be switched off on its own', function (string $group) {
    config(["linear.routes.{$group}" => false]);
    reloadLinearRoutes();

    foreach (linearRoutes() as $name => $routes) {
        foreach ($routes as $route) {
            expect(Route::has($route))->toBe($name !== $group);
        }
    }
})->with(['ui', 'oauth', 'api']);

test('Linear::ignoreRoutes() registers no route at all', function () {
    Linear::ignoreRoutes();
    reloadLinearRoutes();

    expect(Route::has('linear.settings'))->toBeFalse()
        ->and(Route::has('linear.connect'))->toBeFalse()
        ->and(Route::has('linear.api.teams'))->toBeFalse()
        ->and(Linear::routesEnabled('ui'))->toBeFalse();
});

test('a missing routes config enables every group', function () {
    config(['linear.routes' => []]);
    reloadLinearRoutes();

    expect(Route::has('linear.settings'))->toBeTrue()
        ->and(Route::has('linear.api.teams'))->toBeTrue();
});

test('the path is configurable', function () {
    config(['linear.path' => 'account/integrations/linear']);
    reloadLinearRoutes();

    expect(route('linear.settings', absolute: false))->toBe('/account/integrations/linear')
        ->and(route('linear.api.teams', absolute: false))->toBe('/account/integrations/linear/api/teams');
});

test('the middleware is configurable and the gate always runs last', function () {
    config(['linear.middleware' => ['web']]);
    reloadLinearRoutes();

    expect(Route::getRoutes()->getByName('linear.settings')->gatherMiddleware())
        ->toBe(['web', AuthorizeLinear::class]);
});

test('by default the settings page needs a signed in user', function () {
    $this->getJson(route('linear.settings'))->assertUnauthorized();
});

test('without a resolvable owner every route is forbidden', function () {
    config(['linear.middleware' => ['web']]);
    reloadLinearRoutes();

    $this->get(route('linear.settings'))->assertForbidden();
    $this->getJson(route('linear.api.teams'))->assertForbidden();
});

test('the authorization callback decides who may use the package', function () {
    $user = User::factory()->create();
    $seen = [];

    Linear::authorizeUsing(function (Request $request, Model $owner) use (&$seen) {
        $seen = [$request->path(), $owner::class];

        return false;
    });

    $this->actingAs($user)->get(route('linear.settings'))->assertForbidden();
    $this->actingAs($user)->getJson(route('linear.api.teams'))->assertForbidden();

    expect($seen)->toBe(['linear/api/teams', User::class]);

    Linear::authorizeUsing(fn () => true);

    $this->actingAs($user)->get(route('linear.settings'))->assertOk();
});

test('only a strictly true answer from the callback authorizes', function () {
    Linear::authorizeUsing(fn () => 'yes');

    $this->actingAs(User::factory()->create())->get(route('linear.settings'))->assertForbidden();
});

test('the owner can be resolved by something other than the authenticated user', function () {
    $team = User::factory()->create(['name' => 'Platform Team']);
    LinearConnection::factory()->for($team, 'owner')->create(['organization_name' => 'Team Workspace']);

    Linear::resolveOwnerUsing(fn (Request $request) => User::query()->where('name', 'Platform Team')->first());

    $this->actingAs(User::factory()->create())
        ->get(route('linear.settings'))
        ->assertOk()
        ->assertSee('Team Workspace');
});

test('an owner that cannot hold a connection is a configuration error', function () {
    Linear::resolveOwnerUsing(fn () => new PlainModel);

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs(User::factory()->create())->get(route('linear.settings')))
        ->toThrow(LogicException::class, 'HasLinearConnection');
});

test('a resolver that finds nobody forbids access', function () {
    Linear::resolveOwnerUsing(fn () => null);

    $this->actingAs(User::factory()->create())->get(route('linear.settings'))->assertForbidden();
});

test('the settings URL is the configured one, the settings route, or the path', function () {
    expect(Linear::settingsUrl())->toBe(route('linear.settings'));

    config(['linear.settings_url' => 'https://app.test/account']);

    expect(Linear::settingsUrl())->toBe('https://app.test/account');

    config(['linear.settings_url' => null, 'linear.routes.ui' => false]);
    reloadLinearRoutes();

    expect(Linear::settingsUrl())->toBe(url('linear'));
});

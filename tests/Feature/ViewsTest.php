<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Settings\BrandData;
use Dniccum\Linear\Facades\Linear;
use Dniccum\Linear\LinearServiceProvider;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Support\LinearAssets;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    config(['linear.client_id' => 'client', 'linear.client_secret' => 'secret']);

    $this->manifest = tempnam(sys_get_temp_dir(), 'linear-manifest');
    config(['linear.assets_manifest' => $this->manifest]);

    file_put_contents($this->manifest, json_encode([
        'resources/js/main.ts' => ['file' => 'assets/main-abc123.js', 'name' => 'main', 'src' => 'resources/js/main.ts', 'isEntry' => true],
    ], JSON_THROW_ON_ERROR));
});

afterEach(function () {
    @unlink($this->manifest);
});

/**
 * @return array<string, mixed>
 */
function scriptSettings(string $html): array
{
    preg_match('#<script type="application/json" id="linear-settings">(.*?)</script>#s', $html, $matches);

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

test('the settings page renders the mount element, the settings JSON and the branded header', function () {
    config(['linear.brand' => ['name' => 'Acme Support', 'logo' => 'https://acme.test/logo.svg', 'color' => '#ff6600']]);
    LinearConnection::factory()->for($this->owner, 'owner')->create();

    $response = $this->actingAs($this->owner)->get(route('linear.settings'));

    $response->assertOk()
        ->assertSee('<!DOCTYPE html>', false)
        ->assertSee('<title>Linear integration · Acme Support</title>', false)
        ->assertSee('<div data-linear-app class="linear-app" style="--linear-accent: #ff6600">', false)
        ->assertSee('<img class="linear-header__logo" src="https://acme.test/logo.svg"', false)
        ->assertSee('<p class="linear-header__eyebrow">Acme Support</p>', false)
        ->assertSee('<script type="application/json" id="linear-settings">', false)
        ->assertSee('<meta name="csrf-token"', false);

    $payload = scriptSettings($response->getContent());

    expect($payload['brand'])->toBe(['name' => 'Acme Support', 'logo' => 'https://acme.test/logo.svg', 'color' => '#ff6600'])
        ->and($payload['connection']['organizationName'])->toBe('Acme')
        ->and($payload['urls']['teamOptions'])->toContain('{team}')
        ->and($payload['csrf'])->toBeString();
});

test('the default brand has no logo and the plain title', function () {
    $this->actingAs($this->owner)
        ->get(route('linear.settings'))
        ->assertSee('<title>Linear integration</title>', false)
        ->assertDontSee('linear-header__logo', false)
        ->assertSee('--linear-accent: #5E6AD2', false);
});

test('page content is escaped and cannot break out of the settings script', function () {
    config(['linear.brand.name' => '<b>Evil</b>']);
    LinearConnection::factory()->for($this->owner, 'owner')->create(['organization_name' => '</script><script>alert(1)</script>']);

    $response = $this->actingAs($this->owner)->get(route('linear.settings'));

    $response->assertDontSee('<b>Evil</b>', false)->assertDontSee('<script>alert(1)</script>', false);

    expect(scriptSettings($response->getContent())['connection']['organizationName'])->toBe('</script><script>alert(1)</script>');
});

test('the page is standalone', function () {
    $html = $this->actingAs($this->owner)->get(route('linear.settings'))->getContent();

    expect($html)->toContain('<html lang=')->toContain('<body>')->toContain('</html>')
        ->and(substr_count($html, 'data-linear-app'))->toBe(1);
});

test('the settings component embeds the page in any layout', function () {
    $html = Blade::render('<main><x-linear::settings :owner="$owner" /></main>', ['owner' => $this->owner]);

    expect($html)->toContain('<main>')->toContain('data-linear-app')->toContain('id="linear-settings"')
        ->and(scriptSettings($html)['configured'])->toBeTrue();
});

test('the settings component uses the owner resolved for the request', function () {
    Route::middleware('web')->get('/_embed', fn () => Blade::render('<x-linear::settings />'));

    $this->get('/_embed')->assertOk()->assertDontSee('data-linear-app', false);
    $this->actingAs($this->owner)->get('/_embed')->assertOk()->assertSee('data-linear-app', false);
});

test('the settings component renders nothing without an owner', function () {
    expect(Blade::render('<x-linear::settings />'))->toBe('');
});

test('the settings component accepts prepared settings and can leave the assets out', function () {
    $settings = Linear::settingsFor($this->owner);

    $with = Blade::render('<x-linear::settings :settings="$settings" />', ['settings' => $settings]);
    $without = Blade::render('<x-linear::settings :settings="$settings" :assets="false" />', ['settings' => $settings]);

    expect($with)->toContain('main-abc123.js')
        ->and($without)->not->toContain('main-abc123.js')->toContain('data-linear-app');
});

test('the settings component can be used with its hyphenated tag', function () {
    expect(Blade::render('<x-linear-settings :owner="$owner" />', ['owner' => $this->owner]))->toContain('data-linear-app');
});

test('assets come from the Vite manifest', function () {
    $html = (string) app(LinearAssets::class)->render();

    expect($html)->toBe('<script type="module" src="'.asset('vendor/linear/assets/main-abc123.js').'"></script>');
});

test('assets include stylesheets and preloads from the manifest', function () {
    file_put_contents($this->manifest, json_encode([
        'resources/js/main.ts' => ['file' => 'assets/main.js', 'isEntry' => true, 'css' => ['assets/main.css'], 'imports' => ['_chunk.js']],
        '_chunk.js' => ['file' => 'assets/chunk.js', 'css' => ['assets/chunk.css'], 'imports' => ['_deeper.js', '_missing.js']],
        '_deeper.js' => ['file' => 'assets/deeper.js'],
        'style.css' => ['file' => 'assets/style-xyz.css', 'src' => 'style.css'],
    ], JSON_THROW_ON_ERROR));

    $html = (string) app(LinearAssets::class)->render();
    $base = asset('vendor/linear');

    expect(explode("\n", $html))->toBe([
        '<link rel="stylesheet" href="'.$base.'/assets/main.css">',
        '<link rel="stylesheet" href="'.$base.'/assets/chunk.css">',
        '<link rel="stylesheet" href="'.$base.'/assets/style-xyz.css">',
        '<link rel="modulepreload" href="'.$base.'/assets/chunk.js">',
        '<link rel="modulepreload" href="'.$base.'/assets/deeper.js">',
        '<script type="module" src="'.$base.'/assets/main.js"></script>',
    ]);
});

test('assets are served from the configured public path', function () {
    config(['linear.assets_path' => '/packages/linear/']);

    expect((string) app(LinearAssets::class)->render())->toContain(asset('packages/linear/assets/main-abc123.js'));
});

test('a missing manifest leaves an explanatory comment instead of breaking the page', function () {
    unlink($this->manifest);

    expect((string) app(LinearAssets::class)->render())->toStartWith('<!-- Linear assets are not built')
        ->toEndWith('-->');

    $this->actingAs($this->owner)->get(route('linear.settings'))->assertOk()->assertSee('Linear assets are not built', false);
});

test('a manifest without the entry or with broken JSON is treated as missing', function () {
    file_put_contents($this->manifest, json_encode(['other.ts' => ['file' => 'x.js']], JSON_THROW_ON_ERROR));

    expect((string) app(LinearAssets::class)->render())->toContain('not built');

    file_put_contents($this->manifest, 'not json');

    expect((string) app(LinearAssets::class)->render())->toContain('not built');
});

test('the bundled manifest is used when none is configured', function () {
    config(['linear.assets_manifest' => null]);

    $html = (string) app(LinearAssets::class)->render();

    // The frontend build ships its manifest inside the package.
    expect(is_file(dirname(__DIR__, 2).'/public/build/.vite/manifest.json') ? str_contains($html, '<script type="module"') : str_contains($html, 'not built'))->toBeTrue();
});

test('a Vite dev server takes over when configured', function () {
    config(['linear.vite_dev_url' => 'http://localhost:5173/']);

    expect((string) app(LinearAssets::class)->render())->toBe(
        '<script type="module" src="http://localhost:5173/@vite/client"></script>'."\n"
        .'<script type="module" src="http://localhost:5173/resources/js/main.ts"></script>'
    );

    $this->actingAs($this->owner)->get(route('linear.settings'))->assertSee('http://localhost:5173/@vite/client', false);
});

test('the @linearAssets directive and <x-linear::assets /> render the same tags', function () {
    $expected = (string) app(LinearAssets::class)->render();

    expect(Blade::render('@linearAssets'))->toBe($expected)
        ->and(Blade::render('<x-linear::assets />'))->toBe($expected)
        ->and(Blade::render('<x-linear-assets />'))->toBe($expected);
});

test('every publish tag points at the right files', function () {
    $provider = LinearServiceProvider::class;
    $root = str_replace('\\', '/', dirname(__DIR__, 2));

    // Forward slashes everywhere, so the paths compare the same on Windows.
    $normalize = fn (string $path): string => str_replace('\\', '/', realpath($path) ?: $path);
    $sources = fn (string $tag): array => array_map($normalize, array_keys(ServiceProvider::pathsToPublish($provider, $tag)));
    $targets = fn (string $tag): array => array_map($normalize, array_values(ServiceProvider::pathsToPublish($provider, $tag)));

    expect($sources('linear-config'))->toBe(["{$root}/config/linear.php"])
        ->and($targets('linear-config'))->toBe([$normalize(config_path('linear.php'))])
        ->and(array_map('basename', $sources('linear-migrations')))->toBe([
            'create_linear_connections_table.php.stub',
            'create_linear_destinations_table.php.stub',
            'create_linear_issue_links_table.php.stub',
            'create_linear_comment_deliveries_table.php.stub',
        ])
        ->and($targets('linear-migrations'))->each->toMatch('#/migrations/(\./)?\d{4}_\d{2}_\d{2}_\d{6}_create_linear_\w+_table\.php$#')
        ->and($sources('linear-views'))->toBe(["{$root}/resources/views"])
        ->and($targets('linear-views'))->toBe([$normalize(resource_path('views/vendor/linear'))])
        ->and($sources('linear-assets'))->toBe([$normalize("{$root}/public/build")])
        ->and($targets('linear-assets'))->toBe([$normalize(public_path('vendor/linear'))]);
});

test('the published assets path follows the configuration', function () {
    expect(config('linear.assets_path'))->toBe('vendor/linear');
});

test('the views can be overridden by publishing them', function () {
    $this->app['view']->getFinder()->prependNamespace('linear', [__DIR__.'/../Fixtures/views']);

    expect(view('linear::partials.header', ['brand' => BrandData::fromConfig()])->render())->toContain('overridden header');
});

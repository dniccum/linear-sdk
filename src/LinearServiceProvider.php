<?php

declare(strict_types=1);

namespace Dniccum\Linear;

use Dniccum\Linear\Support\LinearAssets;
use Dniccum\Linear\View\Components\Assets;
use Dniccum\Linear\View\Components\Settings;
use Illuminate\Support\Facades\Blade;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LinearServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('linear')
            ->hasConfigFile()
            ->hasViews('linear')
            ->hasMigrations([
                'create_linear_connections_table',
                'create_linear_destinations_table',
                'create_linear_issue_links_table',
                'create_linear_comment_deliveries_table',
            ])
            ->hasViewComponents('linear', Assets::class, Settings::class)
            ->hasRoute('web');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Linear::class);

        $this->app->bind(LinearAssets::class, fn (): LinearAssets => new LinearAssets(
            manifestPath: is_string($path = config('linear.assets_manifest')) && $path !== ''
                ? $path
                : dirname(__DIR__).'/public/build/.vite/manifest.json',
        ));
    }

    public function packageBooted(): void
    {
        // <x-linear::assets /> and <x-linear::settings />.
        Blade::componentNamespace('Dniccum\\Linear\\View\\Components', 'linear');

        // @linearAssets
        Blade::directive('linearAssets', fn (): string => '<?php echo app(\\'.LinearAssets::class.'::class)->render(); ?>');

        // The compiled front-end bundle, published with
        // `php artisan vendor:publish --tag=linear-assets`.
        $this->publishes([
            dirname(__DIR__).'/public/build' => public_path(config()->string('linear.assets_path', 'vendor/linear')),
        ], 'linear-assets');
    }
}

<?php

declare(strict_types=1);

namespace Dniccum\Linear;

use Carbon\CarbonImmutable;
use Dniccum\Linear\Contracts\ErrorReporter;
use Dniccum\Linear\Contracts\LinearStore;
use Dniccum\Linear\Contracts\Mutex;
use Dniccum\Linear\Contracts\SyncQueue;
use Dniccum\Linear\Laravel\EloquentStore;
use Dniccum\Linear\Laravel\LaravelErrorReporter;
use Dniccum\Linear\Laravel\LaravelEventDispatcher;
use Dniccum\Linear\Laravel\LaravelQueue;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Services\LinearOAuth;
use Dniccum\Linear\Support\CacheMutex;
use Dniccum\Linear\Support\Json;
use Dniccum\Linear\Support\LinearAssets;
use Dniccum\Linear\View\Components\Assets;
use Dniccum\Linear\View\Components\Settings;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the framework-agnostic core into Laravel: configuration, the Eloquent
 * store, queued jobs, Laravel's HTTP client, cache locks and events, plus the
 * routes, views, Blade components and publishable resources of the optional
 * configuration page.
 */
class LinearServiceProvider extends ServiceProvider
{
    /**
     * The migrations the `linear-migrations` tag publishes, in order.
     *
     * @var list<string>
     */
    private const array MIGRATIONS = [
        'create_linear_connections_table',
        'create_linear_destinations_table',
        'create_linear_issue_links_table',
        'create_linear_comment_deliveries_table',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->path('config/linear.php'), 'linear');

        $this->app->singleton(Linear::class);

        $this->app->bind(LinearAssets::class, fn (): LinearAssets => new LinearAssets(
            manifestPath: is_string($path = config('linear.assets_manifest')) && $path !== ''
                ? $path
                : $this->path('public/build/.vite/manifest.json'),
        ));

        $this->registerCore();
    }

    public function boot(): void
    {
        $this->loadViewsFrom($this->path('resources/views'), 'linear');
        $this->loadViewComponentsAs('linear', [Assets::class, Settings::class]);
        $this->loadRoutesFrom($this->path('routes/web.php'));

        // <x-linear::assets /> and <x-linear::settings />.
        Blade::componentNamespace('Dniccum\\Linear\\View\\Components', 'linear');

        // @linearAssets
        Blade::directive('linearAssets', fn (): string => '<?php echo app(\\'.LinearAssets::class.'::class)->render(); ?>');

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
        }
    }

    /**
     * Bind the core services to their Laravel implementations. Nothing here is
     * a singleton, so configuration changed at runtime is picked up.
     */
    private function registerCore(): void
    {
        $this->app->bind(LinearConfig::class, function (Container $app): LinearConfig {
            $values = Json::map($app->make('config')->get('linear'));

            // Default to the package's own callback route.
            $values['redirect'] = Json::nullableString($values['redirect'] ?? null)
                ?? (Route::has('linear.callback') ? route('linear.callback') : null);

            return LinearConfig::fromArray($values);
        });

        $this->app->bind(Mutex::class, fn (Container $app): Mutex => new CacheMutex($app->make(CacheFactory::class)->store()));
        $this->app->bind(ErrorReporter::class, LaravelErrorReporter::class);
        $this->app->bind(SyncQueue::class, LaravelQueue::class);
        $this->app->bind(LinearStore::class, EloquentStore::class);

        $this->app->bind(LinearOAuth::class, fn (Container $app): LinearOAuth => new LinearOAuth(
            $app->make(HttpFactory::class),
            $app->make(LinearConfig::class),
            $app->make('log'),
        ));

        $this->app->bind(LinearClient::class, fn (Container $app): LinearClient => new LinearClient(
            $app->make(HttpFactory::class),
            $app->make(LinearOAuth::class),
            $app->make(LinearConfig::class),
            $app->make('log'),
            $app->make(Mutex::class),
        ));

        $this->app->bind(LinearIssueSync::class, fn (Container $app): LinearIssueSync => new LinearIssueSync(
            $app->make(LinearClient::class),
            $app->make(LinearStore::class),
            $app->make(SyncQueue::class),
            $app->make(LinearConfig::class),
            new LaravelEventDispatcher($app->make(Dispatcher::class)),
            $app->make(ErrorReporter::class),
        ));
    }

    private function registerPublishing(): void
    {
        $this->publishes([$this->path('config/linear.php') => config_path('linear.php')], 'linear-config');

        $this->publishes([$this->path('resources/views') => resource_path('views/vendor/linear')], 'linear-views');

        // The compiled front-end bundle, published with
        // `php artisan vendor:publish --tag=linear-assets`.
        $this->publishes([
            $this->path('public/build') => public_path(config()->string('linear.assets_path', 'vendor/linear')),
        ], 'linear-assets');

        $this->publishes($this->migrationFiles(), 'linear-migrations');
    }

    /**
     * Each migration stub and the timestamped file it publishes to. A
     * migration published before keeps its file name, so publishing again
     * overwrites it instead of adding a duplicate.
     *
     * @return array<string, string>
     */
    private function migrationFiles(): array
    {
        $now = CarbonImmutable::now();
        $existing = File::glob(database_path('migrations/*.php'));
        $files = [];

        foreach (self::MIGRATIONS as $name) {
            $published = null;

            foreach ($existing as $file) {
                if (str_ends_with($file, "_{$name}.php")) {
                    $published = $file;

                    break;
                }
            }

            $now = $now->addSecond();

            $files[$this->path("database/migrations/{$name}.php.stub")] = $published
                ?? database_path('migrations/'.$now->format('Y_m_d_His')."_{$name}.php");
        }

        return $files;
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__).'/'.$relative;
    }
}

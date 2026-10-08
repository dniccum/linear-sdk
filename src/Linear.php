<?php

declare(strict_types=1);

namespace Dniccum\Linear;

use Closure;
use Dniccum\Linear\Actions\BuildSettings;
use Dniccum\Linear\Data\Settings\SettingsData;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Services\ConnectionClient;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Support\Json;
use Dniccum\Linear\Support\ModelHooks;
use Dniccum\Linear\Testing\LinearFake;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * The entry point behind the `Linear` facade: package-wide behaviour you tweak
 * from a service provider, and shortcuts to the common operations.
 */
class Linear
{
    /**
     * @var (Closure(Request): (Model|null))|null
     */
    private ?Closure $resolveOwnerUsing = null;

    /**
     * @var (Closure(Request, Model): mixed)|null
     */
    private ?Closure $authorizeUsing = null;

    private bool $ignoreRoutes = false;

    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * Do not register the package's routes at all, so you can build your own
     * UI on top of the Actions. Call this from a service provider's
     * `register()` method: routes are registered while providers boot.
     */
    public function ignoreRoutes(): static
    {
        $this->ignoreRoutes = true;

        return $this;
    }

    /**
     * Whether a route group ("ui", "oauth" or "api") should be registered.
     */
    public function routesEnabled(string $group): bool
    {
        return ! $this->ignoreRoutes && (bool) config("linear.routes.{$group}", true);
    }

    /**
     * Decide who may use the package's routes. The callback receives the
     * request and the resolved owner and returns a bool. By default anyone
     * with a resolvable owner is allowed.
     *
     * @param  Closure(Request, Model): mixed  $callback
     */
    public function authorizeUsing(Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * Decide which model owns the connection for a request (default: the
     * authenticated user). Return null when there is none.
     *
     * @param  Closure(Request): (Model|null)  $callback
     */
    public function resolveOwnerUsing(Closure $callback): static
    {
        $this->resolveOwnerUsing = $callback;

        return $this;
    }

    public function resolveOwner(Request $request): ?Model
    {
        $owner = $this->resolveOwnerUsing === null ? $request->user() : ($this->resolveOwnerUsing)($request);

        if (! $owner instanceof Model) {
            return null;
        }

        if (! method_exists($owner, 'linearConnection')) {
            throw new LogicException($owner::class.' must use the Dniccum\\Linear\\Concerns\\HasLinearConnection trait to own a Linear connection.');
        }

        return $owner;
    }

    /**
     * Whether the request may use the package: an owner can be resolved and
     * the authorizeUsing callback (if any) allows it.
     */
    public function authorize(Request $request): bool
    {
        $owner = $this->resolveOwner($request);

        if ($owner === null) {
            return false;
        }

        return $this->authorizeUsing === null || ($this->authorizeUsing)($request, $owner) === true;
    }

    /**
     * Where a signed-out visitor is sent: `linear.login_route` as a route name,
     * a path or a full URL. Null when it is unset or cannot be resolved.
     */
    public function loginUrl(): ?string
    {
        $login = Json::nullableString(config('linear.login_route'));

        return match (true) {
            $login === null => null,
            Route::has($login) => route($login),
            str_starts_with($login, '/') => url($login),
            str_starts_with($login, 'http://'), str_starts_with($login, 'https://') => $login,
            default => null,
        };
    }

    /**
     * Where users land after connecting or disconnecting: `linear.settings_url`,
     * else the settings page.
     */
    public function settingsUrl(): string
    {
        $url = Json::nullableString(config('linear.settings_url'));

        return $url ?? (Route::has('linear.settings') ? route('linear.settings') : url(config()->string('linear.path', 'linear')));
    }

    /**
     * Everything the configuration page shows for an owner, ready to render or
     * serialize with toArray() (see docs/http-contract.md).
     */
    public function settingsFor(Model $owner): SettingsData
    {
        return $this->app->make(BuildSettings::class)->execute($owner);
    }

    /**
     * Post a comment on the Linear issue filed for a model (queued). Returns
     * null when the model has no issue. Pass the model the comment originates
     * from as `$origin` to deliver each origin at most once.
     */
    public function comment(Model $source, string $body, ?Model $origin = null): ?LinearCommentDelivery
    {
        return $this->app->make(LinearIssueSync::class)->comment($source, $body, $origin);
    }

    /**
     * A Linear API client bound to the owner's connection.
     *
     * @throws LinearApiException When the owner has not connected Linear.
     */
    public function client(Model $owner): ConnectionClient
    {
        $connection = ModelHooks::connection($owner) ?? throw LinearApiException::notConnected();

        return new ConnectionClient($connection, $this->app->make(LinearClient::class));
    }

    /**
     * The configured authentication mode.
     */
    public function authMode(): LinearAuthMode
    {
        return LinearAuthMode::configured();
    }

    /**
     * Replace the Linear API with an in-memory fake, for your own tests.
     * Nothing leaves the process; use the returned object to arrange canned
     * data and make assertions.
     */
    public function fake(): LinearFake
    {
        return LinearFake::install($this->app);
    }
}

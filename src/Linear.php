<?php

declare(strict_types=1);

namespace Dniccum\Linear;

use Closure;
use Dniccum\Linear\Actions\BuildSettings;
use Dniccum\Linear\Data\Settings\BackData;
use Dniccum\Linear\Data\Settings\SettingsData;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Laravel\EloquentSync;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Services\ConnectionClient;
use Dniccum\Linear\Services\LinearClient;
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

    /** @var (Closure(Model): (string|null))|null */
    private ?Closure $backUsing = null;

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
        return $this->resolveLink(Json::nullableString(config('linear.login_route')));
    }

    /**
     * Decide where the "back" link of the configuration page goes, per owner.
     * The callback receives the owner and returns a route name, a path, a full
     * URL, or null to hide the link. It takes precedence over `linear.back.url`.
     *
     * @param  Closure(Model): (string|null)  $callback
     */
    public function backUsing(Closure $callback): static
    {
        $this->backUsing = $callback;

        return $this;
    }

    /**
     * The "back" link shown on the configuration page, or null when it is
     * disabled (`linear.back.enabled`), has no usable destination, or the
     * `backUsing` callback returned null. The label is `linear.back.label`,
     * passed through the translator so it can be a translation key.
     */
    public function backFor(Model $owner): ?BackData
    {
        if (config('linear.back.enabled', true) !== true) {
            return null;
        }

        $target = $this->backUsing === null
            ? Json::nullableString(config('linear.back.url'))
            : ($this->backUsing)($owner);

        $url = $this->resolveLink($target);

        if ($url === null) {
            return null;
        }

        $label = Json::nullableString(config('linear.back.label')) ?? 'Back';

        return new BackData(Json::string(__($label)), $url);
    }

    /**
     * A route name, a root-relative path or an http(s) URL as a URL; null when
     * it is none of those.
     */
    private function resolveLink(?string $link): ?string
    {
        return match (true) {
            $link === null, $link === '' => null,
            Route::has($link) => route($link),
            str_starts_with($link, '/') => url($link),
            str_starts_with($link, 'http://'), str_starts_with($link, 'https://') => $link,
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
        return $this->app->make(EloquentSync::class)->comment($source, $body, $origin);
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
        $mode = config('linear.auth_mode');

        return is_string($mode) ? (LinearAuthMode::tryFrom($mode) ?? LinearAuthMode::OAuth) : LinearAuthMode::OAuth;
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

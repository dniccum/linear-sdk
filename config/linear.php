<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Linear SDK configuration
|--------------------------------------------------------------------------
|
| Publish this file with `php artisan vendor:publish --tag=linear-config`.
|
| This file is for the Laravel integration. In other frameworks build a
| `Dniccum\Linear\LinearConfig` instead (`LinearConfig::fromArray()` reads the
| auth_mode, client_id, client_secret, redirect, scopes, api_url,
| authorize_url, on_update and on_delete keys below); the database, route,
| page, branding and queue options only apply to Laravel.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication mode
    |--------------------------------------------------------------------------
    |
    | "oauth"   Each owner authorizes your Linear OAuth application (OAuth 2.0
    |           with PKCE). Tokens expire and are refreshed automatically.
    | "api_key" Each owner pastes a personal API key (Linear > Settings > API).
    |           The key is stored encrypted, sent as the raw `Authorization`
    |           header, and validated against Linear when it is saved.
    |
    */

    'auth_mode' => env('LINEAR_AUTH_MODE', 'oauth'),

    /*
    |--------------------------------------------------------------------------
    | OAuth application
    |--------------------------------------------------------------------------
    |
    | Create an application at https://linear.app/settings/api/applications and
    | register the callback URL below. Only used when auth_mode is "oauth".
    |
    | "redirect" defaults to route('linear.callback') when left null.
    | "scopes" are all required: the grant is refused if Linear returns fewer.
    |
    */

    'client_id' => env('LINEAR_CLIENT_ID'),
    'client_secret' => env('LINEAR_CLIENT_SECRET'),
    'redirect' => env('LINEAR_REDIRECT_URI'),
    'scopes' => ['read', 'issues:create', 'comments:create'],

    /*
    |--------------------------------------------------------------------------
    | Linear endpoints
    |--------------------------------------------------------------------------
    |
    | Override only to point at a proxy or a test double.
    |
    */

    'api_url' => env('LINEAR_API_URL', 'https://api.linear.app'),
    'authorize_url' => env('LINEAR_AUTHORIZE_URL', 'https://linear.app/oauth/authorize'),

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | "table_prefix" is prepended to the connections, destinations,
    | issue_links and comment_deliveries tables. Set it before running the
    | published migrations.
    |
    | "key_type" is the primary key type of the models that own connections or
    | are linked to issues, used for the morph columns in the published
    | migrations: "int" (default), "uuid" or "ulid".
    |
    | "owner_model" is the model that owns a connection (usually your User or
    | Team model). It is only used for documentation and as the default owner
    | in the package's model factories; nothing depends on it at runtime.
    |
    */

    'table_prefix' => 'linear_',
    'key_type' => 'int',
    'owner_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Configuration page and endpoints
    |--------------------------------------------------------------------------
    |
    | Everything lives under "path" and is named with the `linear.` prefix.
    | "middleware" is applied to every route; the package adds its own
    | authorization middleware on top (see Linear::authorizeUsing()).
    |
    | "routes" toggles each group. Disable "ui" to embed <x-linear::settings />
    | in your own page, or disable all three (or call Linear::ignoreRoutes()
    | from a service provider's register method) to build a UI on the Actions.
    |
    |   ui     GET  /linear                       settings page
    |   oauth  GET  /linear/connect               start OAuth
    |          GET  /linear/callback              finish OAuth
    |          POST /linear/api-key               save a personal API key
    |          DELETE /linear                     disconnect
    |   api    GET  /linear/api/teams             JSON used by the page
    |          GET  /linear/api/teams/{team}/options
    |          PUT/DELETE /linear/api/destination
    |          POST /linear/api/issues/{link}/retry
    |
    | "settings_url" is where users land after OAuth, an API key save or a
    | disconnect. Leave null to use the settings page route.
    |
    */

    'path' => 'linear',
    'middleware' => ['web', 'auth'],

    'routes' => [
        'ui' => true,
        'oauth' => true,
        'api' => true,
    ],

    'settings_url' => null,

    /*
    |--------------------------------------------------------------------------
    | Login redirect
    |--------------------------------------------------------------------------
    |
    | Where a visitor is sent when they use the configuration page or its
    | endpoints while signed out (an expired session, a 401 or a 419 CSRF
    | failure). Accepts a route name (default "login"), a path such as
    | "/sign-in" or a full URL. If it cannot be resolved, or is null, no
    | redirect is attempted: requests are refused with a 403 and the page shows
    | the error instead.
    |
    */

    'login_route' => env('LINEAR_LOGIN_ROUTE', 'login'),

    /*
    |--------------------------------------------------------------------------
    | Back link
    |--------------------------------------------------------------------------
    |
    | A link at the top of the configuration page that lets users leave it.
    |
    | "enabled"  Set to false to hide the link.
    | "label"    The link text. Passed through the translator, so it can be a
    |            translation key such as "linear.back".
    | "url"      A route name (for example "dashboard"), a path ("/dashboard",
    |            the default) or a full URL.
    |
    | To choose the destination at runtime (per user or team), call
    | Linear::backUsing(fn (Model $owner) => route('teams.show', $owner)) from
    | a service provider. It takes precedence over "url" and may return null to
    | hide the link.
    |
    */

    'back' => [
        'enabled' => true,
        'label' => env('LINEAR_BACK_LABEL', 'Back'),
        'url' => env('LINEAR_BACK_URL', '/dashboard'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | Shown in the header of the configuration page. "logo" is an absolute or
    | root-relative image URL (or null), "color" a CSS hex colour.
    |
    */

    'brand' => [
        'name' => env('LINEAR_BRAND_NAME', 'Linear'),
        'logo' => env('LINEAR_BRAND_LOGO'),
        'color' => env('LINEAR_BRAND_COLOR', '#5E6AD2'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Issues and comments are sent from queued jobs (5 tries with backoff).
    | Leave both null to use the default connection and queue.
    |
    */

    'queue' => [
        'connection' => env('LINEAR_QUEUE_CONNECTION'),
        'name' => env('LINEAR_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model lifecycle behaviour
    |--------------------------------------------------------------------------
    |
    | What happens when a model that is already linked to an issue fires an
    | event listed in its linearEvents() method.
    |
    | "on_update"  "ignore" (default) or "comment" to post a comment listing
    |              the changed attributes. A model that has no issue yet is
    |              filed on its first created/updated event instead.
    | "on_delete"  "ignore" (default) or "comment" to post "was deleted".
    |
    */

    'on_update' => 'ignore',
    'on_delete' => 'ignore',

    /*
    |--------------------------------------------------------------------------
    | Front-end assets
    |--------------------------------------------------------------------------
    |
    | Publish the compiled bundle with
    | `php artisan vendor:publish --tag=linear-assets`; "assets_path" is the
    | public path it is served from.
    |
    | Set "vite_dev_url" (for example http://localhost:5173) to load the entry
    | from a running Vite dev server instead of the compiled bundle.
    |
    | "assets_manifest" is an absolute path to the Vite manifest that lists the
    | bundle's files; null (the default) uses the one shipped with the package.
    |
    */

    'assets_path' => 'vendor/linear',
    'vite_dev_url' => env('LINEAR_VITE_DEV_URL'),
    'assets_manifest' => null,

];

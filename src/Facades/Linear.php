<?php

declare(strict_types=1);

namespace Dniccum\Linear\Facades;

use Closure;
use Dniccum\Linear\Data\Settings\SettingsData;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Services\ConnectionClient;
use Dniccum\Linear\Testing\LinearFake;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Dniccum\Linear\Linear ignoreRoutes()
 * @method static bool routesEnabled(string $group)
 * @method static \Dniccum\Linear\Linear authorizeUsing(Closure $callback)
 * @method static \Dniccum\Linear\Linear resolveOwnerUsing(Closure $callback)
 * @method static Model|null resolveOwner(Request $request)
 * @method static bool authorize(Request $request)
 * @method static string settingsUrl()
 * @method static SettingsData settingsFor(Model $owner)
 * @method static LinearCommentDelivery|null comment(Model $source, string $body, Model|null $origin = null)
 * @method static ConnectionClient client(Model $owner)
 * @method static LinearAuthMode authMode()
 * @method static LinearFake fake()
 *
 * @see \Dniccum\Linear\Linear
 */
class Linear extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Dniccum\Linear\Linear::class;
    }
}

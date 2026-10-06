<?php

declare(strict_types=1);

namespace Dniccum\Linear\Support;

use Dniccum\Linear\Concerns\CreatesLinearIssues;
use Dniccum\Linear\Concerns\HasLinearConnection;
use Dniccum\Linear\Models\LinearConnection;
use Dniccum\Linear\Models\LinearDestination;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Typed access to the hooks the model traits expose.
 *
 * Source models use {@see CreatesLinearIssues} and owners use
 * {@see HasLinearConnection}. They are traits, so they cannot be used as
 * types; this is the one place that checks a model really has them and reads
 * its hooks with proper types.
 */
final class ModelHooks
{
    /**
     * The model that owns the connection issues for this source are filed
     * through.
     *
     * @throws LogicException
     */
    public static function owner(Model $source): ?Model
    {
        if (! method_exists($source, 'linearOwner')) {
            throw self::missing($source, 'CreatesLinearIssues');
        }

        $owner = $source->linearOwner();

        return $owner instanceof Model ? $owner : null;
    }

    /**
     * The Eloquent events ("created", "updated", "deleted") that act on the
     * source.
     *
     * @return list<string>
     *
     * @throws LogicException
     */
    public static function events(Model $source): array
    {
        if (! method_exists($source, 'linearEvents')) {
            throw self::missing($source, 'CreatesLinearIssues');
        }

        return Json::strings($source->linearEvents());
    }

    /**
     * A destination that replaces the owner's for this source.
     *
     * @throws LogicException
     */
    public static function destinationOverride(Model $source): ?LinearDestination
    {
        if (! method_exists($source, 'linearDestinationOverride')) {
            throw self::missing($source, 'CreatesLinearIssues');
        }

        $destination = $source->linearDestinationOverride();

        return $destination instanceof LinearDestination ? $destination : null;
    }

    /**
     * @throws LogicException
     */
    public static function connection(Model $owner): ?LinearConnection
    {
        if (! method_exists($owner, 'linearConnection')) {
            throw self::missing($owner, 'HasLinearConnection');
        }

        $connection = $owner->linearConnection()->first();

        return $connection instanceof LinearConnection ? $connection : null;
    }

    /**
     * @throws LogicException
     */
    public static function destination(Model $owner): ?LinearDestination
    {
        if (! method_exists($owner, 'linearDestination')) {
            throw self::missing($owner, 'HasLinearConnection');
        }

        $destination = $owner->linearDestination()->first();

        return $destination instanceof LinearDestination ? $destination : null;
    }

    private static function missing(Model $model, string $trait): LogicException
    {
        return new LogicException(sprintf(
            '%s must use the %s trait to be used with Linear.',
            $model::class,
            'Dniccum\\Linear\\Concerns\\'.$trait,
        ));
    }
}

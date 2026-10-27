<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel\Casts;

use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\Support\Json;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores an {@see IssuePayload} as JSON in a model column.
 *
 * @implements CastsAttributes<IssuePayload, IssuePayload|array<string, mixed>>
 */
final class IssuePayloadCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?IssuePayload
    {
        if (! is_string($value)) {
            return null;
        }

        return IssuePayload::fromArray(Json::map(json_decode($value, true, flags: JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $payload = match (true) {
            $value instanceof IssuePayload => $value,
            is_array($value) => IssuePayload::fromArray(Json::map($value)),
            default => throw new InvalidArgumentException('The Linear issue payload must be an IssuePayload.'),
        };

        return [$key => json_encode($payload->toArray(), JSON_THROW_ON_ERROR)];
    }
}

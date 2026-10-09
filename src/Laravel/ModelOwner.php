<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel;

use Dniccum\Linear\Concerns\HasLinearConnection;
use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Contracts\DestinationSettings;
use Dniccum\Linear\Contracts\IssueOwner;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Model;

/**
 * An Eloquent model that uses {@see HasLinearConnection}, as an
 * {@see IssueOwner} the framework-agnostic core can work with.
 */
final readonly class ModelOwner implements IssueOwner
{
    public function __construct(
        public Model $model,
    ) {}

    public function ownerType(): string
    {
        return $this->model->getMorphClass();
    }

    public function ownerId(): int|string
    {
        $key = $this->model->getKey();

        return is_int($key) || is_string($key) ? $key : '';
    }

    public function connection(): ?Connection
    {
        return ModelHooks::connection($this->model);
    }

    public function destination(): ?DestinationSettings
    {
        return ModelHooks::destination($this->model);
    }
}

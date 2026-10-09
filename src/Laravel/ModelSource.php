<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel;

use Dniccum\Linear\Concerns\CreatesLinearIssues;
use Dniccum\Linear\Contracts\DestinationSettings;
use Dniccum\Linear\Contracts\IssueSource;
use Dniccum\Linear\Services\IssueComposer;
use Dniccum\Linear\Support\ModelHooks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * An Eloquent model that uses {@see CreatesLinearIssues}, as an
 * {@see IssueSource} the framework-agnostic core can work with. Its content
 * comes from the model's `linear*()` hooks.
 */
final readonly class ModelSource implements IssueSource
{
    public function __construct(
        public Model $model,
        private IssueComposer $composer,
    ) {}

    public function sourceType(): string
    {
        return $this->model->getMorphClass();
    }

    public function sourceId(): int|string
    {
        $key = $this->model->getKey();

        return is_int($key) || is_string($key) ? $key : '';
    }

    public function owner(): ?ModelOwner
    {
        $owner = ModelHooks::owner($this->model);

        return $owner === null ? null : new ModelOwner($owner);
    }

    public function destinationOverride(): ?DestinationSettings
    {
        return ModelHooks::destinationOverride($this->model);
    }

    public function title(): string
    {
        return $this->composer->title($this->model);
    }

    public function description(): string
    {
        return $this->composer->description($this->model);
    }

    public function comment(string $event, array $context = []): string
    {
        return $this->composer->comment($this->model, $event, $context);
    }

    public function changes(): array
    {
        return Arr::except($this->model->getChanges(), array_values(array_filter([$this->model->getUpdatedAtColumn()], fn (?string $column): bool => $column !== null)));
    }
}

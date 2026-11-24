<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel;

use Dniccum\Linear\Models\LinearCommentDelivery;
use Dniccum\Linear\Models\LinearIssueLink;
use Dniccum\Linear\Services\IssueComposer;
use Dniccum\Linear\Services\LinearIssueSync;
use Illuminate\Database\Eloquent\Model;

/**
 * {@see LinearIssueSync} for Eloquent models: takes models, returns the
 * package's models. This is what the model traits, the observer and the
 * `Linear` facade use.
 */
final readonly class EloquentSync
{
    public function __construct(
        private LinearIssueSync $sync,
        private IssueComposer $composer,
    ) {}

    /**
     * The model as a record the core can file.
     */
    public function source(Model $model): ModelSource
    {
        return new ModelSource($model, $this->composer);
    }

    /**
     * React to an Eloquent lifecycle event on a source model. Called by the
     * model observer once the model's `linearEvents()` includes the event.
     */
    public function handleEvent(Model $model, string $event): void
    {
        $this->sync->handleEvent($this->source($model), $event);
    }

    public function fileAutomatically(Model $model): ?LinearIssueLink
    {
        $link = $this->sync->fileAutomatically($this->source($model));
        assert($link === null || $link instanceof LinearIssueLink);

        return $link;
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @see LinearIssueSync::sendManually()
     */
    public function sendManually(Model $model, array $overrides = []): LinearIssueLink
    {
        $link = $this->sync->sendManually($this->source($model), $overrides);
        assert($link instanceof LinearIssueLink);

        return $link;
    }

    public function linkFor(Model $model): ?LinearIssueLink
    {
        $link = $this->sync->linkFor($this->source($model));
        assert($link === null || $link instanceof LinearIssueLink);

        return $link;
    }

    /**
     * @see LinearIssueSync::comment()
     */
    public function comment(Model $model, string $body, ?Model $origin = null): ?LinearCommentDelivery
    {
        $delivery = $this->sync->comment($this->source($model), $body, $origin === null ? null : $this->source($origin));
        assert($delivery === null || $delivery instanceof LinearCommentDelivery);

        return $delivery;
    }

    public function retry(LinearIssueLink $link): void
    {
        $this->sync->retry($link);
    }
}

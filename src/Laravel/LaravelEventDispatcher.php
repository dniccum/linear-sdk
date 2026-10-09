<?php

declare(strict_types=1);

namespace Dniccum\Linear\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Lets the framework-agnostic core dispatch its events (LinearIssueCreated and
 * friends) on Laravel's event dispatcher.
 */
final readonly class LaravelEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function dispatch(object $event): object
    {
        $this->events->dispatch($event);

        return $event;
    }
}

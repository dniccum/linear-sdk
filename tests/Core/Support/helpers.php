<?php

declare(strict_types=1);

use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearIssueSync;
use Dniccum\Linear\Services\LinearOAuth;
use Dniccum\Linear\Testing\FakeLinearClient;
use Dniccum\Linear\Testing\FakeLinearOAuth;
use Dniccum\Linear\Testing\InMemory\InMemoryConnection;
use Dniccum\Linear\Testing\InMemory\InMemoryDestination;
use Dniccum\Linear\Testing\InMemory\InMemoryOwner;
use Dniccum\Linear\Testing\InMemory\InMemoryQueue;
use Dniccum\Linear\Testing\InMemory\InMemorySource;
use Dniccum\Linear\Testing\InMemory\InMemoryStore;
use Dniccum\Linear\Tests\Core\Support\ScriptedTransport;
use Dniccum\Linear\Transport\Response;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A fully wired sync that runs without any framework: the fake Linear client,
 * the in-memory store and queue, and a recording event dispatcher.
 *
 * @return object{sync: LinearIssueSync, store: InMemoryStore, queue: InMemoryQueue, client: FakeLinearClient, events: ArrayObject<int, object>, connection: InMemoryConnection, owner: InMemoryOwner}
 */
function coreSync(string $onUpdate = 'ignore', string $onDelete = 'ignore', bool $withDestination = true): object
{
    $config = new LinearConfig(onUpdate: $onUpdate, onDelete: $onDelete);
    $client = new FakeLinearClient(new FakeLinearOAuth($config), $config);
    $store = new InMemoryStore;
    $queue = new InMemoryQueue;
    $events = new ArrayObject;
    $connection = new InMemoryConnection(1);
    $owner = new InMemoryOwner(7, $connection, $withDestination ? new InMemoryDestination(new Destination('team-1')) : null);

    $dispatcher = new class($events) implements EventDispatcherInterface
    {
        /** @param ArrayObject<int, object> $events */
        public function __construct(private ArrayObject $events) {}

        public function dispatch(object $event): object
        {
            $this->events->append($event);

            return $event;
        }
    };

    return (object) [
        'sync' => new LinearIssueSync($client, $store, $queue, $config, $dispatcher),
        'store' => $store,
        'queue' => $queue,
        'client' => $client,
        'events' => $events,
        'connection' => $connection,
        'owner' => $owner,
    ];
}

function coreSource(InMemoryOwner $owner, int $id = 1): InMemorySource
{
    return new InMemorySource($id, $owner, "Record {$id}", 'It broke.');
}

/**
 * A real client over a scripted transport.
 *
 * @param  list<Response|Throwable>  $script
 */
function scriptedClient(array $script, ?ScriptedTransport &$transport = null, ?LinearConfig $config = null): LinearClient
{
    $config ??= new LinearConfig(clientId: 'id', clientSecret: 'secret', redirectUri: 'https://app.test/callback');
    $transport = new ScriptedTransport($script);

    return new LinearClient($transport, new LinearOAuth($transport, $config), $config);
}

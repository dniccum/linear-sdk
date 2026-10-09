# Using Linear SDK outside Laravel

Linear SDK started life as a Laravel package, and the Laravel integration is still the most complete one. But the part that talks to Linear, and the part that decides *when* and *how often* to talk to it, do not need Laravel. They are a framework-agnostic core that depends only on PHP and a handful of PSR interfaces, so they run in Symfony, Slim, Mezzio, or a plain PHP script.

- [What works where](#what-works-where)
- [How the package is layered](#how-the-package-is-layered)
- [Install](#install)
- [Level 1: the API client](#level-1-the-api-client)
- [Level 2: connecting a workspace](#level-2-connecting-a-workspace)
- [Level 3: filing issues from your records](#level-3-filing-issues-from-your-records)
- [Wiring it in Symfony](#wiring-it-in-symfony)
- [Locking the token refresh](#locking-the-token-refresh)
- [Events](#events)
- [Testing](#testing)
- [What is not available: the configuration page](#what-is-not-available-the-configuration-page)
- [Differences from the Laravel integration](#differences-from-the-laravel-integration)

## What works where

| Capability | Laravel | Symfony / other |
|---|:-:|:-:|
| API client (`LinearClient`): viewer, teams, team options, create/find issues and comments, raw GraphQL | ✅ | ✅ |
| OAuth 2.0 with PKCE, token refresh, revoke (`LinearOAuth`) and personal API keys | ✅ | ✅ |
| Destination validation (`DestinationResolver`) | ✅ | ✅ |
| Idempotent issue and comment sync with retry/backoff rules and events (`LinearIssueSync`) | ✅ | ✅ |
| Typed DTOs and enums | ✅ | ✅ |
| Fakes for tests | `Linear::fake()` | `FakeLinearClient`, `FakeLinearOAuth`, `InMemory*` |
| Service provider, facade, config file, migrations, Eloquent models, model traits, queued jobs, Actions | ✅ | not applicable |
| **Configuration page** (Blade views and components, routes, JSON API) | ✅ | ❌ |

Everything in the second and third rows is plain PHP. Everything in the last two rows needs Laravel and is only loaded by Laravel's service provider.

## How the package is layered

```
                 ┌──────────────────────────────────────────────────────────────┐
 Laravel         │ Service provider · Facade · Eloquent models · Traits · Jobs  │
 adapter         │ Actions · Routes · Blade components · Configuration page     │
 (needs Laravel) └───────────────┬──────────────────────────────────────────────┘
                                 │ implements the ports below
                 ┌───────────────▼──────────────────────────────────────────────┐
 Core            │ LinearClient · LinearOAuth · LinearIssueSync                 │
 (PHP + PSR)     │ DTOs · Enums · Events · LinearConfig · FakeLinearClient      │
                 └───────────────┬──────────────────────────────────────────────┘
                                 │ talks to the outside world only through
        PSR-18 / PSR-17 (HTTP) · PSR-3 (logs) · PSR-14 (events)  +  the ports:
        Connection · IssueLink · CommentDelivery · LinearStore · SyncQueue · Mutex · ErrorReporter
```

An architecture test (`tests/Arch/ArchTest.php`) fails the build if any class in the core uses `Illuminate\*`, Carbon or the Laravel adapter, and a CI job installs the package with **no Laravel at all** and files an issue through Symfony's HTTP client (`tests/Standalone/smoke.php`).

### The ports

You only implement the ports you use. The API client needs just a `Connection`; the sync needs the rest.

| Interface | You provide | What it is for |
|---|---|---|
| `Psr\Http\Client\ClientInterface` + PSR-17 factories | any PSR-18 client | Every HTTP request. Wrap them in `PsrTransport`. Configure a timeout of about 15 seconds on the client. |
| `LinearConfig` | built from your config | OAuth credentials, scopes, API URL, `on_update` / `on_delete`. `LinearConfig::fromArray()` reads the keys of `config/linear.php`. |
| `Contracts\Connection` | your entity | One owner's authorization of one workspace: tokens, status, and the `mark*()` calls the client makes when Linear rejects them. |
| `Contracts\IssueSource` / `IssueOwner` | a wrapper around your records | The record that becomes an issue (title, description, owner) and whoever owns the connection. |
| `Contracts\IssueLink` / `CommentDelivery` | your entities | The durable state that makes retries idempotent. There must be at most one link per record. |
| `Contracts\LinearStore` | your repository | Finds and creates links and deliveries. |
| `Contracts\SyncQueue` | your queue | Hands "create this issue" and "post this comment" messages to a worker. |
| `Contracts\Mutex` | optional | Serialises OAuth token refreshes between workers. The default does no locking. |
| `Contracts\ErrorReporter` | optional | Where unexpected exceptions go (the sync treats them as transient and retries). Pass `new LogErrorReporter($psr3Logger)` to log them; without a reporter they are swallowed. |
| `Psr\EventDispatcher\EventDispatcherInterface` | optional | Receives `LinearIssueCreated`, `LinearIssueFailed` and `LinearCommentDelivered`. |
| `Psr\Log\LoggerInterface` | optional | Warnings about failed requests. |

`Dniccum\Linear\Testing\InMemory\*` implements every port in a few lines each. It is the reference to copy from when you write a Doctrine or SQL version.

## Install

```bash
composer require dniccum/linear-sdk
composer require symfony/http-client nyholm/psr7     # or any PSR-18 client and PSR-17 factories
```

Nothing from Laravel is installed. (If Laravel *is* present, the package registers itself through Laravel's package discovery, as before.)

## Level 1: the API client

Enough for scripts and for apps that keep their own storage.

```php
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Data\IssuePayload;
use Dniccum\Linear\LinearConfig;
use Dniccum\Linear\Services\LinearClient;
use Dniccum\Linear\Services\LinearOAuth;
use Dniccum\Linear\Transport\PsrTransport;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$psr18 = new Psr18Client(HttpClient::create(['timeout' => 15]));   // a client *and* PSR-17 factories
$transport = new PsrTransport($psr18, $psr18, $psr18);

$config = new LinearConfig(
    clientId: $_ENV['LINEAR_CLIENT_ID'],
    clientSecret: $_ENV['LINEAR_CLIENT_SECRET'],
    redirectUri: 'https://app.test/linear/callback',
);

$client = new LinearClient($transport, new LinearOAuth($transport, $config), $config);

$teams = $client->teams($connection);
$options = $client->teamOptions($connection, $teams[0]->id);   // projects, statuses, labels, members

$issue = $client->createIssue(
    $connection,
    Dniccum\Linear\Support\Uuid::v4(),   // Linear's issue ID: generated by you, reused on every retry
    new IssuePayload(new Destination($teams[0]->id, priority: 2), 'Cannot upload', 'Uploads fail.'),
);
```

`$connection` is any object implementing `Dniccum\Linear\Contracts\Connection`. If you only ever use one token, `Dniccum\Linear\Testing\InMemory\InMemoryConnection` is a ready-made one.

Every failure is a `LinearApiException` with a `reason` (`authentication`, `forbidden`, `rate_limited`, `invalid_request`, `transient`) and `isRetryable()` / `requiresReconnect()` helpers. The client also calls `$connection->markNeedsReconnect()` / `markFailed()` for you, and refreshes expiring OAuth tokens through `$connection->storeTokens()`.

> [!NOTE]
> Linear generates issue and comment IDs when you do not send one. This package always sends its own UUID so a retry can look the issue up (`findIssue()`) instead of creating a duplicate. Keep that habit if you call `createIssue()` yourself.

## Level 2: connecting a workspace

The OAuth building blocks are the same ones the Laravel Actions use. Here is the flow in a Symfony controller; storing `state` and the PKCE verifier in the session is up to you.

```php
// 1. Send the user to Linear.
$state = bin2hex(random_bytes(20));
$verifier = bin2hex(random_bytes(48));
$session->set('linear_oauth', ['state' => $state, 'verifier' => $verifier]);

return new RedirectResponse($oauth->authorizationUrl($state, $verifier));

// 2. Linear sends them back to your redirect URI.
$pending = $session->remove('linear_oauth');
if (! hash_equals($pending['state'], $request->query->get('state', ''))) {
    throw new AccessDeniedHttpException;
}

$tokens = $oauth->exchangeCode($request->query->get('code'), $pending['verifier']);

if ($tokens->missingScopes($oauth->scopes()) !== []) {
    $oauth->revoke($tokens->accessToken);       // the user declined part of the grant
    // ... show an error
}

$viewer = $client->viewer($tokens->accessToken);   // who authorized, and which workspace

// 3. Persist your Connection entity from $tokens and $viewer->organization.
```

For personal API keys, validate with `$client->viewer($apiKey, LinearAuthMode::ApiKey)` (a `LinearApiException` with `requiresReconnect()` means Linear rejected the key) and store the key encrypted.

## Level 3: filing issues from your records

`LinearIssueSync` files a record once, retries safely, mirrors updates and deletes as comments, and queues comments until the issue exists. You give it your storage and queue:

```php
use Dniccum\Linear\Services\LinearIssueSync;

$sync = new LinearIssueSync(
    client: $client,
    store: $store,                // your LinearStore
    queue: $queue,                // your SyncQueue
    config: $config,
    events: $eventDispatcher,     // optional PSR-14 dispatcher
    reporter: $errorReporter,     // optional
);
```

### 1. Describe your records

Wrap a record (an entity, a model, an array) as an `IssueSource`, and the person who owns the Linear connection as an `IssueOwner`:

```php
final class SupportRequestSource implements IssueSource
{
    public function __construct(private SupportRequest $request, private UserOwner $owner) {}

    public function sourceType(): string { return 'support_request'; }
    public function sourceId(): int|string { return $this->request->getId(); }
    public function owner(): ?IssueOwner { return $this->owner; }
    public function destinationOverride(): ?DestinationSettings { return null; }
    public function title(): string { return $this->request->getSubject(); }
    public function description(): string { return $this->request->getBody(); }   // Markdown
    public function comment(string $event, array $context = []): string { return "Request was {$event}."; }
    public function changes(): array { return []; }   // changed fields, for "updated" comments
}
```

`IssueOwner` exposes the owner's `connection()` (your `Connection` entity) and `destination()` (a `DestinationSettings`: the chosen team, project, status, labels, priority, assignee, and whether sending is automatic).

### 2. Tell the sync when things happen

Call `handleEvent()` from your ORM's lifecycle hooks. It does nothing unless the owner has an active connection and a destination in automatic mode.

```php
$sync->handleEvent($source, 'created');   // files the issue (queued)
$sync->handleEvent($source, 'updated');   // files it if needed, or comments when on_update = "comment"
$sync->handleEvent($source, 'deleted');   // comments when on_delete = "comment"

$sync->sendManually($source, ['title' => 'Custom title', 'team_id' => $teamId]);   // manual mode
$sync->comment($source, 'Customer replied.', origin: $replySource);                  // origin makes it idempotent
$sync->retry($link);                                                                 // requeue what failed
```

A problem talking to Linear must never break the save of your own record, so wrap these calls in a `try`/`catch` that reports and swallows, as the Laravel observer does.

### 3. Run the work in a worker

`SyncQueue::issue()` and `comment()` receive the link or delivery to queue. Your worker takes the ID and calls one method:

```php
$delay = $sync->processIssue($linkId, attempt: $attempt);         // or processComment($deliveryId, ...)

if ($delay !== null) {
    // Linear is down or rate limiting. Run this message again after $delay seconds.
}
```

`processIssue()` loads the link, ignores it unless it is still pending, creates (or adopts) the issue, records failures on the link and connection, and tells you whether to retry. Permanent errors (revoked access, a deleted team) return `null` after marking the link failed; the `LinearIssueFailed` event fires. Retries follow `LinearIssueSync::BACKOFF` (30s, 2m, 10m, 30m) up to `LinearIssueSync::MAX_ATTEMPTS` (5). If your queue decides on its own that it has given up, call `failIssue($linkId, $exception)` / `failComment($deliveryId, $exception)`.

### 4. Persist links and deliveries

Implement `IssueLink` and `CommentDelivery` on your entities and `LinearStore` on your repository. Each mutator on the entities (`markSynced()`, `recordAttempt()`, `requeue()`, ...) must persist immediately. The two requirements that matter:

- **One link per record.** `LinearStore::createLink()` must throw `DuplicateIssueLinkException` when the record already has one (a unique index on the source type and ID gives you this for free). That is what stops two processes from filing the same record twice.
- **One delivery per origin.** `createDelivery()` called again with the same `$origin` returns the delivery made earlier, and `wasJustQueued()` says which case it was.

## Wiring it in Symfony

A condensed, working shape. The class names are yours; everything under `Dniccum\Linear` is the package's.

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    Psr\Http\Client\ClientInterface:
        class: Symfony\Component\HttpClient\Psr18Client
        arguments: ['@http_client']        # configure framework.http_client.default_options.timeout: 15

    Dniccum\Linear\Transport\Transport:
        class: Dniccum\Linear\Transport\PsrTransport
        arguments: ['@Psr\Http\Client\ClientInterface', '@Psr\Http\Client\ClientInterface', '@Psr\Http\Client\ClientInterface']

    Dniccum\Linear\LinearConfig:
        factory: ['Dniccum\Linear\LinearConfig', 'fromArray']
        arguments:
            -
                client_id: '%env(LINEAR_CLIENT_ID)%'
                client_secret: '%env(LINEAR_CLIENT_SECRET)%'
                redirect: '%env(LINEAR_REDIRECT_URI)%'
                on_update: ignore
                on_delete: ignore

    Dniccum\Linear\Contracts\Mutex: '@App\Linear\SymfonyLockMutex'
    Dniccum\Linear\Contracts\LinearStore: '@App\Linear\DoctrineLinearStore'
    Dniccum\Linear\Contracts\SyncQueue: '@App\Linear\MessengerSyncQueue'

    Dniccum\Linear\Services\LinearOAuth: ~
    Dniccum\Linear\Services\LinearClient: ~
    Dniccum\Linear\Support\LogErrorReporter: ~    # autowires the PSR-3 logger
    Dniccum\Linear\Services\LinearIssueSync:
        arguments:
            $events: '@event_dispatcher'    # Symfony's dispatcher implements PSR-14
            $reporter: '@Dniccum\Linear\Support\LogErrorReporter'
```

`LinearClient` and `LinearOAuth` take the optional PSR-3 logger as their `$logger` argument; autowire it to the `logger` service if you want the warnings.

**The queue** is two messages and one handler:

```php
final class MessengerSyncQueue implements SyncQueue
{
    public function __construct(private MessageBusInterface $bus) {}

    public function issue(IssueLink $link, bool $afterCommit = false): void
    {
        $this->bus->dispatch(new CreateLinearIssueMessage($link->linkId()));
    }

    public function comment(CommentDelivery $delivery, bool $afterCommit = false): void
    {
        $this->bus->dispatch(new DeliverLinearCommentMessage($delivery->deliveryId()));
    }
}

#[AsMessageHandler]
final class CreateLinearIssueHandler
{
    public function __construct(private LinearIssueSync $sync) {}

    public function __invoke(CreateLinearIssueMessage $message): void
    {
        // Let Messenger count the attempts: never mark the link failed here because attempts ran out.
        $delay = $this->sync->processIssue($message->linkId, attempt: 1, maxAttempts: PHP_INT_MAX);

        if ($delay !== null) {
            throw new RecoverableMessageHandlingException('Linear is unavailable; retrying.');
        }
    }
}
```

```yaml
# config/packages/messenger.yaml: roughly the Laravel schedule (30s, 2m, 8m, 32m; 5 attempts)
framework:
    messenger:
        transports:
            linear:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy: { max_retries: 4, delay: 30000, multiplier: 4 }
        routing:
            App\Linear\CreateLinearIssueMessage: linear
            App\Linear\DeliverLinearCommentMessage: linear
```

`afterCommit` is a hint for queues that share a database transaction with your application. The Doctrine listener below runs after the flush, so the link is committed before the message is dispatched, and you can ignore it. When Messenger gives up after the last retry, mark the work failed so the user can retry it:

```php
#[AsEventListener]
public function onFailed(WorkerMessageFailedEvent $event): void
{
    if ($event->willRetry()) {
        return;
    }

    $message = $event->getEnvelope()->getMessage();

    match (true) {
        $message instanceof CreateLinearIssueMessage => $this->sync->failIssue($message->linkId, $event->getThrowable()),
        $message instanceof DeliverLinearCommentMessage => $this->sync->failComment($message->deliveryId, $event->getThrowable()),
        default => null,
    };
}
```

**The lifecycle hook** is a Doctrine listener. Creating the link flushes, and Doctrine does not allow a flush inside a flush, so remember the new entities in `postPersist` and hand them to the sync in `postFlush`:

```php
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postFlush)]
final class LinearDoctrineListener
{
    /** @var list<SupportRequest> */
    private array $created = [];

    public function __construct(private LinearIssueSync $sync, private SourceFactory $sources, private LoggerInterface $logger) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof SupportRequest) {
            $this->created[] = $entity;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $created = $this->created;
        $this->created = [];

        foreach ($created as $request) {
            try {
                $this->sync->handleEvent($this->sources->for($request), 'created');
            } catch (\Throwable $e) {
                $this->logger->error('Linear sync failed.', ['exception' => $e]);   // never break the save
            }
        }
    }
}
```

**The store** is the one piece that is mostly SQL. Look at `Dniccum\Linear\Testing\InMemory\InMemoryStore` for the exact contract; the interesting method on Doctrine is `createLink()`, which translates the unique-constraint violation:

```php
public function createLink(IssueSource $source, IssueOwner $owner, Connection $connection, LinearIssueSource $kind, string $issueId, IssuePayload $payload): IssueLink
{
    $link = new LinearIssueLinkEntity($source, $owner, $connection, $kind, $issueId, $payload);

    try {
        $this->em->persist($link);
        $this->em->flush();
    } catch (UniqueConstraintViolationException) {
        throw new DuplicateIssueLinkException;   // the sync adopts the link the other process created
    }

    return $link;
}
```

(After a unique-constraint failure Doctrine closes the `EntityManager`, so use a connection or a manager registry that can reopen it.)

**A `Connection` entity** is a plain entity with encrypted token columns; the `mark*()` methods persist:

```php
#[ORM\Entity]
class LinearConnectionEntity implements Connection
{
    // id, ownerId, organizationId, authMode, accessToken (encrypted), refreshToken (encrypted),
    // tokenExpiresAt, status, lastError ...

    public function tokenExpiresSoon(): bool
    {
        return $this->tokenExpiresAt !== null && $this->tokenExpiresAt <= new \DateTimeImmutable('+5 minutes');
    }

    public function storeTokens(Tokens $tokens): void
    {
        $this->accessToken = $tokens->accessToken;
        $this->refreshToken = $tokens->refreshToken ?? $this->refreshToken;
        $this->tokenExpiresAt = $tokens->expiresAt();
        $this->status = 'active';
        $this->flush();
    }

    // ... isActive(), usesApiKey(), reload() (refresh the entity from the database), markSynced(), markFailed(), markNeedsReconnect()
}
```

## Locking the token refresh

Linear rotates the refresh token every time it is used. If two workers refresh the same connection at once, one of them is left with a dead token. `LinearClient` takes the lock named `linear-connection-refresh:{connectionId}` through the `Mutex` port, so give it a real lock if you run more than one worker. With the Symfony Lock component:

```php
final class SymfonyLockMutex implements Mutex
{
    public function __construct(private LockFactory $locks) {}

    public function synchronized(string $key, int $ttlSeconds, int $waitSeconds, Closure $callback): mixed
    {
        $lock = $this->locks->createLock($key, $ttlSeconds);
        $deadline = microtime(true) + $waitSeconds;

        while (! $lock->acquire()) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException("Could not acquire the lock {$key}.");
            }

            usleep(100_000);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
```

Pass it as the fifth argument of `LinearClient`. After acquiring the lock the client reloads the connection (`Connection::reload()`), so make that method re-read the row.

## Events

The sync dispatches three events on the PSR-14 dispatcher you pass in: `LinearIssueCreated`, `LinearIssueFailed` and `LinearCommentDelivered`. Their `$link` / `$delivery` properties are your own `IssueLink` / `CommentDelivery` entities. In Symfony, listen to them like any other event:

```php
#[AsEventListener]
public function onIssueCreated(LinearIssueCreated $event): void
{
    $this->logger->info('Filed '.$event->link->issueIdentifier());
}
```

## Testing

Everything that talks to Linear can be replaced by the fakes, with no HTTP and no framework:

```php
$config = new LinearConfig;
$client = new FakeLinearClient(new FakeLinearOAuth($config), $config);
$client->failWith('createIssue', new LinearApiException('Linear is down', LinearApiException::TRANSIENT));

$store = new InMemoryStore;
$queue = new InMemoryQueue;
$sync = new LinearIssueSync($client, $store, $queue, $config);

$sync->handleEvent($source, 'created');
$queue->work($sync);                          // plays the worker, retrying like the real one

self::assertCount(1, $client->createdIssues());
```

`FakeLinearClient` records every call (`calls()`, `createdIssues()`, `postedComments()`) and can fail any operation on demand. `InMemorySource`, `InMemoryOwner`, `InMemoryConnection` and `InMemoryDestination` let you build the objects the sync needs without writing your own. The `tests/Core` directory of this repository is a good set of examples.

To test your own `LinearStore` and entities, run the same scenarios as `tests/Core/SyncTest.php` against them.

## What is not available: the configuration page

The branded page at `/linear` (connection card, destination form, failures list), its routes, JSON API, back link and Blade components exist **only in the Laravel integration**. They are built from Laravel's routing, middleware, sessions, CSRF, Blade views and Blade components, and there is no equivalent in another framework that this package could plug into.

In Symfony or another framework, build your own screen. Everything it shows is available from the core:

| The page shows | Get it from |
|---|---|
| Teams | `$client->teams($connection)` |
| Projects, statuses, labels, members (with colours, icons and avatars) | `$client->teamOptions($connection, $teamId)` |
| Validating a destination before saving it | `(new DestinationResolver($client))->resolve($connection, $destination)`, which throws `InvalidDestinationException` with field errors keyed like the HTTP contract (`teamId`, `projectId`, ...) |
| Priorities | `LinearPriority::options()` |
| Failed issues and comments | whatever your `LinearStore` keeps; `LinearIssueSync::retry($link)` retries one |

[docs/custom-ui.md](custom-ui.md) documents every field of those objects and how Linear's icons, colours and avatars should be rendered. It is written for Laravel but the data objects are identical.

The page's JavaScript bundle talks to a documented JSON API ([http-contract.md](http-contract.md)). Nothing stops you from implementing those endpoints in your own application and loading the compiled assets from `public/build`, but that is not something the package supports or tests outside Laravel.

## Differences from the Laravel integration

| | Laravel | Other frameworks |
|---|---|---|
| Configuration | `config/linear.php` and `.env` | Build a `LinearConfig` |
| HTTP client | Laravel's (so `Http::fake()` and your HTTP middleware apply) | Any PSR-18 client; set its timeout yourself |
| Storage | Four Eloquent tables from published migrations | Your own: implement `Connection`, `IssueLink`, `CommentDelivery`, `LinearStore` |
| Queue | `CreateLinearIssue` and `DeliverLinearComment` jobs | Your own: implement `SyncQueue` and call `processIssue()` / `processComment()` |
| Token refresh lock | Laravel cache lock, automatic | Provide a `Mutex` if you run several workers |
| Lifecycle hooks | Eloquent events via `CreatesLinearIssues` | Call `handleEvent()` from your ORM |
| Encryption of tokens | `encrypted` cast | Your responsibility |
| Events | Laravel events | PSR-14 |
| Owner / authorization callbacks, back link, branding, login redirect | `Linear::resolveOwnerUsing()`, `authorizeUsing()`, `backUsing()`, ... | Part of the page; not applicable |
| Tests | `Linear::fake()` | `FakeLinearClient` and the `InMemory*` classes |

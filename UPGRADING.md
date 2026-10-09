# Upgrading

## Framework-agnostic release (Laravel is now optional)

The package no longer requires Laravel. Its core (the API client, OAuth, DTOs, the issue/comment sync) depends only on PHP and PSR interfaces, and everything Laravel-specific is an adapter that Laravel's package discovery activates. See [Framework support](README.md#framework-support) and [docs/frameworks.md](docs/frameworks.md).

**A typical Laravel application needs no code changes.** The traits, the `Linear` facade, the Actions, the models and tables, the config file, the publish tags, `Linear::fake()`, the events, the routes and the configuration page all work as before. The notes below are for code that reaches into the package's internals.

### Composer

- The package no longer requires `illuminate/*` (your Laravel application provides it) or `spatie/laravel-package-tools`. If your own code uses Spatie's package tools, require that package yourself.
- It now declares a conflict with `laravel/framework` older than 12, and requires `psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/log` and `psr/event-dispatcher` (all already installed by Laravel).

### Changes that can affect your code

| Before | Now |
|---|---|
| Data objects (`Dniccum\Linear\Data\*`) implemented `Illuminate\Contracts\Support\Arrayable`. | They implement only `JsonSerializable` and still have `toArray()`. `response()->json($dto)`, `json_encode()` and `->toArray()` behave as before; `$dto instanceof Arrayable` is no longer true. |
| `Tokens::expiresAt()` returned a `CarbonImmutable`. | It returns a `DateTimeImmutable` (and accepts an optional "now"). Wrap it in `Carbon::instance()` if you need Carbon. |
| `LinearAuthMode::configured()` | Removed from the enum (it read Laravel's config). Use `Linear::authMode()`. |
| `IssuePayload` was an Eloquent `Castable`. | Use the cast class `Dniccum\Linear\Laravel\Casts\IssuePayloadCast` if you cast a column to a payload yourself. The package's own models already do. |
| `LinearIssueSync` methods took Eloquent models (`handleEvent($model, ...)`, `fileAutomatically`, `sendManually`, `linkFor`, `comment`). | `LinearIssueSync` is framework-agnostic and takes `IssueSource` objects. For models use `Dniccum\Linear\Laravel\EloquentSync`, which has the same methods and takes and returns models. `retry()`, `pushIssue()`, `pushComment()` and `queueComment()` keep their signatures. |
| `LinearClient`, `LinearOAuth`, `DestinationResolver`, `ConnectionClient` received Laravel's HTTP factory and read `config()`; their methods took `LinearConnection`. | They receive a PSR-18 `Transport` and a `LinearConfig`, and their methods take `Contracts\Connection` (which `LinearConnection` implements). Resolve them from the container as before. |
| `DestinationResolver::resolve()` threw `ValidationException`. | It throws `InvalidDestinationException` with the same field-keyed messages. The `SaveDestination` action still throws `ValidationException`, so controllers are unaffected. |
| Events carried `LinearIssueLink` / `LinearCommentDelivery`. | They carry the `IssueLink` / `CommentDelivery` contracts. At runtime they are still the same Eloquent models, so `$event->link->linear_issue_url` works; add a `@var` for static analysis if you want the model type. |
| `FakeLinearClient` / `FakeLinearOAuth` took Laravel's HTTP factory. | They take an optional `LinearConfig`. Use `Linear::fake()` as before. |
| A job's work lived in the job class. | The jobs call `LinearIssueSync::processIssue()` / `processComment()`. `CreateLinearIssue::isRetryable()` and `describe()` still exist. |

### Behaviour notes

- Linear's requests still go through Laravel's HTTP client (so `Http::fake()` and `Http::preventStrayRequests()` keep working), now via a PSR-18 bridge. Every request has a 15 second timeout; revoking a token used to have 10 seconds.
- Token refresh locks still use Laravel's cache locks. A cache store without lock support now runs the refresh unlocked instead of failing.
- With the OAuth `redirect` option unset and the package's routes disabled, resolving the redirect URI now throws a `LogicException` explaining what to configure, instead of a missing-route exception.
- Migrations are published to the same file names as before. If you published them earlier, publishing again overwrites those files rather than adding new ones.

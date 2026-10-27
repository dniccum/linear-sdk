# Contributing

Thank you for considering contributing to this Linear SDK! To maintain a high standard for our code and ensure a smooth process, please follow these guidelines.

## Code of Conduct

Help us keep the Linear community open and inclusive. Please be kind and respectful in all interactions.

## Bug Reports

If you discover a bug, please [open an issue](https://github.com/dniccum/linear-sdk/issues) on GitHub. To help us fix it quickly, please include:
- A clear, descriptive title.
- Steps to reproduce the issue.
- Your environment details (PHP version, Laravel version, OS).
- Expected vs. actual behavior.

## Pull Requests

1. **Fork the repository** and create your branch from `main`.
2. **Install dependencies**:
   ```bash
   composer install
   npm ci
   ```
3. **Coding Style**: We use Laravel Pint (Laravel preset, strict types). You can automatically fix code style issues by running:
   ```bash
   composer format
   ```
4. **Tests**: Ensure that your PR includes tests for any new functionality or bug fixes. We use [Pest](https://pestphp.com/).
   ```bash
   composer test
   ```
5. **Documentation**: If you're adding a new feature or changing an existing one, please update the `README.md` accordingly.
6. **One PR per feature**: Keep your PRs focused. If you have multiple unrelated changes, please submit them as separate pull requests.
7. **Commit messages**: Use descriptive commit messages (e.g., `feat: add support for custom config paths` instead of `update code`).

## Security Vulnerabilities

If you discover a security-related issue, please email doug@dniccumdesign.com instead of using the public issue tracker. We take security seriously and will respond promptly.

## Architecture: core vs. Laravel adapter

The package has a framework-agnostic **core** and a **Laravel adapter** around it. Keep the line between them clean:

- **Core** (`Contracts`, `Enums`, `Events`, `Exceptions`, `LinearConfig`, most of `Data`, `Services\{LinearClient,LinearOAuth,LinearIssueSync,DestinationResolver,ConnectionClient}`, `Testing\InMemory`, the fakes): may use the standalone Illuminate packages (`Str`, `Arr`, `blank()`/`filled()`/`data_get()`, the `illuminate/http` client, the cache and support contracts), Carbon and PSR interfaces, but nothing that needs a Laravel application: no facades, no `config()`, `app()`, `route()` or `now()`, no Eloquent. `tests/Arch/ArchTest.php` keeps it to an allow-list (add to it deliberately), and CI installs the package into an empty project and runs `tests/Standalone/smoke.php`.
- **Laravel adapter** (`Laravel\*`, `Models`, `Concerns`, `Jobs`, `Actions`, `Http`, `View`, `Facades`, `Observers`, the service provider and `Linear`): implements the core's ports (`Connection`, `IssueLink`, `LinearStore`, `SyncQueue`, `Mutex`, ...) on Eloquent, queues and cache locks, and adds the configuration page.

When you add behaviour to the sync, put it in the core and give the new need a port if it touches storage, queues, time or the framework. Test it in `tests/Core` (no Laravel booted, using the `InMemory*` classes) and the Laravel wiring in `tests/Feature`. Avoid giving Eloquent models contract methods that share a name with a column (`status()`, `payload()`, `id()`): Eloquent would try to treat them as relations when the attribute is unset.

## Development Workflow

This package leverages several modern PHP and Laravel features:
- **PHP 8.3+**: Keep code strictly typed (`declare(strict_types=1)`) and compatible with PHP 8.3 and 8.4.
- **Strict typing everywhere**: PHPStan runs at level 9 (`composer analyse`) and TypeScript runs with `strict` (`npm run typecheck`). No `any`, no untyped properties.
- **100% coverage**: `composer test-coverage` and `npm run test:coverage` both enforce 100%.
- **Framework-free core**: see the architecture section above. To check it locally, require this repository from an empty project (`composer config repositories.sdk path ../linear-sdk && composer require dniccum/linear-sdk:@dev`) and run `LINEAR_AUTOLOAD=vendor/autoload.php php ../linear-sdk/tests/Standalone/smoke.php`.
- **Frontend**: The configuration page is TypeScript compiled with Vite. After changing anything under `resources/js` or `resources/css`, run `npm run build` and commit `public/build` (CI fails if it is stale). See [docs/frontend.md](docs/frontend.md).

## Thank You!

Your contributions help make Linear SDK a better tool for the entire Laravel community. We appreciate your time and effort!

---

*Inspired by the [Spatie Contribution Guidelines](https://github.com/spatie/laravel-permission/blob/master/CONTRIBUTING.md).*

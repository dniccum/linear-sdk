# Changelog

All notable changes to `dniccum/linear-sdk` will be documented in this file.

## Initial release - 2026-10-08

### Linear SDK for Laravel 1.0.0

The first stable release of `dniccum/linear-sdk`: create [Linear](https://linear.app) issues from your Eloquent models, the way Laravel Spark handles billing. Add a trait to a model, let your users connect their Linear workspace on a ready-made configuration page, and issues are filed for you from the model's lifecycle events.

> This is an unofficial, community-built package. It is not affiliated with, endorsed by, or sponsored by Linear.

#### Installation

```bash
composer require dniccum/linear-sdk

php artisan vendor:publish --tag=linear-migrations
php artisan migrate
php artisan vendor:publish --tag=linear-assets

```
Requires PHP 8.3+ and Laravel 12 or 13. See the [README](https://github.com/dniccum/linear-sdk#readme) for configuration.

#### Highlights

##### Model traits

- `HasLinearConnection` goes on the owner of a Linear workspace (a user or team).
- `CreatesLinearIssues` goes on any model that should become an issue. It adds `linear_issue_url`, `linear_issue_identifier` and `linear_sync_status`, plus `sendToLinear()`, `retryLinear()` and `commentOnLinear()`.
- Choose which `created`, `updated` and `deleted` events act on Linear with `linearEvents()`, and customize the title, description and comments per model.

##### Authentication

- OAuth 2.0 with PKCE, with automatic, lock-protected token refresh.
- Personal API keys, validated when saved and stored encrypted.

##### Configuration page (optional)

- A branded page at `/linear` for connecting a workspace and choosing the team, project, status, assignee, labels and priority, with **automatic** or **manual** sending.
- A custom, accessible select with Linear colors, team and project icons, status and priority icons, and assignee avatars (with an initials fallback).
- A configurable **Back** link (default `/dashboard`, settable per owner with `Linear::backUsing()`).
- Signed-out users are redirected to a configurable login route (default `login`) when their session expires.
- Written in TypeScript with Alpine and built with Vite. The compiled assets ship with the package, so no Node is needed to use it.
- Every part is optional: turn off the UI, OAuth or API route groups, embed `<x-linear::settings />` in your own layout, or call `Linear::ignoreRoutes()`.

##### Build your own UI

- Headless Actions and typed DTOs (`Team`, `Project`, `WorkflowState`, `Member`) that expose the same colors, icons and avatars as the bundled page.
- A documented HTTP contract (`docs/http-contract.md`) and a worked example in `docs/custom-ui.md`.

##### Reliable by design

- Issues and comments are sent from queued jobs with retries and backoff.
- Creation is idempotent: if a request times out after Linear created the issue, the retry adopts it instead of creating a duplicate.
- Lifecycle hooks never break your own saves; errors are reported, not thrown.
- Failed syncs appear on the configuration page with a retry button.
- `LinearIssueCreated`, `LinearIssueFailed` and `LinearCommentDelivered` events.

##### Testing

- `Linear::fake()` lets your application tests run without any HTTP calls, with assertions such as `assertIssueCreated()` and `failWith()`.
- The package itself is fully typed (PHPStan level 9, strict TypeScript) and tested to 100% coverage on PHP 8.3 and 8.4, Laravel 12 and 13.

#### Upgrade note

The configuration page's JavaScript and CSS are published into your `public` directory. Re-publish them after every update:

```bash
php artisan vendor:publish --tag=linear-assets --force

```
Add it to your application's `post-update-cmd` scripts to automate this. See the README for details.

#### Known limitations

- A sync that is still pending when its model is soft-deleted fails with "no longer exists".
- On SQL Server, only one comment row without a source model can exist (MySQL, PostgreSQL and SQLite are unaffected).
- Applications that have not published the migrations will log a query error on each save of a linked model, although the save itself still succeeds.
- If you use a strict Content-Security-Policy, allow the hosts that serve your members' avatars in `img-src`. The initials fallback is used when images are blocke

**Full Changelog**: https://github.com/dniccum/linear-sdk/compare/v0.2.1...v1.0.0

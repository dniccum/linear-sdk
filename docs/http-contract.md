# HTTP contract

> [!NOTE]
> The HTTP contract is served by the Laravel integration's routes. The configuration page and its JSON API are not available in Symfony or other frameworks (see [Using Linear SDK outside Laravel](frameworks.md#what-is-not-available-the-configuration-page)).

The configuration page is a thin Blade shell plus a TypeScript (Alpine) bundle. Everything it needs comes from this contract, so you can also build your own UI against the same endpoints (or call the `Actions` classes directly).

All routes live under `config('linear.path')` (default `linear`) and are named with the `linear.` prefix. Each group can be disabled via `config('linear.routes.{ui,oauth,api}')` or `Linear::ignoreRoutes()`.

| Group | Method | Path | Name |
|---|---|---|---|
| ui | GET | `/linear` | `linear.settings` |
| oauth | GET | `/linear/connect` | `linear.connect` |
| oauth | GET | `/linear/callback` | `linear.callback` |
| oauth | POST | `/linear/api-key` | `linear.api-key.store` |
| oauth | DELETE | `/linear` | `linear.disconnect` |
| api | GET | `/linear/api/teams` | `linear.api.teams` |
| api | GET | `/linear/api/teams/{team}/options` | `linear.api.team-options` |
| api | PUT | `/linear/api/destination` | `linear.api.destination.update` |
| api | DELETE | `/linear/api/destination` | `linear.api.destination.destroy` |
| api | POST | `/linear/api/issues/{link}/retry` | `linear.api.issues.retry` |

JSON responses use camelCase keys. Validation failures return Laravel's standard 422 `{ message, errors: { field: string[] } }`. A signed-out request returns 401 `{ message }` (or 419 when the session or CSRF token has expired); the bundled UI then sends the browser to `urls.login`. That URL comes from `config('linear.login_route')` (a route name, path or URL; default the `login` route; `null` disables it). Linear outages return 503 `{ message }`; a connection that needs re-authorising returns 409 `{ message, reconnect: true }`.

## Settings payload (embedded in the page)

The settings page renders `<div data-linear-app></div>` and `<script type="application/json" id="linear-settings">…</script>` containing:

```ts
interface Settings {
  configured: boolean;            // OAuth app credentials / API key mode available
  authMode: 'oauth' | 'api_key';
  brand: { name: string; logo: string | null; color: string };
  csrf: string;
  back: null | { label: string; url: string };   // link that lets the user leave the page; null when disabled or unresolvable
  urls: {
    connect: string; apiKey: string; disconnect: string;
    teams: string; teamOptions: string;   // teamOptions contains the literal "{team}" placeholder
    destination: string; retry: string;   // retry contains the literal "{link}" placeholder
    login: string;                        // where to send a signed-out user; '' when no redirect is configured
  };
  connection: null | {
    status: 'active' | 'needs_reconnect';
    organizationName: string | null; organizationUrlKey: string | null;
    userName: string | null; userEmail: string | null;
    lastError: string | null; lastSyncedAt: string | null;   // ISO 8601
  };
  destination: null | Destination;
  failures: Array<{
    id: string; kind: 'issue' | 'comment'; subject: string;
    message: string | null; attempts: number; occurredAt: string | null;
    linkId: string;
  }>;
  flash: { status: string | null; error: string | null };
}

interface Destination {
  sendMode: 'automatic' | 'manual';
  teamId: string; teamName: string | null;
  projectId: string | null; stateId: string | null;
  labelIds: string[]; priority: 0 | 1 | 2 | 3 | 4;
  assigneeId: string | null;
}
```

## API payloads

`GET teams` → `{ teams: Team[] }`

`GET teams/{team}/options` → `TeamOptions`

```ts
interface Team {
  id: string; name: string; key: string;
  color: string | null;   // hex, e.g. "#5e6ad2"
  icon: string | null;    // an emoji or a Linear icon name ("Bug"); never a URL
}

interface TeamOptions {
  states:   Array<{
    id: string; name: string;
    type: string;          // triage | backlog | unstarted | started | completed | canceled | duplicate
    color: string | null;  // hex
  }>;
  projects: Array<{
    id: string; name: string;
    color: string | null;  // hex
    icon: string | null;   // an emoji or a Linear icon name; never a URL
  }>;
  members:  Array<{
    id: string; name: string;
    avatarUrl: string | null;              // absolute image URL; null when the member has no photo
    initials: string | null;               // e.g. "AL", for the fallback avatar
    avatarBackgroundColor: string | null;  // hex behind the initials
  }>;
  labels:   Array<{ id: string; name: string; color: string | null }>;
}
```

The visual fields (`color`, `icon`, `avatarUrl`, `initials`, `avatarBackgroundColor`) are always present and `null` when Linear has no value. Priorities (`0` none, `1` urgent, `2` high, `3` medium, `4` low) have no API data; they are fixed, and `Dniccum\Linear\Enums\LinearPriority` lists them for PHP. The bundled UI tolerates responses from an older server that lack these keys (it treats them as `null`), but other clients should not rely on that.

Avatars are loaded by the browser directly from `avatarUrl`; a strict Content-Security-Policy needs to allow those hosts in `img-src`, and the initials are the fallback when the image is blocked. The DTOs behind these payloads, how to render each field, and a worked custom page are in [docs/custom-ui.md](custom-ui.md).

`PUT destination` body is `Destination` minus `teamName` (the server resolves it) and returns `{ destination: Destination }`.
`DELETE destination` returns `{ destination: null }`.
`POST issues/{link}/retry` returns `{ ok: true }`.

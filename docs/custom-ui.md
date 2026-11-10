# Building your own UI

> [!NOTE]
> This guide is for the Laravel integration. The data objects and the core services it builds on also work in other frameworks (see [Using Linear SDK outside Laravel](frameworks.md)), but the Actions, the HTTP routes and the bundled configuration page it mentions are Laravel-only.

The bundled configuration page is optional. Everything it shows comes from typed, headless pieces you can use directly: the [Actions](../README.md#building-your-own-ui) return readonly data objects (DTOs), and the same data is served as JSON by the [HTTP contract](http-contract.md). This page documents that data (including the visuals Linear sends: colours, icons, avatars) and walks through a complete custom page that does not load the bundled JavaScript at all.

- [Where the data comes from](#where-the-data-comes-from)
- [The data objects](#the-data-objects)
- [Icons: emoji, icon names, and never URLs](#icons-emoji-icon-names-and-never-urls)
- [Colours](#colours)
- [Avatars](#avatars)
- [Workflow state types](#workflow-state-types)
- [Priority](#priority)
- [Content-Security-Policy](#content-security-policy)
- [Worked example: a custom page without the bundled UI](#worked-example-a-custom-page-without-the-bundled-ui)

## Where the data comes from

| You call | You get | Same data over HTTP |
|---|---|---|
| `app(ListTeams::class)->execute($owner)` / `$owner->linearClient()->teams()` / `Linear::client($owner)->teams()` | `list<Team>` | `GET /linear/api/teams` |
| `app(ListTeamOptions::class)->execute($owner, $teamId)` / `...->teamOptions($teamId)` | `TeamOptions` (projects, states, labels, members) | `GET /linear/api/teams/{team}/options` |

All DTOs are `final readonly` classes in `Dniccum\Linear\Data`. `toArray()` (and `json_encode()`) produce exactly the JSON the endpoints return, with camelCase keys. Every visual field is **present but nullable**: when Linear omits a value, or an older payload never had it, the property is `null`; nothing throws. `Linear::fake()` accepts the same DTOs, so you can build them in your own tests (`new Team('team-1', 'Support', 'SUP', '#5e6ad2', '🛟')`); the new constructor arguments are optional and trailing, so existing code keeps working.

## The data objects

### `Dniccum\Linear\Data\Team`

| Property | Type | Meaning | Example |
|---|---|---|---|
| `id` | `string` | Linear's team ID. | `"3f1c…"` |
| `name` | `string` | Display name. | `"Support"` |
| `key` | `string` | Short issue prefix (also a good fallback label for a tile). | `"SUP"` |
| `color` | `?string` | Hex colour of the team. | `"#5e6ad2"` |
| `icon` | `?string` | An emoji **or** the name of one of Linear's built-in icons. Not a URL. | `"🛟"`, `"Bug"` |

Helper: `iconIsEmoji(): bool`.

### `Dniccum\Linear\Data\Project`

| Property | Type | Meaning | Example |
|---|---|---|---|
| `id`, `name` | `string` | | `"Onboarding revamp"` |
| `color` | `?string` | Hex colour (Linear always sets one, but treat it as nullable). | `"#f2994a"` |
| `icon` | `?string` | An emoji or a built-in icon name. Not a URL. | `"🚀"`, `"Mobile"` |

Helper: `iconIsEmoji(): bool`. Only open projects (not completed or canceled) are returned.

### `Dniccum\Linear\Data\WorkflowState` (status)

| Property | Type | Meaning | Example |
|---|---|---|---|
| `id`, `name` | `string` | | `"In Review"` |
| `type` | `string` | The category of the status; see [Workflow state types](#workflow-state-types). Kept as a string so a type Linear adds later still passes through. | `"started"` |
| `color` | `?string` | Hex colour of the status. | `"#0f783c"` |

Helper: `kind(): ?LinearStateType` (null for an unknown type). States are ordered the way Linear orders them in the team.

### `Dniccum\Linear\Data\Member` (assignee)

Only active members are returned, sorted by the name shown.

| Property | Type | Meaning | Example |
|---|---|---|---|
| `id` | `string` | | |
| `name` | `string` | The member's display name, or their full name when they have none. | `"ada"` |
| `avatarUrl` | `?string` | Absolute image URL, or `null` when the member has no photo. | `"https://…/ada.png"` |
| `initials` | `?string` | Linear's initials for the member. | `"AL"` |
| `avatarBackgroundColor` | `?string` | Hex colour Linear draws behind the initials. | `"#5e6ad2"` |

Helper: `displayInitials(): string` returns `initials`, otherwise the first letters of the first two words of `name`, otherwise `"?"`.

### `Dniccum\Linear\Data\Label` (unchanged)

`id: string`, `name: string`, `color: ?string` (hex).

### `Dniccum\Linear\Data\TeamOptions`

`team: Team`, `projects: list<Project>`, `states: list<WorkflowState>`, `labels: list<Label>`, `members: list<Member>`. The `hasProject()`, `hasState()`, `hasMember()` and `hasLabels()` methods are what destination validation uses; they are unchanged.

## Icons: emoji, icon names, and never URLs

`Team::$icon` and `Project::$icon` are Linear's free-form icon string. They are **not image URLs** and must never be used as an `<img src>`. Expect either:

- an emoji: `"🚀"`, `"🛟"`, `"🇳🇱"`. Show it as text, usually on a tile in the item's colour; or
- the name of one of Linear's built-in icons: `"Bug"`, `"Mobile"`, `"Wrench"`. This package does not ship Linear's icon set, so draw your own glyph, or fall back to letters (a team's `key`) on a tile in the item's colour.

`iconIsEmoji()` tells the two apart (it checks that the value starts with an emoji; it does not look at the rest of the string). `null` means no icon. Legacy shortcodes such as `":rocket:"` are not emoji; treat them like icon names.

## Colours

Colours are CSS hex strings (`#rgb`, `#rrggbb`, sometimes with alpha). Linear is the source, but validate before putting one in a `style` attribute (the worked example does) and have a fallback for `null`. For text on a coloured tile, choose white or near-black by luminance; the bundled UI does this for you.

## Avatars

`avatarUrl` is loaded straight from Linear's image hosts: the package never proxies or stores it. Recommended rendering:

1. Draw a circle in `avatarBackgroundColor` (fallback to a neutral grey) containing `displayInitials()`.
2. Lay the `<img>` over it with `alt=""`, `loading="lazy"` and `referrerpolicy="no-referrer"`.

When the image is missing, blocked by your Content-Security-Policy, or fails to load, the initials underneath show through. No JavaScript is needed. The bundled UI does the same and additionally hides a failed image.

## Workflow state types

`WorkflowState::$type` is one of Linear's categories; `LinearStateType` (`Dniccum\Linear\Enums\LinearStateType`) names them and has a `label()`:

| `type` | Enum case | Meaning | Linear's icon |
|---|---|---|---|
| `triage` | `Triage` | New issues waiting to be sorted. | circle with an inbox arrow |
| `backlog` | `Backlog` | Not planned yet. | dashed circle |
| `unstarted` | `Unstarted` | Planned, not started (usually "Todo"). | empty circle |
| `started` | `Started` | In progress, including review. | half-filled circle |
| `completed` | `Completed` | Done. | filled circle with a check |
| `canceled` | `Canceled` | Closed without being done. | filled circle with an x |
| `duplicate` | `Duplicate` | Closed as a duplicate; drawn like a cancellation. | filled circle with an x |

Treat any other value as "unstarted" (`kind()` returns `null` so you can tell). A team can have several statuses of the same type ("In Progress" and "In Review" are both `started`), so identify a status by `id`, not `type`.

## Priority

Linear's API has no endpoint for priorities, so the scale lives in `Dniccum\Linear\Enums\LinearPriority`, an int-backed enum:

| Case | `value` | `label()` |
|---|---|---|
| `NoPriority` | `0` | No priority |
| `Urgent` | `1` | Urgent |
| `High` | `2` | High |
| `Medium` | `3` | Medium |
| `Low` | `4` | Low |

`LinearPriority::options()` returns `list<array{value: int, label: string}>` for rendering a select or radio group, and `LinearPriority::fromNumber(?int)` turns a stored number into a case (`null` and anything outside 0-4 become `NoPriority`). `Destination::priorityLevel()` does the same for a destination. `Destination::$priority` and the `priority` field of the HTTP contract stay plain integers.

## Content-Security-Policy

Icons, tiles and the status and priority glyphs need nothing from the network. **Avatars do**: the browser requests each `avatarUrl` directly from the host in the URL. If your application sends a strict `Content-Security-Policy`, its `img-src` must allow those hosts, or the images are blocked and only the initials fallback shows (which still works, and is not an error). Linear serves uploaded avatars from its own domains, but a member who signs in with Google or GitHub can have an avatar on that provider's domain, so inspect the `avatarUrl` values your workspace returns and allow those hosts, or allow `https:` for `img-src`. The URLs are plain `https`; `data:` URLs are never needed.

## Worked example: a custom page without the bundled UI

This example replaces the bundled page with a controller and a Blade view: colour tiles for teams and projects, avatars with an initials fallback, status and priority labels, and a save action that uses `SaveDestination`. It is the code the package's own test suite runs (`tests/Feature/CustomUiTest.php`), so it works as written. The view deliberately uses plain HTML and a few inline styles; swap in your own components.

### 1. Turn the bundled page off, keep the OAuth routes

```php
// config/linear.php
'routes' => ['ui' => false, 'oauth' => true, 'api' => false],
```

With `ui` off, `GET /linear` is not registered. Keeping `oauth` on gives you `route('linear.connect')`, the callback and the disconnect route; `api` can stay off because your controller calls the Actions directly. Your own routes use your own middleware, so authorize them yourself (the package's `Linear::authorizeUsing()` only guards the package's routes).

### 2. Routes

```php
// routes/web.php
use App\Http\Controllers\LinearSettingsController;

Route::middleware(['web', 'auth'])->prefix('integrations/linear')->group(function () {
    Route::get('/', [LinearSettingsController::class, 'show'])->name('integrations.linear');
    Route::post('/', [LinearSettingsController::class, 'update'])->name('integrations.linear.update');
});
```

### 3. The controller

The owner is any model that uses `HasLinearConnection` (here `App\Models\User`). The Actions throw `LinearApiException` when Linear is not connected or cannot be reached, and `SaveDestination` throws a `ValidationException` (Laravel turns it into a normal redirect back with errors) when a team, project, status or assignee no longer exists in the workspace.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Dniccum\Linear\Actions\ListTeamOptions;
use Dniccum\Linear\Actions\ListTeams;
use Dniccum\Linear\Actions\SaveDestination;
use Dniccum\Linear\Data\Destination;
use Dniccum\Linear\Enums\LinearPriority;
use Dniccum\Linear\Enums\LinearSendMode;
use Dniccum\Linear\Exceptions\LinearApiException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LinearSettingsController
{
    public function show(Request $request, ListTeams $listTeams, ListTeamOptions $listOptions): View
    {
        /** @var User $owner */
        $owner = $request->user();
        $saved = $owner->linearDestination;

        try {
            $teams = $listTeams->execute($owner);
            $teamId = $request->string('team')->toString() ?: $saved?->team_id ?? ($teams[0]->id ?? null);
            $options = $teamId === null ? null : $listOptions->execute($owner, $teamId);
        } catch (LinearApiException $exception) {
            return view('integrations.linear', ['error' => $exception->getMessage(), 'teams' => [], 'options' => null, 'saved' => $saved]);
        }

        return view('integrations.linear', [
            'error' => null,
            'teams' => $teams,
            'options' => $options,
            'saved' => $saved,
            'priorities' => LinearPriority::options(),
        ]);
    }

    public function update(Request $request, SaveDestination $save): RedirectResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        $data = $request->validate([
            'team' => ['required', 'string'],
            'project' => ['nullable', 'string'],
            'state' => ['nullable', 'string'],
            'assignee' => ['nullable', 'string'],
            'priority' => ['nullable', 'integer', Rule::enum(LinearPriority::class)],
        ]);

        // SaveDestination re-checks every ID against Linear and throws a
        // ValidationException (a normal redirect back with errors) if one is stale.
        $save->execute($owner, new Destination(
            teamId: $data['team'],
            projectId: $data['project'] ?? null,
            stateId: $data['state'] ?? null,
            priority: isset($data['priority']) ? (int) $data['priority'] : null,
            assigneeId: $data['assignee'] ?? null,
        ), LinearSendMode::Automatic);

        return back()->with('status', 'Destination saved.');
    }
}
```

### 4. The view

`resources/views/integrations/linear.blade.php`:

```blade
@php
    // Colours come from Linear, but never put an unchecked string in a style attribute.
    $color = fn (?string $value, string $fallback) => preg_match('/^#[0-9a-f]{3,8}$/i', (string) $value) ? $value : $fallback;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Linear</title>
    <style>
        .tile, .avatar { display: inline-flex; position: relative; width: 20px; height: 20px; align-items: center; justify-content: center; overflow: hidden; font: 600 10px/1 system-ui; color: #fff; vertical-align: middle; }
        .tile { border-radius: 6px; }
        .avatar { border-radius: 50%; }
        .avatar img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>
    <h1>Linear</h1>

    @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
    @if ($error) <p role="alert">{{ $error }}</p> @endif

    @if (! auth()->user()->hasLinearConnection())
        <a href="{{ route('linear.connect') }}">Connect Linear</a>
    @endif

    <h2>Team</h2>
    <ul>
        @foreach ($teams as $team)
            <li>
                <a href="{{ request()->url() }}?team={{ $team->id }}">
                    <span class="tile" style="background: {{ $color($team->color, '#5e6ad2') }}">
                        {{-- icon is an emoji or a Linear icon name, never a URL --}}
                        {{ $team->iconIsEmoji() ? $team->icon : mb_strtoupper(mb_substr($team->key, 0, 2)) }}
                    </span>
                    {{ $team->name }}
                </a>
            </li>
        @endforeach
    </ul>

    @if ($options)
        <form method="post" action="{{ route('integrations.linear.update') }}">
            @csrf
            <input type="hidden" name="team" value="{{ $options->team->id }}">

            <fieldset>
                <legend>Project</legend>
                <label><input type="radio" name="project" value="" @checked(! $saved?->project_id)> No project</label>
                @foreach ($options->projects as $project)
                    <label>
                        <input type="radio" name="project" value="{{ $project->id }}" @checked($saved?->project_id === $project->id)>
                        <span class="tile" style="background: {{ $color($project->color, '#8a8f98') }}">{{ $project->iconIsEmoji() ? $project->icon : '' }}</span>
                        {{ $project->name }}
                    </label>
                @endforeach
            </fieldset>

            <fieldset>
                <legend>Status</legend>
                <label><input type="radio" name="state" value="" @checked(! $saved?->state_id)> Team default</label>
                @foreach ($options->states as $state)
                    <label>
                        <input type="radio" name="state" value="{{ $state->id }}" @checked($saved?->state_id === $state->id)>
                        <span style="color: {{ $color($state->color, '#8a8f98') }}">&#9679;</span>
                        {{ $state->name }}
                        {{-- kind() is null for a type this package does not know --}}
                        <small>({{ $state->kind()?->label() ?? $state->type }})</small>
                    </label>
                @endforeach
            </fieldset>

            <fieldset>
                <legend>Assignee</legend>
                <label><input type="radio" name="assignee" value="" @checked(! $saved?->assignee_id)> Unassigned</label>
                @foreach ($options->members as $member)
                    <label>
                        <input type="radio" name="assignee" value="{{ $member->id }}" @checked($saved?->assignee_id === $member->id)>
                        {{-- Initials sit underneath the photo: if the image is blocked or fails, they show through. --}}
                        <span class="avatar" style="background: {{ $color($member->avatarBackgroundColor, '#8a8f98') }}">
                            {{ $member->displayInitials() }}
                            @if ($member->avatarUrl)
                                <img src="{{ $member->avatarUrl }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                            @endif
                        </span>
                        {{ $member->name }}
                    </label>
                @endforeach
            </fieldset>

            <fieldset>
                <legend>Priority</legend>
                @foreach ($priorities as $priority)
                    <label>
                        <input type="radio" name="priority" value="{{ $priority['value'] }}" @checked(($saved?->priority ?? 0) === $priority['value'])>
                        {{ $priority['label'] }}
                    </label>
                @endforeach
            </fieldset>

            <button>Save</button>
        </form>
    @endif
</body>
</html>
```

### What to notice

- The team tile shows `icon` only when `iconIsEmoji()` is true; otherwise the first letters of `key`. The icon string is never used as an image source.
- Every colour goes through the `$color` closure, which accepts only a hex string and substitutes a fallback otherwise, so API data never reaches a `style` attribute unchecked.
- The avatar is a coloured circle with `displayInitials()` and, on top of it, the `<img>` when there is an `avatarUrl`. A failing or CSP-blocked image simply leaves the initials visible.
- A status shows its `name`, and `kind()?->label()` as the category, falling back to the raw `type` for a type this package does not know.
- Priorities come from `LinearPriority::options()`; no number is hard-coded, and the controller validates with `Rule::enum(LinearPriority::class)`.

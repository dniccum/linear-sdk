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

<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Fixtures\CustomUi;

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
use Workbench\App\Models\User;

/**
 * The controller from docs/custom-ui.md. Keep the two in sync: this copy is
 * what the test suite runs.
 */
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

<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Controllers;

use Dniccum\Linear\Actions\ListTeamOptions;
use Dniccum\Linear\Actions\ListTeams;
use Dniccum\Linear\Data\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The teams of the connected workspace, and the projects, statuses, labels
 * and members inside one of them.
 */
class TeamController extends Controller
{
    public function index(Request $request, ListTeams $action): JsonResponse
    {
        return $this->json(fn (): JsonResponse => response()->json([
            'teams' => array_map(fn (Team $team): array => $team->toArray(), $action->execute($this->owner($request))),
        ]));
    }

    public function options(Request $request, ListTeamOptions $action, string $team): JsonResponse
    {
        return $this->json(fn (): JsonResponse => response()->json($action->execute($this->owner($request), $team)));
    }
}

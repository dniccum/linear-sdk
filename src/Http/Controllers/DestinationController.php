<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Controllers;

use Dniccum\Linear\Actions\DeleteDestination;
use Dniccum\Linear\Actions\SaveDestination;
use Dniccum\Linear\Data\Settings\DestinationData;
use Dniccum\Linear\Http\Requests\SaveDestinationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's destination: where issues are filed and how they are sent.
 */
class DestinationController extends Controller
{
    public function update(SaveDestinationRequest $request, SaveDestination $action): JsonResponse
    {
        return $this->json(fn (): JsonResponse => response()->json([
            'destination' => DestinationData::fromModel(
                $action->execute($this->owner($request), $request->destination(), $request->sendMode()),
            ),
        ]));
    }

    public function destroy(Request $request, DeleteDestination $action): JsonResponse
    {
        $action->execute($this->owner($request));

        return response()->json(['destination' => null]);
    }
}

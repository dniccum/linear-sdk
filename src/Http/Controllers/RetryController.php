<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Controllers;

use Dniccum\Linear\Actions\RetryFailedSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Requeue an issue (or comments on it) that failed to reach Linear.
 */
class RetryController extends Controller
{
    public function __invoke(Request $request, RetryFailedSync $action, string $link): JsonResponse
    {
        $action->execute($this->owner($request), $link);

        return response()->json(['ok' => true]);
    }
}

<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Controllers;

use Closure;
use Dniccum\Linear\Data\Settings\FlashData;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Linear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\ValidationException;

abstract class Controller extends BaseController
{
    public function __construct(
        protected readonly Linear $linear,
    ) {}

    /**
     * The owner of the connection for this request. The AuthorizeLinear
     * middleware has already ensured there is one.
     */
    protected function owner(Request $request): Model
    {
        return $this->linear->resolveOwner($request) ?? abort(403);
    }

    /**
     * Run a JSON endpoint, translating Linear failures into the contract's
     * error responses: 409 `{ message, reconnect: true }` when the connection
     * needs re-authorising, 503 `{ message }` for outages and rate limits, and
     * a standard 422 for anything else.
     *
     * @param  Closure(): JsonResponse  $callback
     */
    protected function json(Closure $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (LinearApiException $e) {
            return $this->apiError($e);
        }
    }

    protected function apiError(LinearApiException $e): JsonResponse
    {
        if ($e->requiresReconnect()) {
            return response()->json(['message' => $e->getMessage(), 'reconnect' => true], 409);
        }

        if ($e->isRetryable()) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        throw ValidationException::withMessages(['linear' => $e->getMessage()]);
    }

    /**
     * Back to the settings page with a one-off message.
     */
    protected function flash(string $key, string $message): RedirectResponse
    {
        return redirect()->to($this->linear->settingsUrl())->with($key, $message);
    }

    protected function flashStatus(string $message): RedirectResponse
    {
        return $this->flash(FlashData::STATUS_KEY, $message);
    }

    protected function flashError(string $message): RedirectResponse
    {
        return $this->flash(FlashData::ERROR_KEY, $message);
    }
}

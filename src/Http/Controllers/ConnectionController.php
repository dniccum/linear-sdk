<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Controllers;

use Dniccum\Linear\Actions\BuildConnectUrl;
use Dniccum\Linear\Actions\DisconnectLinear;
use Dniccum\Linear\Actions\HandleOAuthCallback;
use Dniccum\Linear\Actions\SaveApiKey;
use Dniccum\Linear\Data\Settings\ConnectionData;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Exceptions\LinearApiException;
use Dniccum\Linear\Http\Requests\OAuthCallbackRequest;
use Dniccum\Linear\Http\Requests\SaveApiKeyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Connecting and disconnecting a Linear workspace. These endpoints are
 * navigated to or submitted as plain forms, so they answer with a redirect
 * and a flash message; a client that asks for JSON gets JSON instead.
 */
class ConnectionController extends Controller
{
    /**
     * Send the user to Linear to authorize the workspace.
     */
    public function connect(Request $request, BuildConnectUrl $action): RedirectResponse
    {
        abort_unless($this->linear->authMode() === LinearAuthMode::OAuth, 404);

        try {
            return redirect()->away($action->execute($request->session()));
        } catch (LinearApiException) {
            abort(404);
        }
    }

    /**
     * Linear's redirect back: store the connection for the chosen workspace.
     */
    public function callback(OAuthCallbackRequest $request, HandleOAuthCallback $action): RedirectResponse
    {
        abort_unless($this->linear->authMode() === LinearAuthMode::OAuth, 404);

        $result = $action->execute(
            $this->owner($request),
            $request->session(),
            $request->state(),
            $request->code(),
            $request->error(),
        );

        return $result->connected ? $this->flashStatus($result->message) : $this->flashError($result->message);
    }

    /**
     * Validate a personal API key against Linear and store it encrypted.
     */
    public function storeApiKey(SaveApiKeyRequest $request, SaveApiKey $action): RedirectResponse|JsonResponse
    {
        abort_unless($this->linear->authMode() === LinearAuthMode::ApiKey, 404);

        try {
            $connection = $action->execute($this->owner($request), $request->apiKey());
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return $this->flashError(array_merge([], ...array_values($e->errors()))[0] ?? $e->getMessage());
        } catch (LinearApiException $e) {
            return $request->expectsJson() ? $this->apiError($e) : $this->flashError($e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['connection' => ConnectionData::fromModel($connection)]);
        }

        return $this->flashStatus("Connected to {$connection->organization_name} on Linear.");
    }

    public function destroy(Request $request, DisconnectLinear $action): RedirectResponse|JsonResponse
    {
        $disconnected = $action->execute($this->owner($request));

        if ($request->expectsJson()) {
            return response()->json(['connection' => null]);
        }

        return $this->flashStatus($disconnected ? 'Linear has been disconnected.' : 'Linear is not connected.');
    }
}

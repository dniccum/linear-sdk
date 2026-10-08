<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Middleware;

use Closure;
use Dniccum\Linear\Linear;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the `Linear::authorizeUsing()` gate (by default: anyone with a
 * resolvable owner) to every route of the package, and sends signed-out
 * visitors to the configured login page.
 */
final readonly class AuthorizeLinear
{
    public function __construct(
        private Linear $linear,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->redirectGuests($request);

        abort_unless($this->linear->authorize($request), 403);

        /** @var Response */
        return $next($request);
    }

    /**
     * A signed-out visitor is sent to the configured login page (a 401 for
     * JSON requests) rather than refused with a 403. Signed-in users that
     * simply have no owner still get the 403.
     */
    private function redirectGuests(Request $request): void
    {
        $login = $this->linear->loginUrl();

        if ($login === null || $request->user() !== null || $this->linear->resolveOwner($request) !== null) {
            return;
        }

        throw new AuthenticationException('Unauthenticated.', [], $login);
    }
}

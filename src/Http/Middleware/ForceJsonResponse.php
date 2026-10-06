<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API routes always speak JSON, so validation failures are 422 bodies
 * rather than redirects even when the client forgot the Accept header.
 */
final readonly class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        /** @var Response */
        return $next($request);
    }
}

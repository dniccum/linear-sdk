<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Middleware;

use Closure;
use Dniccum\Linear\Linear;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the `Linear::authorizeUsing()` gate (by default: anyone with a
 * resolvable owner) to every route of the package.
 */
final readonly class AuthorizeLinear
{
    public function __construct(
        private Linear $linear,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->linear->authorize($request), 403);

        /** @var Response */
        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace Workbench\App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Workbench\App\Models\User;
use Workbench\App\Support\DemoData;

/**
 * Signs the demo user in on every request, so the workbench has no login
 * screen. In fake mode it also seeds the demo data once.
 */
final readonly class LoginDemoUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => 'password'],
        );

        if (config('linear.workbench.fake') === true && config('linear.workbench.seed') === true) {
            DemoData::seed($user);
        }

        if (! Auth::check()) {
            Auth::login($user);
        }

        /** @var Response */
        return $next($request);
    }
}

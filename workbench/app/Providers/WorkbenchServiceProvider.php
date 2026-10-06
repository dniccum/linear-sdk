<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Dniccum\Linear\Facades\Linear;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Http\Middleware\LoginDemoUser;
use Workbench\App\Models\User;

/**
 * Wires up the workbench app used to develop the configuration page:
 * `composer serve` opens it at /linear with a demo user signed in.
 *
 * Set LINEAR_WORKBENCH_FAKE=true to run against `Linear::fake()` instead of
 * Linear itself. A demo connection and a failed issue are seeded
 * (LINEAR_WORKBENCH_SEED=false to start disconnected). With
 * LINEAR_AUTH_MODE=api_key any API key is accepted.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'auth.providers.users.model' => User::class,
            'linear.owner_model' => User::class,
            'linear.workbench.fake' => filter_var(Env::get('LINEAR_WORKBENCH_FAKE', false), FILTER_VALIDATE_BOOL),
            'linear.workbench.seed' => filter_var(Env::get('LINEAR_WORKBENCH_SEED', true), FILTER_VALIDATE_BOOL),
        ]);
    }

    public function boot(Router $router, Kernel $kernel): void
    {
        // Sign in right after the session starts, ahead of the auth
        // middleware. The priority is synced first: it re-syncs the kernel's
        // groups to the router, which would drop the push below.
        if ($kernel instanceof HttpKernel) {
            $kernel->addToMiddlewarePriorityAfter(StartSession::class, LoginDemoUser::class);
        }

        $router->pushMiddlewareToGroup('web', LoginDemoUser::class);

        if (config('linear.workbench.fake') === true) {
            Linear::fake();
        }
    }
}

<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests\Fixtures;

use Dniccum\Linear\Facades\Linear;
use Illuminate\Support\ServiceProvider;

/**
 * What an application's AppServiceProvider does to build its own UI.
 */
class IgnoreRoutesProvider extends ServiceProvider
{
    public function register(): void
    {
        Linear::ignoreRoutes();
    }
}

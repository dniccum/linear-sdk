<?php

declare(strict_types=1);

namespace Dniccum\Linear\Tests;

use Dniccum\Linear\LinearServiceProvider;
use Dniccum\Linear\Tests\Fixtures\IgnoreRoutesProvider;
use Illuminate\Foundation\Application;

/**
 * Boots with a provider that calls `Linear::ignoreRoutes()` from `register()`,
 * exactly as an application's AppServiceProvider would.
 */
abstract class IgnoredRoutesTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LinearServiceProvider::class, IgnoreRoutesProvider::class];
    }
}

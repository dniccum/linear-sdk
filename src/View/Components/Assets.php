<?php

declare(strict_types=1);

namespace Dniccum\Linear\View\Components;

use Dniccum\Linear\Support\LinearAssets;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;

/**
 * `<x-linear::assets />`: the script and stylesheet tags of the configuration
 * page. Equivalent to the `@linearAssets` directive.
 */
final class Assets extends Component
{
    public function render(): HtmlString
    {
        return app(LinearAssets::class)->render();
    }
}

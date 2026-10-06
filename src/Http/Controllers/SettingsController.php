<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The branded configuration page.
 */
class SettingsController extends Controller
{
    public function show(Request $request): View
    {
        return view('linear::settings', [
            'settings' => $this->linear->settingsFor($this->owner($request)),
        ]);
    }
}

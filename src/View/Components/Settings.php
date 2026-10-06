<?php

declare(strict_types=1);

namespace Dniccum\Linear\View\Components;

use Dniccum\Linear\Data\Settings\SettingsData;
use Dniccum\Linear\Linear;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;

/**
 * `<x-linear::settings />`: the configuration page, embeddable in your own
 * layout. It renders the mount element, the settings JSON and (unless
 * `:assets="false"`) the asset tags.
 *
 * The owner defaults to the one resolved for the current request; pass
 * `:owner="$team"` to use another model. Nothing is rendered when there is no
 * owner.
 */
final class Settings extends Component
{
    /**
     * Private on purpose: public properties are shared with the view and would
     * overwrite the variables the view is given.
     */
    public function __construct(
        private readonly ?Model $owner = null,
        private readonly ?SettingsData $settings = null,
        private readonly bool $assets = true,
    ) {}

    public function render(): View|string
    {
        $linear = app(Linear::class);
        $owner = $this->owner ?? $linear->resolveOwner(request());
        $settings = $this->settings ?? ($owner === null ? null : $linear->settingsFor($owner));

        if ($settings === null) {
            return '';
        }

        return view('linear::partials.app', ['settings' => $settings, 'assets' => $this->assets]);
    }
}

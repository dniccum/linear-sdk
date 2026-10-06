{{--
    The mount point of the configuration page: the element the front-end
    bundle takes over, the settings it boots from, and the asset tags.
    Expects $settings (Dniccum\Linear\Data\Settings\SettingsData) and $assets
    (whether to include the asset tags).
--}}
@if ($assets)
    @linearAssets
@endif
<div data-linear-app class="linear-app" style="--linear-accent: {{ $settings->brand->color }}">
    @include('linear::partials.header', ['brand' => $settings->brand])
    <noscript>
        <p>JavaScript is required to configure the Linear integration.</p>
    </noscript>
</div>
<script type="application/json" id="linear-settings">{!! $settings->toScriptJson() !!}</script>

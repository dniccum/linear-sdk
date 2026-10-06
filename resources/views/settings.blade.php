{{-- The Linear configuration page. Receives $settings (Dniccum\Linear\Data\Settings\SettingsData). --}}
@extends('linear::layout')

@section('title', $settings->brand->name === \Dniccum\Linear\Data\Settings\BrandData::DEFAULT_NAME ? 'Linear integration' : 'Linear integration · '.$settings->brand->name)

@section('content')
    <x-linear::settings :settings="$settings" />
@endsection

{{--
    Standalone page shell for the Linear configuration page. It has no
    dependency on the host application's layout. Publish it with
    `php artisan vendor:publish --tag=linear-views` to restyle or replace it,
    or embed <x-linear::settings /> in your own layout instead.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Linear integration')</title>
</head>
<body>
@yield('content')
</body>
</html>

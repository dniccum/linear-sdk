{{--
    The branded header. It is the server-rendered fallback inside the mount
    element; the front-end bundle replaces it with its own once it boots.
    Expects $brand (Dniccum\Linear\Data\Settings\BrandData) and, optionally,
    $back (Dniccum\Linear\Data\Settings\BackData|null).
--}}
@if (($back ?? null) !== null)
    <a class="linear-back" href="{{ $back->url }}">{{ $back->label }}</a>
@endif
<header class="linear-header">
    @if ($brand->logo !== null)
        <img class="linear-header__logo" src="{{ $brand->logo }}" alt="" width="80" height="80">
    @endif
    <div class="linear-header__text">
        <p class="linear-header__eyebrow">{{ $brand->name }}</p>
        <h1 class="linear-header__title">Linear integration</h1>
        <p class="linear-header__subtitle">Connect your Linear workspace and choose where new issues are created.</p>
    </div>
</header>

{{--
    The branded header. It is the server-rendered fallback inside the mount
    element; the front-end bundle replaces it with its own once it boots.
    Expects $brand (Dniccum\Linear\Data\Settings\BrandData).
--}}
<header class="linear-header">
    @if ($brand->logo !== null)
        <img class="linear-header__logo" src="{{ $brand->logo }}" alt="" width="44" height="44">
    @endif
    <div class="linear-header__text">
        <p class="linear-header__eyebrow">{{ $brand->name }}</p>
        <h1 class="linear-header__title">Linear integration</h1>
        <p class="linear-header__subtitle">Connect your Linear workspace and choose where new issues are created.</p>
    </div>
</header>

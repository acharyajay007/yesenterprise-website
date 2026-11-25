@php
    $locale = app()->getLocale();
    $parameters = request()->route()?->parameters() ?? [];
    $routeName = request()->route()?->getName();
    $canonical = $routeName ? route($routeName, $parameters) : url()->current();
    $seoTitle = trim($title ?? __('footerIntro'));
    $seoDescription = strip_tags($description ?? __('footerIntro'));
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seoTitle }} | Yes Enterprise Rajkot</title>
    <meta name="description" content="{{ $seoDescription }}">
    <meta name="robots" content="{{ config('website.indexable') && !request()->query() ? 'index,follow' : 'noindex,follow' }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if($routeName)
        @foreach(config('website.locales') as $language)
            <link rel="alternate" hreflang="{{ $language }}" href="{{ route($routeName, array_merge($parameters, ['locale' => $language])) }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ route($routeName, array_merge($parameters, ['locale' => config('website.default_locale')])) }}">
    @endif
    <meta property="og:title" content="{{ $seoTitle }} | Yes Enterprise Rajkot">
    <meta property="og:description" content="{{ $seoDescription }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:type" content="website">
    <link rel="icon" type="image/png" href="{{ asset('assets/yes-enterprise-logo.png') }}">
    <link rel="stylesheet" href="{{ asset('vendor/industro/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/industro/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('style.css') }}">
    <link rel="stylesheet" href="{{ asset('industro.css') }}">
    <link rel="stylesheet" href="{{ asset('website.css') }}">
    @stack('assets')
    <link rel="stylesheet" href="{{ asset('showcase.css') }}">
    <script src="{{ asset('photo-viewer.js') }}" defer></script>
    <script src="{{ asset('website.js') }}" defer></script>
    <script type="application/ld+json">{!! \Illuminate\Support\Js::encode([
        '@context' => 'https://schema.org',
        '@type' => 'Store',
        'name' => 'Yes Enterprise Rajkot',
        'logo' => asset('assets/yes-enterprise-logo.png'),
        'url' => route('home', ['locale' => $locale]),
        'telephone' => str_replace(' ', '', config('website.phone')),
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Bilkha Plaza, Kasturba Rd, Naval Nagar, Sadar', 'addressLocality' => 'Rajkot', 'addressRegion' => 'Gujarat', 'postalCode' => '360001', 'addressCountry' => 'IN'],
        'hasMap' => config('website.maps_url'),
    ]) !!}</script>
</head>
<body>
<a class="skip" href="#main">{{ __('skip') }}</a>
<header>
    <div class="industro-topbar"><div class="masthead wrap">
        <a class="topbar-phone" href="tel:{{ str_replace(' ', '', config('website.phone')) }}" aria-label="{{ __('business.callUs') }}: {{ config('website.phone') }}"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M7 3H4a1 1 0 0 0-1 1c0 9.4 7.6 17 17 17a1 1 0 0 0 1-1v-3l-5-2-2 2a14 14 0 0 1-7-7l2-2-2-5Z" stroke-linejoin="round"/></svg><span>{{ config('website.phone') }}</span></a>
        <form class="global-search" action="{{ route('catalogue', ['locale' => $locale]) }}" role="search">
            <input type="search" name="q" maxlength="150" placeholder="{{ __('searchGlobal') }}" aria-label="{{ __('searchGlobal') }}">
            <button aria-label="{{ __('search') }}">→</button>
        </form>
        <div class="languages" aria-label="{{ __('language') }}">
            @foreach(config('website.locales') as $language)
                <a lang="{{ $language }}" hreflang="{{ $language }}" @if($language === $locale) aria-current="true" @endif href="{{ $routeName ? route($routeName, array_merge(request()->query(), $parameters, ['locale' => $language])) : route('home', ['locale' => $language]) }}">{{ ['en' => 'EN', 'gu' => 'ગુજરાતી', 'hi' => 'हिन्दी'][$language] }}</a>
            @endforeach
        </div>
        <div class="topbar-right">{{ __('openingHours') }}</div>
    </div></div>
    <div class="nav-line"><div class="wrap navbar industro-nav">
        <a class="navbar-brand" href="{{ route('home', ['locale' => $locale]) }}"><x-business-brand /></a>
        <button id="menu-toggle" class="menu-toggle" type="button" aria-label="{{ __('menu') }}" aria-controls="nav" aria-expanded="false">☰</button>
        <nav id="nav" class="navigation navbar-nav" aria-label="{{ __('navigation') }}">
            @foreach(['home' => 'home', 'catalogue' => 'catalogue', 'awning' => 'awnings', 'gallery' => 'gallery', 'about' => 'about', 'careers' => 'careers', 'contact' => 'contact'] as $link => $label)
                <a class="nav-link" @if($routeName === $link) aria-current="page" @endif href="{{ route($link, ['locale' => $locale]) }}">{{ __($label) }}</a>
            @endforeach
        </nav>
    </div></div>
</header>
<main id="main" tabindex="-1">@yield('content')</main>
<footer>
    <div class="wrap footer-top">
        <div><a href="{{ route('home', ['locale' => $locale]) }}"><x-business-brand /></a><p>{{ __('footerIntro') }}</p></div>
        <div><h3>{{ __('explore') }}</h3><a href="{{ route('catalogue', ['locale' => $locale]) }}">{{ __('catalogue') }}</a><a href="{{ route('awning', ['locale' => $locale]) }}">{{ __('awnings') }}</a><a href="{{ route('gallery', ['locale' => $locale]) }}">{{ __('gallery') }}</a><a href="{{ route('privacy', ['locale' => $locale]) }}">{{ __('site.privacy') }}</a></div>
        <div><h3>{{ __('visit') }}</h3><p>{{ config('website.address') }}</p><a href="{{ config('website.maps_url') }}" target="_blank" rel="noopener">{{ __('directions') }}</a></div>
        <div><h3>{{ __('hours') }}</h3><p>{{ __('openingHours') }}</p><a href="tel:{{ str_replace(' ', '', config('website.phone')) }}">{{ config('website.phone') }}</a><a href="{{ route('contact', ['locale' => $locale]) }}">{{ __('contact') }}</a></div>
    </div>
    <div class="wrap footer-bottom"><span>© {{ date('Y') }} Yes Enterprise Rajkot</span></div>
</footer>
<a class="whatsapp-chat" href="https://wa.me/{{ config('website.whatsapp') }}" target="_blank" rel="noopener" aria-label="{{ __('business.whatsapp') }}" title="{{ __('business.whatsapp') }}"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M21 11.5a9 9 0 0 1-13.2 8L3 21l1.5-4.8A9 9 0 1 1 21 11.5Z" stroke-linecap="round" stroke-linejoin="round"/><path d="m8.2 6.8-1 .4c-.5.2-.6.7-.5 1.3.8 4 4 7.2 8 8 .6.1 1.1 0 1.3-.5l.4-1-2.6-1.3-1 1a9.2 9.2 0 0 1-3.5-3.5l1-1-1.3-2.6Z" fill="currentColor" stroke="none"/></svg></a>
<x-photo-viewer />
</body>
</html>

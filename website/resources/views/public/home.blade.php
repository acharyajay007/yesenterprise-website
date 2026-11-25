@extends('layouts.public', ['title' => $page->text('title'), 'description' => $page->text('seo_description')])
@push('assets')
    @vite('resources/js/hero-slider.js')
@endpush
@section('content')
<section class="storefront-hero splide" data-hero-slider aria-label="{{ __('photos.featured') }}"
    data-splide="{{ json_encode(['i18n' => ['prev' => __('photos.previousSlide'), 'next' => __('photos.nextSlide'), 'first' => __('photos.firstSlide'), 'last' => __('photos.lastSlide'), 'play' => __('photos.playSlides'), 'pause' => __('photos.pauseSlides'), 'slideX' => __('photos.goToSlide', ['number' => '%s']), 'pageX' => __('photos.goToSlide', ['number' => '%s']), 'carousel' => __('photos.carousel'), 'slide' => __('photos.slide'), 'slideLabel' => '%s / %s']]) }}">
    <div class="splide__track"><ul class="splide__list">
        @foreach($heroSlides as $slide)
            <li class="hero-slide splide__slide" @if($slide['category']) data-hero-category="{{ $slide['category'] }}" @endif>
                @if($slide['image'])
                    <img class="hero-image" src="{{ $slide['image'] }}" alt="{{ $slide['alt'] }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}" @if($loop->first) fetchpriority="high" @endif>
                @endif
                <div class="wrap hero-slide-inner"><div class="storefront-hero-copy">
                    <span class="eyebrow">{{ $slide['eyebrow'] }}</span>
                    @if($loop->first)<h1>{{ $slide['title'] }}</h1>@else<h2>{{ $slide['title'] }}</h2>@endif
                    <p>{{ $slide['description'] }}</p>
                    <div class="actions">
                        <a class="button" href="{{ $slide['href'] }}">{{ $slide['label'] }} &#8594;</a>
                        <a class="hero-secondary" href="{{ route('contact', ['locale' => $locale]) }}">{{ __('contact') }}</a>
                    </div>
                </div></div>
            </li>
        @endforeach
    </ul></div>
    @if($heroSlides->count() > 1)
        <button type="button" class="splide__toggle hero-toggle" aria-label="{{ __('photos.pauseSlides') }}">
            <span class="splide__toggle__play" aria-hidden="true">&#9654;</span>
            <span class="splide__toggle__pause" aria-hidden="true">&#8545;</span>
        </button>
        <span class="hero-count" data-hero-count aria-hidden="true">01 / {{ str_pad($heroSlides->count(), 2, '0', STR_PAD_LEFT) }}</span>
    @endif
</section>
<section class="brand-strip" aria-labelledby="brands-title"><div class="wrap brand-inner"><div class="brand-heading"><span class="eyebrow">{{ __('merchandising.brandsEyebrow') }}</span><h2 id="brands-title">{{ __('merchandising.brandsTitle') }}</h2></div><div class="brands">
    @foreach(config('website.logos') as $brand => $logo)
        <a class="supplier-brand" href="{{ route('catalogue', ['locale' => $locale, 'brand' => $brand]) }}"><span class="brand-logo-frame"><img class="brand-logo" src="{{ asset($logo) }}" alt="{{ $brand }}" width="148" height="64"></span><small>{{ $brand }}</small></a>
    @endforeach
</div></div></section>
@if($products->isNotEmpty())
<section class="section home-featured" id="popular-products"><div class="wrap">
    <div class="section-heading"><div><span class="eyebrow">{{ __('merchandising.popular') }}</span><h2>{{ __('merchandising.popularTitle') }}</h2><p>{{ __('merchandising.popularBody') }}</p></div><a class="arrow-link" href="{{ route('catalogue', ['locale' => $locale, 'popular' => 1]) }}">{{ __('merchandising.viewPopular') }}</a></div>
    <div class="product-grid">@foreach($products as $product)<x-product-card :product="$product" group="home-products" />@endforeach</div>
</div></section>
@endif
@if($awning)
<section id="home-awnings" class="section awning-showcase"><div class="wrap home-awning-grid">
    <div class="home-awning-lead">
        @if($awningPage->hero_image && (!config('website.indexable') || $awningPage->media_approved))
            <x-photo-link :src="Storage::disk('media')->url($awningPage->hero_image)" :alt="__('awning.installationAlt')" group="home-awnings" />
        @endif
    </div>
    <div class="story-copy">
        <span class="eyebrow">{{ __('awning.eyebrow') }}</span><h2>{{ $page->text('awning_title') }}</h2><p>{{ $page->text('awning_body') }}</p>
        <div class="actions"><a class="button" href="{{ route('awning', ['locale' => $locale]) }}">{{ __('awning.explore') }} &#8594;</a><a class="text-button" href="{{ route('contact', ['locale' => $locale, 'product' => $awning?->slug]) }}">{{ __('awningEnquire') }}</a></div>
        @if($awningPhotos->isNotEmpty())
            <div class="home-awning-projects"><h3>{{ __('awning.projectsTitle') }}</h3><x-awning-gallery :images="$awningPhotos" group="home-awnings" /></div>
        @endif
    </div>
</div></section>
@endif
<section class="section home-materials"><div class="wrap">
    <div class="section-heading"><div><span class="eyebrow">{{ __('categories') }}</span><h2>{{ __('photos.browse') }}</h2><p>{{ __('photos.browseBody') }}</p></div><a class="arrow-link" href="{{ route('catalogue', ['locale' => $locale]) }}">{{ __('viewCatalogue') }}</a></div>
    <div class="material-links">@foreach(config('website.categories') as $category)<a href="{{ route('catalogue', ['locale' => $locale, 'category' => $category]) }}"><span>{{ __($category) }}</span><span aria-hidden="true">&#8599;</span></a>@endforeach</div>
</div></section>
<section class="section home-introduction"><div class="wrap">
    <div><span class="eyebrow">YES ENTERPRISE / RAJKOT</span><h2>{{ __('photos.aboutTitle') }}</h2></div>
    <div><p>{{ __('photos.aboutBody') }}</p><div class="actions"><a class="button" href="{{ route('about', ['locale' => $locale]) }}">{{ __('site.about') }}</a><a class="button light" href="{{ route('contact', ['locale' => $locale]) }}">{{ __('contact') }}</a></div></div>
</div></section>
@endsection

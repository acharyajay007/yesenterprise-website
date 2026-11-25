@extends('layouts.public', ['title' => __('awnings'), 'description' => $page->text('seo_description')])
@section('content')
@php
    $heroImage = $page->hero_image && (!config('website.indexable') || $page->media_approved) ? Storage::disk('media')->url($page->hero_image) : null;
    $enquiryUrl = route('contact', ['locale' => $locale, 'product' => $products->first()?->slug]);
@endphp
<div class="awning-page">
<section class="awning-service-hero">
    <div class="wrap">
        <nav class="awning-breadcrumb" aria-label="{{ __('navigation') }}"><a href="{{ route('home', ['locale' => $locale]) }}">{{ __('home') }}</a><span aria-hidden="true">/</span><span>{{ __('awnings') }}</span></nav>
        <div @class(['awning-service-grid', 'without-photo' => !$heroImage])>
            <div class="awning-hero-copy">
                <p class="eyebrow">{{ __('awning.eyebrow') }}</p>
                <h1>{{ $page->text('title') }}</h1>
                <p class="business-lead">{{ $page->text('description') }}</p>
                <div class="actions"><a class="button" href="{{ $enquiryUrl }}">{{ __('awning.enquire') }} &#8594;</a>@if($images->isNotEmpty())<a class="text-button" href="#customer-awnings">{{ __('awning.projectsTitle') }} &#8595;</a>@endif</div>
            </div>
            @if($heroImage)
                <figure class="awning-hero-photo"><x-photo-link :src="$heroImage" :alt="__('awning.installationAlt')" group="awning-projects" width="1200" height="900" loading="eager" priority="high" /></figure>
            @endif
        </div>
    </div>
</section>
<section class="section awning-spaces"><div class="wrap">
    <div class="section-heading"><div><p class="eyebrow">{{ __('awning.spacesEyebrow') }}</p><h2>{{ __('awning.serviceTitle') }}</h2></div></div>
    <div class="awning-services">@foreach(__('awning.services') as $service)<article><span aria-hidden="true">0{{ $loop->iteration }}</span><h3>{{ $service['title'] }}</h3><p>{{ $service['body'] }}</p></article>@endforeach</div>
</div></section>
@if($images->isNotEmpty())
    <section class="section awning-portfolio" id="customer-awnings"><div class="wrap">
        <div class="section-heading"><div><p class="eyebrow">{{ __('awning.projectsEyebrow') }}</p><h2>{{ __('awning.projectsTitle') }}</h2><p>{{ __('awning.projectsBody') }}</p></div><a class="text-button" href="{{ $enquiryUrl }}">{{ __('awning.enquire') }} &#8599;</a></div>
        <x-awning-gallery :images="$images" />
    </div></section>
@endif
<section class="section awning-process"><div class="wrap">
    <div class="awning-process-intro"><p class="eyebrow">{{ __('awning.processEyebrow') }}</p><h2>{{ __('awning.processTitle') }}</h2></div>
    <ol class="awning-process-steps">@foreach(__('awning.steps') as $step)<li><h3>{{ $step['title'] }}</h3><p>{{ $step['body'] }}</p></li>@endforeach</ol>
</div></section>
@if($products->isNotEmpty())
    <section class="section awning-fabrics"><div class="wrap">
        <div class="section-heading"><div><p class="eyebrow">{{ __('awning.fabricsEyebrow') }}</p><h2>{{ __('awning.fabricsTitle') }}</h2></div><a class="arrow-link" href="{{ route('catalogue', ['locale' => $locale, 'category' => 'awnings']) }}">{{ __('viewCatalogue') }}</a></div>
        <div class="product-grid">@foreach($products as $product)<x-product-card :product="$product" group="awning-fabrics" />@endforeach</div>
    </div></section>
@endif
<section class="section awning-enquiry"><div class="wrap">
    <div><p class="eyebrow">YES ENTERPRISE / RAJKOT</p><h2>{{ __('awning.ctaTitle') }}</h2><p>{{ __('awning.ctaBody') }}</p></div>
    <a class="button" href="{{ $enquiryUrl }}">{{ __('awning.enquire') }} &#8594;</a>
</div></section>
</div>
@endsection

@extends('layouts.public', ['title' => __('site.about'), 'description' => $page->text('seo_description')])
@section('content')
<section class="business-hero"><div class="wrap">
    <p class="eyebrow">{{ __('business.aboutEyebrow') }}</p>
    <h1>{{ $page->text('title') }}</h1>
    <p class="business-lead">{{ $page->text('description') }}</p>
    <div class="actions"><a class="button" href="{{ route('catalogue', ['locale' => $locale]) }}">{{ __('viewCatalogue') }}</a><a class="button light" href="{{ route('contact', ['locale' => $locale]) }}">{{ __('contact') }}</a></div>
</div></section>
<section class="wrap section business-story">
    <figure class="business-photo">
        @if($page->hero_image && (!config('website.indexable') || $page->media_approved))
            <x-photo-link :src="Storage::disk('media')->url($page->hero_image)" :alt="__('business.teamPhoto')" group="about-team" width="3264" height="2448" />
        @else
            <div class="business-photo-pending" aria-hidden="true"><x-business-brand /></div>
        @endif
        <figcaption>{{ __('business.teamPhoto') }}</figcaption>
    </figure>
    <div><span class="eyebrow">{{ __('about') }}</span><h2>{{ __('business.storyTitle') }}</h2><p>{{ __('business.storyBody') }}</p><p>{{ __('business.storySecond') }}</p></div>
</section>
<section class="section tint"><div class="wrap">
    <div class="section-heading"><div><span class="eyebrow">{{ __('business.offerEyebrow') }}</span><h2>{{ __('business.offerTitle') }}</h2></div></div>
    <div class="business-offerings">
        @foreach(['print' => 'flex', 'finish' => 'laminate', 'shade' => 'awnings'] as $group => $category)
            <article><span class="offering-number">0{{ $loop->iteration }}</span><h3>{{ __('business.'.$group.'Title') }}</h3><p>{{ __('business.'.$group.'Body') }}</p><a class="arrow-link" href="{{ route('catalogue', ['locale' => $locale, 'category' => $category]) }}">{{ __('viewCatalogue') }} →</a></article>
        @endforeach
    </div>
</div></section>
<section class="wrap section business-approach"><div><span class="eyebrow">{{ __('enquire') }}</span><h2>{{ __('business.approachTitle') }}</h2><p>{{ __('business.approachBody') }}</p></div>
    <ol class="business-steps">@foreach(__('business.steps') as $step)<li><h3>{{ $step['title'] }}</h3><p>{{ $step['body'] }}</p></li>@endforeach</ol>
</section>
<section class="section business-visit"><div class="wrap business-visit-inner"><div><span class="eyebrow">RAJKOT / GUJARAT</span><h2>{{ __('business.visitTitle') }}</h2><p>{{ config('website.address') }}</p><p>{{ __('openingHours') }}</p></div><a class="button" href="{{ config('website.maps_url') }}" target="_blank" rel="noopener">{{ __('directions') }}</a></div></section>
<section class="wrap section"><div class="cta-panel"><div><h2>{{ __('business.aboutCta') }}</h2><p>{{ __('business.aboutCtaBody') }}</p></div><a class="button" href="{{ route('contact', ['locale' => $locale]) }}">{{ __('enquire') }}</a></div></section>
@endsection

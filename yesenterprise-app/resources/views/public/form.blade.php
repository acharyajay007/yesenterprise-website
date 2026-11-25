@extends('layouts.public', ['title' => __($career ? 'careers' : 'contact'), 'description' => $career ? __('merchandising.careersSummary') : $page->text('seo_description')])
@section('content')
@if($career)
    <x-page-heading :title="__('careers')" :body="__('merchandising.careersSummary')" />
@else
    <section class="business-hero contact-hero"><div class="wrap"><p class="eyebrow">{{ __('business.contactEyebrow') }}</p><h1>{{ $page->text('title') }}</h1><p class="business-lead">{{ $page->text('description') }}</p></div></section>
    <section class="wrap contact-info-grid" aria-label="{{ __('contact') }}">
        <article><span class="eyebrow">01 / RAJKOT</span><h2>{{ __('business.visitTitle') }}</h2><address>{{ config('website.address') }}</address><a class="arrow-link" href="{{ config('website.maps_url') }}" target="_blank" rel="noopener">{{ __('directions') }} →</a></article>
        <article><span class="eyebrow">02 / {{ __('hours') }}</span><h2>{{ __('business.hoursTitle') }}</h2><dl><div><dt>{{ __('business.weekdays') }}</dt><dd>{{ __('business.hoursValue') }}</dd></div><div><dt>{{ __('business.sunday') }}</dt><dd>{{ __('business.closed') }}</dd></div></dl></article>
        <article><span class="eyebrow">03 / {{ __('contact') }}</span><h2>{{ __('business.callTitle') }}</h2><div class="contact-direct"><a class="contact-phone" href="tel:{{ str_replace(' ', '', config('website.phone')) }}">{{ config('website.phone') }}</a><a class="arrow-link" href="https://wa.me/{{ config('website.whatsapp') }}" target="_blank" rel="noopener">{{ __('business.whatsapp') }} &#8599;</a><a href="#enquiry-form">{{ __('business.enquiryTitle') }} &#8594;</a></div></article>
    </section>
@endif
<div class="wrap section contact-layout {{ $career ? '' : 'professional-contact' }}"><section class="form-panel" id="enquiry-form">
    <span class="eyebrow">{{ __($career ? 'careers' : 'enquire') }}</span>
    <h2>{{ $career ? __('merchandising.applicationTitle') : __('business.enquiryTitle') }}</h2>
    @unless($career)<p>{{ __('business.enquiryIntro') }}</p>@if($selectedProduct)<p class="selected-product"><strong>{{ __('business.selectedProduct') }}:</strong> {{ $selectedProduct->text('title') }}</p>@endif @endunless
    @if(session('status'))<p class="notice" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="notice error-summary" role="alert"><p>{{ __('site.validation') }}</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form action="{{ route($career ? 'careers.submit' : 'contact.submit', ['locale' => $locale]) }}" method="post" enctype="multipart/form-data">
        @csrf
        <div class="form-grid">
            @foreach(['name' => 'text', 'email' => 'email', 'phone' => 'tel'] as $field => $type)
                <div class="field"><label for="{{ $field }}">{{ __($field) }} {{ $field !== 'phone' ? '*' : '' }}</label><input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ ['name' => 'name', 'email' => 'email', 'phone' => 'tel'][$field] }}" value="{{ old($field) }}" maxlength="{{ $field === 'phone' ? 50 : 150 }}" @required($field !== 'phone')></div>
            @endforeach
            @if($career)
                <div class="field"><label for="role">{{ __('role') }} *</label><input id="role" name="role" value="{{ old('role') }}" maxlength="150" required></div>
                <div class="field full"><label for="resume">{{ __('resume') }} *</label><input id="resume" type="file" name="resume" accept=".pdf,.docx" aria-describedby="resume-help" required><p id="resume-help">{{ __('merchandising.resumeHelp') }}</p></div>
            @else
                <div class="field"><label for="company">{{ __('company') }}</label><input id="company" name="company" autocomplete="organization" value="{{ old('company') }}" maxlength="150"></div>
                <div class="field"><label for="product">{{ __('product') }}</label><select id="product" name="product"><option value="">{{ __('all') }}</option>@foreach($products as $product)<option value="{{ $product->slug }}" @selected(old('product', $selectedProduct?->slug) === $product->slug)>{{ $product->text('title') }}</option>@endforeach</select></div>
                <div class="field"><label for="variant">{{ __('finish') }}</label><select id="variant" name="variant"><option value="">{{ __('merchandising.selectVariant') }}</option>@foreach($products as $product)@foreach($product->variants ?? [] as $variant)<option data-product="{{ $product->slug }}" value="{{ $variant }}" @selected(old('variant', $selectedVariant) === $variant)>{{ $product->text('title') }} / {{ $variant }}</option>@endforeach @endforeach</select></div>
                <div class="field"><label for="quantity">{{ __('quantity') }}</label><input id="quantity" name="quantity" value="{{ old('quantity') }}" maxlength="150"></div>
            @endif
            <div class="field full"><label for="message">{{ __('message') }} *</label><textarea id="message" name="message" maxlength="2000" @unless($career) aria-describedby="enquiry-hint" @endunless required>{{ old('message') }}</textarea>@unless($career)<p id="enquiry-hint" class="fine-print">{{ __('business.enquiryHint') }}</p>@endunless</div>
        </div>
        <div class="honeypot" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <label class="consent"><input type="checkbox" name="consent" value="1" @checked(old('consent')) required><span>{{ __('site.consent') }} <a href="{{ route('privacy', ['locale' => $locale]) }}">{{ __('site.privacy') }}</a></span></label>
        <button class="button">{{ __('site.'.($career ? 'apply' : 'submit')) }}</button>
    </form>
</section><aside class="contact-side">
    @if($career)
        <div class="location-card"><h2>{{ __('visit') }}</h2><p>{{ config('website.address') }}</p><p>{{ __('openingHours') }}</p><a class="button" href="{{ config('website.maps_url') }}" target="_blank" rel="noopener">{{ __('directions') }}</a></div><p>{{ __('site.privacyBody') }}</p>
    @else
        <div class="contact-guidance"><h2>{{ __('business.contactSupportTitle') }}</h2><p>{{ __('business.contactSupportBody') }}</p><ol class="business-steps">@foreach(__('business.steps') as $step)<li><h3>{{ $step['title'] }}</h3><p>{{ $step['body'] }}</p></li>@endforeach</ol></div>
        <div class="contact-next"><h3>{{ __('business.afterSubmitTitle') }}</h3><p>{{ __('business.afterSubmitBody') }}</p><p>{{ __('business.privacyShort') }} <a href="{{ route('privacy', ['locale' => $locale]) }}">{{ __('site.privacy') }}</a></p></div>
    @endif
</aside></div>
@unless($career)<section class="section business-visit"><div class="wrap business-visit-inner"><div><h2>{{ __('business.contactCta') }}</h2><p>{{ __('business.contactCtaBody') }}</p></div><a class="button" href="{{ route('catalogue', ['locale' => $locale]) }}">{{ __('viewCatalogue') }}</a></div></section>@endunless
@endsection

@extends('layouts.public', ['title' => $product->text('title'), 'description' => $product->text('seo_description')])
@section('content')
<div class="wrap product-page">
    <nav class="product-breadcrumb" aria-label="{{ __('navigation') }}"><a href="{{ route('home', ['locale' => $locale]) }}">{{ __('home') }}</a><span aria-hidden="true">/</span><a href="{{ route('catalogue', ['locale' => $locale, 'category' => $product->category]) }}">{{ __($product->category) }}</a><span aria-hidden="true">/</span><span>{{ $product->text('title') }}</span></nav>
    <div class="detail-layout">
        <div class="detail-visual">
            @if($images)
                <x-photo-link :src="$images[0]['url']" :alt="$images[0]['alt']" :group="'product-'.$product->slug" class="product-lead-photo" loading="eager" priority="high" />
                @if(count($images) > 1)
                    <div class="product-thumbnails" aria-label="{{ __('photos.title') }}">@foreach($images as $image)
                        <x-photo-link :src="$image['url']" :alt="$image['alt']" :group="'product-'.$product->slug" :aria-label="__('photos.thumbnail', ['number' => $loop->iteration])" />
                    @endforeach</div>
                @endif
            @else
                <div class="photo-placeholder" aria-hidden="true"><x-business-brand /></div>
            @endif
        </div>
        <div class="detail-copy">
            <div class="product-meta"><a class="eyebrow" href="{{ route('catalogue', ['locale' => $locale, 'category' => $product->category]) }}">{{ __($product->category) }}</a>@if($product->is_popular)<span class="popular-badge">{{ __('merchandising.popular') }}</span>@endif</div>
            <h1>{{ $product->text('title') }}</h1>
            @if($product->brand)<p class="product-brand">{{ __('brand') }} <strong>{{ $product->brand }}</strong></p>@endif
            <p class="product-description">{{ $product->text('description') }}</p>
            <form class="product-enquiry" action="{{ route('contact', ['locale' => $locale]) }}"><input type="hidden" name="product" value="{{ $product->slug }}">
                @if($product->variants)<label for="variant">{{ __('finish') }}</label><select id="variant" name="variant">@foreach($product->variants as $variant)<option>{{ $variant }}</option>@endforeach</select>@endif
                <button class="button">{{ __('enquiryProduct') }} &#8594;</button>
            </form>
            @if($product->publicSpecifications())
                <section class="product-specifications"><h2>{{ __('specifications') }}</h2><table class="spec-table"><tbody>@foreach($product->publicSpecifications() as $spec)<tr><th scope="row">{{ __($spec['label']) }}</th><td>{{ str_starts_with($spec['value'], '@') ? __(substr($spec['value'], 1)) : $spec['value'] }}</td></tr>@endforeach</tbody></table></section>
            @endif
            @if($product->category === 'awnings')<a class="arrow-link product-service-link" href="{{ route('awning', ['locale' => $locale]) }}">{{ __('awning.explore') }}</a>@endif
            <a class="text-button product-back" href="{{ route('catalogue', ['locale' => $locale]) }}">{{ __('photos.back') }}</a>
        </div>
    </div>
</div>
@if($relatedProducts->isNotEmpty())
    <section class="section related-products tint"><div class="wrap"><div class="section-heading"><div><span class="eyebrow">{{ __($product->category) }}</span><h2>{{ __('photos.related') }}</h2></div></div><div class="product-grid">@foreach($relatedProducts as $related)<x-product-card :product="$related" group="related-products" />@endforeach</div></div></section>
@endif
@endsection

@props(['product', 'group' => 'product-cards'])
<article class="product-card">
    <div @class(['card-photo', 'card-photo--contain' => $product->image_fit === 'contain'])>
        @if($product->imageUrl())
            <x-photo-link :src="$product->imageUrl()" :alt="$product->imageAlt()" :group="$group" class="material photo-material" />
        @else
            <div class="photo-placeholder" aria-hidden="true"><x-business-brand /></div>
        @endif
        @if($product->is_popular)<span class="popular-badge">{{ __('merchandising.popular') }}</span>@endif
    </div>
    <div class="card-copy">
        <p class="card-category">{{ __($product->category) }}</p>
        <a class="product-detail-link" href="{{ route('product', ['locale' => app()->getLocale(), 'product' => $product]) }}" title="{{ $product->text('title') }}">
            <h3>{{ $product->text('title') }}</h3>
        </a>
        @if($product->brand)<p class="card-brand-name">{{ $product->brand }}</p>@endif
        <a class="card-bottom product-detail-link" href="{{ route('product', ['locale' => app()->getLocale(), 'product' => $product]) }}">
            <span>{{ __('viewDetails') }}</span><span aria-hidden="true">&#8594;</span>
        </a>
    </div>
</article>

@extends('layouts.public', ['title' => __('catalogue'), 'description' => __('merchandising.catalogueSummary')])
@section('content')
<section class="catalogue-intro"><div class="wrap"><span class="eyebrow">YES ENTERPRISE / RAJKOT</span><h1>{{ __('catalogue') }}</h1><p>{{ __('merchandising.catalogueSummary') }}</p></div></section>
<div class="wrap catalogue-layout">
    <aside class="filters"><details class="catalogue-filter-panel" open>
        <summary>{{ __('photos.filters') }}</summary>
        <form id="catalogue-filters" action="{{ route('catalogue', ['locale' => $locale]) }}">
            <h2>{{ __('photos.filters') }}</h2>
            <div><label for="q">{{ __('searchGlobal') }}</label><input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="150"></div>
            <div><label for="category">{{ __('category') }}</label><select id="category" name="category"><option value="">{{ __('all') }}</option>@foreach(config('website.categories') as $category)<option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ __($category) }}</option>@endforeach</select></div>
            <div><label for="brand">{{ __('brand') }}</label><select id="brand" name="brand"><option value="">{{ __('all') }}</option>@foreach($brands as $brand)<option value="{{ $brand }}" @selected(($filters['brand'] ?? '') === $brand)>{{ $brand }}</option>@endforeach</select></div>
            <label class="popular-filter" for="popular"><input id="popular" name="popular" type="checkbox" value="1" @checked(($filters['popular'] ?? '') === '1')> {{ __('merchandising.popularOnly') }}</label>
            <button class="button">{{ __('search') }}</button><a class="text-button" href="{{ route('catalogue', ['locale' => $locale]) }}">{{ __('clear') }}</a>
        </form>
    </details></aside>
    <section class="catalogue-main" aria-label="{{ __('catalogue') }}">
        <div class="catalogue-toolbar">
            <p role="status">{{ __('site.shown', ['first' => $products->firstItem() ?? 0, 'last' => $products->lastItem() ?? 0, 'total' => $products->total()]) }}</p>
            <div><label for="sort">{{ __('sort') }}</label><select id="sort" name="sort" form="catalogue-filters">@foreach(['popular', 'name-asc', 'name-desc', 'newest'] as $sort)<option value="{{ $sort }}" @selected(($filters['sort'] ?? 'popular') === $sort)>{{ __('merchandising.sortOptions.'.$sort) }}</option>@endforeach</select></div>
        </div>
        <div class="product-grid">@forelse($products as $product)<x-product-card :product="$product" group="catalogue-products" />@empty<div class="catalogue-empty"><h2>{{ __('site.noResults') }}</h2><a class="button light" href="{{ route('catalogue', ['locale' => $locale]) }}">{{ __('clear') }}</a></div>@endforelse</div>
        @if($products->hasPages())
            <nav class="catalogue-pagination" aria-label="{{ __('site.pagination') }}">
                @if($products->previousPageUrl())<a class="button light" rel="prev" href="{{ $products->previousPageUrl() }}">{{ __('site.previous') }}</a>@endif
                <span>{{ __('site.pageCount', ['page' => $products->currentPage(), 'pages' => $products->lastPage()]) }}</span>
                @if($products->nextPageUrl())<a class="button" rel="next" href="{{ $products->nextPageUrl() }}">{{ __('site.next') }}</a>@endif
            </nav>
        @endif
    </section>
</div>
@endsection

@extends('layouts.public', ['title' => __('gallery'), 'description' => __('merchandising.gallerySummary')])
@section('content')
<x-page-heading :title="__('gallery')" :body="__('merchandising.gallerySummary')" />
<div class="wrap section"><div class="pills"><a class="button light" href="{{ route('gallery', ['locale' => $locale]) }}">{{ __('all') }}</a>@foreach(['materials','awnings','inspiration'] as $category)<a class="button light" href="{{ route('gallery', ['locale' => $locale, 'category' => $category]) }}">{{ __($category) }}</a>@endforeach</div>
<div class="gallery-grid">@foreach($images as $image)<figure class="gallery-card">@if($image->imageUrl())<x-photo-link :src="$image->imageUrl()" :alt="$image->title()" group="gallery" />@else<div class="photo-placeholder" aria-hidden="true"><x-business-brand /></div>@endif<figcaption><h3>{{ $image->title() }}</h3></figcaption></figure>@endforeach</div></div>
@endsection

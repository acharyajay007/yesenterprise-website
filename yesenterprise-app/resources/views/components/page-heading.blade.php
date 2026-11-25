@props(['title', 'body' => ''])
<section class="page-heading industro-page-header"><div class="wrap">
    <div class="breadcrumb"><a href="{{ route('home', ['locale' => app()->getLocale()]) }}">{{ __('home') }}</a><span>/</span><span>{{ $title }}</span></div>
    <h1>{{ $title }}</h1><p>{{ $body }}</p>
</div></section>

@extends('layouts.public', ['title' => __('site.privacy')])
@section('content')
<x-page-heading :title="__('site.privacy')" />
<section class="wrap section"><p>{{ __('site.privacyBody') }}</p></section>
@endsection

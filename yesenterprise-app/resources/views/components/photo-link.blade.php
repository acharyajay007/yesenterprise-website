@props(['src', 'alt', 'group' => 'photos', 'loading' => 'lazy', 'priority' => null, 'width' => null, 'height' => null])
<a href="{{ $src }}" {{ $attributes->except('aria-label')->class(['photo-link']) }} data-photo-group="{{ $group }}" data-photo-caption="{{ $alt }}" aria-label="{{ $attributes->get('aria-label', __('photos.open', ['name' => $alt])) }}">
    <img src="{{ $src }}" alt="{{ $alt }}" loading="{{ $loading }}" @if($priority) fetchpriority="{{ $priority }}" @endif @if($width) width="{{ $width }}" @endif @if($height) height="{{ $height }}" @endif>
    <span class="photo-action" aria-hidden="true">{{ __('photos.view') }} &#8599;</span>
</a>

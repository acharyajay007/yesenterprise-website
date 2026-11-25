@props(['images', 'group' => 'awning-projects'])
<div class="awning-project-grid">
    @foreach($images as $image)
        @if($image->imageUrl())
            <figure class="awning-project">
                <x-photo-link :src="$image->imageUrl()" :alt="$image->title()" :group="$group" width="1200" height="900" />
                <figcaption>{{ $image->title() }}</figcaption>
            </figure>
        @endif
    @endforeach
</div>

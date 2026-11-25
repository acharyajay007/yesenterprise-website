<x-filament-widgets::widget>
    <x-filament::section class="workspace-section" heading="Your website workspace" description="Manage the Yes Enterprise catalogue, content and customer requests from one place.">
        <x-slot name="afterHeader">
            <x-filament::button :href="route('home', ['locale' => 'en'])" tag="a" target="_blank" rel="noopener" color="gray" icon="heroicon-o-arrow-top-right-on-square">
                View website
            </x-filament::button>
        </x-slot>
        <nav class="workspace-shortcuts" aria-label="Website management shortcuts">
            @foreach($this->shortcuts() as $shortcut)
                <a class="workspace-shortcut" href="{{ $shortcut['url'] }}">
                    <span class="workspace-shortcut-icon"><x-filament::icon :icon="$shortcut['icon']" /></span>
                    <span class="workspace-shortcut-title">{{ $shortcut['title'] }}</span>
                    <span class="workspace-shortcut-description">{{ $shortcut['description'] }}</span>
                </a>
            @endforeach
        </nav>
    </x-filament::section>
</x-filament-widgets::widget>

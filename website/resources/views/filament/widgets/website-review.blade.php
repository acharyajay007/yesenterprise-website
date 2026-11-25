<x-filament-widgets::widget>
    <x-filament::section heading="Website review & source warnings" description="Photograph approvals, source checks and publication readiness." collapsible collapsed>
        <div class="space-y-6">
            @unless(config('website.indexable'))<p>{{ __('site.development') }} Search indexing remains disabled.</p>@endunless
            <p>These review notices are visible only in admin. Manage product-specific source notes, image types, photograph sources and approval status in Products. Unapproved media remains hidden when indexing is enabled.</p>
            <ul class="list-disc space-y-2 ps-5">@foreach($this->reviewCounts() as $label => $count)<li>{{ $label }}: {{ $count }}</li>@endforeach</ul>
            <details>
                <summary class="cursor-pointer font-semibold">Publication and specification notes</summary>
                <ul class="mt-4 list-disc space-y-3 ps-5">
                    @foreach(['site.media', 'stockNote', 'sourceNote', 'supplierThickness', 'durabilityNote', 'awning.scope', 'site.catalogueScope'] as $note)<li>{{ __($note) }}</li>@endforeach
                    <li>Supplier micron values with an unconfirmed measurement basis and catalogue-page references remain in the admin specification editor, not in the public specification table.</li>
                </ul>
            </details>
            <details>
                <summary class="cursor-pointer font-semibold">{{ __('site.coverageTitle') }}</summary>
                <p class="mt-4">{{ __('site.coverageIntro') }}</p>
                @foreach($this->coverage() as $supplier)
                    <article class="mt-4 space-y-2">
                        <h3 class="font-semibold">{{ $supplier['supplier'] }}</h3>
                        <p>{{ __('site.coverageStatuses.'.$supplier['status']) }} · {{ __('site.checkedEntries', ['count' => $supplier['checked']]) }}@if($supplier['reported'] !== null) / {{ $supplier['reported'] }}@endif</p>
                        <p>{{ __('site.coverageNotes.'.$supplier['note']) }}</p>
                        @if($supplier['source_url'])<a class="underline" href="{{ $supplier['source_url'] }}" target="_blank" rel="noopener">{{ __('site.source') }}</a>@endif
                    </article>
                @endforeach
            </details>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

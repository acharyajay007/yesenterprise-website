<dialog id="photo-viewer" class="photo-viewer" aria-labelledby="photo-viewer-title"
    data-count="{{ __('photos.count', ['current' => '{current}', 'total' => '{total}']) }}"
    data-thumbnail="{{ __('photos.thumbnail', ['number' => '{number}']) }}"
    data-loading="{{ __('photos.loading') }}">
    <h2 id="photo-viewer-title" class="visually-hidden">{{ __('photos.title') }}</h2>
    <div class="viewer-toolbar">
        <div class="viewer-navigation">
            <button type="button" data-viewer-previous aria-label="{{ __('photos.previous') }}">&#8592;</button>
            <span data-viewer-count role="status" aria-live="polite"></span>
            <button type="button" data-viewer-next aria-label="{{ __('photos.next') }}">&#8594;</button>
        </div>
        <button type="button" data-viewer-close aria-label="{{ __('photos.close') }}" autofocus>&#10005;</button>
    </div>
    <div class="viewer-stage" data-viewer-stage>
        <p data-viewer-status role="status"></p>
        <div data-viewer-error hidden role="alert">
            <p>{{ __('photos.error') }}</p>
            <button type="button" data-viewer-retry>{{ __('photos.retry') }}</button>
            <a data-viewer-original target="_blank" rel="noopener">{{ __('photos.original') }}</a>
        </div>
    </div>
    <div class="viewer-information">
        <p data-viewer-caption></p>
        <div class="viewer-zoom">
            <button type="button" data-viewer-zoom-out aria-label="{{ __('photos.zoomOut') }}">&#8722;</button>
            <button type="button" data-viewer-reset aria-label="{{ __('photos.reset') }}">100%</button>
            <button type="button" data-viewer-zoom-in aria-label="{{ __('photos.zoomIn') }}">+</button>
        </div>
    </div>
    <div class="viewer-thumbnails" data-viewer-thumbnails aria-label="{{ __('photos.title') }}"></div>
</dialog>

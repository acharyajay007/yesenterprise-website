import Splide from '@splidejs/splide';
import '@splidejs/splide/css/core';

const hero = document.querySelector('[data-hero-slider]');

if (hero) {
    const multiple = hero.querySelectorAll('.splide__slide').length > 1;
    const slider = new Splide(hero, {
        type: 'fade',
        rewind: true,
        speed: 500,
        autoplay: multiple,
        interval: 5000,
        pauseOnHover: false,
        pauseOnFocus: true,
        arrows: multiple,
        pagination: false,
        keyboard: 'focused',
        reducedMotion: { speed: 0, rewindSpeed: 0, autoplay: multiple },
    });
    const counter = hero.querySelector('[data-hero-count]');
    slider.on('mounted moved', () => {
        if (counter) counter.textContent = `${String(slider.index + 1).padStart(2, '0')} / ${String(slider.length).padStart(2, '0')}`;
    });
    slider.mount();

    const viewer = document.getElementById('photo-viewer');
    if (viewer) {
        let resumeAfterViewer = false;
        new MutationObserver(() => {
            if (viewer.open) {
                resumeAfterViewer = hero.querySelector('.splide__toggle')?.classList.contains('is-active') ?? false;
                slider.Components.Autoplay.pause();
            } else if (resumeAfterViewer) {
                slider.Components.Autoplay.play();
            }
        }).observe(viewer, { attributes: true, attributeFilter: ['open'] });
    }
}

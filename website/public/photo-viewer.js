"use strict";

(() => {
    const dialog = document.getElementById("photo-viewer");
    if (!dialog || typeof dialog.showModal !== "function") return;

    const stage = dialog.querySelector("[data-viewer-stage]");
    const status = dialog.querySelector("[data-viewer-status]");
    const error = dialog.querySelector("[data-viewer-error]");
    const count = dialog.querySelector("[data-viewer-count]");
    const caption = dialog.querySelector("[data-viewer-caption]");
    const thumbnails = dialog.querySelector("[data-viewer-thumbnails]");
    const previous = dialog.querySelector("[data-viewer-previous]");
    const next = dialog.querySelector("[data-viewer-next]");
    const zoomIn = dialog.querySelector("[data-viewer-zoom-in]");
    const zoomOut = dialog.querySelector("[data-viewer-zoom-out]");
    const reset = dialog.querySelector("[data-viewer-reset]");
    let entries = [];
    let index = 0;
    let opener;
    let activeImage;
    let loaded = false;
    let zoom = 1;
    let baseWidth = 0;
    let baseHeight = 0;
    let gesture;

    const setZoom = value => {
        zoom = Math.max(1, Math.min(3, value));
        stage.classList.toggle("is-zoomed", zoom > 1);
        zoomIn.disabled = !loaded || zoom === 3;
        zoomOut.disabled = !loaded || zoom === 1;
        reset.disabled = !loaded;
        reset.textContent = Math.round(zoom * 100) + "%";
        if (!loaded) return;
        activeImage.style.width = baseWidth * zoom + "px";
        activeImage.style.height = baseHeight * zoom + "px";
        stage.scrollLeft = (stage.scrollWidth - stage.clientWidth) / 2;
        stage.scrollTop = (stage.scrollHeight - stage.clientHeight) / 2;
    };

    const fitImage = () => {
        if (!loaded) return;
        const ratio = Math.min(
            (stage.clientWidth - 32) / activeImage.naturalWidth,
            (stage.clientHeight - 32) / activeImage.naturalHeight,
            1,
        );
        baseWidth = activeImage.naturalWidth * Math.max(0.01, ratio);
        baseHeight = activeImage.naturalHeight * Math.max(0.01, ratio);
        setZoom(zoom);
    };

    const show = value => {
        index = (value + entries.length) % entries.length;
        const entry = entries[index];
        loaded = false;
        gesture = null;
        activeImage?.remove();
        status.hidden = false;
        status.textContent = dialog.dataset.loading;
        error.hidden = true;
        count.textContent = dialog.dataset.count.replace("{current}", index + 1).replace("{total}", entries.length);
        caption.textContent = entry.caption;
        dialog.querySelector("[data-viewer-original]").href = entry.url;
        previous.disabled = next.disabled = entries.length < 2;
        setZoom(1);
        for (const [position, button] of [...thumbnails.children].entries()) {
            button.setAttribute("aria-pressed", String(position === index));
        }
        thumbnails.children[index]?.scrollIntoView({ block: "nearest", inline: "center" });
        const image = new Image();
        activeImage = image;
        image.alt = entry.caption;
        image.draggable = false;
        image.hidden = true;
        image.addEventListener("load", () => {
            if (activeImage !== image || !dialog.open) return;
            loaded = true;
            status.hidden = true;
            image.hidden = false;
            fitImage();
        });
        image.addEventListener("error", () => {
            if (activeImage !== image || !dialog.open) return;
            status.hidden = true;
            error.hidden = false;
        });
        stage.append(image);
        image.src = entry.url;
    };

    document.addEventListener("click", event => {
        const link = event.target.closest("a[data-photo-group]");
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.hasAttribute("download")) return;
        event.preventDefault();
        opener = link;
        const seen = new Set();
        entries = [...document.querySelectorAll("a[data-photo-group]")]
            .filter(candidate => candidate.dataset.photoGroup === link.dataset.photoGroup)
            .filter(candidate => {
                if (seen.has(candidate.href)) return false;
                seen.add(candidate.href);
                return true;
            })
            .map(candidate => ({ url: candidate.href, caption: candidate.dataset.photoCaption }));
        thumbnails.replaceChildren();
        entries.forEach((entry, position) => {
            const button = document.createElement("button");
            button.type = "button";
            button.setAttribute("aria-label", dialog.dataset.thumbnail.replace("{number}", position + 1));
            const image = new Image();
            image.alt = "";
            image.loading = "lazy";
            image.src = entry.url;
            button.append(image);
            button.addEventListener("click", () => show(position));
            thumbnails.append(button);
        });
        thumbnails.hidden = entries.length < 2;
        dialog.showModal();
        document.documentElement.classList.add("photo-viewer-open");
        show(entries.findIndex(entry => entry.url === link.href));
    });

    previous.addEventListener("click", () => show(index - 1));
    next.addEventListener("click", () => show(index + 1));
    zoomIn.addEventListener("click", () => setZoom(zoom + 0.5));
    zoomOut.addEventListener("click", () => setZoom(zoom - 0.5));
    reset.addEventListener("click", () => setZoom(1));
    dialog.querySelector("[data-viewer-retry]").addEventListener("click", () => show(index));
    dialog.querySelector("[data-viewer-close]").addEventListener("click", () => dialog.close());
    dialog.addEventListener("close", () => {
        activeImage?.remove();
        activeImage = null;
        loaded = false;
        gesture = null;
        thumbnails.replaceChildren();
        document.documentElement.classList.remove("photo-viewer-open");
        if (opener?.isConnected) opener.focus({ preventScroll: true });
    });
    dialog.addEventListener("keydown", event => {
        if (event.ctrlKey || event.metaKey || event.altKey) return;
        switch (event.key) {
            case "ArrowLeft": show(index - 1); break;
            case "ArrowRight": show(index + 1); break;
            case "+": case "=": if (loaded) setZoom(zoom + 0.5); break;
            case "-": if (loaded) setZoom(zoom - 0.5); break;
            case "0": if (loaded) setZoom(1); break;
            default: return;
        }
        event.preventDefault();
    });
    stage.addEventListener("pointerdown", event => {
        if (!loaded || !event.isPrimary || event.button !== 0 || event.target.closest("a,button")) return;
        gesture = { x: event.clientX, y: event.clientY, left: stage.scrollLeft, top: stage.scrollTop, pointer: event.pointerId };
        if (zoom > 1) stage.setPointerCapture(event.pointerId);
    });
    stage.addEventListener("pointermove", event => {
        if (!gesture || event.pointerId !== gesture.pointer || zoom === 1) return;
        stage.scrollLeft = gesture.left - (event.clientX - gesture.x);
        stage.scrollTop = gesture.top - (event.clientY - gesture.y);
    });
    stage.addEventListener("pointerup", event => {
        if (!gesture || event.pointerId !== gesture.pointer) return;
        const dx = event.clientX - gesture.x;
        const dy = event.clientY - gesture.y;
        gesture = null;
        if (zoom === 1 && Math.abs(dx) >= 60 && Math.abs(dx) > Math.abs(dy) * 1.3 && entries.length > 1) show(index + (dx < 0 ? 1 : -1));
    });
    stage.addEventListener("pointercancel", () => { gesture = null; });
    window.addEventListener("resize", () => { if (dialog.open) fitImage(); });
})();

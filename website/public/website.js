"use strict";

const toggle = document.getElementById("menu-toggle");
toggle?.addEventListener("click", () => {
    const open = document.getElementById("nav").classList.toggle("open");
    toggle.setAttribute("aria-expanded", String(open));
});

const product = document.getElementById("product");
const variant = document.getElementById("variant");
if (product && variant) {
    const updateVariants = () => {
        for (const option of variant.options) {
            if (!option.dataset.product) continue;
            option.disabled = option.dataset.product !== product.value;
            option.hidden = option.disabled;
        }

        if (variant.selectedOptions[0]?.disabled) variant.value = "";
    };
    product.addEventListener("change", updateVariants);
    updateVariants();
}

const filterPanel = document.querySelector(".catalogue-filter-panel");
if (filterPanel) {
    const compact = window.matchMedia("(max-width: 900px)");
    const updateFilterPanel = () => { filterPanel.open = !compact.matches; };
    updateFilterPanel();
    compact.addEventListener("change", updateFilterPanel);
    document.getElementById("sort").addEventListener("change", () => {
        document.getElementById("catalogue-filters").requestSubmit();
    });
}

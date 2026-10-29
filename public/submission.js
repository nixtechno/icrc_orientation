(() => {
    'use strict';
    const form = document.querySelector('[data-final-submission]');
    if (!form) return;
    const button = form.querySelector('button[type="submit"], button:not([type])');
    const label = button?.textContent;
    let pending = false;
    form.addEventListener('submit', event => {
        if (pending) { event.preventDefault(); return; }
        pending = true;
        form.setAttribute('aria-busy', 'true');
        if (button) { button.disabled = true; button.textContent = 'Submitting your answers…'; }
    });
    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        pending = false;
        form.removeAttribute('aria-busy');
        if (button) { button.disabled = false; button.textContent = label; }
    });
})();

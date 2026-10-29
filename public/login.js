(() => {
    'use strict';
    const form = document.querySelector('[data-login-form]');
    if (!form) return;
    const button = form.querySelector('button[type="submit"], button:not([type])');
    const error = form.querySelector('[data-login-error]');
    const originalLabel = button?.textContent;
    let pending = false;

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending) return;
        pending = true;
        error.hidden = true;
        if (button) { button.disabled = true; button.textContent = 'Signing in…'; }
        form.setAttribute('aria-busy', 'true');
        try {
            // Reuse the current same-origin session; never retry or bypass a rejected POST.
            const response = await fetch(form.dataset.sessionUrl, {
                credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'},
                signal: AbortSignal.timeout(15000),
            });
            if (!response.ok || response.redirected) throw new Error('Session refresh failed');
            const data = await response.json();
            if (typeof data.token !== 'string' || !data.token) throw new Error('Missing session token');
            form.querySelector('input[name="_token"]').value = data.token;
            HTMLFormElement.prototype.submit.call(form);
        } catch {
            pending = false;
            form.removeAttribute('aria-busy');
            if (button) { button.disabled = false; button.textContent = originalLabel; }
            error.textContent = 'Unable to connect. Check your connection and try signing in again.';
            error.hidden = false;
        }
    });

    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            pending = false;
            form.removeAttribute('aria-busy');
            if (button) { button.disabled = false; button.textContent = originalLabel; }
        }
    });
})();

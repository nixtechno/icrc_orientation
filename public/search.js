(() => {
    'use strict';
    const main = document.getElementById('main');
    if (!main || !document.body.classList.contains('portal-body')) return;
    let controller, timer, sequence = 0;
    const initialPath = location.pathname;
    const status = document.createElement('p');
    status.className = 'search-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.hidden = true;
    document.querySelector('.portal-topbar')?.after(status);
    const resultArea = () => main.querySelector('[data-search-results]');
    const initialSearchable = Boolean(resultArea());
    const supported = form => form?.matches('form.portal-search') || (form?.matches('form.filters') && !form.hasAttribute('data-live-filter') && resultArea());
    const formUrl = form => {
        const url = new URL(form.getAttribute('action') || location.pathname, location.href);
        url.search = new URLSearchParams(new FormData(form)).toString();
        return url;
    };
    const announce = message => {status.textContent = message; status.hidden = !message;};
    const syncFields = url => {
        main.querySelectorAll('form.filters').forEach(form => {
            for (const field of form.elements) {
                if (field.name && !['checkbox', 'radio', 'submit'].includes(field.type)) field.value = url.searchParams.get(field.name) || '';
            }
        });
    };
    const search = async (url, mode = 'push', restore = false) => {
        clearTimeout(timer);
        controller?.abort();
        controller = new AbortController();
        const id = ++sequence;
        main.setAttribute('aria-busy', 'true');
        announce('Updating results…');
        try {
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {'X-Requested-With': 'XMLHttpRequest'}, signal: controller.signal});
            if (id !== sequence) return;
            if (response.redirected) {window.location.assign(response.url); return;}
            if (!response.ok) throw new Error('Search failed');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (id !== sequence) return;
            const incomingMain = page.getElementById('main');
            const incoming = incomingMain?.querySelector('[data-search-results]');
            if (!incoming) throw new Error('No search results');
            const current = resultArea();
            const currentPath = main.dataset.searchPath || initialPath;
            if (current && currentPath === url.pathname) {
                current.replaceChildren(...incoming.childNodes);
                if (restore) syncFields(url);
            } else {
                main.replaceChildren(...incomingMain.childNodes);
                main.dataset.searchPath = url.pathname;
                const workspace = main.closest('.app-shell');
                if (workspace) workspace.scrollTop = 0;
            }
            if (mode !== 'none' && url.href !== location.href) history[mode === 'replace' ? 'replaceState' : 'pushState']({search: true}, '', url);
            document.querySelectorAll('.portal-nav-link').forEach(link => {
                const target = new URL(link.href, location.href);
                const active = target.pathname === url.pathname && (!target.search || target.search === url.search);
                link.classList.toggle('selected', active);
                if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
            });
            document.dispatchEvent(new CustomEvent('portal:search-updated'));
            announce('Results updated.');
        } catch (error) {
            if (id === sequence && error.name !== 'AbortError') announce('Unable to update results. Your current results are still shown. Try searching again.');
        } finally {
            if (id === sequence) main.removeAttribute('aria-busy');
        }
    };
    document.addEventListener('submit', event => {
        if (!supported(event.target)) return;
        event.preventDefault();
        search(formUrl(event.target), 'push', event.target.matches('form.portal-search'));
    });
    document.addEventListener('input', event => {
        const field = event.target;
        const form = field.closest('form');
        if (!supported(form) || !field.matches('input:not([type=hidden]):not([type=date]):not([type=file]):not([type=checkbox]):not([type=radio])')) return;
        clearTimeout(timer);
        // Cancel immediately so older results cannot overwrite a newer query while typing.
        controller?.abort(); sequence++; main.removeAttribute('aria-busy');
        timer = setTimeout(() => search(formUrl(form), 'push', form.matches('form.portal-search')), 350);
    });
    document.addEventListener('change', event => {
        const form = event.target.closest('form');
        if (supported(form) && event.target.matches('select,input[type=date]')) search(formUrl(form));
    });
    document.addEventListener('click', event => {
        const link = event.target.closest('form.filters a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0 || !supported(link.closest('form'))) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || url.pathname !== location.pathname) return;
        event.preventDefault(); search(url, 'push', true);
    });
    window.addEventListener('popstate', () => {
        if (!initialSearchable && !main.dataset.searchPath) return;
        const url = new URL(location.href);
        if (url.pathname === '/timetable' || (url.pathname === initialPath && initialSearchable)) search(url, 'none', true);
        else window.location.reload();
    });
})();

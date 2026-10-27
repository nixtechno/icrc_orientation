(() => {
    const root = document.documentElement;
    let release;
    window.icrcPageReady = new Promise(resolve => { release = resolve; });
    const started = performance.now();
    let requested = false;
    let finished = false;
    const previousInert = new Map();
    root.classList.add('icrc-loading');
    const close = () => {
        if (finished) return;
        finished = true;
        const loader = document.getElementById('icrc-preloader');
        loader?.classList.add('is-leaving');
        loader?.setAttribute('aria-hidden', 'true');
        root.classList.remove('icrc-loading');
        for (const [element, value] of previousInert) element.inert = value;
        previousInert.clear();
        const duration = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 300;
        setTimeout(() => { loader?.remove(); release(); }, duration);
    };
    const finish = () => {
        if (requested) return;
        requested = true;
        setTimeout(close, Math.max(0, 650 - (performance.now() - started)));
    };
    // A failed or slow resource must never trap the visitor behind the loader.
    setTimeout(finish, 4000);
    const prepare = () => {
        if (finished) return;
        for (const element of document.body.children) {
            if (element.id !== 'icrc-preloader' && element.tagName !== 'SCRIPT') {
                previousInert.set(element, element.inert);
                element.inert = true;
            }
        }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', prepare, {once: true});
    else prepare();
    if (document.readyState === 'complete') finish();
    else window.addEventListener('load', finish, {once: true});
    // Restoring from the back/forward cache must leave the page usable.
    window.addEventListener('pageshow', event => { if (event.persisted) close(); });
})();

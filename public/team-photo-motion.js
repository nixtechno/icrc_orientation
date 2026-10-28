(() => {
    'use strict';
    const section = document.getElementById('icrc-team');
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (!section || !('IntersectionObserver' in window)) return;
    let visible = false;
    let frame = 0;
    const paint = () => {
        frame = 0;
        if (!visible || preference.matches) return;
        const rect = section.getBoundingClientRect();
        const progress = Math.max(0, Math.min(1, (window.innerHeight - rect.top) / (window.innerHeight + rect.height)));
        section.style.setProperty('--team-photo-scale', (1 + progress * .025).toFixed(4));
    };
    const queue = () => { if (!frame && visible && !preference.matches) frame = requestAnimationFrame(paint); };
    const configure = () => {
        section.classList.toggle('team-photo-ready', !preference.matches);
        if (preference.matches) {
            if (frame) cancelAnimationFrame(frame);
            frame = 0;
            section.style.removeProperty('--team-photo-scale');
        } else queue();
    };
    new IntersectionObserver(entries => {
        visible = entries[0].isIntersecting;
        if (visible) { section.classList.add('team-photo-visible'); queue(); }
    }, {threshold: 0}).observe(section);
    window.addEventListener('scroll', queue, {passive: true});
    window.addEventListener('resize', queue, {passive: true});
    preference.addEventListener('change', configure);
    configure();
})();

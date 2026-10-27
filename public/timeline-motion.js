(() => {
    const section = document.querySelector('#schedule');
    const list = section?.querySelector('.milestone-list');
    if (!list || !window.IntersectionObserver || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    let inView = false;
    let queued = false;
    function paint() {
        queued = false;
        if (!inView) return;
        const rect = list.getBoundingClientRect();
        const items = [...list.querySelectorAll('.milestone')];
        const last = items.at(-1);
        const end = last ? last.offsetTop + 11 : rect.height;
        const length = Math.max(1, end - 11);
        const progress = Math.max(0, Math.min(1, (window.innerHeight * .76 - rect.top - 11) / length));
        list.style.setProperty('--timeline-progress', progress.toFixed(4));
        list.style.setProperty('--timeline-length', `${length}px`);
    }
    function queue() { if (!queued && inView) { queued = true; requestAnimationFrame(paint); } }
    list.classList.add('timeline-enhanced');
    new IntersectionObserver(entries => {
        inView = entries[0].isIntersecting;
        if (inView) { section.classList.add('timeline-active'); queue(); }
    }, {threshold: 0}).observe(list);
    window.addEventListener('scroll', queue, {passive: true});
    window.addEventListener('resize', queue, {passive: true});
    if (window.ResizeObserver) new ResizeObserver(queue).observe(list);
})();

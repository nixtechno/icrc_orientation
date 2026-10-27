(() => {
    const hero = document.querySelector('.orientation-hero');
    if (!hero || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const title = document.getElementById('hero-title');
    const portrait = hero.querySelector('.orientation-portrait');
    const image = portrait?.querySelector('.orientation-host');
    hero.classList.add('hero-choreographed');
    title?.classList.add('headline-cinematic');
    portrait?.classList.add('portrait-cinematic');
    const imageReady = image?.decode ? image.decode().catch(() => {}) : Promise.resolve();
    const fontsReady = Promise.resolve(document.fonts?.ready).catch(() => {});
    let releaseScene;
    const sceneStarted = new Promise(resolve => { releaseScene = resolve; });
    function whenVisible(element, action) {
        if (!element || !('IntersectionObserver' in window)) return action();
        const observer = new IntersectionObserver(entries => {
            if (entries.some(entry => entry.isIntersecting)) { observer.disconnect(); action(); }
        }, {threshold: .1});
        observer.observe(element);
    }
    // Do not hold the heading and links indefinitely if an asset loads slowly.
    const ready = new Promise(resolve => {
        const timer = setTimeout(resolve, 1500);
        Promise.all([imageReady, fontsReady]).then(() => { clearTimeout(timer); resolve(); });
    });
    whenVisible(hero, () => Promise.all([ready, window.icrcPageReady ?? Promise.resolve()]).then(() => requestAnimationFrame(() => {
        hero.classList.add('hero-scene-active');
        title?.classList.add('headline-entering');
        releaseScene();
    })));
    // On narrow screens, save the portrait's entrance until it actually scrolls into view.
    whenVisible(portrait, () => Promise.all([sceneStarted, imageReady]).then(() => {
        requestAnimationFrame(() => portrait?.classList.add('portrait-entering'));
    }));
})();

(() => {
    'use strict';
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Account for the actual masthead height so the countdown starts after the first screen.
    if (document.querySelector('.ceremony-hero')) {
        const masthead = document.querySelector('.header');
        const institutionBar = document.querySelector('.institution-bar');
        const sizeHero = () => {
            const height = Math.ceil((masthead?.getBoundingClientRect().height || 0) + (institutionBar?.getBoundingClientRect().height || 0));
            document.documentElement.style.setProperty('--hero-header-height', `${height}px`);
        };
        sizeHero();
        window.addEventListener('resize', sizeHero, {passive: true});
        if ('ResizeObserver' in window) {
            const mastheadObserver = new ResizeObserver(sizeHero);
            if (masthead) mastheadObserver.observe(masthead);
            if (institutionBar) mastheadObserver.observe(institutionBar);
        }
    }

    // Only collapse navigation after its keyboard-accessible toggle is ready.
    const toggle = document.querySelector('.menu-toggle');
    const navigation = document.getElementById('primary-navigation');
    if (toggle && navigation) {
        document.documentElement.classList.add('js-navigation');
        const close = () => { navigation.classList.remove('is-open'); toggle.setAttribute('aria-expanded', 'false'); };
        toggle.addEventListener('click', () => {
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', String(open));
            navigation.classList.toggle('is-open', open);
        });
        navigation.addEventListener('click', event => { if (event.target.closest('a')) close(); });
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') { close(); toggle.focus(); } });
    }

    // Links remain normal anchors without JavaScript; all six chapters remain readable.
    document.querySelectorAll('[data-week-explorer]').forEach(explorer => {
        const choices = [...explorer.querySelectorAll('[data-week]')];
        const panels = [...explorer.querySelectorAll('[data-week-panel]')];
        function selectWeek(number) {
            choices.forEach(choice => {
                const active = choice.dataset.week === String(number);
                choice.classList.toggle('is-active', active);
                if (active) choice.setAttribute('aria-current', 'true'); else choice.removeAttribute('aria-current');
            });
            panels.forEach(panel => {
                const active = panel.dataset.weekPanel === String(number);
                panel.hidden = !active;
                panel.classList.toggle('is-active', active);
            });
        }
        choices.forEach((choice, index) => {
            choice.addEventListener('click', event => { event.preventDefault(); selectWeek(choice.dataset.week); });
            choice.addEventListener('keydown', event => {
                let next = index;
                if (['ArrowDown', 'ArrowRight'].includes(event.key)) next = (index + 1) % choices.length;
                else if (['ArrowUp', 'ArrowLeft'].includes(event.key)) next = (index - 1 + choices.length) % choices.length;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = choices.length - 1;
                else return;
                event.preventDefault(); choices[next].focus(); selectWeek(choices[next].dataset.week);
            });
        });
        document.querySelectorAll('[data-open-week]').forEach(link => link.addEventListener('click', () => selectWeek(link.dataset.openWeek)));
        const hashWeek = /^#week-panel-([1-6])$/.exec(window.location.hash);
        selectWeek(hashWeek ? hashWeek[1] : '1');
    });

    // Absolute timestamps include the programme timezone offset; no visitor-local interpretation.
    const countdown = document.querySelector('[data-countdown]');
    if (countdown) {
        const start = Date.parse(countdown.dataset.start);
        const end = Date.parse(countdown.dataset.end);
        const status = document.querySelector('[data-programme-status]');
        let interval;
        const tick = () => {
            const now = Date.now();
            if (now >= start) {
                const message = now <= end ? 'The orientation programme is in progress.' : 'The 2026 orientation has concluded.';
                if (status) status.textContent = message;
                countdown.textContent = now <= end ? 'Learning in progress' : 'A foundation for the future';
                countdown.setAttribute('aria-label', message);
                if (now > end) clearInterval(interval);
                return;
            }
            const seconds = Math.max(0, Math.floor((start - now) / 1000));
            const values = {days: Math.floor(seconds / 86400), hours: Math.floor(seconds / 3600) % 24, minutes: Math.floor(seconds / 60) % 60, seconds: seconds % 60};
            Object.entries(values).forEach(([unit, value]) => { const element = countdown.querySelector(`[data-unit="${unit}"]`); if (element) element.textContent = String(value).padStart(2, '0'); });
        };
        if (Number.isFinite(start) && Number.isFinite(end)) { interval = window.setInterval(tick, 1000); tick(); }
    }

    // Reveal effects never hide content when reduced motion is requested or JS is unavailable.
    if (!reducedMotion && 'IntersectionObserver' in window) {
        document.documentElement.classList.add('motion-ready');
        const observer = new IntersectionObserver(entries => entries.forEach(entry => {
            if (entry.isIntersecting) { entry.target.classList.add('is-visible'); observer.unobserve(entry.target); }
        }), {threshold: 0.08});
        document.querySelectorAll('.reveal').forEach(element => observer.observe(element));
    }
})();

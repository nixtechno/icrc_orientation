// Timetable week tabs. Tabs are links, so they still work without JavaScript; with it they switch in place.
(() => {
    'use strict';
    const select = (tab, focus) => {
        const list = tab.closest('[data-week-tabs]');
        list.querySelectorAll('[role=tab]').forEach(other => {
            const active = other === tab;
            other.setAttribute('aria-selected', active ? 'true' : 'false');
            other.tabIndex = active ? 0 : -1;
            const panel = document.getElementById(other.getAttribute('aria-controls'));
            if (panel) panel.hidden = !active;
        });
        if (focus) tab.focus();
        tab.scrollIntoView({block: 'nearest', inline: 'nearest'});
        const url = new URL(location.href);
        url.searchParams.set('week', tab.dataset.week);
        history.replaceState(history.state, '', url);
    };
    document.addEventListener('click', event => {
        const tab = event.target.closest('[data-week-tabs] [role=tab]');
        if (!tab || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
        event.preventDefault();
        select(tab, false);
    });
    document.addEventListener('keydown', event => {
        const tab = event.target.closest('[data-week-tabs] [role=tab]');
        if (!tab) return;
        const tabs = [...tab.closest('[data-week-tabs]').querySelectorAll('[role=tab]')];
        const index = tabs.indexOf(tab);
        const next = {ArrowRight: tabs[index + 1] || tabs[0], ArrowLeft: tabs[index - 1] || tabs[tabs.length - 1], Home: tabs[0], End: tabs[tabs.length - 1]}[event.key];
        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();
            select(tab, true);
        } else if (next) {
            event.preventDefault();
            select(next, true);
        }
    });
})();

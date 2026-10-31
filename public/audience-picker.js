// Activity audience: shows the group or staff picker for the chosen mode and filters the staff list.
(() => {
    'use strict';
    const initialise = () => document.querySelectorAll('[data-audience]').forEach(root => {
        if (root.dataset.audienceBound) return;
        root.dataset.audienceBound = '1';
        const mode = root.querySelector('[name="assignment_mode"]');
        const groupField = root.querySelector('[data-group-field]');
        const group = root.querySelector('[name="assessment_group_id"]');
        const staffField = root.querySelector('[data-staff-field]');
        const search = root.querySelector('[data-staff-search]');
        const count = root.querySelector('[data-staff-count]');
        const empty = root.querySelector('[data-staff-empty]');
        const options = [...root.querySelectorAll('[data-staff-option]')];
        const update = () => {
            const grouped = mode.value === 'group', selected = mode.value === 'selected';
            groupField.hidden = !grouped; group.disabled = !grouped; group.required = grouped;
            staffField.hidden = !selected; staffField.disabled = !selected;
        };
        const countSelected = () => { count.textContent = `(${options.filter(option => option.querySelector('input').checked).length} selected)`; };
        const filter = () => {
            const terms = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
            let shown = 0;
            options.forEach(option => {
                option.hidden = !terms.every(term => option.dataset.searchText.includes(term));
                if (!option.hidden) shown++;
            });
            empty.hidden = shown > 0 || !options.length;
        };
        mode.addEventListener('change', update);
        staffField.addEventListener('change', countSelected);
        search.addEventListener('input', filter);
        // Enter in the search box filters the list instead of submitting the form.
        search.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
        update(); countSelected();
    });
    document.addEventListener('portal:search-updated', initialise);
    initialise();
})();

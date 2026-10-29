(() => {
    const initialise = () => document.querySelectorAll('[data-staff-group]').forEach(form => {
        if (form.dataset.bound) return;
        form.dataset.bound = '1';
        const search = form.querySelector('[data-member-search]');
        const labels = [...form.querySelectorAll('[data-member-label]')];
        const count = form.querySelector('[data-member-count]');
        const empty = form.querySelector('[data-member-empty]');
        const update = () => {
            const query = search.value.trim().toLocaleLowerCase();
            let visible = 0;
            labels.forEach(label => { label.hidden = !label.textContent.toLocaleLowerCase().includes(query); if (!label.hidden) visible++; });
            count.textContent = `${form.querySelectorAll('[name="members[]"]:checked').length} staff selected`;
            empty.hidden = visible > 0;
        };
        search.addEventListener('input', update);
        form.addEventListener('change', update);
        update();
    });
    initialise();
    document.addEventListener('portal:search-updated', initialise);
})();

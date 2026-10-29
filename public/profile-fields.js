(() => {
    const initialise = () => {
    document.querySelectorAll('[data-profile-fields]').forEach(root => {
        if (root.dataset.profileBound) return;
        root.dataset.profileBound = '1';
        const role = root.closest('form')?.querySelector('[name="role"]');
        const update = () => {
            const current = role?.value || root.dataset.profileRole;
            root.querySelectorAll('[data-profile-role-section]').forEach(section => {
                const active = section.dataset.profileRoleSection === 'facilitator' ? current === 'facilitator' : current !== 'facilitator';
                section.hidden = !active;
                if (section.tagName === 'FIELDSET') section.disabled = !active;
                else section.querySelectorAll('input,select,textarea').forEach(field => field.disabled = !active);
            });
        };
        role?.addEventListener('change', update);
        update();
    });
    document.querySelectorAll('[data-facilitator-form]').forEach(form => {
        if (form.dataset.profileBound) return;
        form.dataset.profileBound = '1';
        const account = form.querySelector('[name="user_id"]');
        const email = form.querySelector('[name="email"]');
        if (!account || !email) return;
        const update = () => {
            const option = account.selectedOptions[0];
            const locked = form.dataset.emailLocked === '1' || option?.dataset.emailLocked === '1';
            email.readOnly = locked;
            if (option?.dataset.emailLocked === '1') email.value = option.dataset.email;
        };
        account.addEventListener('change', update); update();
    });
    };
    initialise();
    document.addEventListener('portal:search-updated', initialise);
})();

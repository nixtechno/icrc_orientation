(() => {
    const form = document.querySelector('[data-new-account-permissions]');
    if (!form) return;
    const role = form.querySelector('[name="role"]');
    const defaults = new Set(form.dataset.newAccountPermissions.split(','));
    role?.addEventListener('change', () => {
        form.querySelectorAll('[name="permissions[]"]').forEach(field => {
            field.checked = role.value === 'facilitator' && defaults.has(field.value);
        });
    });
})();

// Delete buttons open a confirmation dialog; nothing is deleted until the manager confirms there.
(() => {
    'use strict';
    const dialog = document.querySelector('[data-delete-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const form = dialog.querySelector('form');
    const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;
    document.querySelectorAll('[data-delete-activity]').forEach(button => { button.hidden = false; });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-delete-activity]');
        if (!button) return;
        const {url, title, label} = button.dataset;
        const submissions = Number(button.dataset.submissions || 0);
        form.action = url;
        dialog.querySelector('[data-delete-heading]').textContent = `Delete this ${label}?`;
        dialog.querySelector('[data-delete-message]').textContent = submissions
            ? `“${title}” and its ${plural(submissions, 'submission')}, including any uploaded files, will be permanently deleted.`
            : `“${title}” will be permanently deleted.`;
        dialog.querySelector('[data-delete-confirm]').textContent = `Delete ${label}`;
        dialog.showModal();
        dialog.querySelector('[data-delete-cancel]').focus();
    });
    dialog.querySelector('[data-delete-cancel]').addEventListener('click', () => dialog.close());
    form.addEventListener('submit', () => { const confirm = dialog.querySelector('[data-delete-confirm]'); confirm.disabled = true; confirm.textContent = 'Deleting…'; });
})();

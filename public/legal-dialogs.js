// Terms and privacy links open their modal in place; the href remains a working page without JavaScript.
(() => {
    'use strict';
    const dialogs = {};
    document.querySelectorAll('[data-legal-dialog]').forEach(dialog => {
        if (typeof dialog.showModal !== 'function') return;
        dialogs[dialog.dataset.legalDialog] = dialog;
        dialog.querySelectorAll('[data-legal-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
        // A click on the backdrop lands on the dialog element itself.
        dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    });
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-legal-open]');
        const dialog = link && dialogs[link.dataset.legalOpen];
        if (!dialog) return;
        event.preventDefault();
        Object.values(dialogs).forEach(open => open.open && open.close());
        dialog.showModal();
        dialog.querySelector('.legal-dialog-body').scrollTop = 0;
    });
})();

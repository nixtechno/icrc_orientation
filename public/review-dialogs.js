// Submission reviews open in a dialog from the submissions table, or from a #submission-… link to this page.
(() => {
    'use strict';
    const dialogFor = hash => {
        const dialog = hash.length > 1 ? document.getElementById(decodeURIComponent(hash.slice(1))) : null;
        return dialog?.matches('dialog.review-dialog') && typeof dialog.showModal === 'function' ? dialog : null;
    };
    const open = dialog => {
        document.querySelectorAll('dialog.review-dialog[open]').forEach(other => other.close());
        dialog.showModal();
        dialog.querySelector('input[name="score"]')?.focus();
    };
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-review-open]');
        if (trigger) {
            const dialog = dialogFor(new URL(trigger.href, location.href).hash);
            if (!dialog || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
            event.preventDefault();
            open(dialog);
            return;
        }
        if (event.target.closest('[data-review-close]')) { event.target.closest('dialog')?.close(); return; }
        // A click on the backdrop lands on the dialog element itself.
        if (event.target.matches('dialog.review-dialog')) {
            const box = event.target.getBoundingClientRect();
            if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) event.target.close();
        }
    });
    document.addEventListener('submit', event => {
        const button = event.target.closest('dialog.review-dialog')?.querySelector('button:not([type="button"])');
        if (button) { button.disabled = true; button.textContent = 'Saving…'; }
    });
    const fromHash = dialogFor(location.hash);
    if (fromHash) open(fromHash);
})();

// Daily training feedback is compulsory: the dialog opens by itself at the feedback time (straight away if the page loads
// later), cannot be closed, and walks through one step at a time until it is sent. It never opens over an assessment in progress.
(() => {
    'use strict';
    const dialog = document.querySelector('[data-feedback-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const form = dialog.querySelector('form');
    const steps = [...dialog.querySelectorAll('[data-feedback-step]')];
    const progress = dialog.querySelector('[data-feedback-progress]');
    const dots = [...dialog.querySelectorAll('[data-feedback-dot]')];
    const back = dialog.querySelector('[data-feedback-back]');
    const next = dialog.querySelector('[data-feedback-next]');
    const submit = dialog.querySelector('[data-feedback-submit]');
    const count = dialog.querySelector('[data-feedback-count]');
    const comment = dialog.querySelector('[data-feedback-comment]');
    const chars = dialog.querySelector('[data-feedback-chars]');
    let current = 0, sending = false;

    const busy = () => Boolean(document.fullscreenElement || document.webkitFullscreenElement || document.documentElement.classList.contains('quiz-focus-mode') || document.querySelector('[data-quiz-entry] [data-quiz-stage]:not([hidden])'));

    const needsRating = step => step.hasAttribute('data-feedback-rating') && !step.querySelector('.rating-input:checked');
    const show = index => {
        current = index;
        steps.forEach((step, i) => { step.hidden = i !== index; });
        // The welcome screen comes before the numbered steps.
        progress.hidden = index === 0;
        dots.forEach((dot, i) => { dot.classList.toggle('is-current', i === index - 1); dot.classList.toggle('is-done', i < index - 1); });
        if (count) count.textContent = index > 0 ? `Step ${index} of ${steps.length - 1}` : '';
        back.hidden = index <= 1;
        next.hidden = index === steps.length - 1;
        submit.hidden = index !== steps.length - 1;
        next.textContent = index === 0 ? 'Start' : 'Next';
        (steps[index].querySelector('.rating-input:checked, .rating-input, textarea') || next).focus();
    };
    const check = index => {
        const ok = !needsRating(steps[index]);
        const error = steps[index].querySelector('[data-feedback-error]');
        if (error) error.hidden = ok;
        return ok;
    };
    const open = () => {
        if (dialog.open) return;
        if (busy()) { setTimeout(open, 30000); return; }
        dialog.showModal();
        // A rejected submission comes back with ratings filled in, so resume at the first gap or the comment.
        const resume = steps.findIndex(needsRating);
        show(form.querySelector('.rating-input:checked') ? (resume === -1 ? steps.length - 1 : resume) : 0);
    };

    steps.forEach(step => {
        const word = step.querySelector('[data-rating-word]');
        if (!word) return;
        const label = () => { const input = step.querySelector('.rating-input:checked'); word.textContent = input ? step.querySelector(`label[for="${input.id}"]`).title : ''; };
        step.addEventListener('change', () => { label(); step.querySelector('[data-feedback-error]').hidden = true; });
        label();
    });
    if (comment && chars) {
        const left = () => { chars.textContent = `${comment.maxLength - comment.value.length} characters left`; };
        comment.addEventListener('input', left); left();
    }
    // Picking a star moves on by itself after a moment, so a rating is one tap.
    steps.forEach((step, i) => step.addEventListener('change', event => {
        if (event.target.matches('.rating-input') && i === current) setTimeout(() => { if (current === i) show(i + 1); }, 350);
    }));
    next.addEventListener('click', () => { if (check(current)) show(current + 1); });
    back.addEventListener('click', () => show(current - 1));
    dialog.addEventListener('cancel', event => event.preventDefault());
    // Some browsers close a modal on a repeated Escape regardless; reopen it until feedback is sent.
    dialog.addEventListener('close', () => { if (!sending) setTimeout(open, 0); });
    form.addEventListener('submit', event => {
        const missing = steps.findIndex(needsRating);
        if (missing !== -1) { event.preventDefault(); show(missing); check(missing); return; }
        sending = true; submit.disabled = true; submit.textContent = 'Sending…';
    });

    // Times come from the server, so a wrong device clock does not change when the form opens.
    const wait = Date.parse(dialog.dataset.opensAt) - Date.parse(dialog.dataset.serverNow);
    if (wait <= 0) open();
    else if (wait < 86400000) setTimeout(open, wait);
})();

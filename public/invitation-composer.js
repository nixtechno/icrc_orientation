(() => {
    'use strict';
    const modal = document.querySelector('[data-invite-modal]');
    const configNode = document.querySelector('[data-invite-config]');
    if (!modal || !configNode || typeof modal.showModal !== 'function') return;
    const config = JSON.parse(configNode.textContent);
    const BATCH = 10;
    const $ = selector => modal.querySelector(selector);
    const field = name => $(`[data-field="${name}"]`);
    const newField = name => $(`[data-new="${name}"]`);
    const selection = new Map();
    const state = {mode: 'new', recipients: [], sending: false, sent: 0, previewTimer: null, previewRequest: null};
    const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

    const content = () => ({subject: field('subject').value, body: field('body').value, button: field('button').value});
    const fill = values => ['subject', 'body', 'button'].forEach(name => {field(name).value = values[name] ?? '';});
    fill(config.content);

    const post = async (url, payload) => {
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf, 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const messages = data.errors ? Object.values(data.errors).flat() : [data.message || `Request failed (${response.status}).`];
            throw Object.assign(new Error(messages[0]), {messages});
        }
        return data;
    };

    const showErrors = messages => {
        const box = $('[data-invite-errors]');
        box.replaceChildren();
        if (messages?.length) {
            const list = document.createElement('ul');
            messages.forEach(message => list.appendChild(Object.assign(document.createElement('li'), {textContent: message})));
            box.append(Object.assign(document.createElement('strong'), {textContent: 'Please check the following:'}), list);
        }
        box.hidden = !messages?.length;
    };

    // Preview -----------------------------------------------------------------
    // One existing person previews with their own details and QR code; new people and bulk sends use a sample QR.
    const single = () => state.mode !== 'new' && state.recipients.length === 1;
    const sample = () => state.mode === 'new'
        ? {name: newField('name').value.trim(), email: newField('email').value.trim(), role: newField('role').value}
        : (state.recipients[0] ? {name: state.recipients[0].name, email: state.recipients[0].email, role: state.recipients[0].role, ...(single() ? {user: state.recipients[0].key} : {})} : {});
    const frame = $('[data-invite-frame]');
    const previewStatus = $('[data-invite-preview-status]');
    const refreshPreview = async () => {
        const request = state.previewRequest = Symbol('preview');
        previewStatus.textContent = 'Updating…';
        try {
            const data = await post('/manage/staff/invitations/preview', {...content(), ...sample()});
            if (request !== state.previewRequest) return;
            let scroll = 0;
            try { scroll = frame.contentWindow.scrollY; } catch { /* preview not loaded yet */ }
            frame.addEventListener('load', () => { try { frame.contentWindow.scrollTo(0, scroll); } catch { /* ignore */ } }, {once: true});
            frame.srcdoc = data.html;
            $('[data-invite-preview-subject]').textContent = data.subject;
            const who = sample().name;
            previewStatus.textContent = single() ? `Shown as ${who} will see it`
                : who ? `Shown with ${who}'s details and a sample QR code; each person receives their own`
                : 'Shown with sample details and a sample QR code';
        } catch (error) {
            if (request === state.previewRequest) previewStatus.textContent = `Preview unavailable: ${error.message}`;
        }
    };
    const schedulePreview = () => {clearTimeout(state.previewTimer); state.previewTimer = setTimeout(refreshPreview, 350);};

    // Recipients --------------------------------------------------------------
    const updateSendButton = () => {
        const button = $('[data-invite-send]');
        const count = state.mode === 'new' ? 1 : state.recipients.length;
        button.textContent = state.mode === 'new' ? 'Create & send invitation' : (count === 1 ? 'Send invitation' : `Send to ${plural(count, 'person', 'people')}`);
        button.disabled = state.sending || count === 0;
    };
    const renderRecipients = () => {
        const list = $('[data-invite-recipients]');
        list.replaceChildren(...state.recipients.map(person => {
            const item = document.createElement('li');
            const text = document.createElement('span');
            text.append(Object.assign(document.createElement('strong'), {textContent: person.name}), document.createTextNode(` ${person.email}`));
            const remove = Object.assign(document.createElement('button'), {type: 'button', textContent: '×', title: `Remove ${person.name}`});
            remove.setAttribute('aria-label', `Remove ${person.name}`);
            remove.dataset.inviteRemove = person.key;
            remove.disabled = state.sending;
            item.append(text, remove);
            return item;
        }));
        $('[data-invite-recipient-count]').textContent = `(${state.recipients.length})`;
        $('[data-invite-summary]').textContent = state.mode === 'new'
            ? 'Create an account and send a personal activation email.'
            : `${plural(state.recipients.length, 'person', 'people')} will receive a personal activation link.`;
        updateSendButton();
    };

    const setMode = mode => {
        state.mode = mode;
        modal.querySelectorAll('[data-invite-mode]').forEach(set => {set.hidden = set.dataset.inviteMode !== mode;});
        $('[data-invite-title]').textContent = mode === 'new' ? 'Invite a new person' : 'Send invitations';
    };

    const setView = view => {
        $('[data-invite-body]').dataset.view = view;
        modal.querySelectorAll('[data-invite-view]').forEach(tab => tab.setAttribute('aria-selected', String(tab.dataset.inviteView === view)));
    };

    const resetProgress = () => {
        $('[data-invite-progress]').hidden = true;
        $('[data-invite-results]').replaceChildren();
        showErrors(null);
    };

    const open = (mode, recipients = []) => {
        if (state.sending) return;
        state.recipients = recipients;
        state.sent = 0;
        setMode(mode);
        setView('compose');
        resetProgress();
        renderRecipients();
        $('[data-invite-close]:not(.invite-close)').textContent = 'Cancel';
        modal.showModal();
        (mode === 'new' ? newField('name') : field('subject')).focus();
        refreshPreview();
    };

    const close = () => {
        if (state.sending) return;
        modal.close();
        if (state.sent > 0) window.location.reload();
    };

    // Sending -----------------------------------------------------------------
    const setBusy = busy => {
        state.sending = busy;
        modal.classList.toggle('is-sending', busy);
        modal.querySelectorAll('input, select, textarea, [data-invite-insert], [data-invite-reset], [data-invite-save-default], [data-invite-remove]').forEach(control => {control.disabled = busy;});
        modal.querySelectorAll('[data-invite-close]').forEach(control => {control.disabled = busy;});
        updateSendButton();
    };

    const addResult = (name, status, message) => {
        const item = document.createElement('li');
        item.className = `is-${status}`;
        item.append(Object.assign(document.createElement('strong'), {textContent: name || 'Unknown person'}), document.createTextNode(` — ${message}`));
        $('[data-invite-results]').appendChild(item);
    };

    const sendNew = async () => {
        const person = {name: newField('name').value.trim(), email: newField('email').value.trim(), role: newField('role').value};
        const missing = [!person.name && 'Enter the full name.', !newField('email').checkValidity() || !person.email ? 'Enter a valid email address.' : null].filter(Boolean);
        if (missing.length) { showErrors(missing); return; }
        setBusy(true);
        try {
            const data = await post('/manage/staff', {invitation_only: 1, ...person, ...content()});
            state.sent++;
            const progress = $('[data-invite-progress]');
            progress.hidden = false;
            $('[data-invite-progress-text]').textContent = 'Invitation sent';
            $('[data-invite-progress-tally]').textContent = '';
            $('[data-invite-progress-bar]').value = 1;
            addResult(person.name, 'sent', data.message || 'Invitation sent.');
            ['name', 'email'].forEach(name => {newField(name).value = '';});
            setBusy(false);
            $('[data-invite-send]').textContent = 'Create & send another';
            $('[data-invite-close]:not(.invite-close)').textContent = 'Close';
        } catch (error) {
            showErrors(error.messages || [error.message]);
            setBusy(false);
        }
    };

    const sendExisting = async () => {
        const people = [...state.recipients];
        if (people.length > 1 && !window.confirm(`Send the invitation email to ${plural(people.length, 'person', 'people')}? Each earlier unused link for these people will stop working.`)) return;
        setBusy(true);
        resetProgress();
        const progress = $('[data-invite-progress]');
        const bar = $('[data-invite-progress-bar]');
        const tally = {sent: 0, skipped: 0, failed: 0};
        const failed = [];
        progress.hidden = false;
        bar.max = people.length;
        bar.value = 0;
        const report = done => {
            $('[data-invite-progress-text]').textContent = done < people.length ? `Sending ${done} of ${people.length}…` : `Finished ${people.length} of ${people.length}`;
            $('[data-invite-progress-tally]').textContent = `${tally.sent} sent · ${tally.skipped} skipped · ${tally.failed} failed`;
            bar.value = done;
        };
        report(0);
        const payload = content();
        for (let i = 0; i < people.length; i += BATCH) {
            const batch = people.slice(i, i + BATCH);
            let results;
            try {
                results = (await post('/manage/staff/invitations/send', {...payload, users: batch.map(person => person.key)})).results;
            } catch (error) {
                if (error.messages && i === 0) { // Validation problems apply to every batch: stop before sending anything.
                    showErrors(error.messages);
                    progress.hidden = true;
                    setBusy(false);
                    return;
                }
                results = batch.map(person => ({key: person.key, name: person.name, status: 'failed', message: error.message || 'Network error.'}));
            }
            results.forEach(result => {
                tally[result.status] = (tally[result.status] || 0) + 1;
                if (result.status === 'sent') { state.sent++; selection.delete(result.key); }
                if (result.status === 'failed') failed.push(people.find(person => person.key === result.key));
                if (result.status !== 'sent' || people.length <= 25) addResult(result.name || people.find(person => person.key === result.key)?.name, result.status, result.message);
            });
            report(Math.min(i + BATCH, people.length));
        }
        if (people.length > 25 && tally.sent) addResult(plural(tally.sent, 'person', 'people'), 'sent', 'Invitation sent.');
        state.recipients = failed.filter(Boolean);
        setBusy(false);
        renderRecipients();
        syncTable();
        const send = $('[data-invite-send]');
        $('[data-invite-summary]').textContent = `${plural(tally.sent, 'invitation', 'invitations')} sent${tally.failed ? `; ${tally.failed} failed and can be retried` : ''}.`;
        if (state.recipients.length) send.textContent = `Retry ${plural(state.recipients.length, 'failed invitation', 'failed invitations')}`;
        else { send.textContent = 'All done'; send.disabled = true; }
        $('[data-invite-close]:not(.invite-close)').textContent = 'Close';
    };

    // Template actions ------------------------------------------------------------
    const templateStatus = $('[data-invite-template-status]');
    $('[data-invite-reset]').addEventListener('click', () => {
        if (!window.confirm('Replace the subject, message and button text with the standard invitation email?')) return;
        fill(config.defaults);
        templateStatus.textContent = 'Standard email restored. Save as default to keep it.';
        schedulePreview();
    });
    $('[data-invite-save-default]')?.addEventListener('click', async () => {
        templateStatus.textContent = 'Saving…';
        try {
            const data = await post('/manage/staff/invitations/template', content());
            config.content = content();
            templateStatus.textContent = data.message;
            showErrors(null);
        } catch (error) {
            templateStatus.textContent = '';
            showErrors(error.messages || [error.message]);
        }
    });

    $('[data-invite-send]').addEventListener('click', () => {
        showErrors(null);
        if (state.mode === 'new') sendNew(); else sendExisting();
    });
    modal.querySelectorAll('[data-invite-close]').forEach(button => button.addEventListener('click', close));
    modal.addEventListener('cancel', event => { event.preventDefault(); close(); });
    modal.addEventListener('click', event => { if (event.target === modal) close(); });
    modal.addEventListener('input', event => {
        if (event.target.matches('[data-field], [data-new="name"]')) schedulePreview();
        if (event.target.matches('[data-new]')) $('[data-invite-send]').textContent = 'Create & send invitation';
    });
    modal.addEventListener('change', event => { if (event.target.matches('[data-new="role"]')) schedulePreview(); });
    modal.addEventListener('click', event => {
        const insert = event.target.closest('[data-invite-insert]');
        if (insert) {
            const body = field('body');
            const token = insert.dataset.inviteInsert;
            body.setRangeText(token, body.selectionStart, body.selectionEnd, 'end');
            body.focus();
            schedulePreview();
        }
        const remove = event.target.closest('[data-invite-remove]');
        if (remove) {
            state.recipients = state.recipients.filter(person => person.key !== remove.dataset.inviteRemove);
            renderRecipients();
            schedulePreview();
        }
        const view = event.target.closest('[data-invite-view]');
        if (view) setView(view.dataset.inviteView);
    });

    // Table selection (the table is replaced by live search, so use delegation) --------------
    const bulkbar = document.querySelector('[data-invite-bulkbar]');
    const person = node => ({key: node.value || node.dataset.key, name: node.dataset.name, email: node.dataset.email, role: node.dataset.role});
    const syncTable = () => {
        const boxes = [...document.querySelectorAll('[data-invite-select]')];
        boxes.forEach(box => {box.checked = selection.has(box.value);});
        const all = document.querySelector('[data-invite-select-all]');
        if (all) {
            const checked = boxes.filter(box => box.checked).length;
            all.checked = boxes.length > 0 && checked === boxes.length;
            all.indeterminate = checked > 0 && checked < boxes.length;
            all.disabled = boxes.length === 0;
        }
        if (bulkbar) {
            bulkbar.hidden = selection.size === 0;
            bulkbar.querySelector('[data-invite-count]').textContent = selection.size;
            bulkbar.querySelector('[data-invite-count-label]').textContent = selection.size === 1 ? 'person selected' : 'people selected';
        }
    };
    document.addEventListener('change', event => {
        if (event.target.matches('[data-invite-select]')) {
            if (event.target.checked) selection.set(event.target.value, person(event.target)); else selection.delete(event.target.value);
            syncTable();
        } else if (event.target.matches('[data-invite-select-all]')) {
            document.querySelectorAll('[data-invite-select]').forEach(box => {
                if (event.target.checked) selection.set(box.value, person(box)); else selection.delete(box.value);
            });
            syncTable();
        }
    });
    document.addEventListener('click', event => {
        const single = event.target.closest('[data-invite-person]');
        if (single) open('existing', [person(single)]);
        if (event.target.closest('[data-invite-new]')) open('new');
        if (event.target.closest('[data-invite-selected]')) open('existing', [...selection.values()]);
        if (event.target.closest('[data-invite-clear]')) { selection.clear(); syncTable(); }
    });
    document.addEventListener('portal:search-updated', syncTable);
    syncTable();
})();

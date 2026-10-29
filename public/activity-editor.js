(() => {
    const microphones = new Map();
    const initialise = () => {
        microphones.forEach((recognition, form) => {
            if (!form.isConnected) { recognition.abort(); microphones.delete(form); }
        });
        document.querySelectorAll('[data-activity-editor]').forEach(form => {
            if (form.dataset.editorBound) return;
            form.dataset.editorBound = '1';
            const week = form.querySelector('[name="programme_week_id"]');
            const session = form.querySelector('[name="session_id"]');
            const options = [...session.options].slice(1);
            const updateSessions = (clear = false) => {
                const selected = clear ? '' : session.value;
                options.forEach(option => { option.disabled = option.dataset.week !== week.value; option.hidden = option.disabled; });
                session.disabled = !week.value;
                session.options[0].textContent = !week.value ? 'Choose a week first' : options.some(option => !option.disabled) ? 'Not linked to a session' : 'No sessions in this week';
                session.value = options.some(option => !option.disabled && option.value === selected) ? selected : '';
            };
            week.addEventListener('change', () => updateSessions(true));
            updateSessions();

            const audience = form.querySelector('[name="assignment_mode"]');
            const groupField = form.querySelector('[data-group-field]');
            const group = form.querySelector('[name="assessment_group_id"]');
            const updateGroup = () => { const active = audience.value === 'group'; groupField.hidden = !active; group.disabled = !active; group.required = active; };
            audience.addEventListener('change', updateGroup); updateGroup();

            const toggle = form.querySelector('[data-dictation-toggle]');
            const instructions = form.querySelector('[name="instructions"]');
            const status = form.querySelector('[data-dictation-status]');
            const preview = form.querySelector('[data-dictation-preview]');
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) {
                toggle.disabled = true;
                status.textContent = 'Voice dictation is unavailable in this browser. You can type your instructions, or try a browser with speech recognition.';
                return;
            }
            const recognition = new SpeechRecognition();
            microphones.set(form, recognition);
            recognition.lang = 'en-NG';
            recognition.continuous = true;
            recognition.interimResults = true;
            let active = false, message = '', processed = new Set();
            const setActive = value => {
                active = value;
                toggle.setAttribute('aria-pressed', String(value));
                toggle.textContent = value ? 'Stop dictation' : 'Use AI Speech to Text';
            };
            toggle.addEventListener('click', () => {
                if (active) { recognition.stop(); return; }
                processed = new Set(); message = ''; preview.textContent = '';
                try {
                    setActive(true);
                    status.textContent = 'Requesting microphone access…';
                    recognition.start();
                } catch {
                    setActive(false);
                    status.textContent = 'Could not start dictation. Please try again or type your instructions.';
                }
            });
            recognition.onstart = () => { status.textContent = 'Listening. Speak clearly, then stop dictation to review your instructions.'; };
            recognition.onresult = event => {
                const interim = [];
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const result = event.results[i];
                    if (result.isFinal && !processed.has(i)) {
                        processed.add(i);
                        const transcript = result[0].transcript.trim();
                        if (!transcript) continue;
                        const text = instructions.value + (instructions.value && !/\s$/.test(instructions.value) ? ' ' : '') + transcript;
                        const limit = instructions.maxLength > 0 ? instructions.maxLength : 20000;
                        instructions.value = text.slice(0, limit);
                        instructions.dispatchEvent(new Event('input', { bubbles: true }));
                        if (text.length > limit) { message = 'The instruction length limit has been reached. Review the text before saving.'; recognition.stop(); }
                    } else if (!result.isFinal) interim.push(result[0].transcript);
                }
                preview.textContent = interim.join(' ');
            };
            recognition.onerror = event => {
                message = ({ 'not-allowed': 'Microphone access was denied. Allow microphone access to dictate, or type your instructions.',
                    'audio-capture': 'No microphone is available. Connect a microphone or type your instructions.',
                    'network': 'Speech recognition could not connect. Check your connection and try again.',
                    'no-speech': 'No speech was detected. Try dictating again.' })[event.error] || 'Dictation stopped. You can retry or continue typing.';
                status.textContent = message; preview.textContent = ''; setActive(false);
            };
            recognition.onend = () => { setActive(false); preview.textContent = ''; status.textContent = message || 'Dictation stopped. Review and edit your instructions before saving.'; };
            form.addEventListener('submit', event => {
                if (active) {
                    event.preventDefault();
                    message = 'Dictation stopped. Review your instructions, then choose Create draft again.';
                    recognition.stop(); instructions.focus();
                }
            });
            form.closest('details')?.addEventListener('toggle', event => { if (!event.target.open && active) recognition.stop(); });
        });
    };
    window.addEventListener('pagehide', () => microphones.forEach(recognition => recognition.abort()));
    document.addEventListener('visibilitychange', () => { if (document.hidden) microphones.forEach(recognition => recognition.abort()); });
    document.addEventListener('portal:search-updated', initialise);
    initialise();
})();

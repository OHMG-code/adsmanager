(function () {
    const form = document.getElementById('creative-brief-form');
    if (!form) return;

    const panels = Array.from(form.querySelectorAll('[data-brief-panel]'));
    const stepButtons = Array.from(document.querySelectorAll('[data-brief-step]'));
    const prevButton = document.querySelector('[data-brief-prev]');
    const nextButton = document.querySelector('[data-brief-next]');
    const draftButton = document.querySelector('[data-brief-draft]');
    const actionStatus = document.querySelector('[data-brief-action-status]');
    const review = document.getElementById('briefReview');
    const formErrors = document.getElementById('brief-form-errors');

    const hidden = {
        spotLength: document.getElementById('spot_length_seconds'),
        additionalInfo: document.getElementById('additional_info'),
        toneStyle: document.getElementById('tone_style'),
        soundEffects: document.getElementById('sound_effects'),
        notes: document.getElementById('notes'),
        lectorCount: document.getElementById('lector_count')
    };

    let currentStep = 0;

    function valueOf(selector) {
        const el = form.querySelector(selector);
        return el ? (el.value || '').trim() : '';
    }

    function checkedValue(name) {
        const el = form.querySelector('[name="' + name + '"]:checked');
        return el ? el.value : '';
    }

    function clearError() {
        if (!formErrors) return;
        formErrors.textContent = '';
        formErrors.classList.add('d-none');
    }

    function showError(message) {
        if (!formErrors) return;
        formErrors.textContent = message;
        formErrors.classList.remove('d-none');
    }

    function syncBackendFields() {
        const goal = checkedValue('campaign_goal');
        const slogan = valueOf('#ui_slogan');
        const company = valueOf('#ui_company_name');
        const industry = valueOf('#ui_industry');
        const deadline = valueOf('#ui_deadline');
        const references = valueOf('#ui_references');
        const notes = valueOf('#ui_notes');

        if (hidden.spotLength) hidden.spotLength.value = checkedValue('spot_length_choice');
        if (hidden.lectorCount) hidden.lectorCount.value = checkedValue('ui_voice_count');

        if (hidden.additionalInfo) {
            hidden.additionalInfo.value = [
                company ? 'Firma: ' + company : '',
                industry ? 'Branza: ' + industry : '',
                goal ? 'Cel reklamy: ' + goal : '',
                slogan ? 'Haslo: ' + slogan : ''
            ].filter(Boolean).join("\n");
        }

        if (hidden.toneStyle) {
            hidden.toneStyle.value = [
                checkedValue('ui_style') ? 'Styl: ' + checkedValue('ui_style') : '',
                checkedValue('ui_voice') ? 'Glos: ' + checkedValue('ui_voice') : '',
                checkedValue('ui_tempo') ? 'Tempo: ' + checkedValue('ui_tempo') : ''
            ].filter(Boolean).join("\n");
        }

        if (hidden.soundEffects) {
            hidden.soundEffects.value = [
                checkedValue('ui_music') ? 'Muzyka: ' + checkedValue('ui_music') : '',
                form.querySelector('#ui_sound_fx')?.checked ? 'Efekty dzwiekowe: tak' : '',
                references ? 'Referencje: ' + references : ''
            ].filter(Boolean).join("\n");
        }

        if (hidden.notes) {
            hidden.notes.value = [
                deadline ? 'Termin realizacji: ' + deadline : '',
                notes
            ].filter(Boolean).join("\n");
        }
    }

    function updateRadioCards() {
        form.querySelectorAll('.brief-radio-card').forEach((card) => {
            const input = card.querySelector('input[type="radio"], input[type="checkbox"]');
            card.classList.toggle('is-selected', !!input && input.checked);
        });
    }

    function validatePanel(panel) {
        clearError();
        const missing = [];
        panel.querySelectorAll('[data-required-group]').forEach((group) => {
            const name = group.dataset.requiredGroup;
            if (!checkedValue(name)) missing.push(group.dataset.requiredLabel || name);
        });
        panel.querySelectorAll('[data-required-field]').forEach((field) => {
            if (!(field.value || '').trim()) missing.push(field.dataset.requiredLabel || field.name);
        });
        if (missing.length) {
            showError('Uzupelnij: ' + missing.join(', ') + '.');
            return false;
        }
        return true;
    }

    function buildReview() {
        syncBackendFields();
        if (!review) return;
        const rows = [
            ['Firma', valueOf('#ui_company_name')],
            ['Branza', valueOf('#ui_industry')],
            ['Cel reklamy', checkedValue('campaign_goal')],
            ['Grupa docelowa', valueOf('#target_group')],
            ['Tresc spotu', valueOf('#main_message')],
            ['Haslo', valueOf('#ui_slogan')],
            ['Call to action', valueOf('#contact_details')],
            ['Styl', checkedValue('ui_style')],
            ['Glos', checkedValue('ui_voice')],
            ['Tempo', checkedValue('ui_tempo')],
            ['Muzyka', checkedValue('ui_music')],
            ['Efekty', form.querySelector('#ui_sound_fx')?.checked ? 'tak' : 'nie'],
            ['Dlugosc', checkedValue('spot_length_choice') ? checkedValue('spot_length_choice') + ' sek.' : ''],
            ['Termin', valueOf('#ui_deadline')],
            ['Uwagi', valueOf('#ui_notes')]
        ];
        review.innerHTML = rows.map(([label, value]) => (
            '<div class="brief-summary-item"><span>' + label + '</span><strong>' + escapeHtml(value || 'Nie podano') + '</strong></div>'
        )).join('');
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showStep(index) {
        currentStep = Math.max(0, Math.min(index, panels.length - 1));
        panels.forEach((panel, idx) => panel.classList.toggle('is-active', idx === currentStep));
        stepButtons.forEach((button, idx) => button.classList.toggle('is-active', idx === currentStep));
        if (prevButton) prevButton.disabled = currentStep === 0;
        if (nextButton) nextButton.textContent = currentStep === panels.length - 1 ? 'Zapisz brief' : 'Dalej ->';
        if (currentStep === panels.length - 1) buildReview();
        clearError();
    }

    function updateCounter(textarea) {
        const target = document.querySelector('[data-counter-for="' + textarea.id + '"]');
        if (!target) return;
        const count = textarea.value.length;
        const parts = Math.max(1, Math.ceil(count / 160));
        target.textContent = count + ' znakow / ok. ' + parts + ' SMS';
    }

    form.querySelectorAll('textarea[data-count]').forEach((textarea) => {
        textarea.addEventListener('input', () => updateCounter(textarea));
        updateCounter(textarea);
    });

    form.querySelectorAll('input[type="radio"], input[type="checkbox"], input, textarea').forEach((field) => {
        field.addEventListener('change', () => {
            syncBackendFields();
            updateRadioCards();
        });
        field.addEventListener('input', syncBackendFields);
    });

    form.querySelectorAll('[name="ui_style"]').forEach((radio) => {
        radio.addEventListener('change', () => {
            const hint = document.getElementById('briefMusicHint');
            if (!hint) return;
            const style = checkedValue('ui_style');
            let text = '';
            if (style === 'humorystyczny') text = 'Sugestia: humorystyczny spot zwykle lepiej dziala z dynamiczna muzyka.';
            if (style === 'premium') text = 'Sugestia: przy stylu premium zwykle sprawdza sie spokojna muzyka.';
            hint.textContent = text;
            hint.hidden = text === '';
        });
    });

    stepButtons.forEach((button, index) => {
        button.addEventListener('click', () => {
            if (index <= currentStep || validatePanel(panels[currentStep])) showStep(index);
        });
    });

    prevButton?.addEventListener('click', () => showStep(currentStep - 1));
    nextButton?.addEventListener('click', () => {
        if (currentStep === panels.length - 1) {
            form.requestSubmit();
            return;
        }
        if (validatePanel(panels[currentStep])) showStep(currentStep + 1);
    });

    draftButton?.addEventListener('click', () => {
        syncBackendFields();
        if (actionStatus) actionStatus.textContent = 'Szkic zachowany w formularzu';
    });

    form.addEventListener('submit', (event) => {
        syncBackendFields();
        for (let i = 0; i < panels.length - 1; i += 1) {
            if (!validatePanel(panels[i])) {
                event.preventDefault();
                showStep(i);
                return;
            }
        }
    });

    syncBackendFields();
    updateRadioCards();
    showStep(0);
})();

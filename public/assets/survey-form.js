(() => {
    'use strict';

    /* ==========================================================
       Helper & state
       ========================================================== */
    const $ = (selector, scope = document) => scope.querySelector(selector);
    const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];

    const root = $('#sw');
    const form = $('#sw-form');
    if (!root || !form) return;

    const exists = root.dataset.exists === '1';
    const locked = root.dataset.locked === '1';
    const serverStep = Number(root.dataset.start) || 1;

    const list = $('#sw-list');
    const template = $('#sw-q-tpl');
    const alertBox = $('#sw-alert');

    const bank = JSON.parse($('#sw-bank').textContent);
    const initial = Object.values(JSON.parse($('#sw-initial').textContent) || {});

    const MAX_QUESTIONS = 100;
    const DRAFT_KEY = 'ts-survey-draft-v1';
    const TYPES = {
        rating: 'Rating 1–5',
        choice: window.TransSurveyI18n.t("Pilihan ganda"),
        text: window.TransSurveyI18n.t("Teks terbuka"),
    };

    let step = 1;
    let maxStep = 1;
    let saveTimer;

    function toOptions(value) {
        const raw = Array.isArray(value)
            ? value
            : String(value == null ? '' : value).split(/\r?\n/);

        return raw
            .map((s) => String(s).trim())
            .filter(Boolean);
    }

    function formatDate(value) {
        if (!value) return '—';

        return new Date(value + 'T00:00').toLocaleDateString(window.TransSurveyI18n.locale === 'en' ? 'en-GB' : 'id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    }

    function toIsoDate(date) {
        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0'),
        ].join('-');
    }

    function make(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function checkedStatus() {
        return $('input[name=status]:checked');
    }

    /* ==========================================================
       Alert & validasi
       ========================================================== */
    function showAlert(message) {
        alertBox.textContent = message;
        alertBox.hidden = false;
        alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clearAlert() {
        alertBox.hidden = true;
        $$('.sw-invalid').forEach((node) => node.classList.remove('sw-invalid'));
        $$('.sw-err').forEach((node) => node.remove());
    }

    function fail(input, message) {
        const wrap = input.closest('label') || input.parentElement;
        wrap.classList.add('sw-invalid');
        wrap.after(make('div', 'sw-err', message));

        const card = input.closest('.sw-q');
        if (card) card.classList.remove('is-collapsed');

        showAlert(message);
        input.focus();
        return false;
    }

    function validateInfo() {
        clearAlert();

        const title = $('#sw-title');
        const start = $('#sw-start');
        const end = $('#sw-end');

        if (!title.value.trim()) return fail(title, window.TransSurveyI18n.t("Judul kuesioner wajib diisi."));
        if (!start.value) return fail(start, window.TransSurveyI18n.t("Tanggal mulai wajib diisi."));
        if (!end.value) return fail(end, window.TransSurveyI18n.t("Tanggal selesai wajib diisi."));
        if (end.value < start.value) {
            return fail(end, window.TransSurveyI18n.t("Tanggal selesai tidak boleh sebelum tanggal mulai."));
        }
        return true;
    }

    function validateQuestions() {
        clearAlert();
        if (locked) return true;

        const cards = $$('.sw-q', list);
        if (!cards.length) {
            showAlert(window.TransSurveyI18n.t("Tambahkan minimal satu pertanyaan."));
            return false;
        }

        for (const [index, card] of cards.entries()) {
            const no = index + 1;
            const text = $('[data-f=text]', card);
            const category = $('[data-f=category]', card);
            const options = $('[data-f=options]', card);

            if (!text.value.trim()) {
                return fail(text, window.TransSurveyI18n.t("Pertanyaan :no: teks pertanyaan wajib diisi.", {no}));
            }
            if (!category.value.trim()) {
                return fail(category, window.TransSurveyI18n.t("Pertanyaan :no: kategori wajib diisi.", {no}));
            }

            if ($('[data-f=type]', card).value === 'choice') {
                const values = toOptions(options.value);
                const invalid = values.length < 2 || new Set(values).size !== values.length;
                if (invalid) {
                    return fail(options, window.TransSurveyI18n.t("Pertanyaan :no: isi minimal dua pilihan yang berbeda.", {no}));
                }
            }
        }
        return true;
    }

    const validators = {
        1: validateInfo,
        2: validateQuestions,
    };

    /* ==========================================================
       Navigasi langkah
       ========================================================== */
    function goTo(target, options) {
        const opts = Object.assign({ push: true, force: false }, options);

        target = Math.max(1, Math.min(3, target));

        if (target > step && !opts.force) {
            for (let s = step; s < target; s++) {
                if (validators[s] && !validators[s]()) return;
            }
        } else {
            clearAlert();
        }

        step = target;
        maxStep = Math.max(maxStep, target);

        $$('.sw-panel').forEach((panel) => {
            panel.classList.toggle('is-active', Number(panel.dataset.step) === target);
        });

        $$('.sw-step').forEach((btn) => {
            const n = Number(btn.dataset.go);
            btn.classList.toggle('is-active', n === target);
            btn.classList.toggle('is-done', n < target);
            btn.disabled = n > maxStep;
            btn.setAttribute('aria-current', n === target ? 'step' : 'false');
        });

        if (target === 3) renderReview();

        if (opts.push && location.hash !== '#langkah-' + target) {
            history.pushState({ target: target }, '', '#langkah-' + target);
        }
        root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    $$('[data-go]').forEach((btn) =>
        btn.addEventListener('click', () => goTo(Number(btn.dataset.go)))
    );
    $$('[data-next]').forEach((btn) =>
        btn.addEventListener('click', () => goTo(step + 1))
    );
    $$('[data-prev]').forEach((btn) =>
        btn.addEventListener('click', () => goTo(step - 1))
    );

    window.addEventListener('popstate', () => {
        const match = location.hash.match(/langkah-(\d)/);
        goTo(match ? Number(match[1]) : 1, { push: false });
    });

    form.addEventListener('keydown', (e) => {
        const isTextInput = e.target.tagName === 'INPUT' && e.target.type !== 'submit';
        if (e.key === 'Enter' && isTextInput) {
            e.preventDefault();
            if (step < 3) goTo(step + 1);
        }
    });

    /* ==========================================================
       Kartu pertanyaan
       ========================================================== */
    function readCard(card) {
        return {
            text: $('[data-f=text]', card).value,
            category: $('[data-f=category]', card).value,
            type: $('[data-f=type]', card).value,
            required: $('[data-f=required]', card).checked,
            options: toOptions($('[data-f=options]', card).value),
        };
    }

    function getQuestions() {
        if (locked) {
            return initial.map((q) => Object.assign({}, q, { options: toOptions(q.options) }));
        }
        return $$('.sw-q', list).map(readCard);
    }

    function refreshCard(card) {
        const q = readCard(card);

        $('[data-title]', card).textContent = q.text.trim() || window.TransSurveyI18n.t("Pertanyaan baru");
        $('[data-meta]', card).textContent = [
            TYPES[q.type],
            q.category.trim() || window.TransSurveyI18n.t("tanpa kategori"),
            q.required ? window.TransSurveyI18n.t("Wajib") : window.TransSurveyI18n.t("Opsional"),
        ].join(' · ');
    }

    function setType(card, type) {
        $('[data-f=type]', card).value = type;

        $$('[data-type]', card).forEach((btn) => {
            btn.setAttribute('aria-pressed', String(btn.dataset.type === type));
        });

        $('[data-wrap=options]', card).hidden = type !== 'choice';
        refreshCard(card);
    }

    function renumber() {
        if (locked) return;

        const cards = $$('.sw-q', list);

        cards.forEach((card, i) => {
            $('[data-no]', card).textContent = i + 1;

            $$('[data-f]', card).forEach((input) => {
                input.name = `questions[${i}][${input.dataset.f}]`;
            });
            $('[data-h=required]', card).name = `questions[${i}][required]`;

            $('[data-act=up]', card).disabled = i === 0;
            $('[data-act=down]', card).disabled = i === cards.length - 1;
        });

        $('#sw-empty').hidden = cards.length > 0;
        $('#sw-count-label').textContent = window.TransSurveyI18n.t(":count pertanyaan", {count: cards.length});
    }

    function addQuestion(q, options) {
        q = q || {};
        const opts = Object.assign({ after: null, open: true }, options);

        if ($$('.sw-q', list).length >= MAX_QUESTIONS) {
            showAlert(window.TransSurveyI18n.t("Maksimal :count pertanyaan.", {count: MAX_QUESTIONS}));
            return null;
        }

        const card = template.content.firstElementChild.cloneNode(true);

        $('[data-f=text]', card).value = q.text == null ? '' : q.text;
        $('[data-f=category]', card).value = q.category == null ? 'Kualitas layanan' : q.category;
        $('[data-f=options]', card).value = toOptions(q.options).join('\n');
        $('[data-f=required]', card).checked =
            q.required === undefined ? true : [true, 1, '1', 'on'].includes(q.required);

        card.classList.toggle('is-collapsed', !opts.open);

        if (opts.after) {
            opts.after.after(card);
        } else {
            list.appendChild(card);
        }

        setType(card, TYPES[q.type] ? q.type : 'rating');
        renumber();
        changed();
        return card;
    }

    function collapseAll() {
        $$('.sw-q', list).forEach((card) => card.classList.add('is-collapsed'));
    }

    function handleCardClick(e) {
        const btn = e.target.closest('[data-act],[data-type]');
        if (!btn) return;

        const card = btn.closest('.sw-q');

        if (btn.dataset.type) {
            setType(card, btn.dataset.type);
            changed();
            return;
        }

        switch (btn.dataset.act) {
            case 'toggle':
                card.classList.toggle('is-collapsed');
                break;

            case 'up': {
                const prev = card.previousElementSibling;
                if (prev) prev.before(card);
                break;
            }

            case 'down': {
                const next = card.nextElementSibling;
                if (next) next.after(card);
                break;
            }

            case 'dup':
                addQuestion(readCard(card), { after: card });
                return;

            case 'del':
                if (!$('[data-f=text]', card).value.trim() || confirm(window.TransSurveyI18n.t("Hapus pertanyaan ini?"))) {
                    card.remove();
                }
                break;
        }

        renumber();
        changed();
    }

    function bindQuestionEditor() {
        list.addEventListener('click', handleCardClick);

        list.addEventListener('input', (e) => {
            const card = e.target.closest('.sw-q');
            if (card) refreshCard(card);
        });

        $('#sw-add').addEventListener('click', () => {
            collapseAll();
            const card = addQuestion({});
            if (card) {
                $('[data-f=text]', card).focus();
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        $('#sw-bank-select').addEventListener('change', (e) => {
            const q = bank.find((item) => String(item.id) === e.target.value);
            if (q) {
                collapseAll();
                addQuestion(q);
            }
            e.target.value = '';
        });
    }

    /* ==========================================================
       Durasi cepat
       ========================================================== */
    $$('[data-days]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const start = $('#sw-start');
            if (!start.value) start.value = toIsoDate(new Date());

            const end = new Date(start.value + 'T00:00');
            end.setDate(end.getDate() + Number(btn.dataset.days));

            $('#sw-end').value = toIsoDate(end);
            changed();
        });
    });

    /* ==========================================================
       Pratinjau & tinjau
       ========================================================== */
    function renderPreview() {
        const box = $('#sw-preview');
        box.replaceChildren();

        box.append(make('h4', '', $('#sw-title').value.trim() || window.TransSurveyI18n.t("Judul kuesioner")));
        box.append(make('p', '', $('#sw-desc').value.trim() || window.TransSurveyI18n.t("Pengantar akan tampil di sini.")));

        getQuestions().forEach((q, i) => {
            const wrap = make('div', 'sw-pv-q');
            const label = `${i + 1}. ${q.text.trim() || window.TransSurveyI18n.t("Pertanyaan baru")}${q.required ? ' *' : ''}`;
            wrap.append(make('b', '', label));

            if (q.type === 'rating') {
                const dots = make('div', 'sw-pv-dots');
                [1, 2, 3, 4, 5].forEach((n) => dots.append(make('i', '', n)));
                wrap.append(dots);
            } else if (q.type === 'choice') {
                const opts = q.options.length ? q.options : [window.TransSurveyI18n.t("Pilihan 1"), window.TransSurveyI18n.t("Pilihan 2")];
                opts.forEach((o) => wrap.append(make('span', 'sw-pv-opt', '○ ' + o)));
            } else {
                wrap.append(make('div', 'sw-pv-txt'));
            }

            box.append(wrap);
        });
    }

    function renderReview() {
        const questions = getQuestions();

        const checked = checkedStatus();
        const statusLabel = checked
            ? checked.nextElementSibling.querySelector('b').textContent
            : '';

        const rows = [
            [window.TransSurveyI18n.t("Judul"), $('#sw-title').value.trim() || '—'],
            [window.TransSurveyI18n.t("Periode"), `${formatDate($('#sw-start').value)} – ${formatDate($('#sw-end').value)}`],
            ['Status', statusLabel || '—'],
            ['Template', $('input[type=checkbox][name=is_template]').checked ? window.TransSurveyI18n.t("Ya") : window.TransSurveyI18n.t("Tidak")],
            [window.TransSurveyI18n.t("Jumlah pertanyaan"), String(questions.length)],
        ];

        const info = $('#sw-review-info');
        info.replaceChildren();
        rows.forEach((row) => info.append(make('dt', '', row[0]), make('dd', '', row[1])));

        const ol = $('#sw-review-list');
        ol.replaceChildren();

        questions.forEach((q) => {
            const li = make('li', '', q.text.trim());
            li.append(
                make('span', 'sw-pill', TYPES[q.type]),
                make('span', 'sw-pill' + (q.required ? ' req' : ''), q.required ? window.TransSurveyI18n.t("Wajib") : window.TransSurveyI18n.t("Opsional"))
            );
            ol.append(li);
        });
    }

    /* ==========================================================
       Autosave draf lokal (hanya kuesioner baru)
       ========================================================== */
    function snapshot() {
        const checked = checkedStatus();

        return {
            title: $('#sw-title').value,
            description: $('#sw-desc').value,
            starts_at: $('#sw-start').value,
            ends_at: $('#sw-end').value,
            status: checked ? checked.value : 'draft',
            is_template: $('input[type=checkbox][name=is_template]').checked,
            questions: getQuestions(),
        };
    }

    function changed() {
        renderPreview();
        if (exists) return;

        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            const data = snapshot();
            const worthSaving = data.title.trim() || data.questions.length > 1;

            try {
                if (worthSaving) {
                    localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
                } else {
                    localStorage.removeItem(DRAFT_KEY);
                }
            } catch (err) {
                /* localStorage tidak tersedia, abaikan */
            }
        }, 500);
    }

    function restoreDraft(data) {
        $('#sw-title').value = data.title || '';
        $('#sw-desc').value = data.description || '';
        $('#sw-start').value = data.starts_at || $('#sw-start').value;
        $('#sw-end').value = data.ends_at || '';

        const radio = $(`input[name=status][value="${data.status}"]`);
        if (radio) radio.checked = true;
        $('input[type=checkbox][name=is_template]').checked = !!data.is_template;

        list.replaceChildren();
        (data.questions || []).forEach((q) => addQuestion(q, { open: false }));
        changed();
    }

    function offerDraft() {
        const canOffer = !exists && !locked && !$('#sw-title').value && initial.length === 0;
        if (!canOffer) return;

        try {
            const saved = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null');
            const usable = saved && (saved.title || (saved.questions || []).length > 1);
            if (!usable) return;

            const banner = $('#sw-draft-banner');
            banner.hidden = false;

            $('#sw-draft-restore').onclick = () => {
                restoreDraft(saved);
                banner.hidden = true;
            };
            $('#sw-draft-discard').onclick = () => {
                localStorage.removeItem(DRAFT_KEY);
                banner.hidden = true;
            };
        } catch (err) {
            /* localStorage tidak tersedia, abaikan */
        }
    }

    /* ==========================================================
       Submit
       ========================================================== */
    form.addEventListener('submit', (e) => {
        if (!validateInfo()) {
            e.preventDefault();
            goTo(1, { force: true });
            return;
        }
        if (!validateQuestions()) {
            e.preventDefault();
            goTo(2, { force: true });
            return;
        }

        renumber();

        try {
            if (!exists) localStorage.removeItem(DRAFT_KEY);
        } catch (err) {
            /* abaikan */
        }

        const btn = $('#sw-submit');
        btn.disabled = true;
        btn.replaceChildren(make('span', 'sw-spin'), document.createTextNode(' Menyimpan…'));
    });

    form.addEventListener('input', changed);
    form.addEventListener('change', changed);

    /* ==========================================================
       Init
       ========================================================== */
    function init() {
        if (locked) {
            $('#sw-count-label').textContent = window.TransSurveyI18n.t(":count pertanyaan (dikunci)", {count: initial.length});
        } else {
            bindQuestionEditor();

            if (initial.length) {
                initial.forEach((q, i) => addQuestion(q, { open: i === 0 }));
            } else {
                addQuestion(
                    {
                        text: 'Seberapa puas Anda terhadap kualitas layanan kami?',
                        category: 'Kualitas layanan',
                        type: 'rating',
                        required: true,
                    },
                    { open: false }
                );
            }
        }

        offerDraft();

        const hash = location.hash.match(/langkah-(\d)/);
        const startStep = serverStep > 1 ? serverStep : hash ? Number(hash[1]) : 1;

        maxStep = exists ? 3 : startStep;
        goTo(startStep, { push: false, force: true });
        renderPreview();
    }

    init();
})();
/* TransSurvey UI: tanpa CDN. app.js tetap menangani editor dan jawaban survei. */
'use strict';
(() => {
    if (window.TransSurveyUI) return;
    let toastTimer;
    function toast(message, kind = 'success') {
        let box = document.getElementById('toast');
        if (!box) { box = document.createElement('div'); box.id = 'toast'; box.className = 'toast'; document.body.append(box); }
        clearTimeout(toastTimer); box.replaceChildren(); box.dataset.kind = kind;
        box.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        box.setAttribute('aria-live', kind === 'error' ? 'assertive' : 'polite');
        const icon = document.createElement('span'); icon.className = 'ts-toast-icon'; icon.textContent = kind === 'error' ? '!' : '✓'; icon.setAttribute('aria-hidden', 'true');
        const body = document.createElement('div'); body.className = 'ts-toast-body';
        const title = document.createElement('strong'); title.textContent = kind === 'error' ? 'Perlu diperiksa' : 'Berhasil';
        const text = document.createElement('p'); text.textContent = message;
        const close = document.createElement('button'); close.type = 'button'; close.textContent = '×'; close.setAttribute('aria-label', 'Tutup pemberitahuan');
        close.addEventListener('click', () => { clearTimeout(toastTimer); box.hidden = true; });
        body.append(title, text); box.append(icon, body, close); box.hidden = false;
        const schedule = () => { clearTimeout(toastTimer); if (kind !== 'error') toastTimer = setTimeout(() => { box.hidden = true; }, 6500); };
        box.onpointerenter = () => clearTimeout(toastTimer); box.onpointerleave = schedule;
        box.onfocusin = () => clearTimeout(toastTimer); box.onfocusout = schedule; schedule();
    }
    window.toast = toast;
    // Public helper is also used by the existing question editor.
    window.TransSurveyUI = { toast };
    const menu = document.getElementById('sidebar');
    const toggle = document.querySelector('[data-toggle-menu]');
    const backdrop = document.querySelector('[data-close-menu]');
    const mobile = window.matchMedia('(max-width: 767px)');
    function setMenu(open, restoreFocus = false) {
        if (!menu) return;
        document.body.classList.toggle('menu-open', open && mobile.matches);
        toggle?.setAttribute('aria-expanded', String(open && mobile.matches));
        if (backdrop) backdrop.hidden = !open || !mobile.matches;
        menu.inert = mobile.matches && !open;
        if (open && mobile.matches) menu.querySelector('a')?.focus();
        if (restoreFocus) toggle?.focus();
    }
    setMenu(false);
    toggle?.addEventListener('click', () => setMenu(!document.body.classList.contains('menu-open')));
    backdrop?.addEventListener('click', () => setMenu(false, true));
    mobile.addEventListener('change', () => setMenu(false));
    document.addEventListener('keydown', e => {
        if (!document.body.classList.contains('menu-open')) return;
        if (e.key === 'Escape') { setMenu(false, true); return; }
        if (e.key !== 'Tab') return;
        const focusable = [...menu.querySelectorAll('a[href],button:not(:disabled)')];
        const first = focusable[0], last = focusable.at(-1);
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
    });
    document.querySelectorAll('[data-toggle-password]').forEach(button => button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.togglePassword); if (!input) return;
        const show = input.type === 'password'; input.type = show ? 'text' : 'password';
        button.textContent = show ? 'Sembunyikan' : 'Lihat';
        button.setAttribute('aria-pressed', String(show));
        button.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
    }));
    document.querySelectorAll('[data-brand-logo]').forEach(img => {
        const hideMissing = () => { img.hidden = true; };
        img.addEventListener('error', hideMissing);
        if (img.complete && !img.naturalWidth) hideMissing();
    });
    document.querySelectorAll('[data-dismiss-notice]').forEach(button => button.addEventListener('click', () => button.closest('.notice').remove()));
    document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));

    // Native <dialog> manages focus and Escape; every visible label is textContent.
    let dialogSequence = 0;
    function dialog(message, copyValue, options = {}) {
        return new Promise(resolve => {
            const opener = document.activeElement;
            const el = document.createElement('dialog'); el.className = 'ts-dialog';
            const id = 'ts-dialog-' + (++dialogSequence);
            const symbol = document.createElement('span'); symbol.className = 'ts-dialog-symbol'; symbol.textContent = copyValue === undefined ? '?' : '↗'; symbol.setAttribute('aria-hidden', 'true');
            const title = document.createElement('h2'); title.id = id + '-title'; title.textContent = options.title || (copyValue === undefined ? 'Simpan perubahan?' : 'Salin tautan survei');
            const description = document.createElement('p'); description.id = id + '-description'; description.textContent = message;
            el.setAttribute('aria-labelledby', title.id); el.setAttribute('aria-describedby', description.id); el.append(symbol, title, description);
            let input;
            if (copyValue !== undefined) { input = document.createElement('input'); input.value = copyValue; input.readOnly = true; input.setAttribute('aria-label', 'Tautan survei'); el.append(input); }
            const actions = document.createElement('div'); actions.className = 'ts-dialog-actions';
            const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'btn'; cancel.textContent = copyValue === undefined ? 'Batal' : 'Tutup';
            const confirm = document.createElement('button'); confirm.type = 'button'; confirm.className = 'btn primary'; confirm.textContent = options.confirmText || 'Ya, simpan';
            cancel.addEventListener('click', () => el.close('cancel'));
            confirm.addEventListener('click', () => el.close('confirm'));
            actions.append(cancel); if (copyValue === undefined) actions.append(confirm); el.append(actions); document.body.append(el);
            el.addEventListener('close', () => { const ok = el.returnValue === 'confirm'; el.remove(); if (opener?.isConnected) opener.focus(); resolve(ok); }, { once: true });
            el.showModal(); if (input) { input.focus(); input.select(); } else cancel.focus();
        });
    }
    window.TransSurveyUI.confirm = (message, options) => dialog(message, undefined, options);
    const approved = new WeakSet(), pending = new WeakSet(), busy = new Map();
    function setBusy(form, submitter) {
        const buttons = [...form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')];
        const states = buttons.map(button => ({ button, aria: button.getAttribute('aria-disabled') }));
        let indicator;
        if (submitter?.tagName === 'BUTTON') {
            indicator = document.createElement('span'); indicator.className = 'ts-submit-progress';
            indicator.textContent = form.dataset.busyText || 'Sedang memproses…'; indicator.setAttribute('role', 'status');
            submitter.after(indicator);
        }
        form.setAttribute('aria-busy', 'true');
        // Don't disable controls: submitter name/value (e.g. action=draft) must be sent.
        buttons.forEach(button => { button.setAttribute('aria-disabled', 'true'); button.classList.add('ts-is-busy'); });
        busy.set(form, { states, indicator });
    }
    window.addEventListener('pageshow', () => {
        busy.forEach(({ states, indicator }, form) => {
            form.removeAttribute('aria-busy'); indicator?.remove();
            states.forEach(({ button, aria }) => { button.classList.remove('ts-is-busy'); if (aria === null) button.removeAttribute('aria-disabled'); else button.setAttribute('aria-disabled', aria); });
        });
        busy.clear();
    });
    document.addEventListener('submit', async e => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || e.defaultPrevented) return;
        const submitter = e.submitter;
        if (busy.has(form)) { e.preventDefault(); return; }
        if (approved.has(form)) { approved.delete(form); setBusy(form, submitter); return; }
        // Confirmation can be set on a form or on one specific submit button.
        const message = submitter?.dataset.confirm || form.dataset.confirm;
        if (!message) {
            if (form.hasAttribute('data-submit-feedback')) setBusy(form, submitter);
            return;
        }
        e.preventDefault(); if (pending.has(form)) return; pending.add(form);
        let ok = false;
        try {
            ok = await dialog(message, undefined, {
                title: submitter?.dataset.confirmTitle || form.dataset.confirmTitle || 'Konfirmasi tindakan',
                confirmText: submitter?.dataset.confirmButton || form.dataset.confirmButton || 'Ya, lanjutkan'
            });
        } finally { pending.delete(form); }
        if (ok && form.isConnected) {
            if (submitter && (!submitter.isConnected || submitter.disabled)) return;
            approved.add(form);
            // Re-run native validation and preserve the exact submit button's value.
            try { form.requestSubmit(submitter || undefined); } finally { approved.delete(form); }
        }
    });
    // Success must come from server flash data, never from the confirmation click.
    const success = document.querySelector('[data-success-message]');
    if (success) {
        const message = success.querySelector('p')?.textContent.trim() || success.textContent.trim();
        if (message) { toast(message); success.hidden = true; }
    }
    const serverError = document.querySelector('[data-error-message]');
    if (serverError) toast('Data belum tersimpan. Periksa pesan kesalahan pada form.', 'error');
    document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(button.dataset.copy); toast('Tautan survei berhasil disalin.'); }
        catch { await dialog('Pilih tautan berikut, lalu salin dengan Ctrl+C atau fitur salin di perangkat Anda.', button.dataset.copy); }
    }));

    // Progressive enhancement: the original select remains the submitted control.
    let selectId = 0, activeSelect = null;
    const controllers = new WeakMap();
    function enhance(select) {
        if (controllers.has(select) || select.multiple || select.size > 1 || select.closest('template') || select.hasAttribute('data-native-select')) return;
        const labelText = select.labels?.[0] ? [...select.labels[0].childNodes].filter(node => node.nodeType === Node.TEXT_NODE).map(node => node.textContent.trim()).filter(Boolean).join(' ') : '';
        const label = select.getAttribute('aria-label') || labelText || 'Pilih opsi';
        const wrap = document.createElement('span'); wrap.className = 'ts-select';
        select.before(wrap); wrap.append(select);
        const trigger = document.createElement('button'); trigger.type = 'button'; trigger.className = 'ts-select-trigger';
        trigger.setAttribute('role', 'combobox'); trigger.setAttribute('aria-label', label); trigger.setAttribute('aria-haspopup', 'listbox'); trigger.setAttribute('aria-expanded', 'false');
        if (select.required) trigger.setAttribute('aria-required', 'true');
        const caption = document.createElement('span'), arrow = document.createElement('span'); arrow.textContent = '⌄'; arrow.setAttribute('aria-hidden', 'true'); trigger.append(caption, arrow); wrap.append(trigger);
        const list = document.createElement('ul'); list.className = 'ts-select-list'; list.id = 'ts-options-' + (++selectId); list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', label); list.hidden = true;
        // Keep popup owned by wrapper so removing a question also removes its popup.
        wrap.append(list); trigger.setAttribute('aria-controls', list.id);
        select.classList.add('ts-native-select'); select.tabIndex = -1; select.setAttribute('aria-hidden', 'true');
        let focused = -1, options = [];
        function sync() { caption.textContent = select.selectedOptions[0]?.textContent || 'Pilih opsi'; trigger.disabled = select.disabled; }
        function close() { list.hidden = true; trigger.setAttribute('aria-expanded', 'false'); trigger.removeAttribute('aria-activedescendant'); if (activeSelect === controller) activeSelect = null; }
        function position() {
            const rect = trigger.getBoundingClientRect(); const roomBelow = innerHeight - rect.bottom - 12;
            const height = Math.min(260, Math.max(roomBelow, rect.top - 12));
            list.style.width = Math.min(rect.width, innerWidth - 16) + 'px'; list.style.left = Math.max(8, Math.min(rect.left, innerWidth - rect.width - 8)) + 'px';
            list.style.maxHeight = Math.max(80, height) + 'px';
            if (roomBelow < Math.min(list.scrollHeight, 200) && rect.top > roomBelow) { list.style.top = 'auto'; list.style.bottom = (innerHeight - rect.top + 5) + 'px'; }
            else { list.style.bottom = 'auto'; list.style.top = (rect.bottom + 5) + 'px'; }
        }
        function focusOption(index) {
            focused = index; options.forEach((option, i) => option.classList.toggle('is-focused', i === index));
            if (options[index]) { trigger.setAttribute('aria-activedescendant', options[index].id); options[index].scrollIntoView({ block: 'nearest' }); }
        }
        function choose(index) {
            if (!select.options[index] || select.options[index].disabled) return;
            select.selectedIndex = index; select.dispatchEvent(new Event('input', { bubbles: true })); select.dispatchEvent(new Event('change', { bubbles: true })); sync(); close(); trigger.focus();
        }
        function open() {
            if (select.disabled) return;
            activeSelect?.close(); activeSelect = controller; list.replaceChildren();
            options = [...select.options].map((option, i) => {
                const item = document.createElement('li'); item.id = list.id + '-' + i; item.className = 'ts-select-option'; item.setAttribute('role', 'option'); item.setAttribute('aria-selected', String(i === select.selectedIndex)); item.setAttribute('aria-disabled', String(option.disabled)); item.textContent = option.textContent; if (i === select.selectedIndex) item.classList.add('is-selected');
                item.addEventListener('pointerdown', e => e.preventDefault()); item.addEventListener('click', () => choose(i)); list.append(item); return item;
            });
            list.hidden = false; trigger.setAttribute('aria-expanded', 'true'); position(); focusOption(select.selectedIndex);
        }
        const controller = { close, trigger, wrap, list, sync }; controllers.set(select, controller); sync();
        trigger.addEventListener('click', () => list.hidden ? open() : close());
        let typed = '', typingTimer;
        trigger.addEventListener('keydown', e => {
            if (e.key === 'Escape') { if (!list.hidden) { e.preventDefault(); e.stopPropagation(); close(); } return; }
            if (e.key === 'Tab') { close(); return; }
            if (['ArrowDown','ArrowUp','Home','End'].includes(e.key)) {
                e.preventDefault(); if (list.hidden) open();
                const enabled = [...select.options].map((o, i) => o.disabled ? -1 : i).filter(i => i >= 0);
                let next = enabled.indexOf(focused);
                if (e.key === 'Home') next = 0; else if (e.key === 'End') next = enabled.length - 1; else next = Math.max(0, Math.min(enabled.length - 1, next + (e.key === 'ArrowDown' ? 1 : -1)));
                focusOption(enabled[next]); return;
            }
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (list.hidden) open(); else if (focused >= 0) choose(focused); return; }
            if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                if (list.hidden) open(); typed += e.key.toLocaleLowerCase(); clearTimeout(typingTimer); typingTimer = setTimeout(() => { typed = ''; }, 600);
                const index = [...select.options].findIndex(o => !o.disabled && o.textContent.trim().toLocaleLowerCase().startsWith(typed)); if (index >= 0) focusOption(index);
            }
        });
        select.addEventListener('change', sync);
        select.addEventListener('focus', () => trigger.focus());
        select.addEventListener('invalid', e => { e.preventDefault(); trigger.focus(); trigger.setAttribute('aria-invalid', 'true'); toast('Lengkapi pilihan: ' + label, 'error'); });
        select.addEventListener('change', () => trigger.removeAttribute('aria-invalid'));
        select.form?.addEventListener('reset', () => setTimeout(sync));
    }
    document.querySelectorAll('select').forEach(enhance);
    new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(node => {
            if (!(node instanceof Element)) return;
            if (node.matches('select')) enhance(node); node.querySelectorAll('select').forEach(enhance);
        }));
        if (activeSelect && !activeSelect.wrap.isConnected) activeSelect.close();
    }).observe(document.body, { childList:true, subtree:true });
    document.addEventListener('pointerdown', e => { if (activeSelect && !activeSelect.wrap.contains(e.target)) activeSelect.close(); });
    document.addEventListener('scroll', e => { if (activeSelect && !activeSelect.list.contains(e.target)) activeSelect.close(); }, true);
    window.addEventListener('resize', () => activeSelect?.close());
})();

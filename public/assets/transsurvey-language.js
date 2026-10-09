(() => {
    'use strict';
    let dirty = false;
    const mark = event => {
        const form = event.target.closest('form');
        if (form && form.method.toLowerCase() === 'post' && !form.matches('[data-language-switch]')) dirty = true;
    };
    document.addEventListener('input', mark);
    document.addEventListener('change', mark);
    document.addEventListener('click', event => {
        if (event.target.closest('#sw-add, #sw-draft-restore, [data-act=del], [data-act=dup], [data-act=up], [data-act=down]')) dirty = true;
    });
    document.querySelectorAll('[data-language-switch]').forEach(form => {
        form.addEventListener('submit', async event => {
            if (!dirty || form.dataset.confirmed === '1') return;
            event.preventDefault();
            event.stopImmediatePropagation();
            const t = (key) => window.TransSurveyI18n.t(key);
            const message = t('Mengganti bahasa akan memuat ulang halaman. Perubahan yang belum disimpan dapat hilang. Lanjutkan?');
            const ok = window.TransSurveyUI
                ? await window.TransSurveyUI.confirm(message, {title: t('Ganti bahasa?'), confirmText: t('Ya, lanjutkan')})
                : window.confirm(message);
            if (ok) {
                form.dataset.confirmed = '1';
                form.requestSubmit(event.submitter);
            }
        });
    });
})();

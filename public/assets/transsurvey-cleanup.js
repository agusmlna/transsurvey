(() => {
    'use strict';
    const selection = document.querySelector('[data-cleanup-selection]');
    if (selection) {
        const boxes = [...selection.querySelectorAll('input[name="ids[]"]:not(:disabled)')];
        const all = selection.querySelector('[data-cleanup-all]');
        const count = selection.querySelector('[data-cleanup-count]');
        const submit = selection.querySelector('button[type="submit"]');
        const update = () => {
            const selected = boxes.filter(box => box.checked).length;
            count.textContent = String(selected);
            all.checked = boxes.length > 0 && selected === boxes.length;
            all.indeterminate = selected > 0 && selected < boxes.length;
            all.disabled = boxes.length === 0;
            submit.disabled = selected === 0;
        };
        all.addEventListener('change', () => { boxes.forEach(box => box.checked = all.checked); update(); });
        boxes.forEach(box => box.addEventListener('change', update));
        update();
    }
    const confirmation = document.querySelector('[data-cleanup-confirm]');
    if (confirmation) {
        const input = confirmation.querySelector('[name="confirmation"]');
        const acknowledgement = confirmation.querySelector('[name="acknowledge"]');
        const submit = confirmation.querySelector('button[type="submit"]');
        const update = () => { submit.disabled = input.value !== 'HAPUS' || !acknowledgement.checked; };
        input.addEventListener('input', update);
        acknowledgement.addEventListener('change', update);
        update();
    }
})();

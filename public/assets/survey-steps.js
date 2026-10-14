"use strict";
(() => {
  const form = document.querySelector('.respondent-form');
  if (!form) return;
  const steps = [...form.querySelectorAll('[data-step]')];
  const nav = form.querySelector('[data-steps-nav]');
  if (steps.length < 2 || !nav) return;

  const prev = nav.querySelector('[data-step-prev]');
  const next = nav.querySelector('[data-step-next]');
  const label = nav.querySelector('[data-step-label]');
  const finalBtn = form.querySelector('[data-step-final]');
  let current = 0;

  function show(i, scroll = true) {
    current = Math.max(0, Math.min(i, steps.length - 1));
    steps.forEach((s, idx) => { s.hidden = idx !== current; });
    prev.hidden = current === 0;
    next.hidden = current === steps.length - 1;
    if (finalBtn) finalBtn.hidden = current !== steps.length - 1;
    label.textContent = `${current + 1} / ${steps.length}`;
    if (scroll) form.closest('.survey-sheet').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function stepIsValid(step) {
    for (const el of step.querySelectorAll('input, textarea, select')) {
      if (!el.checkValidity()) { el.reportValidity(); return false; }
    }
    return true;
  }

  next.addEventListener('click', () => { if (stepIsValid(steps[current])) show(current + 1); });
  prev.addEventListener('click', () => show(current - 1));

  // Kalau ada field invalid di bagian yang sedang disembunyikan, pindah ke bagian itu
  form.addEventListener('invalid', (e) => {
    const idx = steps.findIndex((s) => s.contains(e.target));
    if (idx !== -1 && idx !== current) show(idx, false);
  }, true);

  nav.hidden = false;
  show(0, false);

  // Setelah error dari server, langsung buka bagian yang masih bermasalah
  if (document.querySelector('[data-error-message]')) {
    const bad = form.querySelector(':invalid');
    const idx = bad ? steps.findIndex((s) => s.contains(bad)) : -1;
    if (idx > 0) show(idx, false);
  }
})();
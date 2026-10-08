// Run in <head> so raw tables do not flash before DataTables builds its controls.
(() => {
    const root = document.documentElement;
    root.classList.add('ts-tables-booting');
    let timer;
    const finish = () => {
        root.classList.remove('ts-tables-booting');
        clearTimeout(timer);
    };
    window.TransSurveyTableBoot = { finish };
    // Keep the original HTML usable if another script fails or cannot load.
    timer = setTimeout(finish, 5000);
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.DataTable || !window.TransSurveyTables) finish();
    });
})();

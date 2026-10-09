(() => {
    'use strict';
    if (!window.DataTable) { window.TransSurveyTableBoot?.finish(); return; }
    DataTable.ext.errMode = 'none';
    const language = {
        search: window.TransSurveyI18n.t("Cari"), searchPlaceholder: window.TransSurveyI18n.t("Ketik kata kunci…"), lengthMenu: window.TransSurveyI18n.t("Tampilkan _MENU_ baris"),
        info: window.TransSurveyI18n.t("Menampilkan _START_–_END_ dari _TOTAL_ data"), infoEmpty: window.TransSurveyI18n.t("Menampilkan 0 data"),
        infoFiltered: window.TransSurveyI18n.t("(dari _MAX_ data sebelum pencarian)"), zeroRecords: window.TransSurveyI18n.t("Tidak ada data yang cocok. Coba kata kunci lain."),
        emptyTable: window.TransSurveyI18n.t("Belum ada data untuk filter ini."), processing: '<span class="ts-table-loading-label">' + window.TransSurveyI18n.t("Memuat data…") + '</span>', loadingRecords: window.TransSurveyI18n.t("Memuat data…"),
        paginate: { first: window.TransSurveyI18n.t("Awal"), previous: '‹', next: '›', last: window.TransSurveyI18n.t("Akhir") },
        aria: { orderable: window.TransSurveyI18n.t("Urutkan kolom ini"), orderableReverse: window.TransSurveyI18n.t("Balik urutan kolom"), orderableRemove: window.TransSurveyI18n.t("Hapus pengurutan"),
            paginate: { first: window.TransSurveyI18n.t("Halaman pertama"), previous: window.TransSurveyI18n.t("Halaman sebelumnya"), next: window.TransSurveyI18n.t("Halaman berikutnya"), last: window.TransSurveyI18n.t("Halaman terakhir") } }
    };
    function decorate(api) {
        const container = api.table().container();
        if (container.classList.contains('ts-table-container')) return;
        container.classList.add('ts-table-container');
        const table = api.table().node();
        if (!table.parentElement.classList.contains('ts-table-scroll')) {
            const scroll = document.createElement('div');
            scroll.className = 'ts-table-scroll'; scroll.tabIndex = 0;
            scroll.setAttribute('role', 'region'); scroll.setAttribute('aria-label', table.getAttribute('aria-label') || window.TransSurveyI18n.t("Tabel data, geser untuk melihat kolom lainnya"));
            table.before(scroll); scroll.append(table);
        }
        const processing = container.querySelector('.dt-processing');
        if (processing) { processing.setAttribute('role', 'status'); processing.setAttribute('aria-live', 'polite'); }
        container.querySelectorAll('.dt-length select').forEach(select => select.setAttribute('data-native-select', ''));
        container.querySelectorAll('.dt-search input').forEach(input => {
            input.maxLength = 255;
            input.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
        });
        document.querySelectorAll('[data-table-fallback]').forEach(el => { if (el.dataset.tableFallback === table.id) el.hidden = true; });
        if (table.dataset.serverTable) document.querySelectorAll('[data-table-search-fallback]').forEach(el => { el.hidden = true; });
    }
    function create(table, options = {}) {
        if (DataTable.isDataTable(table)) return new DataTable(table);
        // DataTables does not accept colspan empty-state rows as data.
        [...table.tBodies[0].rows].forEach(row => {
            if (row.cells.length === 1 && row.cells[0].colSpan > 1) row.remove();
        });
        if (table.dataset.serverTable) table.querySelectorAll('tbody td').forEach(cell => {
            ['data-order', 'data-sort', 'data-filter', 'data-search'].forEach(attr => cell.removeAttribute(attr));
        });
        const disabled = [...table.tHead.rows[0].cells].flatMap((th, i) => th.dataset.dtOrder === 'disable' ? [i] : []);
        // Apply our layout before the first Ajax request, not after it returns.
        window.jQuery(table).one('preInit.dt.tsTables', (_event, settings) => {
            decorate(new DataTable.Api(settings));
        });
        window.jQuery(table).on('processing.dt.tsTables', (_event, settings, busy) => {
            table.setAttribute('aria-busy', String(busy));
            const container = settings.nTableWrapper;
            if (container) container.classList.toggle('ts-table-busy', busy);
        });
        return new DataTable(table, {
            pageLength: 5, lengthMenu: [5, 10, 25, 50, 100], order: [], orderMulti: false,
            autoWidth: false, language,
            layout: { topStart: 'pageLength', topEnd: 'search', bottomStart: 'info', bottomEnd: { paging: { type: 'simple_numbers' } } },
            columnDefs: [{ targets: disabled, orderable: false, searchable: false }],
            ...options,
            initComplete: function () { decorate(this.api()); options.initComplete?.call(this); }
        });
    }
    function serverOptions(table) {
        let controller;
        let lastResult = { recordsTotal: 0, recordsFiltered: 0, data: [] };
        let latestDraw = 0;
        const notice = document.createElement('div');
        notice.className = 'notice error ts-table-error'; notice.setAttribute('role', 'alert'); notice.hidden = true;
        const text = document.createElement('span');
        const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'btn'; retry.textContent = window.TransSurveyI18n.t("Coba lagi");
        notice.append(text, retry); table.closest('.table-wrap').before(notice);
        retry.addEventListener('click', () => new DataTable(table).ajax.reload(null, false));
        return {
            serverSide: true, processing: true, searchDelay: 350, search: { search: table.dataset.search || '' },
            ajax: async (request, callback) => {
                latestDraw = request.draw;
                controller?.abort(); controller = new AbortController();
                const url = new URL(window.location.href);
                url.searchParams.delete('page'); url.searchParams.delete('q');
                url.searchParams.set('_table', '1');
                ['draw', 'start', 'length'].forEach(key => url.searchParams.set(key, request[key]));
                url.searchParams.set('search[value]', request.search.value);
                if (request.order.length) {
                    url.searchParams.set('order[0][column]', request.order[0].column);
                    url.searchParams.set('order[0][dir]', request.order[0].dir);
                }
                try {
                    const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!response.ok) throw new Error(response.status === 401 || response.status === 419 ? window.TransSurveyI18n.t("Sesi berakhir. Muat ulang halaman lalu masuk kembali.") : window.TransSurveyI18n.t("Tabel belum berhasil dimuat. Coba lagi atau muat ulang halaman."));
                    const result = await response.json();
                    if (request.draw !== latestDraw) return;
                    if (!Number.isInteger(result.draw) || typeof result.html !== 'string') throw new Error(window.TransSurveyI18n.t("Respons tabel tidak sesuai. Silakan muat ulang halaman."));
                    const parsed = document.createElement('table');
                    parsed.innerHTML = '<tbody>' + result.html + '</tbody>';
                    const data = [...parsed.tBodies[0].rows]
                        .filter(row => !(row.cells.length === 1 && row.cells[0].colSpan > 1))
                        .map(row => [...row.cells].map(cell => cell.innerHTML));
                    notice.hidden = true;
                    lastResult = { draw: result.draw, recordsTotal: result.recordsTotal, recordsFiltered: result.recordsFiltered, data };
                    callback(lastResult);
                } catch (error) {
                    if (error.name === 'AbortError' || request.draw !== latestDraw) return;
                    text.textContent = error.message || window.TransSurveyI18n.t("Tabel belum berhasil dimuat.");
                    notice.hidden = false;
                    // Keep the last loaded rows visible and explicitly mark failure.
                    callback({ ...lastResult, draw: request.draw });
                }
            }
        };
    }
    window.TransSurveyTables = { create };
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('table[data-ts-table]').forEach(table => {
            try { create(table, table.dataset.serverTable ? serverOptions(table) : {}); }
            catch { window.TransSurveyUI?.toast(window.TransSurveyI18n.t("Tabel interaktif belum aktif. Muat ulang halaman."), 'error'); }
        });
        window.TransSurveyTableBoot?.finish();
    });
})();

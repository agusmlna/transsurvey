@php
    $cleanupEnglish = app()->getLocale() === 'en';
    $cleanupNotes = $cleanupEnglish ? [
        'title' => 'Deletion rules',
        'intro' => 'Review these rules before cleaning up test data.',
        'data' => 'Data', 'rule' => 'Deletion rule', 'close' => 'Close', 'understood' => 'Understood',
        'warning' => 'Deletion is permanent. Review the selected records and make sure a database backup is available.',
        'rows' => [
            ['Client', 'Can be deleted if it has no invitations or responses. Otherwise, deactivate it using Edit Client.'],
            ['Questionnaire', 'Can be deleted while it is a draft with no invitations or responses. Close a questionnaire that has already been used through Edit Questionnaire.'],
            ['Test invitation', 'Can be deleted together with its responses, answers, follow-ups, email delivery records, and admin notifications after you review and confirm the effects.'],
            ['Test response', 'Deleting a response also deletes its invitation and related data. The old link and access code stop working. Dashboard and report figures update from the remaining data when reloaded.'],
        ],
    ] : [
        'title' => 'Aturan hapus',
        'intro' => 'Perhatikan ketentuan berikut sebelum membersihkan data testing.',
        'data' => 'Data', 'rule' => 'Aturan hapus', 'close' => 'Tutup', 'understood' => 'Saya mengerti',
        'warning' => 'Penghapusan bersifat permanen. Periksa pilihan data dan pastikan backup database tersedia.',
        'rows' => [
            ['Klien', 'Bisa dihapus jika belum punya undangan maupun respons. Jika sudah digunakan, nonaktifkan melalui Edit Klien.'],
            ['Kuesioner', 'Bisa dihapus jika masih draf dan belum punya undangan maupun respons. Kuesioner yang sudah digunakan dapat ditutup melalui Edit Kuesioner.'],
            ['Undangan testing', 'Bisa dihapus beserta respons, jawaban, tindak lanjut, riwayat pengiriman email, dan notifikasi admin terkait setelah dampaknya diperiksa dan dikonfirmasi.'],
            ['Respons testing', 'Menghapus respons juga menghapus undangan dan data terkaitnya. Link dan kode akses lama menjadi tidak berlaku. Skor dashboard dan laporan menyesuaikan data yang tersisa saat dimuat ulang.'],
        ],
    ];
@endphp
<button type="button" class="btn cleanup-rules-trigger" data-cleanup-rules-open aria-haspopup="dialog" aria-controls="cleanup-rules-dialog">
    <span aria-hidden="true">ⓘ</span> {{ $cleanupNotes['title'] }}
</button>
<dialog id="cleanup-rules-dialog" class="cleanup-rules-dialog" aria-labelledby="cleanup-rules-title" aria-describedby="cleanup-rules-intro">
    <div class="cleanup-rules-header">
        <div><span class="cleanup-rules-eyebrow">TransSurvey</span><h2 id="cleanup-rules-title">{{ $cleanupNotes['title'] }}</h2></div>
        <button type="button" class="cleanup-rules-close" data-cleanup-rules-close aria-label="{{ $cleanupNotes['close'] }}" autofocus>×</button>
    </div>
    <div class="cleanup-rules-body">
        <p id="cleanup-rules-intro">{{ $cleanupNotes['intro'] }}</p>
        <dl class="cleanup-rules-list">
            @foreach ($cleanupNotes['rows'] as [$label, $description])
                <div><dt>{{ $label }}</dt><dd>{{ $description }}</dd></div>
            @endforeach
        </dl>
        <p class="cleanup-rules-warning"><span aria-hidden="true">!</span>{{ $cleanupNotes['warning'] }}</p>
    </div>
    <div class="cleanup-rules-footer"><button type="button" class="btn primary" data-cleanup-rules-close>{{ $cleanupNotes['understood'] }}</button></div>
</dialog>
@push('styles')
<style>
.cleanup-rules-trigger{gap:8px}
.cleanup-rules-dialog{position:fixed;inset:0;margin:auto;width:min(760px,calc(100vw - 32px));max-width:none;max-height:calc(100dvh - 32px);padding:0;border:1px solid #e5e7eb;border-radius:18px;color:#243447;background:#fff;box-shadow:0 24px 80px #0f172a40;overflow:auto;text-align:left}
.cleanup-rules-dialog::backdrop{background:rgba(15,23,42,.52);backdrop-filter:blur(3px)}
.cleanup-rules-dialog[open]{animation:cleanup-rules-appear .18s ease-out}
.cleanup-rules-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:22px 26px;border-bottom:1px solid #eef0f4}
.cleanup-rules-eyebrow{font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#be123c}
.cleanup-rules-header h2{font-size:22px;line-height:1.3;margin:5px 0 0}
.cleanup-rules-close{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border:0;border-radius:50%;background:#f1f5f9;color:#475569;font-family:inherit;font-size:26px;line-height:1;cursor:pointer}
.cleanup-rules-close:focus-visible,.cleanup-rules-trigger:focus-visible{outline:3px solid #fda4af;outline-offset:3px}
.cleanup-rules-body{padding:20px 26px}
.cleanup-rules-body>p:first-child{margin:0 0 18px;font-size:14px;color:#64748b;line-height:1.6}
.cleanup-rules-list{margin:0;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
.cleanup-rules-list>div{display:grid;grid-template-columns:145px 1fr;gap:18px;padding:17px 18px;border-bottom:1px solid #e5e7eb}
.cleanup-rules-list>div:last-child{border-bottom:0}
.cleanup-rules-list>div:nth-child(odd){background:#f8fafc}
.cleanup-rules-list dt{font-weight:700;font-size:13px;line-height:1.7}
.cleanup-rules-list dd{margin:0;color:#475569;font-size:13px;line-height:1.7}
.cleanup-rules-warning{display:flex;align-items:flex-start;gap:10px;margin:18px 0 0!important;padding:13px 15px;border-radius:10px;background:#fff1f2;color:#9f1239;font-size:13px;line-height:1.6}
.cleanup-rules-warning>span{display:grid;place-items:center;flex:0 0 20px;height:20px;border-radius:50%;background:#ffe4e6;font-weight:700}
.cleanup-rules-footer{padding:16px 26px;border-top:1px solid #eef0f4;display:flex;justify-content:flex-end}
@keyframes cleanup-rules-appear{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:600px){.cleanup-rules-list>div{grid-template-columns:1fr;gap:4px}.cleanup-rules-header,.cleanup-rules-body,.cleanup-rules-footer{padding:16px 18px}.cleanup-rules-header h2{font-size:20px}}
@media(prefers-reduced-motion:reduce){.cleanup-rules-dialog[open]{animation:none}}
</style>
@endpush
@push('scripts')
<script>
(() => {
    const init = () => {
        const dialog = document.getElementById('cleanup-rules-dialog');
        const trigger = document.querySelector('[data-cleanup-rules-open]');
        if (!dialog || !trigger || dialog.dataset.ready) return;
        dialog.dataset.ready = 'true';
        trigger.addEventListener('click', () => { if (!dialog.open) dialog.showModal(); });
        dialog.querySelectorAll('[data-cleanup-rules-close]').forEach(button => {
            button.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('close', () => trigger.focus());
        // Native dialog supports Escape, traps keyboard focus and makes the page inert.
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
})();
</script>
@endpush

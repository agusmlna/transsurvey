@extends('layouts.app')
@section('title', 'Undangan')
@section('heading', 'Undangan & pengingat')
@section('subtitle', 'Buat undangan survei dan kirim email ke PIC klien.')
@section('content')
    @php
        $emailEnabled = config('survey.email_enabled') && config('mail.default') === 'smtp';
    @endphp
    @if (!$emailEnabled)
        <div class="notice">
            Pengiriman email belum diaktifkan. Anda tetap dapat menyalin tautan survei. Hubungi administrator untuk
            mengaktifkan email.</div>
    @endif
    <details class="panel distribution-form">
        <summary>＋ Buat undangan survei</summary>
        <form action="{{ route('invitations.store') }}" method="post"
            data-confirm="Buat tautan undangan untuk klien yang dipilih? Email belum dikirim pada langkah ini."
            data-confirm-title="Buat undangan?" data-confirm-button="Ya, buat undangan"
            data-busy-text="Sedang membuat undangan…">
            @csrf
            <label>Kuesioner aktif<select name="survey_id" required>
                    <option value="">Pilih kuesioner</option>
                    @foreach ($surveys as $s)
                        <option value="{{ $s->id }}">{{ $s->title }}</option>
                    @endforeach
                </select>
            </label>
            <fieldset>
                <legend>Klien penerima (pilih satu atau lebih)</legend>

                @if ($clients->isEmpty())
                    <p>Belum ada klien aktif.</p>
                @else
                    <div class="client-table-wrap">
                        <table id="client-table" class="display" style="width:100%"
                            data-selected='@json(old('client_ids', []))'>
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="client-check-all"
                                            aria-label="Pilih semua klien hasil pencarian, lintas halaman"></th>
                                    <th>Klien</th>
                                    <th>PIC</th>
                                    <th>Email</th>
                                    <th>Proyek</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($clients as $c)
                                    <tr>
                                        <td><input type="checkbox" class="client-check" value="{{ $c->id }}"
                                                aria-label="Pilih {{ $c->name }}"></td>
                                        <td>{{ $c->name }}</td>
                                        <td>{{ $c->contact }}</td>
                                        <td>{{ $c->email }}</td>
                                        <td>{{ $c->project }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="footnote"><span id="client-selected-count">0 klien dipilih</span> · maksimal 100 klien per
                        pembuatan.</p>
                    <div id="client-hidden"></div>
                @endif
            </fieldset>
            <button class="btn primary">Buat tautan unik</button>
            <p class="footnote">Setelah undangan dibuat, klik Kirim email pada baris PIC yang dituju.</p>
        </form>
    </details>
    <div class="list-toolbar">
        <div class="tabs"><a href="{{ route('invitations.index') }}"
                class="{{ !request('status') ? 'selected' : '' }}">Semua</a><a
                href="{{ route('invitations.index', ['status' => 'pending']) }}"
                class="{{ request('status') === 'pending' ? 'selected' : '' }}">Belum selesai</a><a
                href="{{ route('invitations.index', ['status' => 'completed']) }}"
                class="{{ request('status') === 'completed' ? 'selected' : '' }}">Selesai</a></div>
    </div>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Email & reminder</h2>
                <p>Periksa alamat penerima, lalu kirim undangan atau pengingat.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table id="invitations-table" class="ts-data-table" data-ts-table data-server-table="invitations" data-search="{{ request('q', '') }}" aria-label="Daftar undangan">
                <thead>
                    <tr>
                        <th>Klien / survei</th>
                        <th>PIC penerima</th>
                        <th>Pengisian</th>
                        <th data-dt-order="disable">Email terakhir</th>
                        <th>Reminder</th>
                        <th data-dt-order="disable">Kirim email</th>
                        <th data-dt-order="disable">Tautan</th>
                    </tr>
                </thead>
                <tbody>
@include('invitations.rows')
</tbody>
            </table>
        </div>
    </section>
    <p class="footnote">Email dikirim ke alamat PIC yang tersimpan pada undangan. Setelah konfirmasi, email masuk antrean.
        Muat ulang halaman untuk melihat status terbaru. Status Terkirim berarti pesan diterima server email, bukan
        konfirmasi bahwa email sudah dibaca.</p>
    <div data-table-fallback="invitations-table">{{ $invitations->links() }}</div>
@endsection
@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var tableEl = document.getElementById('client-table');
            if (!tableEl || !window.TransSurveyTables) return;

            var form = tableEl.closest('form');
            var checkAll = document.getElementById('client-check-all');
            var counter = document.getElementById('client-selected-count');
            var hiddenBox = document.getElementById('client-hidden');
            var selected = new Set(JSON.parse(tableEl.dataset.selected || '[]').map(String));
            var dt = null;

            function filteredBoxes() {
                return dt.rows({ search: 'applied' }).nodes().toArray()
                    .map(function (tr) { return tr.querySelector('.client-check'); });
            }

            // Checkbox di halaman lain tidak ada di DOM, jadi pilihan dikirim lewat hidden input
            function syncHidden() {
                hiddenBox.innerHTML = '';
                selected.forEach(function (id) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'client_ids[]';
                    input.value = id;
                    hiddenBox.appendChild(input);
                });
            }

            function refreshState() {
                counter.textContent = selected.size + ' klien dipilih';
                var boxes = filteredBoxes();
                var checked = boxes.filter(function (b) { return selected.has(b.value); }).length;
                checkAll.checked = boxes.length > 0 && checked === boxes.length;
                checkAll.indeterminate = checked > 0 && checked < boxes.length;
                syncHidden();
            }

            dt = window.TransSurveyTables.create(tableEl, {
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                order: [[1, 'asc']],
                columnDefs: [{ targets: 0, orderable: false, searchable: false }],
                drawCallback: function () {
                    tableEl.querySelectorAll('tbody .client-check').forEach(function (cb) {
                        cb.checked = selected.has(cb.value);
                    });
                    if (dt) refreshState();
                }
            });

            // Centang per baris
            tableEl.addEventListener('change', function (e) {
                if (!e.target.classList.contains('client-check')) return;
                if (e.target.checked) selected.add(e.target.value);
                else selected.delete(e.target.value);
                refreshState();
            });

            // Pilih semua baris hasil filter (lintas halaman)
            checkAll.addEventListener('change', function () {
                var state = checkAll.checked;
                filteredBoxes().forEach(function (cb) {
                    cb.checked = state;
                    if (state) selected.add(cb.value);
                    else selected.delete(cb.value);
                });
                refreshState();
            });

            // Validasi minimal 1 klien (capture, supaya jalan sebelum handler konfirmasi lain)
            form.addEventListener('submit', function (e) {
                if (!selected.size || selected.size > 100) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    window.TransSurveyUI.toast(!selected.size ? 'Pilih minimal satu klien.' : 'Maksimal 100 klien per pembuatan undangan.', 'error');
                }
            }, true);

            // Hitung ulang lebar kolom saat <details> dibuka
            var details = document.querySelector('details.distribution-form');
            if (details) details.addEventListener('toggle', function () { dt.columns.adjust(); });

            refreshState();
        });
    </script>
@endpush

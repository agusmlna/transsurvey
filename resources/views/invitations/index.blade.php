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
                                            aria-label="Pilih semua klien yang tampil"></th>
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
            <table>
                <thead>
                    <tr>
                        <th>Klien / survei</th>
                        <th>PIC penerima</th>
                        <th>Pengisian</th>
                        <th>Email terakhir</th>
                        <th>Reminder</th>
                        <th>Kirim email</th>
                        <th>Tautan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invitations as $i)
                        @php
                            $delivery = $i->deliveries->sortByDesc('id')->first();
                            $isReminder = (bool) $i->sent_at;
                            $pending = $i->deliveries->contains(fn($d) => $d->status === 'pending');
                            $buttonText = $delivery?->status === 'failed' ? 'Coba kirim lagi' : 'Kirim email';
                            $blockedReason = null;
                            if ($i->completed_at) {
                                $blockedReason = 'Survei sudah selesai diisi.';
                            } elseif ($i->is_demo) {
                                $blockedReason = 'Email untuk data contoh dinonaktifkan.';
                            } elseif (!$emailEnabled) {
                                $blockedReason = 'Pengiriman email belum diaktifkan.';
                            } elseif (!$i->survey->isOpen()) {
                                $blockedReason = 'Di luar periode survei aktif.';
                            } elseif ($isReminder && $i->reminder_count >= config('survey.max_reminders')) {
                                $blockedReason = 'Batas reminder sudah tercapai.';
                            } elseif ($pending) {
                                $blockedReason = 'Email sedang menunggu proses pengiriman.';
                            }
                            $deliveryLabels = [
                                'pending' => 'Dalam antrean',
                                'sent' => 'Terkirim',
                                'failed' => 'Gagal',
                                'skipped' => 'Dibatalkan',
                            ];
                            $deliveryColors = [
                                'pending' => 'amber',
                                'sent' => 'green',
                                'failed' => 'gray',
                                'skipped' => 'gray',
                            ];
                        @endphp
                        <tr>
                            <td><strong>{{ $i->client->name }}</strong><small>{{ $i->survey->title }}</small>
                                @if ($i->is_demo)
                                    <span class="badge gray">Data contoh</span>
                                @endif
                            </td>
                            <td><strong>{{ $i->recipient_name }}</strong><small>{{ $i->recipient_email }}</small></td>
                            <td><span
                                    class="badge {{ $i->completed_at ? 'green' : 'amber' }}">{{ $i->completed_at ? 'Selesai' : ($i->started_at ? 'Draf tersimpan' : 'Belum mengisi') }}</span>
                            </td>
                            <td><span
                                    class="badge {{ $delivery ? $deliveryColors[$delivery->status] ?? 'gray' : 'gray' }}">{{ $delivery ? $deliveryLabels[$delivery->status] ?? $delivery->status : 'Belum dikirim' }}</span>
                                @if ($delivery?->sent_at)
                                    <small>{{ $delivery->sent_at->format('d M Y H:i') }}</small>
                                    @endif @if ($delivery?->last_error)
                                        <small>{{ $delivery->last_error }}</small>
                                    @endif
                            </td>
                            <td>
                                <strong>{{ $i->reminder_count }} / {{ config('survey.max_reminders') }}</strong>
                                <small>{{ !$i->completed_at && $i->reminder_count < config('survey.max_reminders') ? $i->reminder_at?->format('d M Y H:i') ?? 'Setelah email pertama' : '—' }}</small>
                                <form method="post" action="{{ route('invitations.remind', $i) }}"
                                    data-confirm="Kirim email pengingat ke {{ $i->recipient_name }} ({{ $i->recipient_email }})? Tautan dan kode akses tetap sama."
                                    data-confirm-title="Kirim reminder ke PIC?" data-confirm-button="Ya, kirim reminder"
                                    data-busy-text="Memproses reminder…">
                                    @csrf
                                    <button type="submit" class="btn" @disabled($blockedReason !== null || !$isReminder)
                                        title="{{ $blockedReason ?? (!$isReminder ? 'Kirim email undangan pertama terlebih dahulu.' : 'Kirim ke PIC yang tercantum') }}">Kirim
                                        reminder ke PIC</button>
                                </form>
                                @if ($blockedReason)
                                    <small>{{ $blockedReason }}</small>
                                @elseif (!$isReminder)
                                <small>Kirim undangan pertama terlebih dahulu.</small>@else<small>Maksimal satu reminder
                                        per hari.</small>
                                @endif
                            </td>
                            <td>
                                @if ($isReminder)
                                    <span class="badge green">Undangan sudah dikirim</span>
                                    <small>Untuk mengingatkan PIC, gunakan tombol di kolom Reminder.</small>
                                @else
                                    <form method="post" action="{{ route('invitations.send', $i) }}"
                                        data-confirm="Kirim undangan survei ke {{ $i->recipient_name }} ({{ $i->recipient_email }})?"
                                        data-confirm-title="Kirim email undangan?" data-confirm-button="Ya, kirim email"
                                        data-busy-text="Memproses email…">
                                        @csrf
                                        <button type="submit" class="btn primary" @disabled($blockedReason !== null)
                                            title="{{ $blockedReason ?? 'Kirim undangan pertama ke PIC' }}">{{ $pending && !$i->completed_at ? 'Dalam antrean' : $buttonText }}</button>
                                    </form>
                                @endif
                            </td>
                            <td>
                                <div class="row-actions"><button class="text-button" type="button"
                                        data-copy="{{ $i->surveyUrl() }}">Salin tautan</button><a class="text-button"
                                        href="{{ $i->surveyUrl() }}" target="_blank" rel="noopener">Buka ↗</a></div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="empty" colspan="7">Belum ada undangan. Buat undangan survei untuk klien terlebih
                                dahulu.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <p class="footnote">Email dikirim ke alamat PIC yang tersimpan pada undangan. Setelah konfirmasi, email masuk antrean.
        Muat ulang halaman untuk melihat status terbaru. Status Terkirim berarti pesan diterima server email, bukan
        konfirmasi bahwa email sudah dibaca.</p>
    {{ $invitations->links() }}
@endsection
@push('scripts')
    <link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css">
    <style>
        #client-table th:first-child,
        #client-table td:first-child {
            min-width: 0;
            width: 44px;
            text-align: center;
        }

        .client-table-wrap {
            margin-bottom: 8px;
            font-size: 13px;
        }

        .client-table-wrap .dt-search label,
        .client-table-wrap .dt-length label {
            display: inline-flex;
            flex-direction: row;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .client-table-wrap .dt-search input,
        .client-table-wrap .dt-length select {
            width: auto;
            min-height: 34px;
            padding: 6px 10px;
        }

        .client-table-wrap .dt-paging-button {
            padding: 4px 10px;
            border: 1px solid var(--line);
            border-radius: 6px;
            margin-left: 4px;
            background: #fff;
        }

        .client-table-wrap .dt-paging-button.current {
            background: var(--ts-red);
            color: #fff;
            border-color: var(--ts-red);
        }

        .client-table-wrap .dt-paging-button.disabled {
            opacity: .45;
            cursor: not-allowed;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var tableEl = document.getElementById('client-table');
            if (!tableEl) return;

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

            dt = new DataTable(tableEl, {
                pageLength: 10,
                lengthMenu: [5, 10, 25, 50],
                order: [[1, 'asc']],
                columnDefs: [{ targets: 0, orderable: false, searchable: false }],
                language: {
                    search: 'Cari:',
                    lengthMenu: 'Tampilkan _MENU_ klien',
                    info: '_START_–_END_ dari _TOTAL_ klien',
                    infoEmpty: 'Tidak ada klien',
                    infoFiltered: '(difilter dari _MAX_ klien)',
                    zeroRecords: 'Klien tidak ditemukan',
                    paginate: { previous: '‹', next: '›' }
                },
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
                if (!selected.size) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    alert('Pilih minimal satu klien.');
                }
            }, true);

            // Hitung ulang lebar kolom saat <details> dibuka
            var details = document.querySelector('details.distribution-form');
            if (details) details.addEventListener('toggle', function () { dt.columns.adjust(); });

            refreshState();
        });
    </script>
@endpush

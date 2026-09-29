@extends('layouts.app')
@section('title', 'Editor Kuesioner')
@section('heading', $survey->exists ? 'Edit kuesioner' : 'Buat kuesioner baru')
@section('subtitle', 'Tiga langkah singkat, tanpa pindah halaman.')
@section('actions')
    @if ($survey->exists)
        <a class="btn" href="{{ route('surveys.preview', $survey) }}" target="_blank" rel="noopener">Pratinjau tersimpan ↗</a>
    @endif
    <a class="btn" href="{{ route('surveys.index') }}">← Kembali</a>
@endsection

@section('content')
    @php
        $initial = old(
            'questions',
            $survey->questions
                ->map(
                    fn($q) => [
                        'text' => $q->text,
                        'category' => $q->category,
                        'type' => $q->type,
                        'required' => (bool) $q->required,
                        'options' => $q->options ?? [],
                    ],
                )
                ->values()
                ->all(),
        );
        $bankData = $bank
            ->map(
                fn($b) => [
                    'id' => $b->id,
                    'text' => $b->text,
                    'category' => $b->category,
                    'type' => $b->type,
                    'required' => (bool) $b->required,
                    'options' => $b->options ?? [],
                ],
            )
            ->values();
        $errorStep = $errors->any()
            ? (collect($errors->keys())->contains(fn($k) => str_starts_with($k, 'questions'))
                ? 2
                : 1)
            : 1;
    @endphp

    <style>
        .sw {
            --r: var(--ts-red, #c8102e);
            --soft: var(--ts-soft, #fff1f3);
            font-size: 16px
        }

        .sw label {
            font-size: 15px;
            font-weight: 600;
            color: #404550
        }

        .sw input,
        .sw select,
        .sw textarea {
            font-size: 16px;
            min-height: 48px;
            padding: 12px 14px;
            border-radius: 10px
        }

        .sw small {
            font-size: 13.5px
        }

        .sw-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 380px;
            gap: 24px;
            align-items: start
        }

        .sw-stepper {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap
        }

        .sw-step {
            flex: 1;
            min-width: 190px;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
            border: 1px solid var(--line, #e5e7eb);
            background: #fff;
            border-radius: 12px;
            text-align: left;
            font-size: 16px;
            font-weight: 600;
            color: #6b7280;
            transition: .2s
        }

        .sw-step i {
            font-style: normal;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #eef0f3;
            font-size: 15px;
            flex-shrink: 0
        }

        .sw-step small {
            display: block;
            font-weight: 400;
            color: #9aa0ab
        }

        .sw-step[disabled] {
            opacity: .5;
            cursor: not-allowed
        }

        .sw-step.is-active {
            border-color: var(--r);
            color: #25252b;
            box-shadow: 0 0 0 3px rgb(200 16 46/10%)
        }

        .sw-step.is-active i {
            background: var(--r);
            color: #fff
        }

        .sw-step.is-done i {
            background: #e8f6ee;
            color: #2f855a
        }

        .sw-panel {
            display: none;
            animation: swIn .28s ease
        }

        .sw-panel.is-active {
            display: block
        }

        @keyframes swIn {
            from {
                opacity: 0;
                transform: translateY(8px)
            }

            to {
                opacity: 1;
                transform: none
            }
        }

        .sw-card {
            background: #fff;
            border: 1px solid var(--line, #e5e7eb);
            border-radius: 14px;
            padding: 28px;
            margin-bottom: 20px
        }

        .sw-card h2 {
            font-size: 22px;
            margin-bottom: 6px
        }

        .sw-hint {
            color: #737986;
            font-size: 15px;
            margin-bottom: 22px
        }

        .sw-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 20px
        }

        .sw-grid .full {
            grid-column: 1/-1
        }

        .sw-chips {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: -8px 0 18px
        }

        .sw-chip {
            padding: 8px 14px;
            border: 1px dashed #d4d7dd;
            border-radius: 999px;
            font-size: 14px;
            color: #596170;
            background: #fafbfc
        }

        .sw-chip:hover {
            border-color: var(--r);
            color: var(--r)
        }

        .sw-status {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 18px
        }

        .sw-status label {
            margin: 0;
            cursor: pointer
        }

        .sw-status input {
            position: absolute;
            opacity: 0;
            width: 1px;
            height: 1px;
            min-height: 0
        }

        .sw-status span {
            display: block;
            padding: 14px 16px;
            border: 1px solid #e0e3e8;
            border-radius: 12px;
            font-size: 16px;
            transition: .15s
        }

        .sw-status span small {
            display: block;
            font-weight: 400;
            color: #8a8d98;
            margin-top: 2px
        }

        .sw-status input:checked+span {
            border-color: var(--r);
            background: var(--soft);
            box-shadow: 0 0 0 1px var(--r)
        }

        .sw-status input:focus-visible+span {
            outline: 3px solid rgb(200 16 46/30%)
        }

        .sw-switch {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-direction: row;
            font-size: 16px;
            cursor: pointer
        }

        .sw-switch input {
            width: 20px;
            height: 20px;
            min-height: 0
        }

        .sw-q {
            border: 1px solid #e0e3e8;
            border-radius: 14px;
            background: #fff;
            margin-bottom: 14px;
            transition: box-shadow .2s, border-color .2s
        }

        .sw-q:focus-within {
            border-color: var(--r);
            box-shadow: 0 0 0 3px rgb(200 16 46/8%)
        }

        .sw-q-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            cursor: pointer
        }

        .sw-q-no {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: var(--soft);
            color: var(--r);
            display: grid;
            place-items: center;
            font-weight: 700;
            flex-shrink: 0
        }

        .sw-q-title {
            flex: 1;
            min-width: 0;
            font-size: 16px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .sw-q-title small {
            display: block;
            font-weight: 400;
            color: #8a8d98
        }

        .sw-q-tools {
            display: flex;
            gap: 2px
        }

        .sw-q-tools button {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            font-size: 17px;
            color: #6b7280
        }

        .sw-q-tools button:hover:not(:disabled) {
            background: #f3f4f6;
            color: var(--r)
        }

        .sw-q-body {
            padding: 4px 18px 20px;
            border-top: 1px solid #f0f1f4
        }

        .sw-q.is-collapsed .sw-q-body {
            display: none
        }

        .sw-types {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px
        }

        .sw-types button {
            padding: 10px 16px;
            border: 1px solid #e0e3e8;
            border-radius: 10px;
            font-size: 15px;
            background: #fff
        }

        .sw-types button[aria-pressed=true] {
            background: var(--r);
            border-color: var(--r);
            color: #fff
        }

        .sw-invalid input,
        .sw-invalid textarea,
        .sw-invalid select {
            border-color: #d92d20 !important;
            background: #fff8f7
        }

        .sw-err {
            color: #b42318;
            font-size: 14px;
            margin-top: -10px;
            margin-bottom: 14px;
            font-weight: 500
        }

        .sw-actions {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 8px
        }

        .sw-actions .btn {
            min-height: 50px;
            font-size: 16px;
            padding: 12px 22px
        }

        .sw-add {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 6px
        }

        .sw-add .btn,
        .sw-add select {
            min-height: 48px;
            font-size: 15px
        }

        .sw-add select {
            flex: 1;
            min-width: 220px;
            width: auto
        }

        .sw-empty {
            text-align: center;
            padding: 40px 20px;
            border: 2px dashed #e0e3e8;
            border-radius: 14px;
            color: #8a8d98;
            font-size: 16px;
            margin-bottom: 14px
        }

        .sw-locked-q {
            padding: 14px 0;
            border-top: 1px solid #f0f1f4;
            font-size: 16px
        }

        .sw-locked-q:first-of-type {
            border-top: 0
        }

        .sw-review dl {
            display: grid;
            grid-template-columns: 170px 1fr;
            gap: 12px 16px;
            font-size: 16px;
            margin: 0 0 20px
        }

        .sw-review dt {
            color: #737986
        }

        .sw-review dd {
            margin: 0;
            font-weight: 600
        }

        .sw-review ol {
            padding-left: 22px;
            font-size: 16px;
            line-height: 1.9;
            margin: 0
        }

        .sw-pill {
            display: inline-block;
            font-size: 13px;
            padding: 2px 10px;
            border-radius: 999px;
            background: #f3f4f6;
            color: #596170;
            margin-left: 6px;
            font-weight: 500
        }

        .sw-pill.req {
            background: var(--soft);
            color: var(--r)
        }

        .sw-aside {
            position: sticky;
            top: 20px
        }

        .sw-aside .sw-card {
            padding: 22px
        }

        .sw-aside h3 {
            font-size: 15px;
            letter-spacing: .06em;
            color: #8a8d98;
            text-transform: uppercase;
            margin-bottom: 14px;
            font-weight: 600
        }

        .sw-pv h4 {
            font-size: 20px;
            margin: 0 0 6px
        }

        .sw-pv p {
            font-size: 15px;
            color: #737986;
            margin: 0 0 16px
        }

        .sw-pv-q {
            padding: 14px 0;
            border-top: 1px solid #f0f1f4
        }

        .sw-pv-q b {
            display: block;
            font-size: 15.5px;
            margin-bottom: 10px;
            line-height: 1.5
        }

        .sw-pv-dots {
            display: flex;
            gap: 6px
        }

        .sw-pv-dots i {
            flex: 1;
            height: 34px;
            border: 1px solid #e0e3e8;
            border-radius: 8px;
            display: grid;
            place-items: center;
            font-style: normal;
            font-size: 14px;
            color: #6b7280
        }

        .sw-pv-opt {
            display: block;
            padding: 9px 12px;
            border: 1px solid #e0e3e8;
            border-radius: 8px;
            font-size: 15px;
            margin-bottom: 6px
        }

        .sw-pv-txt {
            height: 54px;
            border: 1px solid #e0e3e8;
            border-radius: 8px;
            background: #fafbfc
        }

        .sw-draft {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 15px
        }

        .sw-alert {
            position: sticky;
            top: 12px;
            z-index: 5;
            margin-bottom: 16px;
            padding: 14px 18px;
            border-radius: 10px;
            background: #fff2ef;
            border: 1px solid #efd6d1;
            color: #b42318;
            font-size: 15.5px;
            font-weight: 500
        }

        .sw-spin {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgb(255 255 255/50%);
            border-top-color: #fff;
            border-radius: 50%;
            animation: swSpin .7s linear infinite
        }

        @keyframes swSpin {
            to {
                transform: rotate(360deg)
            }
        }

        @@media (max-width:1100px) {
            .sw-layout {
                grid-template-columns: 1fr
            }

            .sw-aside {
                display: none
            }
        }

        @@media (max-width:640px) {

            .sw-grid,
            .sw-status {
                grid-template-columns: 1fr
            }

            .sw-review dl {
                grid-template-columns: 1fr
            }

            .sw-card {
                padding: 20px
            }
        }

        @@media (prefers-reduced-motion:reduce) {
            .sw-panel {
                animation: none
            }
        }
    </style>

    <div class="sw" id="sw" data-start="{{ $errorStep }}" data-exists="{{ $survey->exists ? 1 : 0 }}"
        data-locked="{{ $locked ? 1 : 0 }}">
        <div class="sw-draft notice" id="sw-draft-banner" hidden>
            <span>Ada draf yang belum disimpan dari sesi sebelumnya.</span>
            <span><button type="button" class="btn primary" id="sw-draft-restore">Lanjutkan draf</button>
                <button type="button" class="btn" id="sw-draft-discard">Buang</button></span>
        </div>

        <nav class="sw-stepper" aria-label="Langkah kuesioner">
            <button type="button" class="sw-step" data-go="1"><i>1</i><span>Informasi<small>Judul &amp;
                        periode</small></span></button>
            <button type="button" class="sw-step" data-go="2"><i>2</i><span>Pertanyaan<small id="sw-count-label">0
                        pertanyaan</small></span></button>
            <button type="button" class="sw-step" data-go="3"><i>3</i><span>Tinjau<small>Cek &amp;
                        simpan</small></span></button>
        </nav>

        <div id="sw-alert" class="sw-alert" role="alert" hidden></div>

        <form id="sw-form" method="post"
            action="{{ $survey->exists ? route('surveys.update', $survey) : route('surveys.store') }}" novalidate>
            @csrf
            @if ($survey->exists)
                @method('put')<input type="hidden" name="version" value="{{ $survey->version }}">
            @endif
            <div class="sw-layout">
                <div>
                    {{-- LANGKAH 1 --}}
                    <section class="sw-panel" data-step="1">
                        <div class="sw-card">
                            <h2>Informasi kuesioner</h2>
                            <p class="sw-hint">Judul dan pengantar akan dilihat langsung oleh klien.</p>
                            <div class="sw-grid">
                                <label class="full">Judul
                                    <input name="title" id="sw-title" maxlength="255" required
                                        placeholder="cth. Survei Kepuasan Klien Q4 2026"
                                        value="{{ old('title', $survey->title) }}">
                                </label>
                                <label class="full">Pengantar <small>(opsional)</small>
                                    <textarea name="description" id="sw-desc" rows="3" maxlength="5000"
                                        placeholder="Ceritakan singkat tujuan survei ini…">{{ old('description', $survey->description) }}</textarea>
                                </label>
                                <label>Tanggal mulai
                                    <input type="date" name="starts_at" id="sw-start" required
                                        value="{{ old('starts_at', $survey->starts_at?->format('Y-m-d')) }}">
                                </label>
                                <label>Tanggal selesai
                                    <input type="date" name="ends_at" id="sw-end" required
                                        value="{{ old('ends_at', $survey->ends_at?->format('Y-m-d')) }}">
                                </label>
                            </div>
                            <div class="sw-chips" aria-label="Durasi cepat">
                                <span class="muted" style="align-self:center;font-size:14px">Durasi cepat:</span>
                                @foreach ([7 => '+7 hari', 14 => '+14 hari', 30 => '+30 hari', 90 => '+3 bulan'] as $d => $t)
                                    <button type="button" class="sw-chip"
                                        data-days="{{ $d }}">{{ $t }}</button>
                                @endforeach
                            </div>

                            <label style="margin-bottom:10px">Status publikasi</label>
                            <div class="sw-status">
                                @foreach (['draft' => ['Draf', 'Bisa dipratinjau dulu'], 'active' => ['Aktif', 'Langsung menerima respons'], 'closed' => ['Ditutup', 'Tidak menerima respons']] as $k => [$t, $s])
                                    <label><input type="radio" name="status" value="{{ $k }}"
                                            @checked(old('status', $survey->status ?: 'draft') === $k)><span><b>{{ $t }}</b><small>{{ $s }}</small></span></label>
                                @endforeach
                            </div>

                            <label class="sw-switch">
                                <input type="hidden" name="is_template" value="0">
                                <input type="checkbox" name="is_template" value="1" @checked(old('is_template', $survey->is_template))>
                                Simpan sebagai template
                            </label>
                        </div>
                        <div class="sw-actions"><span></span><button type="button" class="btn primary" data-next>Lanjut ke
                                pertanyaan →</button></div>
                    </section>

                    {{-- LANGKAH 2 --}}
                    <section class="sw-panel" data-step="2">
                        <div class="sw-card">
                            @if ($locked)
                                <h2>Pertanyaan</h2>
                                <div class="notice">Pertanyaan dikunci karena kuesioner sudah memiliki undangan. Gunakan
                                    Duplikat di daftar kuesioner untuk membuat versi baru.</div>
                                @foreach ($survey->questions as $q)
                                    <div class="sw-locked-q">
                                        <span class="eyebrow">{{ $q->category }} / {{ $q->type }}</span>
                                        <strong>{{ $loop->iteration }}. {{ $q->text }}</strong>
                                        <span
                                            class="sw-pill {{ $q->required ? 'req' : '' }}">{{ $q->required ? 'Wajib' : 'Opsional' }}</span>
                                    </div>
                                @endforeach
                            @else
                                <h2>Susun pertanyaan</h2>
                                <p class="sw-hint">Klik kartu untuk membuka/menutup. Rating 1–3 otomatis wajib diberi
                                    komentar oleh responden.</p>
                                <div class="sw-empty" id="sw-empty">Belum ada pertanyaan. Tambahkan yang pertama di bawah.
                                </div>
                                <div id="sw-list"></div>
                                <div class="sw-add">
                                    <button type="button" class="btn primary" id="sw-add">＋ Tambah
                                        pertanyaan</button>
                                    <select id="sw-bank-select" aria-label="Tambah dari bank pertanyaan">
                                        <option value="">Ambil dari bank pertanyaan…</option>
                                        @foreach ($bank as $b)
                                            <option value="{{ $b->id }}">
                                                {{ \Illuminate\Support\Str::limit($b->text, 80) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <datalist id="sw-cats">
                                    @foreach ($bank->pluck('category')->unique() as $c)
                                        <option value="{{ $c }}">
                                    @endforeach
                                </datalist>
                            @endif
                        </div>
                        <div class="sw-actions">
                            <button type="button" class="btn" data-prev>← Kembali</button>
                            <button type="button" class="btn primary" data-next>Tinjau →</button>
                        </div>
                    </section>

                    {{-- LANGKAH 3 --}}
                    <section class="sw-panel" data-step="3">
                        <div class="sw-card sw-review">
                            <h2>Tinjau &amp; simpan</h2>
                            <p class="sw-hint">Periksa sekali lagi. Setelah undangan dibuat, pertanyaan akan dikunci.</p>
                            <dl id="sw-review-info"></dl>
                            <h3 style="font-size:17px;margin-bottom:10px">Pertanyaan</h3>
                            <ol id="sw-review-list"></ol>
                        </div>
                        <div class="sw-actions">
                            <button type="button" class="btn" data-prev>← Kembali</button>
                            <button type="submit" class="btn primary"
                                id="sw-submit">{{ $survey->exists ? 'Simpan perubahan' : 'Simpan kuesioner' }}</button>
                        </div>
                    </section>
                </div>

                <aside class="sw-aside" aria-label="Pratinjau langsung">
                    <div class="sw-card">
                        <h3>Pratinjau langsung</h3>
                        <div class="sw-pv" id="sw-preview"></div>
                    </div>
                </aside>
            </div>
        </form>

        <script type="application/json" id="sw-initial">@json($initial)</script>
        <script type="application/json" id="sw-bank">@json($bankData)</script>

        <template id="sw-q-tpl">
            <section class="sw-q">
                <div class="sw-q-head" data-act="toggle">
                    <span class="sw-q-no" data-no>1</span>
                    <div class="sw-q-title"><span data-title></span><small data-meta></small></div>
                    <div class="sw-q-tools">
                        <button type="button" data-act="up" aria-label="Pindah ke atas" title="Naik">↑</button>
                        <button type="button" data-act="down" aria-label="Pindah ke bawah" title="Turun">↓</button>
                        <button type="button" data-act="dup" aria-label="Duplikat" title="Duplikat">⧉</button>
                        <button type="button" data-act="del" aria-label="Hapus" title="Hapus">✕</button>
                    </div>
                </div>
                <div class="sw-q-body">
                    <div style="height:16px"></div>
                    <input type="hidden" data-f="type" value="rating">
                    <div class="sw-types" role="group" aria-label="Jenis pertanyaan">
                        <button type="button" data-type="rating">★ Rating 1–5</button>
                        <button type="button" data-type="choice">◉ Pilihan ganda</button>
                        <button type="button" data-type="text">¶ Teks terbuka</button>
                    </div>
                    <label data-wrap="text">Pertanyaan
                        <textarea data-f="text" rows="2" maxlength="2000" placeholder="Tulis pertanyaan…"></textarea>
                    </label>
                    <div class="sw-grid">
                        <label data-wrap="category">Kategori
                            <input data-f="category" list="sw-cats" maxlength="100" placeholder="cth. Kualitas layanan">
                        </label>
                        <label class="sw-switch" style="align-self:center;margin-top:8px">
                            <input type="hidden" data-h="required" value="0">
                            <input type="checkbox" data-f="required" value="1" checked> Wajib diisi
                        </label>
                    </div>
                    <label data-wrap="options" hidden>Pilihan jawaban <small>(satu per baris, minimal 2)</small>
                        <textarea data-f="options" rows="4" placeholder="Email&#10;Telepon"></textarea>
                    </label>
                </div>
            </section>
        </template>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const $ = (s, r = document) => r.querySelector(s);
            const $$ = (s, r = document) => [...r.querySelectorAll(s)];
            const root = $('#sw'),
                form = $('#sw-form');
            if (!form) return;

            const exists = root.dataset.exists === '1',
                locked = root.dataset.locked === '1';
            const list = $('#sw-list'),
                tpl = $('#sw-q-tpl'),
                alertBox = $('#sw-alert');
            const bank = JSON.parse($('#sw-bank').textContent);
            const initial = Object.values(JSON.parse($('#sw-initial').textContent) || {});
            const MAX = 100,
                KEY = 'ts-survey-draft-v1';
            const TYPES = {
                rating: 'Rating 1–5',
                choice: 'Pilihan ganda',
                text: 'Teks terbuka'
            };
            let step = 1,
                maxStep = 1,
                saveTimer;

            const toOptions = v => (Array.isArray(v) ? v : String(v ?? '').split(/\r?\n/)).map(s => s.trim()).filter(
                Boolean);
            const fmtDate = v => v ? new Date(v + 'T00:00').toLocaleDateString('id-ID', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            }) : '—';

            /* ---------- Alert & validasi ---------- */
            function showAlert(msg) {
                alertBox.textContent = msg;
                alertBox.hidden = false;
                alertBox.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            }

            function clearAlert() {
                alertBox.hidden = true;
                $$('.sw-invalid').forEach(e => e.classList.remove('sw-invalid'));
                $$('.sw-err').forEach(e => e.remove());
            }

            function fail(el, msg) {
                const wrap = el.closest('label') || el.parentElement;
                wrap.classList.add('sw-invalid');
                const p = document.createElement('div');
                p.className = 'sw-err';
                p.textContent = msg;
                wrap.after(p);
                const card = el.closest('.sw-q');
                if (card) card.classList.remove('is-collapsed');
                showAlert(msg);
                el.focus();
                return false;
            }

            function validateStep1() {
                clearAlert();
                const title = $('#sw-title'),
                    s = $('#sw-start'),
                    e = $('#sw-end');
                if (!title.value.trim()) return fail(title, 'Judul kuesioner wajib diisi.');
                if (!s.value) return fail(s, 'Tanggal mulai wajib diisi.');
                if (!e.value) return fail(e, 'Tanggal selesai wajib diisi.');
                if (e.value < s.value) return fail(e, 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
                return true;
            }

            function validateStep2() {
                clearAlert();
                if (locked) return true;
                const cards = $$('.sw-q', list);
                if (!cards.length) {
                    showAlert('Tambahkan minimal satu pertanyaan.');
                    return false;
                }
                for (const [i, c] of cards.entries()) {
                    const t = $('[data-f=text]', c),
                        cat = $('[data-f=category]', c),
                        o = $('[data-f=options]', c);
                    if (!t.value.trim()) return fail(t, `Pertanyaan ${i + 1}: teks pertanyaan wajib diisi.`);
                    if (!cat.value.trim()) return fail(cat, `Pertanyaan ${i + 1}: kategori wajib diisi.`);
                    if ($('[data-f=type]', c).value === 'choice') {
                        const opts = toOptions(o.value);
                        if (opts.length < 2 || new Set(opts).size !== opts.length) return fail(o,
                            `Pertanyaan ${i + 1}: isi minimal dua pilihan yang berbeda.`);
                    }
                }
                return true;
            }
            const validators = {
                1: validateStep1,
                2: validateStep2
            };

            /* ---------- Navigasi langkah ---------- */
            function goTo(n, {
                push = true
            } = {}) {
                n = Math.max(1, Math.min(3, n));
                if (n > step) {
                    for (let s = step; s < n; s++)
                        if (validators[s] && !validators[s]()) return;
                } else clearAlert();
                step = n;
                maxStep = Math.max(maxStep, n);
                $$('.sw-panel').forEach(p => p.classList.toggle('is-active', +p.dataset.step === n));
                $$('.sw-step').forEach(b => {
                    const k = +b.dataset.go;
                    b.classList.toggle('is-active', k === n);
                    b.classList.toggle('is-done', k < n);
                    b.disabled = k > maxStep;
                    b.setAttribute('aria-current', k === n ? 'step' : 'false');
                });
                if (n === 3) renderReview();
                if (push && location.hash !== '#langkah-' + n) history.pushState({
                    n
                }, '', '#langkah-' + n);
                root.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
            $$('[data-go]').forEach(b => b.addEventListener('click', () => goTo(+b.dataset.go)));
            $$('[data-next]').forEach(b => b.addEventListener('click', () => goTo(step + 1)));
            $$('[data-prev]').forEach(b => b.addEventListener('click', () => goTo(step - 1)));
            window.addEventListener('popstate', () => {
                const m = location.hash.match(/langkah-(\d)/);
                goTo(m ? +m[1] : 1, {
                    push: false
                });
            });
            form.addEventListener('keydown', e => {
                if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'submit') {
                    e.preventDefault();
                    if (step < 3) goTo(step + 1);
                }
            });

            /* ---------- Kartu pertanyaan (dilewati saat terkunci) ---------- */
            function setType(card, type) {
                $('[data-f=type]', card).value = type;
                $$('[data-type]', card).forEach(b => b.setAttribute('aria-pressed', String(b.dataset.type === type)));
                $('[data-wrap=options]', card).hidden = type !== 'choice';
                refreshCard(card);
            }

            function refreshCard(card) {
                const text = $('[data-f=text]', card).value.trim();
                const type = $('[data-f=type]', card).value;
                $('[data-title]', card).textContent = text || 'Pertanyaan baru';
                $('[data-meta]', card).textContent =
                    `${TYPES[type]} · ${$('[data-f=category]', card).value.trim() || 'tanpa kategori'} · ${$('[data-f=required]', card).checked ? 'Wajib' : 'Opsional'}`;
            }

            function addQuestion(q = {}, {
                after = null,
                open = true
            } = {}) {
                if ($$('.sw-q', list).length >= MAX) {
                    showAlert(`Maksimal ${MAX} pertanyaan.`);
                    return null;
                }
                const card = tpl.content.firstElementChild.cloneNode(true);
                $('[data-f=text]', card).value = q.text ?? '';
                $('[data-f=category]', card).value = q.category ?? 'Kualitas layanan';
                $('[data-f=options]', card).value = toOptions(q.options).join('\n');
                $('[data-f=required]', card).checked = q.required === undefined ? true : [true, 1, '1', 'on'].includes(q
                    .required);
                card.classList.toggle('is-collapsed', !open);
                after ? after.after(card) : list.appendChild(card);
                setType(card, TYPES[q.type] ? q.type : 'rating');
                renumber();
                changed();
                return card;
            }

            function renumber() {
                if (locked) return;
                const cards = $$('.sw-q', list);
                cards.forEach((c, i) => {
                    $('[data-no]', c).textContent = i + 1;
                    $$('[data-f]', c).forEach(el => el.name = `questions[${i}][${el.dataset.f}]`);
                    $('[data-h=required]', c).name = `questions[${i}][required]`;
                    $('[data-act=up]', c).disabled = i === 0;
                    $('[data-act=down]', c).disabled = i === cards.length - 1;
                });
                $('#sw-empty').hidden = cards.length > 0;
                $('#sw-count-label').textContent = `${cards.length} pertanyaan`;
            }

            if (!locked) {
                list.addEventListener('click', e => {
                    const btn = e.target.closest('[data-act],[data-type]');
                    if (!btn) return;
                    const card = btn.closest('.sw-q');
                    if (btn.dataset.type) return setType(card, btn.dataset.type), changed();
                    switch (btn.dataset.act) {
                        case 'toggle':
                            card.classList.toggle('is-collapsed');
                            break;
                        case 'up':
                            card.previousElementSibling?.before(card);
                            break;
                        case 'down':
                            card.nextElementSibling?.after(card);
                            break;
                        case 'dup':
                            addQuestion(readCard(card), {
                                after: card
                            });
                            return;
                        case 'del':
                            if (!$('[data-f=text]', card).value.trim() || confirm('Hapus pertanyaan ini?')) card
                                .remove();
                            break;
                    }
                    renumber();
                    changed();
                });
                list.addEventListener('input', e => {
                    const c = e.target.closest('.sw-q');
                    if (c) refreshCard(c);
                });
                $('#sw-add').addEventListener('click', () => {
                    $$('.sw-q', list).forEach(c => c.classList.add('is-collapsed'));
                    const c = addQuestion({});
                    if (c) {
                        $('[data-f=text]', c).focus();
                        c.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }
                });
                $('#sw-bank-select').addEventListener('change', e => {
                    const q = bank.find(b => String(b.id) === e.target.value);
                    if (q) {
                        $$('.sw-q', list).forEach(c => c.classList.add('is-collapsed'));
                        addQuestion(q);
                    }
                    e.target.value = '';
                });
            }

            /* ---------- Durasi cepat ---------- */
            $$('[data-days]').forEach(b => b.addEventListener('click', () => {
                const s = $('#sw-start');
                if (!s.value) s.value = new Date().toISOString().slice(0, 10);
                const d = new Date(s.value + 'T00:00');
                d.setDate(d.getDate() + +b.dataset.days);
                $('#sw-end').value = [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d
                    .getDate()).padStart(2, '0')].join('-');
                changed();
            }));

            /* ---------- Data, pratinjau & tinjau ---------- */
            function readCard(c) {
                return {
                    text: $('[data-f=text]', c).value,
                    category: $('[data-f=category]', c).value,
                    type: $('[data-f=type]', c).value,
                    required: $('[data-f=required]', c).checked,
                    options: toOptions($('[data-f=options]', c).value)
                };
            }
            const questions = () => locked ? initial.map(q => ({
                ...q,
                options: toOptions(q.options)
            })) : $$('.sw-q', list).map(readCard);
            const el = (tag, cls, text) => {
                const n = document.createElement(tag);
                if (cls) n.className = cls;
                if (text !== undefined) n.textContent = text;
                return n;
            };

            function renderPreview() {
                const box = $('#sw-preview');
                box.replaceChildren();
                box.append(el('h4', '', $('#sw-title').value.trim() || 'Judul kuesioner'));
                box.append(el('p', '', $('#sw-desc').value.trim() || 'Pengantar akan tampil di sini.'));
                questions().forEach((q, i) => {
                    const w = el('div', 'sw-pv-q');
                    w.append(el('b', '',
                        `${i + 1}. ${q.text.trim() || 'Pertanyaan baru'}${q.required ? ' *' : ''}`));
                    if (q.type === 'rating') {
                        const d = el('div', 'sw-pv-dots');
                        [1, 2, 3, 4, 5].forEach(n => d.append(el('i', '', n)));
                        w.append(d);
                    } else if (q.type === 'choice')(q.options.length ? q.options : ['Pilihan 1', 'Pilihan 2'])
                        .forEach(o => w.append(el('span', 'sw-pv-opt', '○ ' + o)));
                    else w.append(el('div', 'sw-pv-txt'));
                    box.append(w);
                });
            }

            function renderReview() {
                const info = $('#sw-review-info'),
                    st = $('input[name=status]:checked')?.nextElementSibling?.querySelector('b')?.textContent;
                info.replaceChildren();
                [
                    ['Judul', $('#sw-title').value.trim()],
                    ['Periode', `${fmtDate($('#sw-start').value)} – ${fmtDate($('#sw-end').value)}`],
                    ['Status', st || '—'],
                    ['Template', $('input[type=checkbox][name=is_template]').checked ? 'Ya' : 'Tidak'],
                    ['Jumlah pertanyaan', String(questions().length)]
                ].forEach(([k, v]) => {
                    info.append(el('dt', '', k), el('dd', '', v));
                });
                const ol = $('#sw-review-list');
                ol.replaceChildren();
                questions().forEach(q => {
                    const li = el('li', '', q.text.trim());
                    li.append(el('span', 'sw-pill', TYPES[q.type]), el('span', 'sw-pill' + (q.required ?
                        ' req' : ''), q.required ? 'Wajib' : 'Opsional'));
                    ol.append(li);
                });
            }

            /* ---------- Autosave draf lokal (hanya untuk kuesioner baru) ---------- */
            function snapshot() {
                return {
                    title: $('#sw-title').value,
                    description: $('#sw-desc').value,
                    starts_at: $('#sw-start').value,
                    ends_at: $('#sw-end').value,
                    status: $('input[name=status]:checked')?.value,
                    is_template: $('input[type=checkbox][name=is_template]').checked,
                    questions: questions()
                };
            }

            function changed() {
                renderPreview();
                if (exists) return;
                clearTimeout(saveTimer);
                saveTimer = setTimeout(() => {
                    const s = snapshot();
                    try {
                        (s.title.trim() || s.questions.length > 1) ? localStorage.setItem(KEY, JSON.stringify(
                            s)): localStorage.removeItem(KEY);
                    } catch {}
                }, 500);
            }
            form.addEventListener('input', changed);
            form.addEventListener('change', changed);

            function restore(s) {
                $('#sw-title').value = s.title || '';
                $('#sw-desc').value = s.description || '';
                $('#sw-start').value = s.starts_at || $('#sw-start').value;
                $('#sw-end').value = s.ends_at || '';
                const r = $(`input[name=status][value="${s.status}"]`);
                if (r) r.checked = true;
                $('input[type=checkbox][name=is_template]').checked = !!s.is_template;
                list.replaceChildren();
                (s.questions || []).forEach(q => addQuestion(q, {
                    open: false
                }));
                changed();
            }

            /* ---------- Submit ---------- */
            form.addEventListener('submit', e => {
                if (!validateStep1()) {
                    e.preventDefault();
                    return goTo(1);
                }
                if (!validateStep2()) {
                    e.preventDefault();
                    return goTo(2);
                }
                renumber();
                try {
                    if (!exists) localStorage.removeItem(KEY);
                } catch {}
                const b = $('#sw-submit');
                b.disabled = true;
                b.replaceChildren(el('span', 'sw-spin'), document.createTextNode(' Menyimpan…'));
            });

            /* ---------- Init ---------- */
            if (locked) {
                $('#sw-count-label').textContent = `${initial.length} pertanyaan (dikunci)`;
            } else if (initial.length) {
                initial.forEach((q, i) => addQuestion(q, {
                    open: i === 0
                }));
            } else {
                addQuestion({
                    text: 'Seberapa puas Anda terhadap kualitas layanan kami?',
                    category: 'Kualitas layanan',
                    type: 'rating',
                    required: true
                }, {
                    open: false
                });
            }

            if (!exists && !locked && !$('#sw-title').value && initial.length === 0) {
                try {
                    const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
                    if (saved && (saved.title || (saved.questions || []).length > 1)) {
                        const bn = $('#sw-draft-banner');
                        bn.hidden = false;
                        $('#sw-draft-restore').onclick = () => {
                            restore(saved);
                            bn.hidden = true;
                        };
                        $('#sw-draft-discard').onclick = () => {
                            localStorage.removeItem(KEY);
                            bn.hidden = true;
                        };
                    }
                } catch {}
            }

            const m = location.hash.match(/langkah-(\d)/);
            const start = +root.dataset.start > 1 ? +root.dataset.start : (m ? +m[1] : 1);
            maxStep = exists ? 3 : 1;
            goTo(1, {
                push: false
            });
            if (start > 1) goTo(start, {
                push: false
            });
            renderPreview();
        })();
    </script>
@endpush

@extends('layouts.app')

@section('title', 'Editor Kuesioner')
@section('heading', $survey->exists ? 'Edit kuesioner' : 'Buat kuesioner baru')
@section('subtitle', 'Tiga langkah singkat, tanpa pindah halaman.')

@section('actions')
    @if ($survey->exists)
        <a class="btn" href="{{ route('surveys.preview', $survey) }}" target="_blank" rel="noopener">
            Pratinjau tersimpan ↗
        </a>
    @endif
    <a class="btn" href="{{ route('surveys.index') }}">← Kembali</a>
@endsection

@push('styles')
    <link rel="stylesheet"
          href="{{ asset('assets/survey-form.css') }}?v={{ filemtime(public_path('assets/survey-form.css')) }}">
@endpush

@section('content')
    @php
        $initial = old('questions', $survey->questions->map(fn ($q) => [
            'text'     => $q->text,
            'category' => $q->category,
            'type'     => $q->type,
            'required' => (bool) $q->required,
            'options'  => $q->options ?? [],
        ])->values()->all());

        $bankData = $bank->map(fn ($b) => [
            'id'       => $b->id,
            'text'     => $b->text,
            'category' => $b->category,
            'type'     => $b->type,
            'required' => (bool) $b->required,
            'options'  => $b->options ?? [],
        ])->values();

        $hasQuestionError = collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'questions'));
        $startStep = $errors->any() ? ($hasQuestionError ? 2 : 1) : 1;

        $statuses = [
            'draft'  => ['Draf', 'Bisa dipratinjau dulu'],
            'active' => ['Aktif', 'Langsung menerima respons'],
            'closed' => ['Ditutup', 'Tidak menerima respons'],
        ];

        $quickDays = [7 => '+7 hari', 14 => '+14 hari', 30 => '+30 hari', 90 => '+3 bulan'];
    @endphp

    <div class="sw"
         id="sw"
         data-start="{{ $startStep }}"
         data-exists="{{ $survey->exists ? 1 : 0 }}"
         data-locked="{{ $locked ? 1 : 0 }}">

        {{-- Tawaran memulihkan draf lokal --}}
        <div class="sw-draft notice" id="sw-draft-banner" hidden>
            <span>Ada draf yang belum disimpan dari sesi sebelumnya.</span>
            <span>
                <button type="button" class="btn primary" id="sw-draft-restore">Lanjutkan draf</button>
                <button type="button" class="btn" id="sw-draft-discard">Buang</button>
            </span>
        </div>

        {{-- Stepper --}}
        <nav class="sw-stepper" aria-label="Langkah kuesioner">
            <button type="button" class="sw-step" data-go="1">
                <i>1</i>
                <span>Informasi<small>Judul &amp; periode</small></span>
            </button>
            <button type="button" class="sw-step" data-go="2">
                <i>2</i>
                <span>Pertanyaan<small id="sw-count-label">0 pertanyaan</small></span>
            </button>
            <button type="button" class="sw-step" data-go="3">
                <i>3</i>
                <span>Tinjau<small>Cek &amp; simpan</small></span>
            </button>
        </nav>

        <div id="sw-alert" class="sw-alert" role="alert" hidden></div>

        <form id="sw-form"
              method="post"
              action="{{ $survey->exists ? route('surveys.update', $survey) : route('surveys.store') }}"
              novalidate>
            @csrf
            @if ($survey->exists)
                @method('put')
                <input type="hidden" name="version" value="{{ $survey->version }}">
            @endif

            <div class="sw-layout">
                <div>
                    {{-- LANGKAH 1: Informasi --}}
                    <section class="sw-panel" data-step="1">
                        <div class="sw-card">
                            <h2>Informasi kuesioner</h2>
                            <p class="sw-hint">Judul dan pengantar akan dilihat langsung oleh klien.</p>

                            <div class="sw-grid">
                                <label class="full">
                                    Judul
                                    <input name="title"
                                           id="sw-title"
                                           maxlength="255"
                                           required
                                           placeholder="cth. Survei Kepuasan Klien Q4 2026"
                                           value="{{ old('title', $survey->title) }}">
                                </label>

                                <label class="full">
                                    Pengantar <small>(opsional)</small>
                                    <textarea name="description"
                                              id="sw-desc"
                                              rows="3"
                                              maxlength="5000"
                                              placeholder="Ceritakan singkat tujuan survei ini…">{{ old('description', $survey->description) }}</textarea>
                                </label>

                                <label>
                                    Tanggal mulai
                                    <input type="date"
                                           name="starts_at"
                                           id="sw-start"
                                           required
                                           value="{{ old('starts_at', $survey->starts_at?->format('Y-m-d')) }}">
                                </label>

                                <label>
                                    Tanggal selesai
                                    <input type="date"
                                           name="ends_at"
                                           id="sw-end"
                                           required
                                           value="{{ old('ends_at', $survey->ends_at?->format('Y-m-d')) }}">
                                </label>
                            </div>

                            <div class="sw-chips" aria-label="Durasi cepat">
                                <span class="sw-chips-label">Durasi cepat:</span>
                                @foreach ($quickDays as $days => $label)
                                    <button type="button" class="sw-chip" data-days="{{ $days }}">{{ $label }}</button>
                                @endforeach
                            </div>

                            <p class="sw-label">Status publikasi</p>
                            <div class="sw-status">
                                @foreach ($statuses as $value => [$title, $note])
                                    <label>
                                        <input type="radio"
                                               name="status"
                                               value="{{ $value }}"
                                               @checked(old('status', $survey->status ?: 'draft') === $value)>
                                        <span><b>{{ $title }}</b><small>{{ $note }}</small></span>
                                    </label>
                                @endforeach
                            </div>

                            <label class="sw-switch">
                                <input type="hidden" name="is_template" value="0">
                                <input type="checkbox"
                                       name="is_template"
                                       value="1"
                                       @checked(old('is_template', $survey->is_template))>
                                Simpan sebagai template
                            </label>
                        </div>

                        <div class="sw-actions">
                            <span></span>
                            <button type="button" class="btn primary" data-next>Lanjut ke pertanyaan →</button>
                        </div>
                    </section>

                    {{-- LANGKAH 2: Pertanyaan --}}
                    <section class="sw-panel" data-step="2">
                        <div class="sw-card">
                            @if ($locked)
                                <h2>Pertanyaan</h2>
                                <div class="notice">
                                    Pertanyaan dikunci karena kuesioner sudah memiliki undangan.
                                    Gunakan Duplikat di daftar kuesioner untuk membuat versi baru.
                                </div>

                                @foreach ($survey->questions as $q)
                                    <div class="sw-locked-q">
                                        <span class="eyebrow">{{ $q->category }} / {{ $q->type }}</span>
                                        <strong>{{ $loop->iteration }}. {{ $q->text }}</strong>
                                        <span class="sw-pill {{ $q->required ? 'req' : '' }}">
                                            {{ $q->required ? 'Wajib' : 'Opsional' }}
                                        </span>
                                    </div>
                                @endforeach
                            @else
                                <h2>Susun pertanyaan</h2>
                                <p class="sw-hint">
                                    Klik kartu untuk membuka/menutup. Rating 1–3 otomatis wajib diberi komentar oleh responden.
                                </p>

                                <div class="sw-empty" id="sw-empty">
                                    Belum ada pertanyaan. Tambahkan yang pertama di bawah.
                                </div>
                                <div id="sw-list"></div>

                                <div class="sw-add">
                                    <button type="button" class="btn primary" id="sw-add">＋ Tambah pertanyaan</button>

                                    <select id="sw-bank-select" aria-label="Tambah dari bank pertanyaan">
                                        <option value="">Ambil dari bank pertanyaan…</option>
                                        @foreach ($bank as $b)
                                            <option value="{{ $b->id }}">
                                                {{ \Illuminate\Support\Str::limit($b->text, 80) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <datalist id="sw-cats">
                                    @foreach ($bank->pluck('category')->unique() as $category)
                                        <option value="{{ $category }}">
                                    @endforeach
                                </datalist>
                            @endif
                        </div>

                        <div class="sw-actions">
                            <button type="button" class="btn" data-prev>← Kembali</button>
                            <button type="button" class="btn primary" data-next>Tinjau →</button>
                        </div>
                    </section>

                    {{-- LANGKAH 3: Tinjau --}}
                    <section class="sw-panel" data-step="3">
                        <div class="sw-card sw-review">
                            <h2>Tinjau &amp; simpan</h2>
                            <p class="sw-hint">Periksa sekali lagi. Setelah undangan dibuat, pertanyaan akan dikunci.</p>

                            <dl id="sw-review-info"></dl>

                            <h3 class="sw-review-title">Pertanyaan</h3>
                            <ol id="sw-review-list"></ol>
                        </div>

                        <div class="sw-actions">
                            <button type="button" class="btn" data-prev>← Kembali</button>
                            <button type="submit" class="btn primary" id="sw-submit">
                                {{ $survey->exists ? 'Simpan perubahan' : 'Simpan kuesioner' }}
                            </button>
                        </div>
                    </section>
                </div>

                {{-- Pratinjau langsung --}}
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

        {{-- Template kartu pertanyaan --}}
        <template id="sw-q-tpl">
            <section class="sw-q">
                <div class="sw-q-head" data-act="toggle">
                    <span class="sw-q-no" data-no>1</span>

                    <div class="sw-q-title">
                        <span data-title></span>
                        <small data-meta></small>
                    </div>

                    <div class="sw-q-tools">
                        <button type="button" data-act="up" aria-label="Pindah ke atas" title="Naik">↑</button>
                        <button type="button" data-act="down" aria-label="Pindah ke bawah" title="Turun">↓</button>
                        <button type="button" data-act="dup" aria-label="Duplikat" title="Duplikat">⧉</button>
                        <button type="button" data-act="del" aria-label="Hapus" title="Hapus">✕</button>
                    </div>
                </div>

                <div class="sw-q-body">
                    <input type="hidden" data-f="type" value="rating">

                    <div class="sw-types" role="group" aria-label="Jenis pertanyaan">
                        <button type="button" data-type="rating">★ Rating 1–5</button>
                        <button type="button" data-type="choice">◉ Pilihan ganda</button>
                        <button type="button" data-type="text">¶ Teks terbuka</button>
                    </div>

                    <label>
                        Pertanyaan
                        <textarea data-f="text" rows="2" maxlength="2000" placeholder="Tulis pertanyaan…"></textarea>
                    </label>

                    <div class="sw-grid">
                        <label>
                            Kategori
                            <input data-f="category" list="sw-cats" maxlength="100" placeholder="cth. Kualitas layanan">
                        </label>

                        <label class="sw-switch sw-switch-inline">
                            <input type="hidden" data-h="required" value="0">
                            <input type="checkbox" data-f="required" value="1" checked>
                            Wajib diisi
                        </label>
                    </div>

                    <label data-wrap="options" hidden>
                        Pilihan jawaban <small>(satu per baris, minimal 2)</small>
                        <textarea data-f="options" rows="4" placeholder="Email&#10;Telepon"></textarea>
                    </label>
                </div>
            </section>
        </template>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/survey-form.js') }}?v={{ filemtime(public_path('assets/survey-form.js')) }}"></script>
@endpush
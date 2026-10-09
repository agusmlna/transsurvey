@extends('layouts.app')

@section('title', __('Editor Kuesioner'))
@section('heading', $survey->exists ? __('Edit kuesioner') : __('Buat kuesioner baru'))
@section('subtitle', __('Tiga langkah singkat, tanpa pindah halaman.'))

@section('actions')
    @if ($survey->exists)
        <a class="btn" href="{{ route('surveys.preview', $survey) }}" target="_blank" rel="noopener">
            {{ __('Pratinjau tersimpan ↗') }}
        </a>
    @endif
    <a class="btn" href="{{ route('surveys.index') }}">{{ __('← Kembali') }}</a>
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
            'draft'  => [__('Draf'), __('Bisa dipratinjau dulu')],
            'active' => [__('Aktif'), __('Langsung menerima respons')],
            'closed' => [__('Ditutup'), __('Tidak menerima respons')],
        ];

        $quickDays = [7 => __('+7 hari'), 14 => __('+14 hari'), 30 => __('+30 hari'), 90 => __('+3 bulan')];
    @endphp

    <div class="sw"
         id="sw"
         data-start="{{ $startStep }}"
         data-exists="{{ $survey->exists ? 1 : 0 }}"
         data-locked="{{ $locked ? 1 : 0 }}">

        {{-- Tawaran memulihkan draf lokal --}}
        <div class="sw-draft notice" id="sw-draft-banner" hidden>
            <span>{{ __('Ada draf yang belum disimpan dari sesi sebelumnya.') }}</span>
            <span>
                <button type="button" class="btn primary" id="sw-draft-restore">{{ __('Lanjutkan draf') }}</button>
                <button type="button" class="btn" id="sw-draft-discard">{{ __('Buang') }}</button>
            </span>
        </div>

        {{-- Stepper --}}
        <nav class="sw-stepper" aria-label="{{ __('Langkah kuesioner') }}">
            <button type="button" class="sw-step" data-go="1">
                <i>1</i>
                <span>{{ __('Informasi') }}<small>{{ __('Judul & periode') }}</small></span>
            </button>
            <button type="button" class="sw-step" data-go="2">
                <i>2</i>
                <span>{{ __('Pertanyaan') }}<small id="sw-count-label">{{ __('0 pertanyaan') }}</small></span>
            </button>
            <button type="button" class="sw-step" data-go="3">
                <i>3</i>
                <span>{{ __('Tinjau') }}<small>{{ __('Cek & simpan') }}</small></span>
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
                            <h2>{{ __('Informasi kuesioner') }}</h2>
                            <p class="sw-hint">{{ __('Judul dan pengantar akan dilihat langsung oleh klien.') }}</p>

                            <div class="sw-grid">
                                <label class="full">
                                    {{ __('Judul') }}
                                    <input name="title"
                                           id="sw-title"
                                           maxlength="255"
                                           required
                                           placeholder="{{ __('cth. Survei Kepuasan Klien Q4 2026') }}"
                                           value="{{ old('title', $survey->title) }}">
                                </label>

                                <label class="full">
                                    {{ __('Pengantar') }} <small>{{ __('(opsional)') }}</small>
                                    <textarea name="description"
                                              id="sw-desc"
                                              rows="3"
                                              maxlength="5000"
                                              placeholder="{{ __('Ceritakan singkat tujuan survei ini…') }}">{{ old('description', $survey->description) }}</textarea>
                                </label>

                                <label>
                                    {{ __('Tanggal mulai') }}
                                    <input type="date"
                                           name="starts_at"
                                           id="sw-start"
                                           required
                                           value="{{ old('starts_at', $survey->starts_at?->format('Y-m-d')) }}">
                                </label>

                                <label>
                                    {{ __('Tanggal selesai') }}
                                    <input type="date"
                                           name="ends_at"
                                           id="sw-end"
                                           required
                                           value="{{ old('ends_at', $survey->ends_at?->format('Y-m-d')) }}">
                                </label>
                            </div>

                            <div class="sw-chips" aria-label="{{ __('Durasi cepat') }}">
                                <span class="sw-chips-label">{{ __('Durasi cepat:') }}</span>
                                @foreach ($quickDays as $days => $label)
                                    <button type="button" class="sw-chip" data-days="{{ $days }}">{{ $label }}</button>
                                @endforeach
                            </div>

                            <p class="sw-label">{{ __('Status publikasi') }}</p>
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
                                {{ __('Simpan sebagai template') }}
                            </label>
                        </div>

                        <div class="sw-actions">
                            <span></span>
                            <button type="button" class="btn primary" data-next>{{ __('Lanjut ke pertanyaan →') }}</button>
                        </div>
                    </section>

                    {{-- LANGKAH 2: Pertanyaan --}}
                    <section class="sw-panel" data-step="2">
                        <div class="sw-card">
                            @if ($locked)
                                <h2>{{ __('Pertanyaan') }}</h2>
                                <div class="notice">
                                    {{ __('Pertanyaan dikunci karena kuesioner sudah memiliki undangan. Gunakan Duplikat di daftar kuesioner untuk membuat versi baru.') }}
                                </div>

                                @foreach ($survey->questions as $q)
                                    <div class="sw-locked-q">
                                        <span class="eyebrow">{{ $q->category }} / {{ $q->type }}</span>
                                        <strong>{{ $loop->iteration }}. {{ $q->text }}</strong>
                                        <span class="sw-pill {{ $q->required ? 'req' : '' }}">
                                            {{ $q->required ? __('Wajib') : __('Opsional') }}
                                        </span>
                                    </div>
                                @endforeach
                            @else
                                <h2>{{ __('Susun pertanyaan') }}</h2>
                                <p class="sw-hint">
                                    {{ __('Klik kartu untuk membuka/menutup. Rating 1–3 otomatis wajib diberi komentar oleh responden.') }}
                                </p>

                                <div class="sw-empty" id="sw-empty">
                                    {{ __('Belum ada pertanyaan. Tambahkan yang pertama di bawah.') }}
                                </div>
                                <div id="sw-list"></div>

                                <div class="sw-add">
                                    <button type="button" class="btn primary" id="sw-add">{{ __('＋ Tambah pertanyaan') }}</button>

                                    <select id="sw-bank-select" aria-label="{{ __('Tambah dari bank pertanyaan') }}">
                                        <option value="">{{ __('Ambil dari bank pertanyaan…') }}</option>
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
                            <button type="button" class="btn" data-prev>{{ __('← Kembali') }}</button>
                            <button type="button" class="btn primary" data-next>{{ __('Tinjau →') }}</button>
                        </div>
                    </section>

                    {{-- LANGKAH 3: Tinjau --}}
                    <section class="sw-panel" data-step="3">
                        <div class="sw-card sw-review">
                            <h2>{{ __('Tinjau & simpan') }}</h2>
                            <p class="sw-hint">{{ __('Periksa sekali lagi. Setelah undangan dibuat, pertanyaan akan dikunci.') }}</p>

                            <dl id="sw-review-info"></dl>

                            <h3 class="sw-review-title">{{ __('Pertanyaan') }}</h3>
                            <ol id="sw-review-list"></ol>
                        </div>

                        <div class="sw-actions">
                            <button type="button" class="btn" data-prev>{{ __('← Kembali') }}</button>
                            <button type="submit" class="btn primary" id="sw-submit">
                                {{ $survey->exists ? __('Simpan perubahan') : __('Simpan kuesioner') }}
                            </button>
                        </div>
                    </section>
                </div>

                {{-- Pratinjau langsung --}}
                <aside class="sw-aside" aria-label="{{ __('Pratinjau langsung') }}">
                    <div class="sw-card">
                        <h3>{{ __('Pratinjau langsung') }}</h3>
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
                        <button type="button" data-act="up" aria-label="{{ __('Pindah ke atas') }}" title="{{ __('Naik') }}">↑</button>
                        <button type="button" data-act="down" aria-label="{{ __('Pindah ke bawah') }}" title="{{ __('Turun') }}">↓</button>
                        <button type="button" data-act="dup" aria-label="{{ __('Duplikat') }}" title="{{ __('Duplikat') }}">⧉</button>
                        <button type="button" data-act="del" aria-label="{{ __('Hapus') }}" title="{{ __('Hapus') }}">✕</button>
                    </div>
                </div>

                <div class="sw-q-body">
                    <input type="hidden" data-f="type" value="rating">

                    <div class="sw-types" role="group" aria-label="{{ __('Jenis pertanyaan') }}">
                        <button type="button" data-type="rating">★ Rating 1–5</button>
                        <button type="button" data-type="choice">{{ __('◉ Pilihan ganda') }}</button>
                        <button type="button" data-type="text">{{ __('¶ Teks terbuka') }}</button>
                    </div>

                    <label>
                        {{ __('Pertanyaan') }}
                        <textarea data-f="text" rows="2" maxlength="2000" placeholder="{{ __('Tulis pertanyaan…') }}"></textarea>
                    </label>

                    <div class="sw-grid">
                        <label>
                            {{ __('Kategori') }}
                            <input data-f="category" list="sw-cats" maxlength="100" placeholder="{{ __('cth. Kualitas layanan') }}">
                        </label>

                        <label class="sw-switch sw-switch-inline">
                            <input type="hidden" data-h="required" value="0">
                            <input type="checkbox" data-f="required" value="1" checked>
                            {{ __('Wajib diisi') }}
                        </label>
                    </div>

                    <label data-wrap="options" hidden>
                        {{ __('Pilihan jawaban') }} <small>{{ __('(satu per baris, minimal 2)') }}</small>
                        <textarea data-f="options" rows="4" placeholder="{{ __('Email
Telepon') }}"></textarea>
                    </label>
                </div>
            </section>
        </template>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/survey-form.js') }}?v={{ filemtime(public_path('assets/survey-form.js')) }}"></script>
@endpush
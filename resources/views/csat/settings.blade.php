@extends('layouts.app')

@section('title', __('Pengaturan CSAT'))
@section('heading', __('Pengaturan CSAT'))
@section('subtitle', __('Atur metode kirim, status tiket, kanal, dan masa berlaku tautan CSAT per workspace.'))

@section('actions')
    <a class="btn" href="{{ route('csat.templates.index') }}">{{ __('← Kembali ke template') }}</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/csat.css') }}?v={{ filemtime(public_path('assets/csat.css')) }}">
@endpush

@section('content')
    @php
        $modeLabels = ['manual' => __('Manual dari tiket'), 'auto' => __('Otomatis saat tiket berstatus'), 'both' => __('Manual + otomatis')];
        $modeDescriptions = [
            'manual' => __('Kirim dari halaman Kirim CSAT. Tidak ada pengiriman otomatis.'),
            'auto' => __('Kirim sendiri saat tiket masuk ke status yang dipilih.'),
            'both' => __('Bisa dikirim manual, dan tetap ikut jadwal otomatis.'),
        ];
        $channelLabels = ['email' => __('Email'), 'whatsapp' => __('WhatsApp')];
        $oldChannels = array_values(array_filter((array) old('auto_channels', $autoChannels), 'is_string'));
        $unitLabels = ['minutes' => __('menit'), 'hours' => __('jam'), 'days' => __('hari')];
        $initialPreview =
            $expiryUnit === 'unlimited'
                ? __('Tanpa batas waktu.')
                : __(
                    'Berlaku selama :value :unit sejak pesan terkirim.',
                    ['value' => $expiryValue, 'unit' => $unitLabels[$expiryUnit] ?? $expiryUnit],
                );
        $initialChannels = collect($oldChannels)
            ->filter()
            ->map(fn (string $channel): string => $channelLabels[$channel] ?? $channel)
            ->implode(', ');
        $initialStatuses = trim((string) old('auto_statuses', $autoStatuses));
        $statusSuggestions = ['Solved', 'Close', 'Resolved', 'Completed', 'Open', 'Pending'];
        $autosendSummary = ! $serverAutosend
            ? __('Nonaktif di server')
            : ($autosend ? __('Aktif') : __('Nonaktif'));
    @endphp

    <section class="csat-settings-page">
        <div class="panel csat-settings-hero">
            <div class="csat-settings-hero-main">
                <span class="csat-eyebrow">{{ __('Workspace CSAT') }}</span>
                <h2>{{ $workspace->name }}</h2>
                <p>{{ __('Pengaturan ini berlaku untuk pengiriman manual dan otomatis di workspace ini.') }}</p>
            </div>
            <div class="csat-settings-badges" aria-label="{{ __('Status konfigurasi') }}">
                <span class="badge blue">{{ $workspace->name }}</span>
                @if ($serverAutosend)
                    <span class="badge green">{{ __('Autosend server aktif') }}</span>
                @else
                    <span class="badge amber">{{ __('Autosend server nonaktif') }}</span>
                @endif
                @if ($emailOnly)
                    <span class="badge gray">{{ __('Email-only') }}</span>
                @endif
                @if ($disabled)
                    <span class="badge red">{{ __('Dinonaktifkan') }}</span>
                @endif
            </div>
        </div>

        @if ($disabled)
            <div class="notice csat-settings-notice" role="status">{{ __('Workspace ini dinonaktifkan untuk CSAT TransCXP lewat CXP_CSAT_DISABLED_PROJECTS. Pengiriman manual dan otomatis tetap diblokir di server.') }}</div>
        @endif
        @if ($emailOnly)
            <div class="notice csat-settings-notice" role="status">{{ __('Workspace ini hanya memakai email sesuai CXP_CSAT_EMAIL_ONLY_PROJECTS. Pilihan WhatsApp tidak tersedia.') }}</div>
        @endif

        <form method="post" action="{{ route('csat.settings.update') }}" class="csat-settings-form csat-settings-page-form" data-csat-settings>
            @csrf
            @method('put')

            <div class="csat-settings-layout">
                <div class="csat-settings-main">
                    <section class="panel csat-settings-card">
                        <div class="csat-card-head">
                            <div>
                                <span class="csat-eyebrow">{{ __('Tautan') }}</span>
                                <h3>{{ __('Masa berlaku tautan') }}</h3>
                                <p>{{ __('Dihitung sejak pesan CSAT berhasil terkirim ke customer.') }}</p>
                            </div>
                            <span class="csat-card-icon" aria-hidden="true">⏱</span>
                        </div>

                        <div class="csat-card-body">
                            <p class="csat-field-label">{{ __('Pilihan cepat') }}</p>
                            <div class="csat-presets" role="group" aria-label="{{ __('Pilihan cepat masa berlaku') }}">
                                @foreach ([['value' => 30, 'unit' => 'minutes'], ['value' => 1, 'unit' => 'hours'], ['value' => 24, 'unit' => 'hours'], ['value' => 7, 'unit' => 'days'], ['value' => null, 'unit' => 'unlimited']] as $preset)
                                    <button type="button" class="csat-chip" data-preset-value="{{ $preset['value'] }}"
                                        data-preset-unit="{{ $preset['unit'] }}">
                                        @if ($preset['unit'] === 'unlimited')
                                            {{ __('Tanpa batas waktu') }}
                                        @else
                                            {{ $preset['value'] }} {{ $unitLabels[$preset['unit']] }}
                                        @endif
                                    </button>
                                @endforeach
                            </div>

                            <div class="form-grid csat-expiry-grid">
                                <label>
                                    {{ __('Berlaku selama') }}
                                    <input type="number" name="expiry_value" min="1" max="525600"
                                        value="{{ old('expiry_value', $expiryValue) }}" data-csat-expiry-value
                                        @disabled(old('expiry_unit', $expiryUnit) === 'unlimited')>
                                    @error('expiry_value')
                                        <small class="form-error">{{ $message }}</small>
                                    @enderror
                                </label>

                                <label>
                                    {{ __('Satuan waktu') }}
                                    <select name="expiry_unit" data-csat-expiry-unit>
                                        @foreach (['minutes' => __('Menit'), 'hours' => __('Jam'), 'days' => __('Hari'), 'unlimited' => __('Tanpa batas waktu')] as $value => $label)
                                            <option value="{{ $value }}" @selected(old('expiry_unit', $expiryUnit) === $value)>
                                                {{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('expiry_unit')
                                        <small class="form-error">{{ $message }}</small>
                                    @enderror
                                </label>
                            </div>

                            <div class="csat-preview" aria-live="polite">
                                <span class="csat-preview-icon" aria-hidden="true">⏱</span>
                                <p>
                                    <strong>{{ __('Tautan berlaku') }}</strong>
                                    <span data-csat-preview-text>{{ $initialPreview }}</span>
                                </p>
                            </div>
                        </div>
                    </section>

                    <section class="panel csat-settings-card">
                        <div class="csat-card-head">
                            <div>
                                <span class="csat-eyebrow">{{ __('Pemicu') }}</span>
                                <h3>{{ __('Metode pengiriman CSAT') }}</h3>
                                <p>{{ __('Pilih kapan CSAT boleh dikirim dan lewat kanal apa.') }}</p>
                            </div>
                        </div>

                        <div class="csat-card-body">
                            <div class="csat-mode-grid" role="radiogroup" aria-label="{{ __('Metode pengiriman CSAT') }}">
                                @foreach ($modeLabels as $value => $label)
                                    <label class="csat-mode-card @if (old('send_mode', $sendMode) === $value) is-selected @endif">
                                        <input type="radio" name="send_mode" value="{{ $value }}"
                                            @checked(old('send_mode', $sendMode) === $value) data-csat-mode>
                                        <span class="csat-mode-copy">
                                            <strong>{{ $label }}</strong>
                                            <small>{{ $modeDescriptions[$value] }}</small>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @error('send_mode')
                                <small class="form-error">{{ $message }}</small>
                            @enderror

                            <div class="csat-auto-fields" data-csat-auto-fields>
                                <div class="csat-subsection">
                                    <div class="csat-subsection-head">
                                        <h4>{{ __('Status tiket otomatis') }}</h4>
                                        <p>{{ __('Status tiket dibaca persis dari TransCXP. Pisahkan beberapa status dengan koma.') }}</p>
                                    </div>

                                    <label class="csat-field">
                                        <span>{{ __('Status yang dipantau') }}</span>
                                        <input type="text" name="auto_statuses" list="csat-status-options"
                                            value="{{ $initialStatuses }}" placeholder="Solved, Close"
                                            data-csat-status-input>
                                    </label>
                                    <datalist id="csat-status-options">
                                        @foreach ($statusSuggestions as $status)
                                            <option value="{{ $status }}"></option>
                                        @endforeach
                                    </datalist>

                                    <div class="csat-status-suggestions" aria-label="{{ __('Saran status') }}">
                                        @foreach ($statusSuggestions as $status)
                                            <button type="button" class="csat-chip csat-chip-sm"
                                                data-csat-status-suggestion="{{ $status }}">{{ $status }}</button>
                                        @endforeach
                                    </div>

                                    <div class="csat-status-tags" data-csat-status-tags aria-live="polite"></div>

                                    @error('auto_statuses')
                                        <small class="form-error">{{ $message }}</small>
                                    @enderror
                                    <small class="form-help">{{ __('Contoh: DKT memakai Solved, Trakindo memakai Close. Status harus sama persis dengan TransCXP.') }}</small>
                                </div>

                                <fieldset class="csat-channel-grid">
                                    <legend>{{ __('Kanal pengiriman') }}</legend>
                                    <input type="hidden" name="auto_channels[]" value="">
                                    @foreach ($channelLabels as $value => $label)
                                        <label class="csat-channel-card @if ($emailOnly && $value === 'whatsapp') is-disabled @endif">
                                            <input type="checkbox" name="auto_channels[]" value="{{ $value }}"
                                                @checked(in_array($value, $oldChannels, true))
                                                @disabled($emailOnly && $value === 'whatsapp')
                                                data-csat-channel
                                                @if ($emailOnly && $value === 'whatsapp') data-csat-locked="1" @endif>
                                            <span class="csat-channel-copy">
                                                <strong>{{ $label }}</strong>
                                                <small>
                                                    @if ($value === 'email')
                                                        {{ __('Tautan dikirim lewat email customer.') }}
                                                    @else
                                                        {{ __('Tautan dikirim lewat WhatsApp TransCXP.') }}
                                                    @endif
                                                </small>
                                            </span>
                                        </label>
                                    @endforeach
                                </fieldset>
                                @error('auto_channels')
                                    <small class="form-error">{{ $message }}</small>
                                @enderror
                                <small class="form-help">{{ __('Source tiket TransCXP tetap dipertimbangkan; kanal di sini membatasi pilihan yang boleh dipakai workspace ini.') }}</small>
                            </div>
                        </div>
                    </section>

                    <section class="panel csat-settings-card csat-autosend-card">
                        <div class="csat-card-head">
                            <div>
                                <span class="csat-eyebrow">{{ __('Jadwal') }}</span>
                                <h3>{{ __('Autosend workspace') }}</h3>
                                <p>{{ __('Izin ini hanya berlaku bila metode Auto/Keduanya dipilih dan saklar server aktif.') }}</p>
                            </div>
                        </div>

                        <div class="csat-card-body">
                            <div class="notice {{ $serverAutosend ? 'success' : '' }} csat-server-notice" role="status">
                                @if ($serverAutosend)
                                    {{ __('CXP_CSAT_AUTOSEND aktif di server. Autosend bisa berjalan sesuai jadwal.') }}
                                @else
                                    {{ __('CXP_CSAT_AUTOSEND masih nonaktif di server. Centang di bawah tidak akan mengirim apa pun sampai saklar server dinyalakan.') }}
                                @endif
                            </div>

                            <label class="csat-switch-row">
                                <input type="hidden" name="autosend" value="0">
                                <input type="checkbox" name="autosend" value="1"
                                    @checked(old('autosend', $autosend)) data-csat-autosend>
                                <span class="csat-switch" aria-hidden="true"></span>
                                <span class="csat-switch-copy">
                                    <strong>{{ __('Izinkan autosend untuk workspace ini') }}</strong>
                                    <small>{{ __('Jadwal hanya berjalan bila metode Auto/Keduanya dipilih dan CXP_CSAT_AUTOSEND aktif di server.') }}</small>
                                </span>
                            </label>
                        </div>
                    </section>
                </div>

                <aside class="csat-settings-aside">
                    <section class="panel csat-summary">
                        <div class="csat-summary-head">
                            <span class="csat-eyebrow">{{ __('Ringkasan') }}</span>
                            <h3>{{ __('Konfigurasi aktif') }}</h3>
                        </div>
                        <dl class="csat-summary-list">
                            <div>
                                <dt>{{ __('Metode') }}</dt>
                                <dd data-csat-summary-mode>{{ $modeLabels[old('send_mode', $sendMode)] ?? $sendMode }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('Status') }}</dt>
                                <dd data-csat-summary-status>{{ $initialStatuses !== '' ? $initialStatuses : __('Belum diisi') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('Kanal') }}</dt>
                                <dd data-csat-summary-channel>{{ $initialChannels !== '' ? $initialChannels : __('Belum dipilih') }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('Tautan') }}</dt>
                                <dd data-csat-summary-expiry>{{ $initialPreview }}</dd>
                            </div>
                            <div>
                                <dt>{{ __('Autosend') }}</dt>
                                <dd data-csat-summary-autosend>{{ $autosendSummary }}</dd>
                            </div>
                        </dl>
                        @unless ($disabled)
                            <a class="btn full" href="{{ route('csat.send.index') }}">{{ __('Test kirim CSAT') }}</a>
                        @endunless
                    </section>

                    <section class="panel csat-tips">
                        <h3>{{ __('Tips singkat') }}</h3>
                        <ul>
                            <li>{{ __('Cocokkan status dengan nilai asli di TransCXP, termasuk huruf besar/kecil.') }}</li>
                            <li>{{ __('Untuk WhatsApp, pastikan APP_URL sudah memakai HTTPS publik agar tautan tidak ditolak.') }}</li>
                            <li>{{ __('Autosend server tetap menjadi saklar utama. Pengaturan workspace tidak bisa mengaktifkannya sendiri.') }}</li>
                        </ul>
                    </section>
                </aside>
            </div>

            <div class="csat-settings-actions">
                <p class="form-help">{{ __('Perubahan berlaku setelah disimpan.') }}</p>
                <div class="csat-settings-action-buttons">
                    <a class="btn" href="{{ route('csat.templates.index') }}">{{ __('Batal') }}</a>
                    <button class="btn primary">{{ __('Simpan pengaturan') }}</button>
                </div>
            </div>
        </form>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('[data-csat-settings]');
            if (!form) return;

            const i18n = window.TransSurveyI18n;
            const unit = form.querySelector('[data-csat-expiry-unit]');
            const value = form.querySelector('[data-csat-expiry-value]');
            const preview = form.querySelector('[data-csat-preview-text]');
            const presets = [...form.querySelectorAll('[data-preset-unit]')];
            const unitLabels = @json($unitLabels);

            const modeInputs = [...form.querySelectorAll('[data-csat-mode]')];
            const modeCards = [...form.querySelectorAll('.csat-mode-card')];
            const autoFields = form.querySelector('[data-csat-auto-fields]');
            const statusInput = form.querySelector('[data-csat-status-input]');
            const statusTags = form.querySelector('[data-csat-status-tags]');
            const statusSuggestions = [...form.querySelectorAll('[data-csat-status-suggestion]')];
            const channelInputs = [...form.querySelectorAll('[data-csat-channel]')];
            const autosendInput = form.querySelector('[data-csat-autosend]');
            const summaryMode = form.querySelector('[data-csat-summary-mode]');
            const summaryStatus = form.querySelector('[data-csat-summary-status]');
            const summaryChannel = form.querySelector('[data-csat-summary-channel]');
            const summaryExpiry = form.querySelector('[data-csat-summary-expiry]');
            const summaryAutosend = form.querySelector('[data-csat-summary-autosend]');
            const modeLabels = @json($modeLabels);
            const channelLabels = @json($channelLabels);
            const serverAutosend = @json($serverAutosend);

            const selectedMode = () => modeInputs.find((input) => input.checked)?.value || 'manual';
            const isAuto = () => selectedMode() !== 'manual';
            const currentStatuses = () => {
                const parts = String(statusInput?.value || '')
                    .split(/[,;\n]+/)
                    .map((status) => status.trim())
                    .filter(Boolean);

                return [...new Set(parts)];
            };
            const selectedChannels = () => channelInputs
                .filter((input) => input.checked)
                .map((input) => channelLabels[input.value] || input.value);

            const syncExpiryPreview = () => {
                const unlimited = unit.value === 'unlimited';
                value.disabled = unlimited;
                value.required = !unlimited;

                const text = unlimited
                    ? i18n.t('Tanpa batas waktu.')
                    : i18n.t('Berlaku selama :value :unit sejak pesan terkirim.', {
                        value: value.value || '…',
                        unit: unitLabels[unit.value] || unit.value,
                    });

                preview.textContent = text;
                if (summaryExpiry) summaryExpiry.textContent = text;

                presets.forEach((chip) => {
                    chip.classList.toggle('is-active', chip.dataset.presetUnit === unit.value &&
                        (unlimited || chip.dataset.presetValue === String(value.value)));
                });
            };

            const renderStatusTags = () => {
                const statuses = currentStatuses();
                statusTags.replaceChildren();

                statuses.forEach((status) => {
                    const tag = document.createElement('span');
                    tag.className = 'csat-status-tag';
                    tag.append(document.createTextNode(status));

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'csat-status-remove';
                    remove.setAttribute('aria-label', i18n.t('Hapus status :status', { status }));
                    remove.textContent = '×';
                    remove.addEventListener('click', () => {
                        statusInput.value = statuses.filter((item) => item !== status).join(', ');
                        renderStatusTags();
                        syncSummary();
                    });

                    tag.append(remove);
                    statusTags.append(tag);
                });

                statusInput.placeholder = statuses.length === 0 ? 'Solved, Close' : '';
            };

            const syncModeState = () => {
                const auto = isAuto();
                autoFields.hidden = !auto;

                autoFields.querySelectorAll('input, select, button').forEach((control) => {
                    const locked = control.dataset.csatLocked === '1';
                    control.disabled = !auto || locked;
                });

                if (statusInput) statusInput.required = auto;
                if (autosendInput) autosendInput.disabled = !auto;

                modeCards.forEach((card) => {
                    card.classList.toggle('is-selected', card.querySelector('input')?.checked === true);
                });

                syncSummary();
            };

            const syncSummary = () => {
                const auto = isAuto();

                if (summaryMode) summaryMode.textContent = modeLabels[selectedMode()] || selectedMode();
                if (summaryStatus) {
                    summaryStatus.textContent = auto
                        ? (currentStatuses().join(', ') || i18n.t('Belum diisi'))
                        : i18n.t('Tidak dipakai');
                }
                if (summaryChannel) {
                    const channels = selectedChannels();
                    summaryChannel.textContent = auto
                        ? (channels.length > 0 ? channels.join(', ') : i18n.t('Belum dipilih'))
                        : i18n.t('Tidak dipakai');
                }
                if (summaryAutosend) {
                    summaryAutosend.textContent = !serverAutosend
                        ? i18n.t('Nonaktif di server')
                        : (auto && autosendInput?.checked ? i18n.t('Aktif') : i18n.t('Nonaktif'));
                }
            };

            presets.forEach((chip) => {
                chip.addEventListener('click', () => {
                    unit.value = chip.dataset.presetUnit;
                    if (chip.dataset.presetUnit !== 'unlimited') {
                        value.value = chip.dataset.presetValue;
                    }
                    syncExpiryPreview();
                });
            });

            statusSuggestions.forEach((button) => {
                button.addEventListener('click', () => {
                    const status = button.dataset.csatStatusSuggestion;
                    const statuses = currentStatuses();

                    if (status && !statuses.includes(status)) {
                        statuses.push(status);
                        statusInput.value = statuses.join(', ');
                    }

                    renderStatusTags();
                    syncSummary();
                });
            });

            modeInputs.forEach((input) => input.addEventListener('change', syncModeState));
            channelInputs.forEach((input) => input.addEventListener('change', syncSummary));
            autosendInput?.addEventListener('change', syncSummary);
            unit.addEventListener('change', syncExpiryPreview);
            value.addEventListener('input', syncExpiryPreview);
            statusInput?.addEventListener('input', () => {
                renderStatusTags();
                syncSummary();
            });

            syncExpiryPreview();
            renderStatusTags();
            syncModeState();
        })();
    </script>
@endpush

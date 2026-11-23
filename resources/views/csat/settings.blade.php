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
        $channelLabels = ['email' => __('Email'), 'whatsapp' => __('WhatsApp')];
        $oldChannels = old('auto_channels', $autoChannels);
        $unitLabels = ['minutes' => __('menit'), 'hours' => __('jam'), 'days' => __('hari')];
        $initialPreview =
            $expiryUnit === 'unlimited'
                ? __('Tanpa batas waktu.')
                : __(
                    'Berlaku selama :value :unit sejak pesan terkirim.',
                    ['value' => $expiryValue, 'unit' => $unitLabels[$expiryUnit] ?? $expiryUnit],
                );
    @endphp

    <section class="panel csat-settings">
        <div class="panel-heading">
            <div>
                <h2>{{ __('Masa berlaku tautan') }}</h2>
                <p>{{ __('Dihitung sejak pesan CSAT berhasil terkirim ke customer.') }}</p>
            </div>
            <span class="badge blue">{{ $workspace->name }}</span>
        </div>

        @if ($disabled)
            <div class="notice" role="status">{{ __('Workspace ini dinonaktifkan untuk CSAT TransCXP lewat CXP_CSAT_DISABLED_PROJECTS. Pengiriman manual dan otomatis tetap diblokir di server.') }}</div>
        @endif
        @if ($emailOnly)
            <div class="notice" role="status">{{ __('Workspace ini hanya memakai email sesuai CXP_CSAT_EMAIL_ONLY_PROJECTS. Pilihan WhatsApp tidak tersedia.') }}</div>
        @endif

        <form method="post" action="{{ route('csat.settings.update') }}" class="csat-settings-form" data-csat-expiry>
            @csrf
            @method('put')

            <p class="csat-eyebrow">{{ __('Pilihan cepat') }}</p>
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

            <div class="form-grid">
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

            <div class="csat-method-section">
                <p class="csat-eyebrow">{{ __('Metode pengiriman CSAT') }}</p>
                <div class="form-grid">
                    <label>
                        {{ __('Metode') }}
                        <select name="send_mode">
                            @foreach ($modeLabels as $value => $label)
                                <option value="{{ $value }}" @selected(old('send_mode', $sendMode) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('send_mode')
                            <small class="form-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label>
                        {{ __('Status tiket otomatis') }}
                        <input type="text" name="auto_statuses" list="csat-status-options"
                            value="{{ old('auto_statuses', $autoStatuses) }}"
                            placeholder="Close, Resolved">
                        <datalist id="csat-status-options">
                            @foreach (['Close', 'Resolved', 'Solved', 'Completed', 'Open', 'Pending'] as $status)
                                <option value="{{ $status }}"></option>
                            @endforeach
                        </datalist>
                        <small class="form-help">{{ __('Pisahkan beberapa status dengan koma. Status tiket dibaca persis dari TransCXP.') }}</small>
                        @error('auto_statuses')
                            <small class="form-error">{{ $message }}</small>
                        @enderror
                    </label>
                </div>

                <fieldset class="csat-channel-options">
                    <legend>{{ __('Kanal pengiriman') }}</legend>
                    <input type="hidden" name="auto_channels[]" value="">
                    @foreach ($channelLabels as $value => $label)
                        <label class="csat-channel-choice">
                            <input type="checkbox" name="auto_channels[]" value="{{ $value }}"
                                @checked(is_array($oldChannels) && in_array($value, $oldChannels, true))
                                @disabled($emailOnly && $value === 'whatsapp')>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                    @error('auto_channels')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                    <small class="form-help">{{ __('Source tiket TransCXP tetap dipertimbangkan; kanal di sini membatasi pilihan yang boleh dipakai workspace ini.') }}</small>
                </fieldset>
            </div>

            <label class="csat-autosend">
                <input type="hidden" name="autosend" value="0">
                <input type="checkbox" name="autosend" value="1" @checked(old('autosend', $autosend))>
                <span>
                    <strong>{{ __('Izinkan autosend untuk workspace ini') }}</strong>
                    <small>{{ __('Jadwal hanya berjalan bila metode Auto/Keduanya dipilih dan CXP_CSAT_AUTOSEND aktif di server.') }}</small>
                </span>
            </label>

            <div class="form-actions">
                <a class="btn" href="{{ route('csat.templates.index') }}">{{ __('Batal') }}</a>
                <button class="btn primary">{{ __('Simpan pengaturan') }}</button>
            </div>
        </form>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('[data-csat-expiry]');
            if (!form) return;

            const unit = form.querySelector('[data-csat-expiry-unit]');
            const value = form.querySelector('[data-csat-expiry-value]');
            const preview = form.querySelector('[data-csat-preview-text]');
            const chips = [...form.querySelectorAll('[data-preset-unit]')];
            const unitLabels = @json($unitLabels);

            const sync = () => {
                const unlimited = unit.value === 'unlimited';
                value.disabled = unlimited;
                value.required = !unlimited;

                preview.textContent = unlimited
                    ? window.TransSurveyI18n.t('Tanpa batas waktu.')
                    : window.TransSurveyI18n.t('Berlaku selama :value :unit sejak pesan terkirim.', {
                        value: value.value || '…',
                        unit: unitLabels[unit.value] || unit.value,
                    });

                chips.forEach((chip) => {
                    chip.classList.toggle('is-active', chip.dataset.presetUnit === unit.value &&
                        (unlimited || chip.dataset.presetValue === String(value.value)));
                });
            };

            chips.forEach((chip) => {
                chip.addEventListener('click', () => {
                    unit.value = chip.dataset.presetUnit;
                    if (chip.dataset.presetUnit !== 'unlimited') {
                        value.value = chip.dataset.presetValue;
                    }
                    sync();
                });
            });

            unit.addEventListener('change', sync);
            value.addEventListener('input', sync);
            sync();
        })();
    </script>
@endpush

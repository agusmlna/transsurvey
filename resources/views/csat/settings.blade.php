@extends('layouts.app')

@section('title', __('Pengaturan CSAT'))
@section('heading', __('Pengaturan CSAT'))
@section('subtitle', __('Atur berapa lama tautan CSAT tetap bisa dibuka customer.'))

@section('actions')
    <a class="btn" href="{{ route('csat.templates.index') }}">{{ __('← Kembali ke template') }}</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/csat.css') }}?v={{ filemtime(public_path('assets/csat.css')) }}">
@endpush

@section('content')
    @php
        $unitLabels = ['minutes' => __('menit'), 'hours' => __('jam'), 'days' => __('hari')];
        $initialPreview =
            $expiryUnit === 'unlimited'
                ? __('Tanpa batas waktu.')
                : __(
                    'Berlaku selama :value :unit sejak email terkirim.',
                    ['value' => $expiryValue, 'unit' => $unitLabels[$expiryUnit] ?? $expiryUnit],
                );
    @endphp

    <section class="panel csat-settings">
        <div class="panel-heading">
            <div>
                <h2>{{ __('Masa berlaku tautan') }}</h2>
                <p>{{ __('Dihitung sejak email CSAT berhasil terkirim ke customer.') }}</p>
            </div>
            <span class="badge blue">{{ $workspace->name }}</span>
        </div>

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
                    : window.TransSurveyI18n.t('Berlaku selama :value :unit sejak email terkirim.', {
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

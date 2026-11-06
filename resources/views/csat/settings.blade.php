@extends('layouts.app')

@section('title', __('Pengaturan CSAT'))
@section('heading', __('Pengaturan CSAT'))
@section('subtitle', __('Atur berapa lama tautan CSAT tetap bisa dibuka customer.'))

@section('actions')
    <a class="btn" href="{{ route('csat.templates.index') }}">{{ __('← Kembali ke template') }}</a>
@endsection

@section('content')
    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>{{ __('Masa berlaku tautan') }}</h2>
                <p>{{ __('Workspace: :name', ['name' => $workspace->name]) }}</p>
            </div>
        </div>

        <form method="post" action="{{ route('csat.settings.update') }}" data-csat-expiry>
            @csrf
            @method('put')

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
                            <option value="{{ $value }}" @selected(old('expiry_unit', $expiryUnit) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('expiry_unit')
                        <small class="form-error">{{ $message }}</small>
                    @enderror
                </label>
            </div>

            <p class="sw-hint">
                {{ __('Contoh: isi 24 dan pilih Jam, berarti tautan CSAT berlaku 24 jam sejak dikirim. Pengaturan ini dipakai saat pengiriman CSAT.') }}
            </p>

            <button class="btn primary">{{ __('Simpan pengaturan') }}</button>
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
            const sync = () => {
                const unlimited = unit.value === 'unlimited';
                value.disabled = unlimited;
                value.required = !unlimited;
            };
            unit.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush

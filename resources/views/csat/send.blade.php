@extends('layouts.app')

@section('title', __('Kirim CSAT'))
@section('heading', __('Kirim CSAT dari tiket'))
@section('subtitle', __('Kirim survei kepuasan ke customer berdasarkan data tiket TransCXP.'))

@section('actions')
    <a class="btn" href="{{ route('csat.templates.index') }}">{{ __('← Kembali ke template') }}</a>
    <a class="btn" href="{{ route('csat.settings.edit') }}">{{ __('Pengaturan masa berlaku') }}</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/csat.css') }}?v={{ filemtime(public_path('assets/csat.css')) }}">
@endpush

@section('content')
    @php
        $ticket = data_get($context, 'ticket', []);
        $customer = data_get($context, 'customer', []);
        $ticketId = trim((string) data_get($ticket, 'id', ''));
        $customerEmail = trim((string) data_get($customer, 'email', ''));
        $customerName = trim((string) data_get($customer, 'name', '')) ?: $customerEmail;
        $customerWa = trim((string) data_get($customer, 'wa', ''));
        $hasContext = $ticketId !== '' && $customerEmail !== '';
    @endphp

    @unless ($hasContext)
        <section class="panel">
            <div class="empty">
                <strong>{{ __('Belum ada data tiket.') }}</strong>
                <p>{{ __('Buka TransSurvey dari tombol CSAT di tiket TransCXP terlebih dahulu. Data tiket dan customer akan terisi otomatis di halaman ini.') }}</p>
                <a class="btn" href="{{ route('csat.templates.index') }}">{{ __('Kembali ke template CSAT') }}</a>
            </div>
        </section>
    @else
        <section class="panel csat-ticket">
            <div class="panel-heading">
                <div>
                    <h2>{{ __('Data tiket') }}</h2>
                    <p>{{ __('Data ini dibaca dari tiket yang dibuka lewat TransCXP.') }}</p>
                </div>
                <span class="badge blue">{{ $workspace->name }}</span>
            </div>

            <dl class="csat-context">
                <div>
                    <dt>{{ __('Tiket') }}</dt>
                    <dd>{{ $ticketId }}</dd>
                </div>
                <div>
                    <dt>{{ __('Status tiket') }}</dt>
                    <dd>{{ data_get($ticket, 'status') ?: __('Tidak disebutkan') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Channel') }}</dt>
                    <dd>{{ data_get($ticket, 'channel') ?: __('Tidak disebutkan') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Customer') }}</dt>
                    <dd>{{ $customerName }}</dd>
                </div>
                <div>
                    <dt>{{ __('Email customer') }}</dt>
                    <dd>{{ $customerEmail }}</dd>
                </div>
                @if ($customerWa !== '')
                    <div>
                        <dt>{{ __('Nomor WhatsApp') }}</dt>
                        <dd>{{ $customerWa }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>{{ __('Kirim CSAT') }}</h2>
                    <p>{{ __('Pilih template yang akan dipakai untuk tiket ini.') }}</p>
                </div>
            </div>

            @if ($templates->isEmpty())
                <div class="empty">
                    <strong>{{ __('Belum ada template CSAT aktif.') }}</strong>
                    <p>{{ __('Buat dan aktifkan template terlebih dahulu sebelum mengirim CSAT.') }}</p>
                    <a class="btn primary" href="{{ route('csat.templates.create') }}">{{ __('＋ Buat template CSAT') }}</a>
                </div>
            @else
                <form method="post" action="{{ route('csat.send.store') }}" class="csat-settings-form">
                    @csrf

                    <div class="form-grid">
                        <label>
                            {{ __('Template CSAT') }}
                            <select name="template_id" required>
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}"
                                        @selected((string) old('template_id', $defaultId) === (string) $template->id)>
                                        {{ $template->title }}
                                        @if ((int) $defaultId === $template->id)
                                            — {{ __('Default') }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('template_id')
                                <small class="form-error">{{ $message }}</small>
                            @enderror
                            @error('context')
                                <small class="form-error">{{ $message }}</small>
                            @enderror
                        </label>
                    </div>

                    <div class="csat-preview" aria-live="polite">
                        <span class="csat-preview-icon" aria-hidden="true">⏱</span>
                        <p>
                            <strong>{{ __('Tautan berlaku') }}</strong>
                            <span>{{ $expiryLabel }}</span>
                        </p>
                    </div>

                    <div class="form-actions">
                        <button class="btn primary" data-submit-feedback
                            data-busy-text="{{ __('Mengantrekan email…') }}">{{ __('Kirim CSAT sekarang') }}</button>
                    </div>
                </form>
            @endif
        </section>
    @endunless

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>{{ __('Riwayat pengiriman CSAT') }}</h2>
                <p>{{ __('Status pengiriman terakhir untuk workspace ini.') }}</p>
            </div>
            <span class="badge blue">{{ $history->count() }} {{ __('pengiriman') }}</span>
        </div>

        <div class="table-wrap">
            <table class="ts-data-table csat-table" aria-label="{{ __('Riwayat pengiriman CSAT') }}">
                <thead>
                    <tr>
                        <th>{{ __('Tiket') }}</th>
                        <th>{{ __('Customer') }}</th>
                        <th>{{ __('Template') }}</th>
                        <th>{{ __('Pengiriman') }}</th>
                        <th>{{ __('Berlaku sampai') }}</th>
                        <th>{{ __('Waktu kirim') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $item)
                        @php
                            $delivery = $item->deliveries->sortByDesc('id')->first();
                            $status = $delivery?->status ?? 'pending';
                            $badges = [
                                'sent' => 'green',
                                'pending' => 'amber',
                                'failed' => 'red',
                                'skipped' => 'gray',
                            ];
                            $labels = [
                                'sent' => __('Terkirim'),
                                'pending' => __('Menunggu'),
                                'failed' => __('Gagal'),
                                'skipped' => __('Dilewati'),
                            ];
                        @endphp
                        <tr>
                            <td>{{ $item->ticket_id }}</td>
                            <td>
                                <strong>{{ $item->recipient_name }}</strong>
                                <small class="csat-template-desc">{{ $item->recipient_email }}</small>
                            </td>
                            <td>{{ $item->survey?->title }}</td>
                            <td>
                                <span class="badge {{ $badges[$status] ?? 'gray' }}">{{ $labels[$status] ?? $status }}</span>
                                @if ($delivery?->last_error)
                                    <small class="csat-template-desc">{{ \Illuminate\Support\Str::limit($delivery->last_error, 120) }}</small>
                                @endif
                            </td>
                            <td class="ts-nowrap">
                                {{ $item->expires_at?->format('d M Y H:i') ?? __('Tanpa batas waktu') }}</td>
                            <td class="ts-nowrap">
                                {{ $item->sent_at?->format('d M Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="empty">
                                <strong>{{ __('Belum ada pengiriman CSAT.') }}</strong>
                                <p>{{ __('Pengiriman akan muncul di sini setelah email CSAT masuk antrean.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

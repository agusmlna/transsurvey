@extends('layouts.app')

@section('title', __('Kirim CSAT'))
@section('heading', __('Kirim CSAT'))
@section('subtitle', __('Kirim survei manual dari tiket, atau gunakan template standar/workspace untuk pengiriman otomatis.'))

@section('actions')
    <a class="btn" href="{{ route('csat.templates.index') }}">{{ __('Template CSAT') }}</a>
    <a class="btn" href="{{ route('csat.settings.edit') }}">{{ __('Pengaturan CSAT') }}</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/csat.css') }}?v={{ filemtime(public_path('assets/csat.css')) }}">
@endpush

@section('content')
    @php
        $ticketId = old('ticket_id', $prefill['ticket_id']);
        $hasContext = $ticketId !== '';
    @endphp

    @if (! $hasContext)
        <div class="notice" role="status">
            {{ __('Isi nomor tiket dan data customer di bawah untuk kirim manual. Bila dibuka dari tombol CSAT di TransCXP, data tiket akan terisi otomatis.') }}
        </div>
    @endif

    @if (session('csat_link'))
        <section class="panel csat-link-panel" aria-live="polite">
            <div class="panel-heading">
                <div>
                    <h2>{{ __('Tautan survey siap dipakai') }}</h2>
                    <p>{{ __('Tiket: :ticket', ['ticket' => session('csat_ticket', '—')]) }}</p>
                </div>
                <span class="badge green">{{ __('Tautan aktif') }}</span>
            </div>
            <div class="csat-link-row">
                <code>{{ session('csat_link') }}</code>
                <button class="btn" type="button" data-copy="{{ session('csat_link') }}">{{ __('Salin tautan') }}</button>
            </div>
            <small class="form-help">{{ __('Tautan yang sama juga dikirim ke customer dan dikembalikan ke TransCXP saat permintaan dibuat lewat API.') }}</small>
        </section>
    @endif

    @if ($hasContext)
        <section class="panel csat-ticket">
            <div class="panel-heading">
                <div>
                    <h2>{{ __('Data tiket') }}</h2>
                    <p>{{ __('Diisi otomatis dari tiket TransCXP. Boleh diubah bila data perlu diperbaiki.') }}</p>
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
                    <dd>{{ $prefill['ticket_status'] ?: __('Tidak disebutkan') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Customer') }}</dt>
                    <dd>{{ $prefill['customer_name'] ?: ($prefill['customer_email'] ?: '—') }}</dd>
                </div>
                <div>
                    <dt>{{ __('Email customer') }}</dt>
                    <dd>{{ $prefill['customer_email'] ?: '—' }}</dd>
                </div>
                @if ($prefill['customer_wa'] !== '')
                    <div>
                        <dt>{{ __('Nomor WhatsApp') }}</dt>
                        <dd>{{ $prefill['customer_wa'] }}</dd>
                    </div>
                @endif
            </dl>
        </section>
    @endif

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>{{ __('Kirim CSAT manual') }}</h2>
                <p>{{ __('Pilih template standar atau buat template sendiri di menu Template CSAT.') }}</p>
            </div>
        </div>

        @if ($templates->isEmpty())
            <div class="empty">
                <strong>{{ __('Belum ada template CSAT aktif.') }}</strong>
                <p>{{ __('Buat dan aktifkan template terlebih dahulu sebelum mengirim CSAT.') }}</p>
                <a class="btn primary" href="{{ route('csat.templates.create') }}">{{ __('＋ Buat template CSAT') }}</a>
            </div>
        @else
            <form method="post" action="{{ route('csat.send.store') }}" class="csat-settings-form" data-csat-send>
                @csrf

                <p class="csat-eyebrow">{{ __('Data tiket & customer') }}</p>
                <div class="form-grid csat-send-grid">
                    <label>
                        {{ __('Nomor tiket / CSAT') }}
                        <input type="text" name="ticket_id" value="{{ $ticketId }}" required maxlength="120">
                        @error('ticket_id')
                            <small class="form-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label>
                        {{ __('Status tiket') }}
                        <input type="text" name="ticket_status" value="{{ old('ticket_status', $prefill['ticket_status']) }}" maxlength="100"
                            placeholder="Close">
                    </label>

                    <label>
                        {{ __('Nama customer') }}
                        <input type="text" name="customer_name" value="{{ old('customer_name', $prefill['customer_name']) }}" maxlength="255">
                    </label>

                    <label>
                        {{ __('Email customer') }}
                        <input type="email" name="customer_email" value="{{ old('customer_email', $prefill['customer_email']) }}" maxlength="255">
                        @error('customer_email')
                            <small class="form-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label>
                        {{ __('Nomor WhatsApp (+62...)') }}
                        <input type="text" name="customer_wa" value="{{ old('customer_wa', $prefill['customer_wa']) }}" maxlength="40"
                            placeholder="+6281234567890">
                        @error('customer_wa')
                            <small class="form-error">{{ $message }}</small>
                        @enderror
                    </label>

                    <label>
                        {{ __('Kirim lewat') }}
                        <select name="channel" data-csat-channel>
                            @foreach (['email' => __('Email'), 'whatsapp' => __('WhatsApp')] as $value => $label)
                                @if (in_array($value, $channels, true))
                                    <option value="{{ $value }}" @selected(old('channel', $defaultChannel) === $value)>{{ $label }}</option>
                                @endif
                            @endforeach
                        </select>
                        @error('channel')
                            <small class="form-error">{{ $message }}</small>
                        @enderror
                        @if ($emailOnly)
                            <small class="form-help">{{ __('Workspace ini email-only sesuai kebijakan server.') }}</small>
                        @endif
                    </label>
                </div>

                <p class="csat-eyebrow">{{ __('Template & masa berlaku') }}</p>
                <div class="form-grid csat-send-grid">
                    <label>
                        {{ __('Template CSAT') }}
                        <select name="template_id" required>
                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}"
                                    @selected((string) old('template_id', $defaultId) === (string) $template->id)>
                                    {{ $template->title }}
                                    @if ((int) $defaultId === $template->id)
                                        — {{ __('Standar') }}
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        @error('template_id')
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
                        data-busy-text="{{ __('Mengirim…') }}">{{ __('Kirim CSAT sekarang') }}</button>
                </div>
            </form>
        @endif
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>{{ __('Riwayat pengiriman CSAT') }}</h2>
                <p>{{ __('Status kirim dan status pengisian customer untuk workspace ini.') }}</p>
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
                        <th>{{ __('Kanal') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Tautan') }}</th>
                        <th>{{ __('Berlaku sampai') }}</th>
                        <th>{{ __('Waktu kirim') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $item)
                        @php
                            $waDelivery = $item->whatsapp->sortByDesc('id')->first();
                            $emailDelivery = $item->deliveries->sortByDesc('id')->first();
                            $isWa = $waDelivery !== null || $item->channel === 'whatsapp';
                            $delivery = $isWa ? $waDelivery : $emailDelivery;
                            $status = $item->completed_at
                                ? 'completed'
                                : ($delivery?->status ?? ($item->sent_at ? 'sent' : 'link'));
                            $badges = [
                                'completed' => 'green',
                                'sent' => 'green',
                                'delivered' => 'green',
                                'read' => 'green',
                                'pending' => 'amber',
                                'failed' => 'red',
                                'skipped' => 'gray',
                                'link' => 'gray',
                            ];
                            $labels = [
                                'completed' => __('Sudah diisi'),
                                'sent' => __('Terkirim'),
                                'delivered' => __('Terkirim'),
                                'read' => __('Dibaca'),
                                'pending' => __('Menunggu'),
                                'failed' => __('Gagal'),
                                'skipped' => __('Dilewati'),
                                'link' => __('Tautan'),
                            ];
                        @endphp
                        <tr>
                            <td>{{ $item->ticket_id }}</td>
                            <td>
                                <strong>{{ $item->recipient_name }}</strong>
                                <small class="csat-template-desc">{{ $item->recipient_email ?: ($item->client?->wa ?: '—') }}</small>
                            </td>
                            <td>{{ $item->survey?->title }}</td>
                            <td>{{ $isWa ? __('WhatsApp') : __('Email') }}</td>
                            <td>
                                <span class="badge {{ $badges[$status] ?? 'gray' }}">{{ $labels[$status] ?? $status }}</span>
                                @if ($delivery?->last_error)
                                    <small class="csat-template-desc">{{ \Illuminate\Support\Str::limit($delivery->last_error, 120) }}</small>
                                @endif
                            </td>
                            <td>
                                <button class="text-button" type="button" data-copy="{{ $item->surveyUrl() }}">{{ __('Salin tautan') }}</button>
                            </td>
                            <td class="ts-nowrap">
                                {{ $item->expires_at?->format('d M Y H:i') ?? __('Tanpa batas waktu') }}</td>
                            <td class="ts-nowrap">
                                {{ $item->sent_at?->format('d M Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="empty">
                                <strong>{{ __('Belum ada pengiriman CSAT.') }}</strong>
                                <p>{{ __('Pengiriman akan muncul di sini setelah email/WhatsApp CSAT dikirim atau tautan manual dibuat.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

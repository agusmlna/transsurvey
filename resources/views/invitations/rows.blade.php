@forelse ($invitations as $i)
    @php
        $delivery = $i->deliveries->sortByDesc('id')->first();
        $invitationDelivery = $i->deliveries->where('kind', 'invitation')->sortByDesc('id')->first();
        $notStartedDelivery = $i->deliveries->where('kind', 'not_started')->sortByDesc('id')->first();
        $saveDraftDelivery = $i->deliveries->where('kind', 'save_draft')->sortByDesc('id')->first();
        $isReminder = (bool) $i->sent_at;
        $pending = $i->deliveries->contains(fn($d) => $d->status === 'pending');
        $blockedReason = null;

        if ($i->completed_at) {
            $blockedReason = __('Survei sudah selesai diisi.');
        } elseif ($i->is_demo) {
            $blockedReason = __('Email untuk data contoh dinonaktifkan.');
        } elseif (!$emailEnabled) {
            $blockedReason = __('Pengiriman email belum diaktifkan.');
        } elseif (!$i->survey->isOpen()) {
            $blockedReason = __('Di luar periode survei aktif.');
        } elseif ($pending) {
            $blockedReason = __('Email sedang menunggu proses pengiriman.');
        }

        $reminderBlockedReason = $blockedReason;
        if (!$reminderBlockedReason && $isReminder && $i->reminder_count >= config('survey.max_reminders')) {
            $reminderBlockedReason = __('Batas reminder sudah tercapai.');
        }

        $invitationReason = $blockedReason
            ?: ($isReminder || $invitationDelivery?->status === 'sent' ? __('Undangan sudah dikirim.') : null);
        $notStartedReason = $blockedReason
            ?: (!$isReminder
                ? __('Kirim email Invitation terlebih dahulu.')
                : ($i->started_at
                    ? __('PIC sudah mulai mengisi survei; template Not Started tidak sesuai.')
                    : ($notStartedDelivery?->status === 'sent' ? __('Email Not Started sudah terkirim.') : null)));
        $saveDraftReason = $blockedReason
            ?: (!$isReminder
                ? __('Kirim email Invitation terlebih dahulu.')
                : (!$i->started_at
                    ? __('PIC belum memulai survei; template Save Draft belum sesuai.')
                    : ($saveDraftDelivery?->status === 'sent' ? __('Email Save Draft sudah terkirim.') : null)));

        $deliveryLabels = [
            'pending' => __('Dalam antrean'),
            'sent' => __('Terkirim'),
            'failed' => __('Gagal'),
            'skipped' => __('Dibatalkan'),
        ];
        $deliveryColors = [
            'pending' => 'amber',
            'sent' => 'green',
            'failed' => 'gray',
            'skipped' => 'gray',
        ];
    @endphp
    <tr>
        <td>
            <strong>{{ $i->client->name }}</strong><small>{{ $i->survey->title }}</small>
            @if ($i->is_demo)
                <span class="badge gray">{{ __('Data contoh') }}</span>
            @endif
        </td>
        <td><strong>{{ $i->recipient_name }}</strong><small>{{ $i->recipient_email }}</small></td>
        <td>
            <span class="badge {{ $i->completed_at ? 'green' : 'amber' }}">
                {{ $i->completed_at ? __('Selesai') : ($i->started_at ? __('Draf tersimpan') : __('Belum mengisi')) }}
            </span>
        </td>
        <td>
            <span class="badge {{ $delivery ? ($deliveryColors[$delivery->status] ?? 'gray') : 'gray' }}">
                {{ $delivery ? ($deliveryLabels[$delivery->status] ?? $delivery->status) : __('Belum dikirim') }}
            </span>
            @if ($delivery?->sent_at)
                <small>{{ $delivery->sent_at->format('d M Y H:i') }}</small>
            @endif
            @if ($delivery?->last_error)
                <small>{{ $delivery->last_error }}</small>
            @endif
        </td>
        <td>
            <strong>{{ $i->reminder_count }} / {{ config('survey.max_reminders') }}</strong>
            <small>{{ !$i->completed_at && $i->reminder_count < config('survey.max_reminders') ? ($i->reminder_at?->format('d M Y H:i') ?? __('Setelah email pertama')) : '—' }}</small>
            <form method="post" action="{{ route('invitations.remind', $i) }}"
                data-confirm="{{ __('Kirim email pengingat ke :name (:email)? Tautan dan kode akses tetap sama.', ['name' => $i->recipient_name, 'email' => $i->recipient_email]) }}"
                data-confirm-title="{{ __('Kirim reminder ke PIC?') }}" data-confirm-button="{{ __('Ya, kirim reminder') }}"
                data-busy-text="{{ __('Memproses reminder…') }}">
                @csrf
                <button type="submit" class="btn" @disabled($reminderBlockedReason !== null || !$isReminder)
                    title="{{ $reminderBlockedReason ?? (!$isReminder ? __('Kirim email undangan pertama terlebih dahulu.') : __('Kirim ke PIC yang tercantum')) }}">
                    {{ __('Kirim reminder ke PIC') }}
                </button>
            </form>
            @if ($reminderBlockedReason)
                <small>{{ $reminderBlockedReason }}</small>
            @elseif (!$isReminder)
                <small>{{ __('Kirim undangan pertama terlebih dahulu.') }}</small>
            @else
                <small>{{ __('Maksimal satu reminder per hari.') }}</small>
            @endif
        </td>
        <td>
            <div class="invitation-email-actions">
                <form method="post" action="{{ route('invitations.send', $i) }}"
                    data-confirm="{{ __('Kirim undangan survei ke :name (:email)?', ['name' => $i->recipient_name, 'email' => $i->recipient_email]) }}"
                    data-confirm-title="{{ __('Kirim email Invitation?') }}" data-confirm-button="{{ __('Ya, kirim email') }}"
                    data-busy-text="{{ __('Memproses email…') }}">
                    @csrf
                    <button type="submit" class="btn primary" @disabled($invitationReason !== null)
                        title="{{ $invitationReason ?? __('Kirim undangan pertama ke PIC') }}">Invitation</button>
                </form>

                <form method="post" action="{{ route('invitations.send-template', [$i, 'not_started']) }}"
                    data-confirm="{{ __('Kirim email Not Started ke :name (:email)?', ['name' => $i->recipient_name, 'email' => $i->recipient_email]) }}"
                    data-confirm-title="{{ __('Kirim email Not Started?') }}" data-confirm-button="{{ __('Ya, kirim email') }}"
                    data-busy-text="{{ __('Memproses email…') }}">
                    @csrf
                    <button type="submit" class="btn" @disabled($notStartedReason !== null)
                        title="{{ $notStartedReason ?? __('Kirim template Not Started ke PIC yang belum memulai survei') }}">Not Started</button>
                </form>

                <form method="post" action="{{ route('invitations.send-template', [$i, 'save_draft']) }}"
                    data-confirm="{{ __('Kirim email Save Draft ke :name (:email)?', ['name' => $i->recipient_name, 'email' => $i->recipient_email]) }}"
                    data-confirm-title="{{ __('Kirim email Save Draft?') }}" data-confirm-button="{{ __('Ya, kirim email') }}"
                    data-busy-text="{{ __('Memproses email…') }}">
                    @csrf
                    <button type="submit" class="btn" @disabled($saveDraftReason !== null)
                        title="{{ $saveDraftReason ?? __('Kirim template Save Draft ke PIC yang sudah mulai survei') }}">Save Draft</button>
                </form>
            </div>
        </td>
        <td>
            <div class="row-actions">
                <button class="text-button" type="button" data-copy="{{ $i->surveyUrl() }}">{{ __('Salin tautan') }}</button>
                <a class="text-button" href="{{ $i->surveyUrl() }}" target="_blank" rel="noopener">{{ __('Buka ↗') }}</a>
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td class="empty" colspan="7">{{ __('Belum ada undangan. Buat undangan survei untuk klien terlebih dahulu.') }}</td>
    </tr>
@endforelse

@forelse ($invitations as $i)
                        @php
                            $delivery = $i->deliveries->sortByDesc('id')->first();
                            $isReminder = (bool) $i->sent_at;
                            $pending = $i->deliveries->contains(fn($d) => $d->status === 'pending');
                            $buttonText = $delivery?->status === 'failed' ? __('Coba kirim lagi') : __('Kirim email');
                            $blockedReason = null;
                            if ($i->completed_at) {
                                $blockedReason = __('Survei sudah selesai diisi.');
                            } elseif ($i->is_demo) {
                                $blockedReason = __('Email untuk data contoh dinonaktifkan.');
                            } elseif (!$emailEnabled) {
                                $blockedReason = __('Pengiriman email belum diaktifkan.');
                            } elseif (!$i->survey->isOpen()) {
                                $blockedReason = __('Di luar periode survei aktif.');
                            } elseif ($isReminder && $i->reminder_count >= config('survey.max_reminders')) {
                                $blockedReason = __('Batas reminder sudah tercapai.');
                            } elseif ($pending) {
                                $blockedReason = __('Email sedang menunggu proses pengiriman.');
                            }
                            $deliveryLabels = [
                                'pending' => __('Dalam antrean'),
                                'sent' => 'Terkirim',
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
                            <td><strong>{{ $i->client->name }}</strong><small>{{ $i->survey->title }}</small>
                                @if ($i->is_demo)
                                    <span class="badge gray">{{ __('Data contoh') }}</span>
                                @endif
                            </td>
                            <td><strong>{{ $i->recipient_name }}</strong><small>{{ $i->recipient_email }}</small></td>
                            <td><span
                                    class="badge {{ $i->completed_at ? 'green' : 'amber' }}">{{ $i->completed_at ? __('Selesai') : ($i->started_at ? __('Draf tersimpan') : __('Belum mengisi')) }}</span>
                            </td>
                            <td><span
                                    class="badge {{ $delivery ? $deliveryColors[$delivery->status] ?? 'gray' : 'gray' }}">{{ $delivery ? $deliveryLabels[$delivery->status] ?? $delivery->status : __('Belum dikirim') }}</span>
                                @if ($delivery?->sent_at)
                                    <small>{{ $delivery->sent_at->format('d M Y H:i') }}</small>
                                    @endif @if ($delivery?->last_error)
                                        <small>{{ $delivery->last_error }}</small>
                                    @endif
                            </td>
                            <td>
                                <strong>{{ $i->reminder_count }} / {{ config('survey.max_reminders') }}</strong>
                                <small>{{ !$i->completed_at && $i->reminder_count < config('survey.max_reminders') ? $i->reminder_at?->format('d M Y H:i') ?? __('Setelah email pertama') : '—' }}</small>
                                <form method="post" action="{{ route('invitations.remind', $i) }}"
                                    data-confirm="{{ __('Kirim email pengingat ke :name (:email)? Tautan dan kode akses tetap sama.', ['name' => $i->recipient_name, 'email' => $i->recipient_email]) }}"
                                    data-confirm-title="{{ __('Kirim reminder ke PIC?') }}" data-confirm-button="{{ __('Ya, kirim reminder') }}"
                                    data-busy-text="{{ __('Memproses reminder…') }}">
                                    @csrf
                                    <button type="submit" class="btn" @disabled($blockedReason !== null || !$isReminder)
                                        title="{{ $blockedReason ?? (!$isReminder ? __('Kirim email undangan pertama terlebih dahulu.') : __('Kirim ke PIC yang tercantum')) }}">{{ __('Kirim reminder ke PIC') }}</button>
                                </form>
                                @if ($blockedReason)
                                    <small>{{ $blockedReason }}</small>
                                @elseif (!$isReminder)
                                <small>{{ __('Kirim undangan pertama terlebih dahulu.') }}</small>@else<small>{{ __('Maksimal satu reminder per hari.') }}</small>
                                @endif
                            </td>
                            <td>
                                @if ($isReminder)
                                    <span class="badge green">{{ __('Undangan sudah dikirim') }}</span>
                                    <small>{{ __('Untuk mengingatkan PIC, gunakan tombol di kolom Reminder.') }}</small>
                                @else
                                    <form method="post" action="{{ route('invitations.send', $i) }}"
                                        data-confirm="{{ __('Kirim undangan survei ke :name (:email)?', ['name' => $i->recipient_name, 'email' => $i->recipient_email]) }}"
                                        data-confirm-title="{{ __('Kirim email undangan?') }}" data-confirm-button="{{ __('Ya, kirim email') }}"
                                        data-busy-text="{{ __('Memproses email…') }}">
                                        @csrf
                                        <button type="submit" class="btn primary" @disabled($blockedReason !== null)
                                            title="{{ $blockedReason ?? __('Kirim undangan pertama ke PIC') }}">{{ $pending && !$i->completed_at ? __('Dalam antrean') : $buttonText }}</button>
                                    </form>
                                @endif
                            </td>
                            <td>
                                <div class="row-actions"><button class="text-button" type="button"
                                        data-copy="{{ $i->surveyUrl() }}">{{ __('Salin tautan') }}</button><a class="text-button"
                                        href="{{ $i->surveyUrl() }}" target="_blank" rel="noopener">{{ __('Buka ↗') }}</a></div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="empty" colspan="7">{{ __('Belum ada undangan. Buat undangan survei untuk klien terlebih dahulu.') }}</td>
                        </tr>
                    @endforelse

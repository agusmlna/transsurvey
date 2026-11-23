@php
    $rows = $csatInvitations ?? $invitations ?? collect();
    $interactive = (bool) ($interactiveTables ?? false);
    $statusLabels = [
        'completed' => __('Sudah diisi'),
        'pending' => __('Belum diisi'),
        'draft' => __('Belum dikirim'),
    ];
    $statusBadges = ['completed' => 'green', 'pending' => 'amber', 'draft' => 'gray'];
@endphp

@if ($interactive)
    <form method="get" class="csat-status-filter" action="{{ url()->current() }}" data-csat-status-filter>
        <label>
            {{ __('Status CSAT') }}
            <select name="csat_status">
                @foreach (['all' => __('Semua status'), 'completed' => __('Sudah diisi'), 'pending' => __('Belum diisi'), 'draft' => __('Belum dikirim')] as $value => $label)
                    <option value="{{ $value }}" @selected(($csatStatus ?? 'all') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn">{{ __('Terapkan') }}</button>
    </form>
    <script>
        (() => {
            const form = document.querySelector('[data-csat-status-filter]');
            if (!form) return;
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const params = new URLSearchParams(window.location.search);
                params.set('csat_status', form.elements.csat_status.value);
                params.delete('csat_page');
                window.location.assign(form.action + '?' + params.toString());
            });
        })();
    </script>
@endif

<div class="table-wrap">
    <table class="csat-table ts-data-table rs-table" @if($interactive) data-ts-table @endif aria-label="{{ __('Status pengisian CSAT') }}">
        <thead>
            <tr>
                <th>{{ __('Tiket') }}</th>
                <th>{{ __('Customer') }}</th>
                <th>{{ __('Template') }}</th>
                <th>{{ __('Kanal') }}</th>
                <th>{{ __('Status CSAT') }}</th>
                @if ($interactive)
                    <th>{{ __('Tautan') }}</th>
                @endif
                <th>{{ __('Waktu kirim') }}</th>
                <th>{{ __('Waktu isi') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $invitation)
                @php($status = $invitation->csatStatus())
                <tr>
                    <td>{{ $invitation->ticket_id ?: '—' }}</td>
                    <td>
                        <strong>{{ $invitation->recipient_name ?: ($invitation->client?->name ?: '—') }}</strong>
                        <small class="csat-template-desc">{{ $invitation->recipient_email ?: ($invitation->client?->email ?: '—') }}</small>
                    </td>
                    <td>{{ $invitation->survey?->title ?: '—' }}</td>
                    <td>{{ $invitation->csatChannel() === 'whatsapp' ? __('WhatsApp') : __('Email') }}</td>
                    <td><span class="badge {{ $statusBadges[$status] ?? 'gray' }}">{{ $statusLabels[$status] ?? $status }}</span></td>
                    @if ($interactive)
                        <td><button class="text-button" type="button" data-copy="{{ $invitation->surveyUrl() }}">{{ __('Salin tautan') }}</button></td>
                    @endif
                    <td class="ts-nowrap">{{ $invitation->sent_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td class="ts-nowrap">{{ $invitation->completed_at?->format('d M Y H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $interactive ? 8 : 7 }}" class="rs-empty">{{ __('Belum ada undangan CSAT pada filter ini.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($interactive && $rows instanceof \Illuminate\Pagination\LengthAwarePaginator)
    <div data-table-fallback="csat-status-table">{{ $rows->links() }}</div>
@endif

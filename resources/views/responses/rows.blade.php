@forelse($rows as $r)
    <tr>
        <td><strong>{{ $r->client->name }}</strong><small>{{ $r->client->project }}</small></td>
        <td>{{ $r->survey->title }}</td>
        <td data-order="{{ $r->score ?? -1 }}"><span
                class="badge {{ $r->score !== null && $r->score < 3.5 ? 'amber' : 'green' }}">{{ $r->score === null ? '—' : number_format($r->score, 2) }}
                / 5</span></td>
        <td data-order="{{ $r->submitted_at->timestamp }}">{{ $r->submitted_at->format('d M Y H:i') }}</td>

        @if ($showAction)
            @php($low = $r->answers->filter(fn($a) => $a->type === 'rating' && $a->value !== null && $a->value !== '' && (int) $a->value <= 3)->values())
            <td>
                <button type="button" class="btn" data-modal-open="low-{{ $r->id }}">
                    Lihat rating rendah ({{ $low->count() }})
                </button>

                <div class="modal-overlay" id="low-{{ $r->id }}" hidden>
                    <div class="modal-box" role="dialog" aria-modal="true">
                        <div class="modal-head">
                            <div>
                                <h2>{{ $r->client->name }}</h2>
                                <small>{{ $r->survey->title }} · {{ $r->submitted_at->format('d M Y H:i') }}</small>
                            </div>
                            <button type="button" class="icon-link" data-modal-close aria-label="Tutup">✕</button>
                        </div>

                        <div class="modal-respondent">
                            <span class="eyebrow">DIISI OLEH</span>
                            <strong>{{ $r->invitation?->recipient_name ?? '—' }}</strong>
                            @if ($r->invitation?->recipient_email)
                                <small>{{ $r->invitation->recipient_email }}</small>
                            @endif
                        </div>

                        <div class="modal-content">
                            @forelse($low as $a)
                                <div class="answer-detail">
                                    <span class="eyebrow">{{ $a->category }}</span>
                                    <h3>{{ $a->question_text }}</h3>
                                    <p>{{ $a->value }} / 5</p>
                                    @if ($a->comment)
                                        <blockquote>{{ $a->comment }}</blockquote>
                                    @endif
                                </div>
                            @empty
                                <div class="empty">Tidak ada rating rendah pada respons ini.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </td>
        @endif

        <td><a class="icon-link" aria-label="Detail respons" href="{{ route('responses.show', $r) }}">↗</a></td>
    </tr>
@empty
    <tr>
        <td colspan="{{ $showAction ? 6 : 5 }}" class="empty">Belum ada respons untuk filter ini.</td>
    </tr>
@endforelse

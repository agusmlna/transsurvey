@php($showAction = $showAction ?? false)
<div class="table-wrap">
<table>
<thead>
<tr>
    <th>Klien / proyek</th>
    <th>Kuesioner</th>
    <th>Skor</th>
    <th>Tanggal</th>
    @if($showAction)<th>Tindakan</th>@endif
    <th></th>
</tr>
</thead>
<tbody>
@forelse($rows as $r)
<tr>
    <td><strong>{{ $r->client->name }}</strong><small>{{ $r->client->project }}</small></td>
    <td>{{ $r->survey->title }}</td>
    <td><span class="badge {{ $r->score!==null && $r->score<3.5?'amber':'green' }}">{{ $r->score===null?'—':number_format($r->score,2) }} / 5</span></td>
    <td>{{ $r->submitted_at->format('d M Y H:i') }}</td>

    @if($showAction)
        @php($low = $r->answers->filter(fn($a) => $a->type==='rating' && $a->value!==null && $a->value!=='' && (int)$a->value<=3)->values())
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

                    <div class="modal-content">
                        @forelse($low as $a)
                            <div class="answer-detail">
                                <span class="eyebrow">{{ $a->category }}</span>
                                <h3>{{ $a->question_text }}</h3>
                                <p>{{ $a->value }} / 5</p>
                                @if($a->comment)<blockquote>{{ $a->comment }}</blockquote>@endif
                            </div>
                        @empty
                            <div class="empty">Tidak ada rating rendah pada respons ini.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </td>
    @endif

    <td><a class="icon-link" aria-label="Detail respons" href="{{ route('responses.show',$r) }}">↗</a></td>
</tr>
@empty
<tr><td colspan="{{ $showAction ? 6 : 5 }}" class="empty">Belum ada respons untuk filter ini.</td></tr>
@endforelse
</tbody>
</table>
</div>

@if($showAction)
@push('scripts')
<script>
document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-modal-open]');
    if (opener) {
        var modal = document.getElementById(opener.dataset.modalOpen);
        if (modal) { modal.hidden = false; document.body.style.overflow = 'hidden'; }
        return;
    }
    var overlay = e.target.closest('.modal-overlay');
    if (overlay && (e.target === overlay || e.target.closest('[data-modal-close]'))) {
        overlay.hidden = true;
        document.body.style.overflow = '';
    }
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay:not([hidden])').forEach(function (m) { m.hidden = true; });
        document.body.style.overflow = '';
    }
});
</script>
@endpush
@endif
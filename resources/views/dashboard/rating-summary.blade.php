<section class="panel" style="margin-bottom:20px" aria-labelledby="rating-summary-title">
    <div class="panel-heading">
        <div>
            <h2 id="rating-summary-title">{{ __('Ringkasan penilaian') }}</h2>
            <p>{{ __('Berdasarkan jawaban rating pada respons yang sudah dikirim, sesuai filter yang dipilih.') }}</p>
        </div>
        <a class="text-button" href="{{ route('responses.index', request()->query()) }}">{{ __('Lihat detail respons →') }}</a>
    </div>
    @if ($feedback['total'] > 0)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
            <div style="padding:18px;border:1px solid #d1e7dc;border-radius:12px;background:#f5fbf7">
                <span class="badge green">{{ __('Nilai 4–5') }}</span>
                <h3 style="margin:10px 0">{{ __('Penilaian tinggi') }}</h3>
                <p><strong style="font-size:28px">{{ $feedback['high'] }}</strong> {{ __('jawaban ·') }} {{ number_format($feedback['highPercent'], 1) }}%</p>
                <progress value="{{ $feedback['high'] }}" max="{{ $feedback['total'] }}" aria-label="{{ __('Proporsi jawaban nilai 4 sampai 5') }}" style="width:100%;height:8px;accent-color:#15803d"></progress>
            </div>
            <div style="padding:18px;border:1px solid #f1d3d9;border-radius:12px;background:#fff7f8">
                <span class="badge" style="background:#ffe4e6;color:#9f1239">{{ __('Nilai 1–3') }}</span>
                <h3 style="margin:10px 0">{{ __('Perlu perhatian') }}</h3>
                <p><strong style="font-size:28px">{{ $feedback['attention'] }}</strong> {{ __('jawaban ·') }} {{ number_format($feedback['attentionPercent'], 1) }}%</p>
                <progress value="{{ $feedback['attention'] }}" max="{{ $feedback['total'] }}" aria-label="{{ __('Proporsi jawaban nilai 1 sampai 3') }}" style="width:100%;height:8px;accent-color:#c8102e"></progress>
            </div>
        </div>
        <p class="footnote">Total {{ $feedback['total'] }} {{ __('jawaban rating. Satu respons dapat berisi beberapa jawaban. Pengelompokan berdasarkan nilai, bukan isi komentar. Detail komentar tersedia di menu Respons.') }}</p>
    @else
        <div class="empty">{{ __('Belum ada jawaban rating pada filter ini.') }}</div>
    @endif
</section>

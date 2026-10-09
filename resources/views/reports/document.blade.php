<div class="rs-report">
    <table class="rs-brand-table">
        <tr>
            <td>
                @if ($reportLogo)
                    <img class="rs-logo" src="{{ $reportLogo }}" alt="Transcosmos Indonesia">
                @endif
            </td>
            <td class="rs-brand"><strong>TransSurvey</strong><span>CLIENT SATISFACTION</span></td>
        </tr>
    </table>
    <div class="rs-title"><span class="rs-eyebrow">{{ __('HASIL SURVEI') }}</span>
        <h1>{{ __('Laporan kepuasan klien') }}</h1>
        <p>{{ __('Dibuat') }} {{ $generatedAt->format('d/m/Y H:i') }} WIB</p>
    </div>
    <table class="rs-meta">
        <tbody>
            @foreach ($reportFilters as $label => $value)
                <tr>
                    <th>{{ $label }}</th>
                    <td>{{ $value }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <table class="rs-metrics">
        <tr>
            <td><span>{{ __('Total respons') }}</span><strong>{{ $responses->count() }}</strong><small>{{ __('Pada periode yang dipilih') }}</small></td>
            <td><span>Response
                    rate</span><strong>{{ $invCount ? number_format($rate, 0, ',', '.') . '%' : '-' }}</strong><small>{{ $complete }}
                    {{ __('dari') }} {{ $invCount }} {{ __('undangan') }}</small></td>
            <td><span>{{ __('Skor rata-rata') }}</span><strong>{{ $average === null ? '-' : number_format($average, 2, ',', '.') }}<em> /
                        5</em></strong><small>{{ __('Rata-rata skor respons') }}</small></td>
            <td class="rs-highlight"><span>{{ __('Persentase skor') }}</span><strong>{{ $average === null ? '-' : number_format(($average / 5) * 100, 1, ',', '.') . '%' }}</strong><small>{{ __('Skor rata-rata dibagi 5') }}</small></td>
        </tr>
    </table>
    <section class="rs-summary">
        <h2>{{ __('Ringkasan hasil') }}</h2>
        <p>{{ $summaryText }}</p>
    </section>
    <h2 class="rs-section-title">{{ __('Kepuasan per klien') }}</h2>
    <table class="rs-table" @if($interactiveTables ?? false) data-ts-table @endif>
        <thead>
            <tr>
                <th style="width:29%">{{ __('Klien') }}</th>
                <th style="width:26%">{{ __('Proyek') }}</th>
                <th class="rs-num">{{ __('Respons') }}</th>
                <th class="rs-num">{{ __('Skor / 5') }}</th>
                <th class="rs-num">{{ __('Persentase') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($clientRows as $client)
                <tr>
                    <td>{{ $client['name'] }}</td>
                    <td>{{ $client['project'] }}</td>
                    <td class="rs-num">{{ $client['responses'] }}</td>
                    <td class="rs-num" data-order="{{ $client['score'] ?? -1 }}">
                        {{ $client['score'] === null ? '-' : number_format($client['score'], 2, ',', '.') }}</td>
                    <td class="rs-num" data-order="{{ $client['percentage'] ?? -1 }}">
                        {{ $client['percentage'] === null ? '-' : number_format($client['percentage'] * 100, 1, ',', '.') . '%' }}
                    </td>
                </tr>
            @empty<tr>
                    <td colspan="5" class="rs-empty">{{ __('Belum ada respons pada filter ini.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <h2 class="rs-section-title">{{ __('Skor per kategori') }}</h2>
    <table class="rs-table rs-categories" @if($interactiveTables ?? false) data-ts-table @endif>
        <thead>
            <tr>
                <th style="width:40%">{{ __('Kategori') }}</th>
                <th style="width:40%" data-dt-order="disable">{{ __('Perbandingan skor') }}</th>
                <th class="rs-num">{{ __('Skor / 5') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($categories as $name => $value)
                <tr>
                    <td>{{ $name }}</td>
                    <td>
                        <div class="rs-track">
                            <div class="rs-fill" style="width:{{ max(0, min(100, ($value / 5) * 100)) }}%"></div>
                        </div>
                    </td>
                    <td class="rs-num" data-order="{{ $value }}">{{ number_format($value, 2, ',', '.') }}</td>
                </tr>
            @empty<tr>
                    <td colspan="3" class="rs-empty">{{ __('Belum ada jawaban rating pada filter ini.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <h2 class="rs-section-title">{{ __('Detail respons') }}</h2>
    <table class="rs-table" @if($interactiveTables ?? false) data-ts-table @endif>
        <thead>
            <tr>
                <th style="width:28%">{{ __('Klien / proyek') }}</th>
                <th style="width:28%">{{ __('Kuesioner') }}</th>
                <th class="rs-num" style="width:16%">{{ __('Skor / 5') }}</th>
                <th style="width:28%">{{ __('Tanggal (WIB)') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($responses as $response)
                <tr>
                    <td><strong>{{ $response->client?->name ?? __('Klien tidak tersedia') }}</strong><small>{{ $response->client?->project ?: '-' }}</small>
                    </td>
                    <td>{{ $response->survey?->title ?? __('Kuesioner tidak tersedia') }}</td>
                    <td class="rs-num" data-order="{{ $response->score ?? -1 }}">
                        {{ $response->score === null ? '-' : number_format($response->score, 2, ',', '.') }}</td>
                    <td data-order="{{ $response->submitted_at?->timestamp ?? 0 }}">{{ $response->submitted_at?->copy()->timezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '-' }}
                    </td>
                </tr>
            @empty<tr>
                    <td colspan="4" class="rs-empty">{{ __('Belum ada respons pada filter ini.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <div class="rs-method"><strong>{{ __('Dasar perhitungan') }}</strong>
        <p>{{ __('Skor respons adalah rata-rata jawaban rating yang diisi. Skor keseluruhan adalah rata-rata skor respons. Skor kategori adalah rata-rata jawaban rating dalam kategori tersebut. Persentase skor = skor / 5 × 100. Teks dan pilihan ganda tidak dihitung.') }}</p>
        <p>{{ __('Filter tanggal respons memakai waktu pengiriman survei. Response rate memakai undangan yang dibuat pada periode filter beserta status penyelesaiannya. Karena tanggal acuannya berbeda, total respons dapat berbeda dari jumlah undangan selesai.') }}</p>
    </div>
</div>

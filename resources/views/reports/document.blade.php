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
    <div class="rs-title"><span class="rs-eyebrow">HASIL SURVEI</span>
        <h1>Laporan kepuasan klien</h1>
        <p>Dibuat {{ $generatedAt->format('d/m/Y H:i') }} WIB</p>
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
            <td><span>Total respons</span><strong>{{ $responses->count() }}</strong><small>Pada periode yang
                    dipilih</small></td>
            <td><span>Response
                    rate</span><strong>{{ $invCount ? number_format($rate, 0, ',', '.') . '%' : '-' }}</strong><small>{{ $complete }}
                    dari {{ $invCount }} undangan</small></td>
            <td><span>Skor
                    rata-rata</span><strong>{{ $average === null ? '-' : number_format($average, 2, ',', '.') }}<em> /
                        5</em></strong><small>Rata-rata skor respons</small></td>
            <td class="rs-highlight"><span>Persentase
                    skor</span><strong>{{ $average === null ? '-' : number_format(($average / 5) * 100, 1, ',', '.') . '%' }}</strong><small>Skor
                    rata-rata dibagi 5</small></td>
        </tr>
    </table>
    <section class="rs-summary">
        <h2>Ringkasan hasil</h2>
        <p>{{ $summaryText }}</p>
    </section>
    <h2 class="rs-section-title">Kepuasan per klien</h2>
    <table class="rs-table">
        <thead>
            <tr>
                <th style="width:29%">Klien</th>
                <th style="width:26%">Proyek</th>
                <th class="rs-num">Respons</th>
                <th class="rs-num">Skor / 5</th>
                <th class="rs-num">Persentase</th>
            </tr>
        </thead>
        <tbody>
            @forelse($clientRows as $client)
                <tr>
                    <td>{{ $client['name'] }}</td>
                    <td>{{ $client['project'] }}</td>
                    <td class="rs-num">{{ $client['responses'] }}</td>
                    <td class="rs-num">
                        {{ $client['score'] === null ? '-' : number_format($client['score'], 2, ',', '.') }}</td>
                    <td class="rs-num">
                        {{ $client['percentage'] === null ? '-' : number_format($client['percentage'] * 100, 1, ',', '.') . '%' }}
                    </td>
                </tr>
            @empty<tr>
                    <td colspan="5" class="rs-empty">Belum ada respons pada filter ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <h2 class="rs-section-title">Skor per kategori</h2>
    <table class="rs-table rs-categories">
        <thead>
            <tr>
                <th style="width:40%">Kategori</th>
                <th style="width:40%">Perbandingan skor</th>
                <th class="rs-num">Skor / 5</th>
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
                    <td class="rs-num">{{ number_format($value, 2, ',', '.') }}</td>
                </tr>
            @empty<tr>
                    <td colspan="3" class="rs-empty">Belum ada jawaban rating pada filter ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <h2 class="rs-section-title">Detail respons</h2>
    <table class="rs-table">
        <thead>
            <tr>
                <th style="width:28%">Klien / proyek</th>
                <th style="width:28%">Kuesioner</th>
                <th class="rs-num" style="width:16%">Skor / 5</th>
                <th style="width:28%">Tanggal (WIB)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($responses as $response)
                <tr>
                    <td><strong>{{ $response->client?->name ?? 'Klien tidak tersedia' }}</strong><small>{{ $response->client?->project ?: '-' }}</small>
                    </td>
                    <td>{{ $response->survey?->title ?? 'Kuesioner tidak tersedia' }}</td>
                    <td class="rs-num">
                        {{ $response->score === null ? '-' : number_format($response->score, 2, ',', '.') }}</td>
                    <td>{{ $response->submitted_at?->copy()->timezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '-' }}
                    </td>
                </tr>
            @empty<tr>
                    <td colspan="4" class="rs-empty">Belum ada respons pada filter ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <div class="rs-method"><strong>Dasar perhitungan</strong>
        <p>Skor respons adalah rata-rata jawaban rating yang diisi. Skor keseluruhan adalah rata-rata skor respons. Skor
            kategori adalah rata-rata jawaban rating dalam kategori tersebut. Persentase skor = skor / 5 × 100. Teks dan
            pilihan ganda tidak dihitung.</p>
        <p>Filter tanggal respons memakai waktu pengiriman survei. Response rate memakai undangan yang dibuat pada
            periode filter beserta status penyelesaiannya. Karena tanggal acuannya berbeda, total respons dapat berbeda
            dari jumlah undangan selesai.</p>
    </div>
</div>

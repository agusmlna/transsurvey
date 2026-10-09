@extends('layouts.app')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('subtitle', __('Pantau hasil survei, tingkat respons, dan masukan yang perlu ditindaklanjuti.'))
{{-- @section('actions')@if (auth()->user()->role === 'admin')
    <a class="btn primary" href="{{ route('surveys.create') }}">
        ＋ Buat kuesioner</a>
@endif @endsection --}}
@section('content')
    <details class="ts-dashboard-filters no-print" @if(request()->query()) open @endif>
        <summary>{{ __('Filter dashboard') }}</summary>
        @include('layouts.filters')
    </details>
    @if ($responses->contains(fn($r) => $r->invitation->is_demo))
        <p class="demo-label">{{ __('Data contoh disertakan. Pilih sumber Operasional untuk laporan aktual.') }}</p>
    @endif
    @include('layouts.metrics')
    <div class="chart-grid">
        <section class="panel ts-overall-panel">
            <div class="panel-heading"><div><h2>{{ __('Kepuasan keseluruhan') }}</h2><p>{{ __('Rata-rata skor respons pada filter yang dipilih') }}</p></div></div>
            <div class="ts-overall-body">
                <div class="ts-ring ts-score-ring" style="--ring-value: {{ $average === null ? 0 : max(0, min(100, $average / 5 * 100)) }}" role="img" aria-label="{{ __('Skor kepuasan :score', ['score' => $average === null ? __('belum tersedia') : number_format($average, 2).' '.__('dari 5')]) }}"><div><strong>{{ $average === null ? '—' : number_format($average, 2) }}</strong><small>/ 5</small></div></div>
                <div class="ts-overall-copy"><span class="badge {{ $average === null ? 'gray' : 'green' }}">{{ $average === null ? __('Belum ada penilaian') : number_format($average / 5 * 100, 1).__('% kepuasan') }}</span><p>{{ __('Berdasarkan') }} {{ $responses->whereNotNull('score')->count() }} {{ __('respons dengan penilaian.') }}</p><a class="text-button" href="{{ route('reports.index', request()->query()) }}">{{ __('Lihat laporan →') }}</a></div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>{{ __('Tren kepuasan klien') }}</h2>
                    <p>{{ __('Rata-rata rating berdasarkan bulan respons') }}</p>
                </div><span class="legend">{{ __('● Skor kepuasan') }}</span>
            </div>
            @if ($trends->count())
                @php
                    $values = $trends->values();
                    $n = $values->count();
                    $points = [];
                    foreach ($values as $i => $v) {
                        $x = $n === 1 ? 310 : 45 + ($i * 530) / max(1, $n - 1);
                        $y = 190 - (($v - 1) / 4) * 155;
                        $points[] = $x . ',' . $y;
                    }
                @endphp
                <div class="trend-chart"><svg viewBox="0 0 620 240" role="img"
                        aria-label="{{ __('Grafik skor kepuasan dari 1 sampai 5') }}">
                        <defs>
                            <linearGradient id="areaFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#c8102e" stop-opacity="0.17" />
                                <stop offset="100%" stop-color="#c8102e" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        @foreach ([1, 2, 3, 4, 5] as $tick)
                            @php($y = 190 - (($tick - 1) / 4) * 155)
                            <line x1="45" y1="{{ $y }}" x2="575" y2="{{ $y }}"
                                stroke="#e3ebeb" stroke-dasharray="4 5" /><text x="16" y="{{ $y + 4 }}"
                                fill="#84969e" font-size="12">{{ $tick }}</text>
                        @endforeach
                        @if ($n > 1)
                            <polygon points="45,190 {{ implode(' ', $points) }} 575,190" fill="url(#areaFill)" />
                        @endif
                        <polyline points="{{ implode(' ', $points) }}" fill="none" stroke="#c8102e" stroke-width="3" />
                        @foreach ($values as $i => $v)
                            @php($x = $n === 1 ? 310 : 45 + ($i * 530) / max(1, $n - 1))<circle cx="{{ $x }}" cy="{{ 190 - (($v - 1) / 4) * 155 }}" r="4"
                                fill="white" stroke="#c8102e" stroke-width="2">
                                <title>{{ $trends->keys()[$i] }}: {{ $v }}</title>
                            </circle><text x="{{ $x }}" y="221" text-anchor="middle" font-size="12"
                                fill="#84969e">{{ $trends->keys()[$i] }}</text>
                        @endforeach
                    </svg>
            </div>@else<div class="empty">{{ __('Grafik tersedia setelah ada respons dengan rating.') }}</div>
            @endif
            <div class="chart-footer">
                {{ __('Skala penilaian 1–5') }} <span>{{ $responses->whereNotNull('score')->count() }} {{ __('respons dengan penilaian') }}</span>
            </div>
        </section>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>{{ __('Kepuasan per kategori') }}</h2>
                    <p>{{ __('Kenali kekuatan dan area perbaikan') }}</p>
                </div>
            </div>
            @if ($categories->count())
                <div class="ts-category-chart" role="list" aria-label="{{ __('Skor per kategori, skala 1 sampai 5') }}">
                    @foreach ($categories as $name => $value)
                        <div class="ts-category-column" role="listitem"><div class="ts-category-track"><div class="ts-category-bar" style="height: {{ max(0, min(100, $value / 5 * 100)) }}%"><strong>{{ number_format($value, 2) }}</strong></div></div><span>{{ $name }}</span></div>
                    @endforeach
                </div>
                <div class="chart-footer">{{ __('Skala penilaian 1–5') }}<span>{{ $categories->count() }} {{ __('kategori') }}</span></div>
            @else
                <div class="empty">{{ __('Belum ada rating kategori.') }}</div>
            @endif
        </section>
        <section class="panel ts-response-panel">
            <div class="panel-heading"><div><h2>{{ __('Status pengisian undangan') }}</h2><p>{{ __('Berdasarkan tanggal undangan dibuat') }}</p></div></div>
            <div class="ts-response-body"><div class="ts-ring" style="--ring-value: {{ max(0, min(100, $rate)) }}" role="img" aria-label="{{ $complete }} dari {{ $invCount }} {{ __('undangan') }} selesai"><div><strong>{{ $invCount }}</strong><small>{{ __('Undangan') }}</small></div></div><div class="ts-ring-legend"><div><span><i class="ts-dot ts-dot-green"></i>{{ __('Selesai') }}</span><strong>{{ $complete }}</strong></div><div><span><i class="ts-dot ts-dot-gray"></i>{{ __('Belum selesai') }}</span><strong>{{ max(0, $invCount - $complete) }}</strong></div><small>{{ __('Belum selesai termasuk draf yang belum dikirim.') }}</small></div></div>
            @if (!$invCount)<p class="ts-chart-note">{{ __('Belum ada undangan pada filter ini.') }}</p>@endif
        </section>
    </div>
    @include('layouts.client-scores')
    @include('dashboard.rating-summary')
    <div class="bottom-grid">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>{{ __('Respons terbaru') }} <span class="count">{{ $responses->count() }}</span></h2>
                    <p>{{ __('Masukan terbaru dari klien Anda') }}</p>
                </div><a class="text-button" href="{{ route('responses.index', request()->query()) }}">{{ __('Lihat semua →') }}</a>
            </div>@include('layouts.response-table', ['rows' => $responses])
        </section>
        <section class="follow-card"><span class="follow-symbol">✓</span>
            <h2>{{ __('Tindak lanjut') }}</h2>
            <p><strong>{{ $openFollow }} {{ __('tindak lanjut') }}</strong> {{ __('masih terbuka di seluruh workspace.') }}</p><a class="btn"
                href="{{ route('followups.index') }}">{{ __('Lihat tindak lanjut →') }}</a>
        </section>
    </div>
    <p class="footnote">{{ __('Response rate dihitung dari undangan yang dibuat pada periode filter. Total respons memakai tanggal jawaban dikirim. Kedua angka dapat berbeda periodenya.') }}</p>
@endsection

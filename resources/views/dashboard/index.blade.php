@extends('layouts.app')
@section('title', 'Dashboard')
@section('heading', 'Dashboard Kepuasan Klien')
@section('subtitle', 'Pantau hasil survei, tingkat respons, dan masukan yang perlu ditindaklanjuti.')
@section('actions')@if (auth()->user()->role === 'admin')
    <a class="btn primary" href="{{ route('surveys.create') }}">
        ＋ Buat kuesioner</a>
@endif @endsection
@section('content')
    @include('layouts.filters')
    @if ($responses->contains(fn($r) => $r->invitation->is_demo))
        <p class="demo-label">Data contoh disertakan. Pilih sumber Operasional untuk laporan aktual.</p>
    @endif
    @include('layouts.metrics')
    @if ($openFollow > 0)
        <div class="ts-attention">
            <div>
                <strong>
                    {{ $openFollow }} tindak lanjut belum selesai
                </strong>

                <p>
                    Total di seluruh workspace, termasuk yang sedang diproses.
                </p>
            </div>

            <a class="btn" href="{{ route('followups.index') }}">
                Lihat tindak lanjut →
            </a>
        </div>
    @endif
    <div class="chart-grid">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Tren kepuasan klien</h2>
                    <p>Rata-rata rating berdasarkan bulan respons</p>
                </div><span class="legend">● Skor kepuasan</span>
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
                        aria-label="Grafik skor kepuasan dari 1 sampai 5">
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
            </div>@else<div class="empty">Grafik tersedia setelah ada respons dengan rating.</div>
            @endif
            <div class="chart-footer">
                Skala penilaian 1–5 <span>{{ $responses->whereNotNull('score')->count() }} respons dengan penilaian</span>
            </div>
        </section>
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Kepuasan per kategori</h2>
                    <p>Kenali kekuatan dan area perbaikan</p>
                </div>
            </div>
            @forelse($categories as $name=>$value)
                <div class="category">
                    <div><span>{{ $name }}</span><strong>{{ number_format($value, 2) }} <small>/ 5</small></strong>
                    </div>
                    <div class="bar-track"><i
                            style="width:{{ ($value / 5) * 100 }}%;{{ $loop->last ? 'background:#d9ad6a' : '' }}"></i></div>
            </div>@empty<div class="empty">Belum ada rating kategori.</div>
                @endforelse @if ($categories->count())
                    <div class="insight">Prioritas evaluasi: <strong>{{ $categories->keys()->last() }}</strong>, kategori
                        dengan nilai terendah.</div>
                @endif
        </section>
    </div>
    @include('layouts.client-scores')
    <div class="bottom-grid">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Respons terbaru <span class="count">{{ $responses->count() }}</span></h2>
                    <p>Masukan terbaru dari klien Anda</p>
                </div><a class="text-button" href="{{ route('responses.index', request()->query()) }}">Lihat semua →</a>
            </div>@include('layouts.response-table', ['rows' => $responses->take(5)])
        </section>
        <section class="follow-card"><span class="follow-symbol">✓</span>
            <h2>Masukan menjadi<br>tindakan nyata.</h2>
            <p><strong>{{ $openFollow }} tindak lanjut</strong> masih terbuka di seluruh workspace.</p><a class="btn"
                href="{{ route('followups.index') }}">Lihat tindak lanjut ↗</a>
        </section>
    </div>
    <p class="footnote">Response rate dihitung dari undangan yang dibuat pada periode filter. Total respons memakai tanggal
        jawaban dikirim. Kedua angka dapat berbeda periodenya.</p>
@endsection

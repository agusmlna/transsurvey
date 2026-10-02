@extends('layouts.app')
@section('title', 'Laporan')
@section('heading', 'Laporan kepuasan klien')
@section('subtitle', 'Lihat hasil survei dan unduh laporan sesuai filter yang dipilih.')
@section('actions')
    <a class="btn primary" href="{{ route('reports.pdf', request()->query()) }}">Unduh PDF</a>
    <a class="btn" href="{{ route('reports.xlsx', request()->query()) }}">Unduh Excel (.xlsx)</a>
    <a class="btn" href="{{ route('reports.csv', request()->query()) }}">CSV</a>
@endsection
@section('content')
    <link rel="stylesheet"
        href="{{ asset('assets/transsurvey-reports.css') }}?v={{ filemtime(public_path('assets/transsurvey-reports.css')) }}">
    @include('layouts.filters')
    <p class="rs-download-note">PDF berisi ringkasan dan daftar respons. Excel memuat ringkasan serta detail jawaban dan
        komentar. Hasil unduhan mengikuti filter yang sudah diterapkan.</p>
    @include('reports.document')
@endsection

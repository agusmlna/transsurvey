@extends('layouts.app')
@section('title', __('Laporan'))
@section('heading', __('Laporan kepuasan klien'))
@section('subtitle', __('Lihat hasil survei dan unduh laporan sesuai filter yang dipilih.'))
@section('actions')
    <a class="btn primary" href="{{ route('reports.pdf', request()->query()) }}">{{ __('Unduh PDF') }}</a>
    <a class="btn" href="{{ route('reports.xlsx', request()->query()) }}">{{ __('Unduh Excel (.xlsx)') }}</a>
    <a class="btn" href="{{ route('reports.csv', request()->query()) }}">CSV</a>
@endsection
@section('content')
    <link rel="stylesheet"
        href="{{ asset('assets/transsurvey-reports.css') }}?v={{ filemtime(public_path('assets/transsurvey-reports.css')) }}">
    @include('layouts.filters')
    <p class="rs-download-note">{{ __('PDF berisi ringkasan dan daftar respons. Excel memuat ringkasan serta detail jawaban dan komentar. Hasil unduhan mengikuti filter yang sudah diterapkan. Pencarian pada masing-masing tabel hanya mengatur tampilan tabel dan tidak membatasi isi unduhan.') }}</p>
    @include('reports.document', ['interactiveTables' => true])
@endsection

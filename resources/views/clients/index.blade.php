@extends('layouts.app')
@section('title',__('Klien'))
@section('heading',__('Daftar klien'))
@section('subtitle',__('Kelola kontak dan proyek penerima survei.'))
@section('actions')<a class="btn primary" href="{{ route('clients.create') }}">{{ __('＋ Tambah klien') }}</a>@endsection
@section('content')<div data-table-search-fallback><form method="get" class="search list-toolbar"><input name="q" placeholder="{{ __('Cari klien atau proyek…') }}" value="{{ request('q') }}" aria-label="{{ __('Cari klien') }}"><button class="btn">{{ __('Cari') }}</button></form></div><section class="panel"><div class="table-wrap"><table id="clients-table" class="ts-data-table" data-ts-table data-server-table="clients" data-search="{{ request('q', '') }}" aria-label="{{ __('Daftar klien') }}"><thead><tr><th>{{ __('Klien') }}</th><th>{{ __('PIC / email') }}</th><th>{{ __('Proyek') }}</th><th>Status</th><th>{{ __('Undangan') }}</th><th data-dt-order="disable">{{ __('Aksi') }}</th></tr></thead><tbody>
@include('clients.rows')
</tbody></table></div></section><div data-table-fallback="clients-table">{{ $clients->links() }}</div>@endsection

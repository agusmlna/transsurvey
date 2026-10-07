@extends('layouts.app')
@section('title','Klien')
@section('heading','Daftar klien')
@section('subtitle','Kelola kontak dan proyek penerima survei.')
@section('actions')<a class="btn primary" href="{{ route('clients.create') }}">＋ Tambah klien</a>@endsection
@section('content')<div data-table-search-fallback><form method="get" class="search list-toolbar"><input name="q" placeholder="Cari klien atau proyek…" value="{{ request('q') }}" aria-label="Cari klien"><button class="btn">Cari</button></form></div><section class="panel"><div class="table-wrap"><table id="clients-table" class="ts-data-table" data-ts-table data-server-table="clients" data-search="{{ request('q', '') }}" aria-label="Daftar klien"><thead><tr><th>Klien</th><th>PIC / email</th><th>Proyek</th><th>Status</th><th>Undangan</th><th data-dt-order="disable">Aksi</th></tr></thead><tbody>
@include('clients.rows')
</tbody></table></div></section><div data-table-fallback="clients-table">{{ $clients->links() }}</div>@endsection

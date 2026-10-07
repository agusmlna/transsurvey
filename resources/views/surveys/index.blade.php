@extends('layouts.app')
@section('title', 'Survei')
@section('heading', 'Daftar survei')
@section('subtitle', 'Kelola kuesioner, periode pengisian, dan hasil survei.')
@section('actions')<a class="btn primary" href="{{ route('surveys.create') }}">＋ Buat survei</a>@endsection
@section('content')
<form class="list-toolbar" method="get">
    <div class="search" data-table-search-fallback><input name="q" placeholder="Cari judul survei…" aria-label="Cari judul survei" value="{{ request('q') }}"><button class="btn">Cari</button></div>
    <div class="ts-list-filters"><select name="status" aria-label="Status survei"><option value="">Semua status</option>@foreach(['active'=>'Aktif','draft'=>'Draf','closed'=>'Ditutup'] as $key=>$label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select><label class="check-row"><input type="checkbox" name="template" value="1" @checked(request('template'))>Template</label><button class="btn" type="submit">Terapkan</button><a class="text-button" href="{{ route('surveys.index') }}">Reset</a></div>
</form>
<section class="panel"><div class="table-wrap"><table id="surveys-table" class="ts-data-table" data-ts-table data-server-table="surveys" data-search="{{ request('q', '') }}" aria-label="Daftar survei"><thead><tr><th data-dt-order="disable">No.</th><th>Judul survei</th><th>Periode</th><th>Status</th><th>Undangan</th><th>Respons</th><th data-dt-order="disable">Aksi</th></tr></thead><tbody>
@include('surveys.rows')
</tbody></table></div></section><div data-table-fallback="surveys-table">{{ $surveys->links() }}</div>
@endsection

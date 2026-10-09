@extends('layouts.app')
@section('title', __('Survei'))
@section('heading', __('Daftar survei'))
@section('subtitle', __('Kelola kuesioner, periode pengisian, dan hasil survei.'))
@section('actions')<a class="btn primary" href="{{ route('surveys.create') }}">{{ __('＋ Buat survei') }}</a>@endsection
@section('content')
<form class="list-toolbar" method="get">
    <div class="search" data-table-search-fallback><input name="q" placeholder="{{ __('Cari judul survei…') }}" aria-label="{{ __('Cari judul survei') }}" value="{{ request('q') }}"><button class="btn">{{ __('Cari') }}</button></div>
    <div class="ts-list-filters"><select name="status" aria-label="{{ __('Status survei') }}"><option value="">{{ __('Semua status') }}</option>@foreach(['active'=>__('Aktif'),'draft'=>__('Draf'),'closed'=>__('Ditutup')] as $key=>$label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select><label class="check-row"><input type="checkbox" name="template" value="1" @checked(request('template'))>Template</label><button class="btn" type="submit">{{ __('Terapkan') }}</button><a class="text-button" href="{{ route('surveys.index') }}">Reset</a></div>
</form>
<section class="panel"><div class="table-wrap"><table id="surveys-table" class="ts-data-table" data-ts-table data-server-table="surveys" data-search="{{ request('q', '') }}" aria-label="{{ __('Daftar survei') }}"><thead><tr><th data-dt-order="disable">No.</th><th>{{ __('Judul survei') }}</th><th>{{ __('Periode') }}</th><th>Status</th><th>{{ __('Undangan') }}</th><th>{{ __('Respons') }}</th><th data-dt-order="disable">{{ __('Aksi') }}</th></tr></thead><tbody>
@include('surveys.rows')
</tbody></table></div></section><div data-table-fallback="surveys-table">{{ $surveys->links() }}</div>
@endsection

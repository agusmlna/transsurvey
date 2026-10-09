@extends('layouts.app')
@section('title', __('Tindak Lanjut'))
@section('heading', __('Daftar tindak lanjut'))
@section('subtitle', __('Pantau penanganan masukan dari jawaban dengan nilai 1–3.'))
@section('content')
<div class="list-toolbar"><div class="tabs"><a class="{{ !request('status') ? 'selected' : '' }}" href="{{ route('followups.index') }}">{{ __('Semua') }}</a>@foreach(['open'=>__('Terbuka'),'in_progress'=>__('Diproses'),'resolved'=>__('Selesai')] as $key=>$text)<a class="{{ request('status') === $key ? 'selected' : '' }}" href="{{ route('followups.index', ['status'=>$key]) }}">{{ $text }}</a>@endforeach</div></div>
<section class="panel"><div class="table-wrap"><table id="followups-table" class="ts-data-table" data-ts-table data-server-table="followups" data-search="{{ request('q', '') }}" aria-label="{{ __('Daftar tindak lanjut') }}"><thead><tr><th data-dt-order="disable">No.</th><th>{{ __('Klien / survei') }}</th><th>PIC</th><th>{{ __('Tenggat') }}</th><th>Status</th><th data-dt-order="disable">{{ __('Aksi') }}</th></tr></thead><tbody>
@include('followups.rows')
</tbody></table></div></section><div data-table-fallback="followups-table">{{ $followups->links() }}</div>
@endsection

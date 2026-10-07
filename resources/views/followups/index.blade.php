@extends('layouts.app')
@section('title', 'Tindak Lanjut')
@section('heading', 'Daftar tindak lanjut')
@section('subtitle', 'Pantau penanganan masukan dari jawaban dengan nilai 1–3.')
@section('content')
<div class="list-toolbar"><div class="tabs"><a class="{{ !request('status') ? 'selected' : '' }}" href="{{ route('followups.index') }}">Semua</a>@foreach(['open'=>'Terbuka','in_progress'=>'Diproses','resolved'=>'Selesai'] as $key=>$text)<a class="{{ request('status') === $key ? 'selected' : '' }}" href="{{ route('followups.index', ['status'=>$key]) }}">{{ $text }}</a>@endforeach</div></div>
<section class="panel"><div class="table-wrap"><table id="followups-table" class="ts-data-table" data-ts-table data-server-table="followups" data-search="{{ request('q', '') }}" aria-label="Daftar tindak lanjut"><thead><tr><th data-dt-order="disable">No.</th><th>Klien / survei</th><th>PIC</th><th>Tenggat</th><th>Status</th><th data-dt-order="disable">Aksi</th></tr></thead><tbody>
@include('followups.rows')
</tbody></table></div></section><div data-table-fallback="followups-table">{{ $followups->links() }}</div>
@endsection

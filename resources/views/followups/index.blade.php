@extends('layouts.app')
@section('title', 'Tindak Lanjut')
@section('heading', 'Daftar tindak lanjut')
@section('subtitle', 'Pantau penanganan masukan dari jawaban dengan nilai 1–3.')
@section('content')
<div class="list-toolbar"><div class="tabs"><a class="{{ !request('status') ? 'selected' : '' }}" href="{{ route('followups.index') }}">Semua</a>@foreach(['open'=>'Terbuka','in_progress'=>'Diproses','resolved'=>'Selesai'] as $key=>$text)<a class="{{ request('status') === $key ? 'selected' : '' }}" href="{{ route('followups.index', ['status'=>$key]) }}">{{ $text }}</a>@endforeach</div></div>
<section class="panel"><div class="table-wrap"><table class="ts-data-table"><thead><tr><th>No.</th><th>Klien / survei</th><th>PIC</th><th>Tenggat</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
@forelse($followups as $f)
<tr><td>{{ $followups->firstItem() + $loop->index }}</td><td><strong>{{ $f->response->client->name }}</strong><small>{{ $f->response->survey->title }}</small></td><td>{{ $f->assignee?->name ?? 'Belum ditugaskan' }}</td><td class="ts-nowrap">{{ $f->due_at?->format('d M Y') ?? 'Belum ada tenggat' }}</td><td><span class="badge {{ $f->status === 'resolved' ? 'green' : ($f->status === 'open' ? 'amber' : 'blue') }}">{{ ['open'=>'Terbuka','in_progress'=>'Diproses','resolved'=>'Selesai'][$f->status] }}</span></td><td><div class="row-actions"><a class="text-button" href="{{ route('responses.show', $f->response) }}">Lihat masukan</a>@if(auth()->user()->role === 'admin')<a class="text-button" href="{{ route('followups.edit', $f) }}">Kelola</a>@endif</div></td></tr>
@empty<tr><td colspan="6" class="empty">Belum ada tindak lanjut untuk status ini.</td></tr>@endforelse
</tbody></table></div></section>{{ $followups->links() }}
@endsection

@extends('layouts.app')
@section('title', 'Survei')
@section('heading', 'Daftar survei')
@section('subtitle', 'Kelola kuesioner, periode pengisian, dan hasil survei.')
@section('actions')<a class="btn primary" href="{{ route('surveys.create') }}">＋ Buat survei</a>@endsection
@section('content')
<form class="list-toolbar" method="get">
    <div class="search"><input name="q" placeholder="Cari judul survei…" aria-label="Cari judul survei" value="{{ request('q') }}"><button class="btn">Cari</button></div>
    <div class="ts-list-filters"><select name="status" aria-label="Status survei"><option value="">Semua status</option>@foreach(['active'=>'Aktif','draft'=>'Draf','closed'=>'Ditutup'] as $key=>$label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select><label class="check-row"><input type="checkbox" name="template" value="1" @checked(request('template'))>Template</label><button class="btn" type="submit">Terapkan</button><a class="text-button" href="{{ route('surveys.index') }}">Reset</a></div>
</form>
<section class="panel"><div class="table-wrap"><table class="ts-data-table"><thead><tr><th>No.</th><th>Judul survei</th><th>Periode</th><th>Status</th><th>Undangan</th><th>Respons</th><th>Aksi</th></tr></thead><tbody>
@forelse($surveys as $s)
<tr><td>{{ $surveys->firstItem() + $loop->index }}</td><td><strong>{{ $s->title }}</strong>@if($s->is_demo)<small>Data contoh</small>@endif @if($s->is_template)<span class="badge gray">Template</span>@endif</td><td class="ts-nowrap">{{ $s->starts_at->format('d M Y') }}<small>s.d. {{ $s->ends_at->format('d M Y') }}</small></td><td><span class="badge {{ $s->status === 'active' ? 'green' : ($s->status === 'draft' ? 'amber' : 'gray') }}">{{ ['active'=>'Aktif','draft'=>'Draf','closed'=>'Ditutup'][$s->status] }}</span></td><td>{{ $s->invitations_count }}</td><td>{{ $s->responses_count }}</td><td><div class="row-actions"><a class="text-button" href="{{ route('surveys.edit', $s) }}">Edit</a><a class="text-button" href="{{ route('surveys.preview', $s) }}" target="_blank" rel="noopener">Pratinjau ↗</a><form method="post" action="{{ route('surveys.duplicate', $s) }}" data-confirm="Buat salinan kuesioner ini?">@csrf<button class="text-button">Duplikat</button></form></div></td></tr>
@empty<tr><td colspan="7" class="empty"><strong>Belum ada survei yang sesuai.</strong><p>Buat survei baru atau ubah pencarian dan filter.</p></td></tr>@endforelse
</tbody></table></div></section>{{ $surveys->links() }}
@endsection

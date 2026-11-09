@extends('layouts.app')

@section('title', __('Template CSAT'))
@section('heading', __('Template CSAT'))
@section('subtitle', __('Kelola pertanyaan CSAT yang akan diisi customer workspace ini.'))

@section('actions')
    <a class="btn" href="{{ route('csat.settings.edit') }}">{{ __('Pengaturan masa berlaku') }}</a>
    <a class="btn primary" href="{{ route('csat.templates.create') }}">{{ __('＋ Buat template CSAT') }}</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/csat.css') }}?v={{ filemtime(public_path('assets/csat.css')) }}">
@endpush

@section('content')
    <section class="panel csat-overview" aria-label="{{ __('Ringkasan CSAT workspace') }}">
        <span class="csat-overview-icon">
            @include('layouts.icon', ['name' => 'survey'])
        </span>
        <div class="csat-overview-main">
            <span class="csat-eyebrow">{{ __('Workspace aktif') }}</span>
            <h2>{{ $workspace->name }}</h2>
            <p>{{ __('Template di bawah hanya berlaku untuk workspace ini.') }}</p>
        </div>
        <div class="csat-expiry">
            <span class="csat-eyebrow">{{ __('Masa berlaku tautan CSAT') }}</span>
            <strong>{{ $expiryLabel }}</strong>
            <a class="text-button" href="{{ route('csat.settings.edit') }}">{{ __('Ubah pengaturan') }}</a>
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>{{ __('Daftar template') }}</h2>
                <p>{{ __('Pilih satu template default yang dipakai saat mengirim CSAT dari tiket.') }}</p>
            </div>
            <span class="badge blue">{{ $templates->total() }} {{ __('template') }}</span>
        </div>

        <div class="table-wrap">
            <table class="ts-data-table csat-table" aria-label="{{ __('Daftar template CSAT') }}">
                <thead>
                    <tr>
                        <th data-dt-order="disable">No.</th>
                        <th>{{ __('Template') }}</th>
                        <th>{{ __('Pertanyaan') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Diperbarui') }}</th>
                        <th data-dt-order="disable">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($templates as $template)
                        <tr>
                            <td>{{ $templates->firstItem() + $loop->index }}</td>
                            <td>
                                <div class="csat-template-title">
                                    <strong>{{ $template->title }}</strong>
                                    @if ((int) $defaultId === $template->id)
                                        <span class="badge green">{{ __('Default') }}</span>
                                    @endif
                                </div>
                                @if ($template->description)
                                    <small
                                        class="csat-template-desc">{{ \Illuminate\Support\Str::limit($template->description, 110) }}</small>
                                @endif
                            </td>
                            <td>{{ $template->questions_count }}</td>
                            <td>
                                <span
                                    class="badge {{ $template->status === 'active' ? 'green' : ($template->status === 'draft' ? 'amber' : 'gray') }}">
                                    {{ ['active' => __('Aktif'), 'draft' => __('Draf'), 'closed' => __('Ditutup')][$template->status] ?? $template->status }}
                                </span>
                            </td>
                            <td class="ts-nowrap">{{ $template->updated_at->format('d M Y') }}</td>
                            <td>
                                <div class="row-actions">
                                    <a class="text-button"
                                        href="{{ route('csat.templates.edit', $template) }}">{{ __('Edit') }}</a>
                                    <a class="text-button" href="{{ route('csat.templates.preview', $template) }}"
                                        target="_blank" rel="noopener">{{ __('Pratinjau ↗') }}</a>
                                    @if ((int) $defaultId !== $template->id)
                                        <form method="post" action="{{ route('csat.templates.default', $template) }}">
                                            @csrf
                                            <button
                                                class="text-button">{{ __('Jadikan default') }}</button>
                                        </form>
                                    @endif
                                    <form method="post" action="{{ route('csat.templates.duplicate', $template) }}"
                                        data-confirm="{{ __('Buat salinan template ini?') }}">
                                        @csrf
                                        <button class="text-button">{{ __('Duplikat') }}</button>
                                    </form>
                                    <form method="post" action="{{ route('csat.templates.destroy', $template) }}"
                                        data-confirm="{{ __('Hapus template ini? Tindakan ini tidak dapat dibatalkan.') }}">
                                        @csrf
                                        @method('delete')
                                        <button class="text-button danger">{{ __('Hapus') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="empty">
                                <strong>{{ __('Belum ada template CSAT.') }}</strong>
                                <p>{{ __('Buat template pertama; sistem akan menyiapkan tiga pertanyaan contoh yang bisa Anda ubah.') }}
                                </p>
                                <a class="btn primary"
                                    href="{{ route('csat.templates.create') }}">{{ __('＋ Buat template CSAT') }}</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($templates->hasPages())
            <div class="csat-pagination">{{ $templates->links() }}</div>
        @endif
    </section>
@endsection

@extends('layouts.app')

@section('title', __('Template CSAT'))
@section('heading', __('Template CSAT'))
@section('subtitle', __('Kelola pertanyaan CSAT yang akan diisi customer workspace ini.'))

@section('actions')
    <a class="btn" href="{{ route('csat.settings.edit') }}">{{ __('Pengaturan masa berlaku') }}</a>
    <a class="btn primary" href="{{ route('csat.templates.create') }}">{{ __('＋ Buat template CSAT') }}</a>
@endsection

@section('content')
    <section class="panel" style="margin-bottom:16px">
        <div class="panel-head">
            <div>
                <h2>{{ __('Workspace: :name', ['name' => $workspace->name]) }}</h2>
                <p>{{ __('Masa berlaku tautan CSAT: :value', ['value' => $expiryLabel]) }}</p>
            </div>
            <a class="text-button" href="{{ route('csat.settings.edit') }}">{{ __('Ubah') }}</a>
        </div>
    </section>

    <section class="panel">
        <div class="table-wrap">
            <table class="ts-data-table" aria-label="{{ __('Daftar template CSAT') }}">
                <thead>
                    <tr>
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
                            <td>
                                <strong>{{ $template->title }}</strong>
                                @if ((int) $defaultId === $template->id)
                                    <span class="badge green">{{ __('Default') }}</span>
                                @endif
                                @if ($template->description)
                                    <small>{{ \Illuminate\Support\Str::limit($template->description, 90) }}</small>
                                @endif
                            </td>
                            <td>{{ $template->questions_count }}</td>
                            <td>
                                <span class="badge {{ $template->status === 'active' ? 'green' : ($template->status === 'draft' ? 'amber' : 'gray') }}">
                                    {{ ['active' => __('Aktif'), 'draft' => __('Draf'), 'closed' => __('Ditutup')][$template->status] ?? $template->status }}
                                </span>
                            </td>
                            <td class="ts-nowrap">{{ $template->updated_at->format('d M Y') }}</td>
                            <td>
                                <div class="row-actions">
                                    <a class="text-button" href="{{ route('csat.templates.edit', $template) }}">{{ __('Edit') }}</a>
                                    <a class="text-button" href="{{ route('csat.templates.preview', $template) }}" target="_blank" rel="noopener">{{ __('Pratinjau ↗') }}</a>
                                    @if ((int) $defaultId !== $template->id)
                                        <form method="post" action="{{ route('csat.templates.default', $template) }}">
                                            @csrf
                                            <button class="text-button">{{ __('Jadikan default') }}</button>
                                        </form>
                                    @endif
                                    <form method="post" action="{{ route('csat.templates.duplicate', $template) }}" data-confirm="{{ __('Buat salinan template ini?') }}">
                                        @csrf
                                        <button class="text-button">{{ __('Duplikat') }}</button>
                                    </form>
                                    <form method="post" action="{{ route('csat.templates.destroy', $template) }}" data-confirm="{{ __('Hapus template ini? Tindakan ini tidak dapat dibatalkan.') }}">
                                        @csrf
                                        @method('delete')
                                        <button class="text-button danger">{{ __('Hapus') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="empty">
                                <strong>{{ __('Belum ada template CSAT.') }}</strong>
                                <p>{{ __('Buat template pertama; sistem akan menyiapkan tiga pertanyaan contoh yang bisa Anda ubah.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="table-footer">{{ $templates->links() }}</div>
@endsection

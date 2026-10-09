@extends('layouts.app')
@section('title',__('Bank Pertanyaan'))
@section('heading',__('Bank pertanyaan'))
@section('subtitle',__('Simpan pertanyaan untuk dipakai kembali pada kuesioner berikutnya.'))
@section('content')<details class="panel distribution-form"><summary>{{ __('＋ Tambah pertanyaan') }}</summary><form method="post" action="{{ route('bank.store') }}" data-confirm="{{ __('Tambahkan pertanyaan ini ke bank pertanyaan?') }}" data-confirm-title="{{ __('Simpan pertanyaan?') }}" data-confirm-button="{{ __('Ya, simpan') }}" data-busy-text="{{ __('Sedang menyimpan…') }}">@csrf<label>{{ __('Pertanyaan') }}<textarea name="text" required maxlength="2000">{{ old('text') }}</textarea></label><div class="form-grid"><label>{{ __('Kategori') }}<input name="category" required value="{{ old('category') }}" maxlength="100"></label><label>{{ __('Jenis') }}<select name="type"><option value="rating">Rating 1–5</option><option value="choice">{{ __('Pilihan ganda') }}</option><option value="text">{{ __('Teks terbuka') }}</option></select></label></div><label>{{ __('Pilihan jawaban (untuk pilihan ganda, satu per baris)') }}<textarea name="options">{{ old('options') }}</textarea></label><label class="check-row"><input type="checkbox" name="required" value="1" checked>{{ __('Wajib diisi') }}</label><button class="btn primary">{{ __('Simpan pertanyaan') }}</button></form></details><section class="panel"><div class="table-wrap">
<table id="bank-table" class="ts-data-table" data-ts-table data-server-table="bank" aria-label="{{ __('Bank pertanyaan') }}">
<thead><tr><th>{{ __('Pertanyaan') }}</th><th>{{ __('Kategori') }}</th><th>{{ __('Jenis') }}</th><th>{{ __('Pengisian') }}</th><th data-dt-order="disable">{{ __('Aksi') }}</th></tr></thead>
<tbody>@include('bank.rows')</tbody></table></div></section>
<div data-table-fallback="bank-table">{{ $questions->links() }}</div>
@endsection

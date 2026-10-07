@extends('layouts.app')
@section('title','Bank Pertanyaan')
@section('heading','Bank pertanyaan')
@section('subtitle','Simpan pertanyaan untuk dipakai kembali pada kuesioner berikutnya.')
@section('content')<details class="panel distribution-form"><summary>＋ Tambah pertanyaan</summary><form method="post" action="{{ route('bank.store') }}" data-confirm="Tambahkan pertanyaan ini ke bank pertanyaan?" data-confirm-title="Simpan pertanyaan?" data-confirm-button="Ya, simpan" data-busy-text="Sedang menyimpan…">@csrf<label>Pertanyaan<textarea name="text" required maxlength="2000">{{ old('text') }}</textarea></label><div class="form-grid"><label>Kategori<input name="category" required value="{{ old('category') }}" maxlength="100"></label><label>Jenis<select name="type"><option value="rating">Rating 1–5</option><option value="choice">Pilihan ganda</option><option value="text">Teks terbuka</option></select></label></div><label>Pilihan jawaban (untuk pilihan ganda, satu per baris)<textarea name="options">{{ old('options') }}</textarea></label><label class="check-row"><input type="checkbox" name="required" value="1" checked>Wajib diisi</label><button class="btn primary">Simpan pertanyaan</button></form></details><section class="panel"><div class="table-wrap">
<table id="bank-table" class="ts-data-table" data-ts-table data-server-table="bank" aria-label="Bank pertanyaan">
<thead><tr><th>Pertanyaan</th><th>Kategori</th><th>Jenis</th><th>Pengisian</th><th data-dt-order="disable">Aksi</th></tr></thead>
<tbody>@include('bank.rows')</tbody></table></div></section>
<div data-table-fallback="bank-table">{{ $questions->links() }}</div>
@endsection

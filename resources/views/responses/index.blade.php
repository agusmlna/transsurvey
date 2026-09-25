@extends('layouts.app')
@section('title','Respons')
@section('heading','Suara dari klien Anda')
@section('subtitle','Baca masukan dan pahami pengalaman mereka.')
@section('actions')<a class="btn" href="{{ route('reports.csv',request()->query()) }}">↓ Ekspor CSV / Excel</a>@endsection
@section('content')@include('layouts.filters')<section class="panel"><div class="panel-heading"><h2>Semua respons <span class="count">{{ $responses->total() }}</span></h2></div>@include('layouts.response-table',['rows'=>$responses])</section>{{ $responses->links() }}@endsection

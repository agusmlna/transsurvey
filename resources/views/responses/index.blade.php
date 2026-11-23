@extends('layouts.app')
@section('title', __('Respons'))
@section('heading', __('Suara dari klien Anda'))
@section('subtitle', __('Baca masukan dan pahami pengalaman mereka, termasuk status pengisian CSAT.'))
@section('actions')<a class="btn" href="{{ route('reports.csv', request()->query()) }}">{{ __('↓ Ekspor CSV / Excel') }}</a>@endsection
@section('content')@include('layouts.filters')<section class="panel">
        <div class="panel-heading">
            <h2>{{ __('Semua respons') }} <span class="count">{{ $responses->total() }}</span></h2>
        </div>@include('layouts.response-table', ['rows' => $responses, 'showAction' => true])
    </section><div data-table-fallback="responses-table">{{ $responses->links() }}</div>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>{{ __('Status pengisian CSAT') }}</h2>
                <p>{{ __('Admin dapat memantau tiket yang sudah dikirim dan sudah diisi customer.') }}</p>
            </div>
            <span class="badge blue">{{ $csatInvitations->total() }} {{ __('undangan') }}</span>
        </div>
        @include('csat.status-table', [
            'csatInvitations' => $csatInvitations,
            'csatStatus' => $csatStatus,
            'interactiveTables' => true,
        ])
    </section>
@endsection

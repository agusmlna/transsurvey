@extends('layouts.app')
@section('title', __('data_cleanup.history'))
@section('heading', __('data_cleanup.history'))
@section('subtitle', __('data_cleanup.audit_note'))
@section('actions')<a class="btn" href="{{ route('data-cleanup.index') }}">{{ __('data_cleanup.back') }}</a>@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('assets/transsurvey-cleanup.css') }}">@endpush
@section('content')
<section class="panel cleanup-panel"><div class="table-wrap"><table class="cleanup-table"><thead><tr><th>{{ __('data_cleanup.date') }}</th><th>{{ __('data_cleanup.actor') }}</th><th>{{ __('data_cleanup.type') }} / {{ __('data_cleanup.ids') }}</th><th>{{ __('data_cleanup.impact') }}</th><th>{{ __('data_cleanup.reason') }}</th></tr></thead><tbody>
@forelse ($audits as $audit)<tr><td>{{ $audit->created_at }}</td><td>{{ $audit->actor_name }}</td><td>{{ __('data_cleanup.'.$audit->entity) }}<small>{{ implode(', ', json_decode($audit->record_ids, true)) }}</small></td><td>@foreach (json_decode($audit->summary, true) as $key => $count)@if ($count)<small>{{ __('data_cleanup.'.$key) }}: {{ $count }}</small>@endif @endforeach</td><td>{{ $audit->reason }}</td></tr>
@empty<tr><td colspan="5">{{ __('data_cleanup.audit_empty') }}</td></tr>@endforelse
</tbody></table></div><div class="cleanup-pagination">{{ $audits->links() }}</div></section>
@endsection

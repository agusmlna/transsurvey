@extends('layouts.app')
@section('title', __('data_cleanup.preview_title'))
@section('heading', __('data_cleanup.preview_title'))
@section('subtitle', __('data_cleanup.preview_subtitle'))
@section('actions')<a class="btn" href="{{ route('data-cleanup.index', ['type' => $preview['type']]) }}">{{ __('data_cleanup.back') }}</a>@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('assets/transsurvey-cleanup.css') }}">@endpush
@push('scripts')<script defer src="{{ asset('assets/transsurvey-cleanup.js') }}"></script>@endpush
@section('content')
<div class="cleanup-notice"><strong>{{ __('data_cleanup.warning') }}</strong><p>{{ __('data_cleanup.no_restore') }}</p></div>
<div class="cleanup-grid">
<section class="panel cleanup-card"><h2>{{ __('data_cleanup.selected') }} · {{ __('data_cleanup.'.$preview['type']) }}</h2><ul class="cleanup-selected">@foreach ($preview['labels'] as $label)<li><strong>#{{ $label['id'] }} · {{ $label['name'] }}</strong><small>{{ $label['detail'] }}</small></li>@endforeach</ul>
@if ($preview['type'] === 'responses')<p>{{ __('data_cleanup.ids') }}: {{ implode(', ', $preview['ids']) }}</p>@endif
</section>
<section class="panel cleanup-card"><h2>{{ __('data_cleanup.impact') }}</h2><dl class="cleanup-counts">@foreach ($preview['counts'] as $key => $count)@if ($count)<div><dt>{{ __('data_cleanup.'.$key) }}</dt><dd>{{ $count }}</dd></div>@endif @endforeach</dl></section>
</div>
@if (in_array($preview['type'], ['invitations','responses']))<p class="cleanup-help">{{ __('data_cleanup.response_warning') }} {{ __('data_cleanup.email_warning') }}</p>@endif
<form method="post" action="{{ route('data-cleanup.destroy', $preview['token']) }}" class="panel cleanup-card cleanup-confirm" data-cleanup-confirm data-submit-feedback data-busy-text="{{ __('data_cleanup.busy') }}">
    @csrf
    <h2>{{ __('data_cleanup.reason') }}</h2><p>{{ $preview['reason'] }}</p>
    <label class="cleanup-check"><input type="checkbox" name="acknowledge" value="1" required> <span>{{ __('data_cleanup.ack') }}</span></label>
    <label for="delete-confirmation">{{ __('data_cleanup.confirm') }}</label>
    <input id="delete-confirmation" name="confirmation" autocomplete="off" required pattern="HAPUS" maxlength="5" placeholder="HAPUS">
    <div class="cleanup-buttons"><a class="btn" href="{{ route('data-cleanup.index', ['type' => $preview['type']]) }}">{{ __('data_cleanup.cancel') }}</a><button type="submit" class="btn cleanup-danger">{{ __('data_cleanup.delete') }}</button></div>
</form>
@endsection

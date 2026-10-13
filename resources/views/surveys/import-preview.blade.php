@extends('layouts.app')
@section('title', __('question_import.title'))
@section('heading', __('question_import.review'))
@section('subtitle', __('question_import.review_hint'))
@section('actions')
    <a class="btn" href="{{ route('surveys.import.create', $survey ? ['survey_id' => $survey->id] : []) }}">{{ __('question_import.replace_file') }}</a>
@endsection
@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/transsurvey-import.css') }}?v={{ filemtime(public_path('assets/transsurvey-import.css')) }}">
@endpush
@section('content')
<section class="panel qi-card">
    <div class="qi-review-header">
        <div><span class="qi-eyebrow">02 / {{ __('question_import.review') }}</span><h2>{{ $survey?->title ?? $batch->payload['metadata']['title'] }}</h2><p class="qi-muted">{{ $survey ? __('question_import.append_mode') : __('question_import.new_draft') }}</p></div>
        <span class="qi-count">{{ __('question_import.count', ['count' => $batch->question_count]) }}</span>
    </div>
    @if (!$survey)
        <p>{{ __('question_import.period') }}: {{ $batch->payload['metadata']['starts_at'] }} — {{ $batch->payload['metadata']['ends_at'] }}</p>
        @if (!empty($batch->payload['metadata']['description']))<p class="qi-prewrap">{{ $batch->payload['metadata']['description'] }}</p>@endif
        <p>{{ __('question_import.as_template') }}: {{ $batch->payload['metadata']['is_template'] ? __('question_import.yes') : __('question_import.no') }}</p>
    @endif
    <div class="qi-note">{{ __('question_import.preview_expiry') }} {{ __('question_import.scope') }}</div>
    @include('surveys.import-rows', ['rows' => $batch->payload['rows']])
    <form class="qi-confirm" method="post" action="{{ route('surveys.import.confirm', $batch->token) }}" data-confirm="{{ __('question_import.confirm_message') }}" data-confirm-title="{{ __('question_import.confirm') }}" data-confirm-button="{{ __('question_import.confirm') }}">
        @csrf
        <span class="qi-muted">{{ __('question_import.confirm_hint') }}</span>
        <button class="btn primary" type="submit">{{ __('question_import.confirm') }}</button>
    </form>
</section>
@endsection

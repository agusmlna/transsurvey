@extends('layouts.app')
@section('title', __('question_import.title'))
@section('heading', __('question_import.title'))
@section('subtitle', __('question_import.subtitle'))
@section('actions')
    <a class="btn" href="{{ $survey ? route('surveys.edit', $survey) : route('surveys.index') }}">{{ __('question_import.back') }}</a>
    <a class="btn primary" href="{{ route('surveys.import.template') }}">{{ __('question_import.download') }}</a>
@endsection
@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/transsurvey-import.css') }}?v={{ filemtime(public_path('assets/transsurvey-import.css')) }}">
@endpush
@section('content')
<div class="qi-layout">
    <section class="panel qi-card">
        <span class="qi-eyebrow">01 / {{ __('question_import.prepare') }}</span>
        <h2>{{ __('question_import.upload_heading') }}</h2>
        <p class="qi-muted">{{ __('question_import.sheet_hint') }}</p>
        <form method="post" action="{{ route('surveys.import.preview') }}" enctype="multipart/form-data" data-submit-feedback data-busy-text="{{ __('question_import.checking') }}">
            @csrf
            @if ($survey)
                <input type="hidden" name="survey_id" value="{{ $survey->id }}">
                <div class="qi-target"><span>{{ __('question_import.target') }}</span><strong>{{ $survey->title }}</strong><small>{{ __('question_import.append_hint', ['count' => $survey->questions()->count()]) }}</small></div>
            @else
                <div class="qi-target"><strong>{{ __('question_import.new_draft') }}</strong><small>{{ __('question_import.new_hint') }}</small></div>
                <label class="qi-field">{{ __('question_import.survey_title') }}<input name="title" value="{{ old('title') }}" maxlength="255" required></label>
                <label class="qi-field">{{ __('question_import.description') }}<textarea name="description" rows="3" maxlength="5000">{{ old('description') }}</textarea></label>
                <div class="qi-dates">
                    <label class="qi-field">{{ __('question_import.start') }}<input type="date" name="starts_at" value="{{ old('starts_at', today()->format('Y-m-d')) }}" required></label>
                    <label class="qi-field">{{ __('question_import.end') }}<input type="date" name="ends_at" value="{{ old('ends_at', today()->addMonth()->format('Y-m-d')) }}" required></label>
                </div>
                <label class="check-row"><input type="checkbox" name="is_template" value="1" @checked(old('is_template'))>{{ __('question_import.as_template') }}</label>
            @endif
            <label class="qi-upload" for="qi-file"><strong>{{ __('question_import.file_label') }}</strong><span>{{ __('question_import.file_hint') }}</span><input id="qi-file" type="file" name="file" accept=".xlsx" required></label>
            <p class="qi-muted">{{ __('question_import.no_save_yet') }}</p>
            <button class="btn primary" type="submit">{{ __('question_import.preview') }}</button>
        </form>
    </section>
    <aside class="panel qi-card qi-help">
        <span class="qi-eyebrow">{{ __('question_import.instructions') }}</span>
        <h2>{{ __('question_import.format') }}</h2>
        <ol><li>{{ __('question_import.step1') }}</li><li>{{ __('question_import.step2') }}</li><li>{{ __('question_import.step3') }}</li></ol>
        <dl>
            <dt>rating</dt><dd>{{ __('question_import.rating_hint') }}</dd>
            <dt>text</dt><dd>{{ __('question_import.text_hint') }}</dd>
            <dt>choice / multiple</dt><dd>{{ __('question_import.choice_hint') }}</dd>
        </dl>
        <div class="qi-note">{{ __('question_import.scope') }}</div>
    </aside>
</div>
@if ($result)
    <section class="panel qi-card qi-result" aria-labelledby="qi-result-title">
        <h2 id="qi-result-title">{{ __('question_import.fix_heading') }}</h2>
        <p>{{ __('question_import.invalid_summary', ['count' => $result['invalid']]) }}</p>
        @foreach ($result['errors'] as $error)<p class="qi-error" role="alert">{{ $error }}</p>@endforeach
        @include('surveys.import-rows', ['rows' => $result['rows']])
    </section>
@endif
@endsection

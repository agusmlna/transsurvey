<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $title }} · TransSurvey</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/survey-access.css') }}?v={{ filemtime(public_path('assets/survey-access.css')) }}">
    <script defer src="{{ asset('assets/transsurvey.js') }}?v={{ filemtime(public_path('assets/transsurvey.js')) }}"></script>
    @include('layouts.i18n')
</head>

<body class="ts-access-page">
    <main class="ts-access-card">
        <div class="ts-language-row">@include('layouts.language-switch')</div>
        <header class="ts-access-brand">@include('layouts.brand')</header>
        <div class="ts-access-symbol" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
        </div>
        <p class="ts-access-eyebrow">{{ __('TAUTAN CSAT') }}</p>
        <h1>{{ $title }}</h1>
        <p class="ts-access-intro">{{ $message }}</p>
        @isset($survey)
            <div class="ts-access-survey"><strong>{{ $survey->title }}</strong></div>
        @endisset
        <p class="ts-access-help">{{ __('Hubungi tim layanan kami jika Anda memerlukan bantuan lebih lanjut.') }}</p>
        <footer class="ts-access-footer">TransSurvey <span aria-hidden="true">·</span> Client Satisfaction Management</footer>
    </main>
</body>

</html>

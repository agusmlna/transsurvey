<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('Tidak dapat membuka TransSurvey') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey-motion.css') }}?v={{ filemtime(public_path('assets/transsurvey-motion.css')) }}">
    @include('layouts.i18n')
</head>
<body class="login-page">
    <section class="login-brand" aria-label="{{ __('Tentang TransSurvey') }}">
        <a class="ts-login-logo" href="{{ route('login') }}" aria-label="TransSurvey"><img src="{{ asset('images/tcid logo.png') }}" alt="Transcosmos Indonesia" width="112" height="42" data-brand-logo></a>
        <div class="ts-login-message">
            <h1>TransSurvey</h1><p class="ts-login-caption">Client Satisfaction Management</p>
            <img class="ts-login-illustration" src="{{ asset('assets/survey-illustration.svg') }}" width="280" height="220" alt="" aria-hidden="true">
            <h2>{{ __('Dengarkan feedback.') }}<br>{{ __('Bangun layanan lebih baik.') }}</h2>
            <p>{{ __('Kelola survei dan pahami pengalaman klien dalam satu tempat.') }}</p>
        </div>
        <small>PT Transcosmos Indonesia</small>
    </section>
    <main class="login-main">
        <div class="login-form">
            <div class="ts-language-row">@include('layouts.language-switch')</div>
            <span class="eyebrow">{{ __('AKSES DITOLAK') }}</span>
            <h1>{{ __('Tidak dapat membuka TransSurvey') }}</h1>
            <div class="notice error" role="alert">{{ $message }}</div>
            <p class="footnote">{{ __('Jika masalah berlanjut, hubungi administrator TransSurvey.') }}</p>
            <a class="btn primary full" href="{{ route('login') }}">{{ __('Kembali ke halaman login') }}</a>
        </div>
    </main>
</body>
</html>

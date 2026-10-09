<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login · TransSurvey</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey-motion.css') }}?v={{ filemtime(public_path('assets/transsurvey-motion.css')) }}">
    <script defer src="{{ asset('assets/transsurvey.js') }}?v={{ filemtime(public_path('assets/transsurvey.js')) }}"></script>
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
            <span class="eyebrow">{{ __('SELAMAT DATANG KEMBALI') }}</span>
            <h1>{{ __('Login ke TransSurvey') }}</h1><p>{{ __('Masuk untuk mengelola survei dan melihat hasilnya.') }}</p>
            @if (session('success'))
                <div class="notice success" role="status" data-success-message><p>{{ __(session('success')) }}</p></div>
            @endif
            @if ($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('login.submit') }}">@csrf
                <label for="email">Email<input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="{{ __('nama@perusahaan.co.id') }}" autocomplete="username" required autofocus></label>
                <label for="password">Password<span class="ts-password-field"><input id="password" type="password" name="password" placeholder="{{ __('Masukkan password') }}" autocomplete="current-password" required><button type="button" class="ts-password-toggle" data-toggle-password="password" aria-label="{{ __('Tampilkan password') }}" aria-pressed="false">{{ __('Lihat') }}</button></span></label>
                <button type="submit" class="btn primary full">Login</button>
            </form>
            <p class="footnote"><strong>{{ __('Lupa password atau belum punya akun?') }}</strong><br>{{ __('Hubungi administrator aplikasi.') }}</p>
        </div>
    </main>
</body>
</html>

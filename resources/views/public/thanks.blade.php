<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('Terima kasih ·') }} {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
    @include('layouts.i18n')
</head>

<body class="respondent-page">
    <main class="thanks"><div class="ts-language-row">@include('layouts.language-switch')</div><span class="success-mark">✓</span>
        <h1>{{ __('Terima kasih atas suara Anda.') }}</h1>
        <p>{{ __('Respons Anda sudah tercatat. Masukan Anda membantu kami terus meningkatkan kualitas layanan.') }}</p><small>{{ __('Satu undangan hanya menerima satu respons akhir.') }}</small>
    </main>
</body>

</html>

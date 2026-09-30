<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <title>Kode akses survei · TransSurvey</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/survey-access.css') }}?v={{ filemtime(public_path('assets/survey-access.css')) }}">
    <script defer src="{{ asset('assets/transsurvey.js') }}?v={{ filemtime(public_path('assets/transsurvey.js')) }}"></script>
</head>
<body class="ts-access-page">
    <main class="ts-access-card">
        <header class="ts-access-brand">@include('layouts.brand')</header>
        <div class="ts-access-symbol" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg>
        </div>
        @if (!$available)
            <p class="ts-access-eyebrow">PERIODE PENGISIAN</p>
            <h1>Survei belum tersedia</h1>
            <p class="ts-access-intro">Survei ini sedang tidak menerima respons. Hubungi pengirim undangan jika Anda memerlukan informasi lebih lanjut.</p>
            <div class="ts-access-survey"><strong>{{ $invitation->survey->title }}</strong><small>{{ $invitation->survey->starts_at->format('d M Y') }} – {{ $invitation->survey->ends_at->format('d M Y') }}</small></div>
        @else
            <p class="ts-access-eyebrow">VERIFIKASI UNDANGAN</p>
            <h1>Masukkan kode akses</h1>
            <p class="ts-access-intro">Gunakan 6 angka yang tercantum pada email undangan untuk membuka survei Anda.</p>
            <div class="ts-access-survey"><span>Survei yang akan diisi</span><strong>{{ $invitation->survey->title }}</strong></div>
            @if ($retryAfter > 0)
                <div class="ts-access-error" role="alert">Terlalu banyak percobaan. Tunggu sekitar {{ (int) ceil($retryAfter / 60) }} menit sebelum mencoba kembali.</div>
            @endif
            @if ($errors->any())
                <div id="access-code-error" class="ts-access-error" role="alert">{{ $errors->first() }}</div>
            @endif
            @if ($codeReady)
                <form method="post" action="{{ route('survey.verify', $invitation->token) }}" data-submit-feedback data-busy-text="Memeriksa kode…">
                    @csrf
                    <label for="access-code">Kode akses dari email</label>
                    <input class="ts-access-input" id="access-code" name="access_code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" minlength="6" placeholder="000000" required aria-describedby="access-code-hint{{ $errors->any() ? ' access-code-error' : '' }}" aria-invalid="{{ $errors->has('access_code') ? 'true' : 'false' }}">
                    <p id="access-code-hint" class="ts-access-hint">Kode berlaku untuk undangan ini selama survei masih aktif.</p>
                    <button class="btn primary full ts-access-submit" type="submit">Verifikasi & buka survei <span aria-hidden="true">→</span></button>
                </form>
            @else
                <div class="ts-access-note" role="status">Kode untuk undangan ini belum tersedia. Hubungi pengirim agar mengirim email undangan atau pengingat terbaru.</div>
            @endif
            <p class="ts-access-help">Belum menemukan emailnya? Periksa folder Spam atau hubungi pengirim undangan.</p>
        @endif
        <footer class="ts-access-footer">TransSurvey <span aria-hidden="true">·</span> Client Satisfaction Management</footer>
    </main>
</body>
</html>

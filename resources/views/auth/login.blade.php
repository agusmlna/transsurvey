<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login · TransSurvey</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
    <script defer src="{{ asset('assets/transsurvey.js') }}?v={{ filemtime(public_path('assets/transsurvey.js')) }}"></script>
</head>
<body class="login-page">
    <section class="login-brand" aria-label="Tentang TransSurvey">
        <a class="ts-login-logo" href="{{ route('login') }}" aria-label="TransSurvey"><img src="{{ asset('images/tcid logo.png') }}" alt="Transcosmos Indonesia" width="112" height="42" data-brand-logo></a>
        <div class="ts-login-message">
            <h1>TransSurvey</h1><p class="ts-login-caption">Client Satisfaction Management</p>
            <img class="ts-login-illustration" src="{{ asset('assets/survey-illustration.svg') }}" width="280" height="220" alt="" aria-hidden="true">
            <h2>Dengarkan feedback.<br>Bangun layanan lebih baik.</h2>
            <p>Kelola survei dan pahami pengalaman klien dalam satu tempat.</p>
        </div>
        <small>PT Transcosmos Indonesia</small>
    </section>
    <main class="login-main">
        <div class="login-form">
            <span class="eyebrow">SELAMAT DATANG KEMBALI</span>
            <h1>Login ke TransSurvey</h1><p>Masuk untuk mengelola survei dan melihat hasilnya.</p>
            @if ($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('login.submit') }}">@csrf
                <label for="email">Email<input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="nama@perusahaan.co.id" autocomplete="username" required autofocus></label>
                <label for="password">Password<span class="ts-password-field"><input id="password" type="password" name="password" placeholder="Masukkan password" autocomplete="current-password" required><button type="button" class="ts-password-toggle" data-toggle-password="password" aria-label="Tampilkan password" aria-pressed="false">Lihat</button></span></label>
                <button type="submit" class="btn primary full">Login</button>
            </form>
            <p class="footnote"><strong>Lupa password atau belum punya akun?</strong><br>Hubungi administrator aplikasi.</p>
        </div>
    </main>
</body>
</html>

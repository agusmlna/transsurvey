<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Masuk · {{ config('app.name') }}</title>

    <link rel="icon" href="{{ asset('favicon.svg') }}">

    <link
        rel="stylesheet"
        href="{{ asset('assets/app.css') }}"
    >

    <link
        rel="stylesheet"
        href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}"
    >
</head>

<body class="login-page">
    <section class="login-brand">
        <a class="ts-login-logo" href="{{ route('login') }}">
            <img
                src="{{ asset('images/tcid logo.png') }}"
                alt="Transcosmos Indonesia"
                width="140"
            >

            <strong>{{ config('app.name') }}</strong>
        </a>

        <div class="ts-login-message">
            <span class="eyebrow">CLIENT SATISFACTION SURVEY</span>

            <h1>
                Masukan Anda.<br>
                Langkah perbaikan kami.
            </h1>

            <p>
                Kelola survei, pahami pengalaman klien,
                dan pantau tindak lanjut dalam satu tempat.
            </p>
        </div>

        <small>
            {{ config('app.name') }} · Client Experience
        </small>
    </section>

    <main class="login-main">
        <div class="login-form">
            <span class="eyebrow">SELAMAT DATANG KEMBALI</span>

            <h1>Masuk ke {{ config('app.name') }}</h1>

            <p>
                Gunakan akun Anda untuk mengelola survei dan hasilnya.
            </p>

            @if ($errors->any())
                <div class="notice error" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="post" action="{{ route('login.submit') }}">
                @csrf

                <label for="email">
                    Email
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        placeholder="nama@perusahaan.co.id"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </label>

                <label for="password">
                    Password
                    <input
                        id="password"
                        type="password"
                        name="password"
                        placeholder="Masukkan password"
                        autocomplete="current-password"
                        required
                    >
                </label>

                <button type="submit" class="btn primary full">
                    Masuk ke dashboard →
                </button>
            </form>

            <p class="footnote">
                Belum memiliki akun atau lupa password?
                Hubungi administrator aplikasi.
            </p>
        </div>
    </main>
</body>
</html>
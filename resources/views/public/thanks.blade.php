<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Terima kasih · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/transsurvey.css') }}?v={{ filemtime(public_path('assets/transsurvey.css')) }}">
</head>

<body class="respondent-page">
    <main class="thanks"><span class="success-mark">✓</span>
        <h1>Terima kasih atas suara Anda.</h1>
        <p>Respons Anda sudah tercatat. Masukan Anda membantu kami terus meningkatkan kualitas layanan.</p><small>Satu
            undangan hanya menerima satu respons akhir.</small>
    </main>
</body>

</html>

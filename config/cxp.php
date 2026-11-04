<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CXP SSO (Fase 2)
    |--------------------------------------------------------------------------
    |
    | TransCXP mengirim pengguna ke /cxp/launch?token=<JWT>. Token ditandatangani
    | dengan secret yang sama (HS256) atau public key TransCXP (RS256).
    |
    */

    // HS256: shared secret. Jangan tulis nilainya di repo.
    'sso_secret' => env('CXP_SSO_SECRET'),

    // RS256: isi salah satu. Path lebih aman untuk key multi-baris.
    'sso_public_key' => env('CXP_SSO_PUBLIC_KEY'),
    'sso_public_key_path' => env('CXP_SSO_PUBLIC_KEY_PATH'),

    // Algoritma yang diterima. Default hanya HS256; tambahkan RS256 bila disepakati.
    'algorithms' => array_values(array_filter(array_map(
        fn ($value) => strtoupper(trim($value)),
        explode(',', (string) env('CXP_SSO_ALGORITHMS', 'HS256'))
    ))) ?: ['HS256'],

    'issuer' => env('CXP_SSO_ISSUER', 'transcxp'),
    'audience' => env('CXP_SSO_AUDIENCE', 'transsurvey'),

    // Umur token maksimum (detik) dan toleransi selisih jam.
    'ttl' => max(1, (int) env('CXP_SSO_TTL', 300)),
    'leeway' => max(0, (int) env('CXP_SSO_LEEWAY', 60)),

    // Penanda akun yang dibuat/dikelola lewat SSO TransCXP.
    'external_source' => 'transcxp',

    // Halaman tujuan setelah login. Fase 4 dapat mengganti ke halaman Kirim CSAT.
    'redirect_route' => env('CXP_SSO_REDIRECT_ROUTE', 'dashboard'),

    // Untuk pengembangan lokal saja. Di production biarkan false.
    'allow_operation_password' => (bool) env('CXP_ALLOW_OPERATION_PASSWORD', false),

    // Prefix cache untuk anti-replay jti.
    'replay_prefix' => 'cxp:jti:',
];

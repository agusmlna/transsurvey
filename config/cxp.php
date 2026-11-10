<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CXP SSO (Fase 2)
    |--------------------------------------------------------------------------
    |
    | TransCXP mengirim pengguna ke /cxp/launch?token=<JWT> (atau ?sso=<JWT>).
    | Token ditandatangani dengan secret yang sama (HS256), public key RSA
    | (RS256), atau public key ECDSA P-256 (ES256, pola CakrawalaHub).
    |
    */

    // HS256: shared secret. Jangan tulis nilainya di repo.
    'sso_secret' => env('CXP_SSO_SECRET'),

    // RS256 / ES256: isi salah satu. Path lebih aman untuk key multi-baris.
    'sso_public_key' => env('CXP_SSO_PUBLIC_KEY'),
    'sso_public_key_path' => env('CXP_SSO_PUBLIC_KEY_PATH'),

    // Algoritma yang diterima. Default hanya HS256; tambahkan RS256/ES256 bila disepakati.
    'algorithms' => array_values(array_filter(array_map(
        fn ($value) => strtoupper(trim($value)),
        explode(',', (string) env('CXP_SSO_ALGORITHMS', 'HS256'))
    ))) ?: ['HS256'],

    'issuer' => env('CXP_SSO_ISSUER', 'transcxp'),
    'audience' => env('CXP_SSO_AUDIENCE', 'transsurvey'),

    // Token gaya CakrawalaHub tidak mengirim iss/aud. Kalau dimatikan, klaim
    // yang tetap dikirim masih divalidasi; hanya kewajibannya yang dilonggarkan.
    'require_iss_aud' => filter_var(env('CXP_SSO_REQUIRE_ISS_AUD', false), FILTER_VALIDATE_BOOL),

    // Opsional: cocokkan apiTokenId yang dikirim TransCXP. Kosong = abaikan.
    'api_token_id' => env('CXP_SSO_API_TOKEN_ID'),

    // Kalau true, token tanpa jti tetap diterima dan anti-replay memakai hash
    // token. Default false: jti wajib.
    'replay_fallback' => filter_var(env('CXP_SSO_REPLAY_FALLBACK', false), FILTER_VALIDATE_BOOL),

    // Umur token maksimum (detik) dan toleransi selisih jam.
    'ttl' => max(1, (int) env('CXP_SSO_TTL', 300)),
    'leeway' => max(0, (int) env('CXP_SSO_LEEWAY', 60)),

    // Penanda akun yang dibuat/dikelola lewat SSO TransCXP.
    'external_source' => 'transcxp',

    // Halaman tujuan setelah login. Fase 4 dapat mengganti ke halaman Kirim CSAT.
    'redirect_route' => env('CXP_SSO_REDIRECT_ROUTE', 'dashboard'),

    // Alur tanpa JWT: API key untuk POST /api/cxp/launch-sessions.
    'launch_api_key' => env('CXP_LAUNCH_API_KEY'),

    // Umur kode peluncuran sekali pakai (detik).
    'launch_code_ttl' => max(30, (int) env('CXP_LAUNCH_CODE_TTL', 120)),

    // Untuk pengembangan lokal saja. Di production biarkan false.
    'allow_operation_password' => (bool) env('CXP_ALLOW_OPERATION_PASSWORD', false),

    // Prefix cache untuk anti-replay jti.
    'replay_prefix' => 'cxp:jti:',

    /*
    |--------------------------------------------------------------------------
    | TransCXP Ticket API (read-only)
    |--------------------------------------------------------------------------
    |
    | Kredensial akun API TransCXP untuk membaca data tiket. Dipakai oleh
    | command cxp:inspect dan, nanti, mode otomatis. Jangan tulis nilainya
    | di repo; isi hanya di .env server.
    |
    */

    'api' => [
        'base_url' => env('CXP_API_BASE_URL', 'https://crm.trans-cosmos.id'),
        'email' => env('CXP_API_EMAIL'),
        'password' => env('CXP_API_PASSWORD'),
        'timeout' => max(5, (int) env('CXP_API_TIMEOUT', 30)),

        // Field login di body. API resmi memakai email, tetapi beberapa akun
        // internal bisa memakai username. Bisa diubah tanpa mengubah kode.
        'login_field' => env('CXP_API_LOGIN_FIELD', 'email'),

        // Skema header belum didokumentasikan TransCXP. Default Bearer.
        'auth_header' => env('CXP_API_AUTH_HEADER', 'Authorization'),
        'auth_prefix' => env('CXP_API_AUTH_PREFIX', 'Bearer'),

        // Path endpoint tiket. PDF menyebut /api/v1/dataTicketcxp, tetapi
        // server production membalas 404 HTML untuk path itu. Path yang
        // benar-benar ada di production adalah /api/v2/tickets (401 JSON
        // "The token is required!" tanpa token).
        'ticket_path' => env('CXP_API_TICKET_PATH', '/api/v2/tickets'),
        'detail_path' => env('CXP_API_DETAIL_PATH', '/api/v2/tickets'),
    ],
];

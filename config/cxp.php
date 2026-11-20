<?php

// Daftar nilai .env bergaya "a,b,c" dipakai beberapa kali di bawah.
$csv = static fn (string $value): array => array_values(array_filter(array_map(
    static fn ($item): string => trim((string) $item),
    explode(',', $value),
), static fn (string $item): bool => $item !== ''));

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

        // Path endpoint tiket lama. Masih dipakai cxp:inspect dan probe.
        // /api/v1/dataTicketcxp memuat id_project/project_name/wa, sedangkan
        // /api/v2/tickets tidak memuat field project.
        'ticket_path' => env('CXP_API_TICKET_PATH', '/api/v2/tickets'),
        'detail_path' => env('CXP_API_DETAIL_PATH', '/api/v2/tickets'),

        // Endpoint filter status + project. Hanya endpoint ini yang benar-benar
        // menyaring di server: status=Close&project=<id_project>&per_page=100.
        'status_path' => env('CXP_API_STATUS_PATH', '/api/v1/dataTicketByStatus'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CSAT otomatis dari tiket TransCXP (Fase 5)
    |--------------------------------------------------------------------------
    |
    | Command cxp:csat-send memanggil /api/v1/dataTicketByStatus per workspace
    | (status=Close&project=<kode workspace>), lalu membandingkan hasilnya
    | dengan snapshot cxp_ticket_seen. Hanya tiket yang BARU masuk Close yang
    | dikirimi CSAT. enabled hanya mengatur jadwal otomatis; command tetap
    | bisa dijalankan manual.
    |
    */

    'csat' => [
        'enabled' => filter_var(env('CXP_CSAT_AUTOSEND', false), FILTER_VALIDATE_BOOL),

        // Opsional: batasi ke satu kode workspace. Kosong = semua workspace
        // non-legacy yang punya template CSAT default.
        'workspace' => env('CXP_CSAT_WORKSPACE'),

        // Server membatasi per_page di 100, walaupun nilai lebih besar dikirim.
        'per_page' => min(100, max(1, (int) env('CXP_CSAT_PER_PAGE', 100))),

        // 0 = baca semua halaman per project.
        'max_pages' => max(0, (int) env('CXP_CSAT_MAX_PAGES', 0)),

        /*
        | Routing kanal (Fase 6b). Nilai source dibandingkan tanpa peduli
        | huruf besar/kecil, spasi, garis bawah, atau tanda hubung:
        | "Google_business" == "Google Business".
        |
        | - wa_sources            : kirim WhatsApp; kalau nomor/akun tidak
        |                           tersedia, pakai email bila ada, kalau
        |                           tidak ada lewati.
        | - email_sources         : selalu email; tanpa email dilewati.
        | - email_fallback_sources: email bila ada, kalau tidak lewati
        |                           (WhatsApp tidak dipakai).
        | - skip_sources          : tidak dikirim sama sekali.
        | - default_channel       : untuk source yang tidak dikenal.
        */
        'wa_sources' => $csv((string) env('CXP_CSAT_WA_SOURCES', 'WhatsApp')),
        'email_sources' => $csv((string) env('CXP_CSAT_EMAIL_SOURCES', 'Email')),
        'email_fallback_sources' => $csv((string) env('CXP_CSAT_EMAIL_FALLBACK_SOURCES', 'Instagram,Facebook,Google_business,Twitter,Livechat,TikTok')),
        'skip_sources' => $csv((string) env('CXP_CSAT_SKIP_SOURCES', 'Call,Outbound Call,WAG,Whatsapp Call,Whatsapp Call Outbound')),
        'default_channel' => in_array(strtolower(trim((string) env('CXP_CSAT_DEFAULT_CHANNEL', 'email'))), ['email', 'whatsapp'], true)
            ? strtolower(trim((string) env('CXP_CSAT_DEFAULT_CHANNEL', 'email')))
            : 'email',

        /*
        | Onboarding workspace production (cxp:csat-onboard).
        |
        | projects       : kode project TransCXP => nama workspace. Kode sama
        |                  dengan id_project, tanpa fallback.
        | pilot_projects : kode yang boleh autosend lebih dulu. Kosong berarti
        |                  opsi --pilot harus diisi eksplisit lewat .env.
        | master_template_id : template CSAT sumber untuk clone. Kosong =
        |                  pakai template default workspace legacy.
        */
        'projects' => array_replace(
            [
                '4' => 'ABC',
                '5' => 'Transcosmos Indonesia',
                '6' => 'Dipo Star Finance Operating Lease',
                '8' => 'Pristine8.6+',
                '9' => 'DKT',
                '10' => 'Trakindo Utama',
                '11' => 'PT. Tri Swardana Utama',
                '12' => 'DANONE',
                '13' => 'Hisense',
                '14' => 'Yanmar Jembatani',
                '15' => 'Mead Jhonson',
                '16' => 'OMRON',
                '17' => 'Daikin',
                '18' => 'AIRA',
                '19' => 'Official TCID',
                '20' => 'Danone Bookers',
                '21' => 'DSF',
                '22' => 'Indofood',
                '23' => 'Panasonic',
                '24' => 'Yanmar Official',
                '25' => 'Lotte Mart',
                '26' => 'Danone Telepharma',
            ],
            (static function (): array {
                $raw = trim((string) env('CXP_CSAT_PROJECTS', ''));
                $decoded = $raw === '' ? null : json_decode($raw, true);

                return is_array($decoded) ? array_map(static fn ($name): string => trim((string) $name), $decoded) : [];
            })(),
        ),
        'pilot_projects' => $csv((string) env('CXP_CSAT_PILOT_PROJECTS', '')),
        'master_template_id' => (int) env('CXP_CSAT_MASTER_TEMPLATE_ID', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | TransCXP WhatsApp Broadcast API v2 (Fase 6b)
    |--------------------------------------------------------------------------
    |
    | Kredensial User API v2 (email + password) disimpan per project di
    | CXP_WA_ACCOUNTS / CXP_WA_ACCOUNTS_FILE. Hasil /api/v2/login-user
    | dipakai untuk /api/v2/messages-template.
    |
    | Endpoint v2 TIDAK punya parameter pengirim, jadi nomor pengirim
    | ditentukan oleh akun yang dipakai login. Tiap project memakai
    | email+password sendiri; tidak ada fallback lintas project.
    |
    | `number` HANYA catatan audit (sender_number). `account_id` dan
    | `channels` dari /api/v1/accountWaProject/{id_project} disimpan sebagai
    | metadata channel aktif. Akun `active: no` dilewati. Untuk project dengan
    | banyak channel, kredensial bisa ditaruh di level project dan diwariskan
    | ke semua channel. Pemilihan nomor antar-channel dalam satu project masih
    | memerlukan endpoint/field khusus dari TransCXP.
    |
    | Satu template dipakai semua project. Urutan parameter body diatur
    | lewat template_params, contoh: customer_name,survey_url.
    |
    */

    'whatsapp' => [
        'base_url' => env('CXP_WA_BASE_URL', env('CXP_API_BASE_URL', 'https://transcxp.trans-cosmos.id')),
        'timeout' => max(5, (int) env('CXP_WA_TIMEOUT', 30)),

        // Sumber nomor pengirim (sender_number) + account_id per project.
        // Dipakai cxp:csat-wa-accounts-sync untuk mengisi CXP_WA_ACCOUNTS_FILE.
        'account_base_url' => env('CXP_WA_ACCOUNT_BASE_URL', env('CXP_WA_BASE_URL', env('CXP_API_BASE_URL', 'https://transcxp.trans-cosmos.id'))),
        'account_path' => env('CXP_WA_ACCOUNT_PATH', '/api/v1/accountWaProject/{id_project}'),

        'login_path' => env('CXP_WA_LOGIN_PATH', '/api/v2/login-user'),
        'send_path' => env('CXP_WA_SEND_PATH', '/api/v2/messages-template'),
        'status_path' => env('CXP_WA_STATUS_PATH', '/api/v2/messages-template'),
        'template_id' => trim((string) env('CXP_WA_TEMPLATE_ID', '')),
        'template_name' => trim((string) env('CXP_WA_TEMPLATE_NAME', '')),
        'template_language' => trim((string) env('CXP_WA_TEMPLATE_LANGUAGE', 'id')),
        'template_params' => $csv((string) env('CXP_WA_TEMPLATE_PARAMS', '')),

        // Parameter tombol URL (opsional). Isi nilai misalnya "survey_url"
        // bila template memakai tombol URL dinamis.
        'button_url_param' => trim((string) env('CXP_WA_BUTTON_URL_PARAM', '')),

        // Kunci cache token per akun; token tidak pernah ditulis ke log.
        'token_cache_prefix' => env('CXP_WA_TOKEN_CACHE_PREFIX', 'cxp:wa:token:'),
        'token_cache_ttl' => max(300, (int) env('CXP_WA_TOKEN_CACHE_TTL', 43200)),

        'accounts' => env('CXP_WA_ACCOUNTS'),
        'accounts_file' => env('CXP_WA_ACCOUNTS_FILE'),
    ],
];

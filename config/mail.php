<?php

return [
    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [
        // SMTP untuk email operasi: notifikasi admin, user, dan undangan non-CSAT.
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME', 'smtp'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 1025),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => 30,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        // SMTP khusus CSAT. Tidak boleh jatuh ke akun operasi: kalau belum
        // dikonfigurasi, pengiriman CSAT harus gagal dengan jelas.
        'csat' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_CSAT_SCHEME', env('MAIL_SCHEME', 'smtp')),
            'url' => env('MAIL_CSAT_URL'),
            'host' => env('MAIL_CSAT_HOST'),
            'port' => env('MAIL_CSAT_PORT', 1025),
            'username' => env('MAIL_CSAT_USERNAME'),
            'password' => env('MAIL_CSAT_PASSWORD'),
            'timeout' => 30,
            'local_domain' => env('MAIL_CSAT_EHLO_DOMAIN'),
            'from' => [
                'address' => env('MAIL_CSAT_FROM_ADDRESS'),
                'name' => env('MAIL_CSAT_FROM_NAME', 'CSAT TransSurvey'),
            ],
        ],

        'log' => ['transport' => 'log', 'channel' => env('MAIL_LOG_CHANNEL')],
        'array' => ['transport' => 'array'],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'survey@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Suara'),
    ],
];

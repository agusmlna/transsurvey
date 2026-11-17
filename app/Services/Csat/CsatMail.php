<?php

namespace App\Services\Csat;

/**
 * Kesiapan SMTP khusus CSAT.
 *
 * Email undangan CSAT memakai akun yang berbeda dari email operasi. Kalau
 * akun CSAT belum diisi, pengiriman harus ditolak — jangan diam-diam memakai
 * SMTP operasi, karena itu mengirim survei dengan identitas yang salah.
 */
final class CsatMail
{
    public const MAILER = 'csat';

    public static function isReady(): bool
    {
        $mailer = (array) config('mail.mailers.'.self::MAILER, []);

        return (bool) config('survey.email_enabled')
            && ($mailer['transport'] ?? null) === 'smtp'
            && trim((string) ($mailer['host'] ?? '')) !== ''
            && trim((string) ($mailer['username'] ?? '')) !== ''
            && (string) ($mailer['password'] ?? '') !== ''
            && self::fromAddress() !== '';
    }

    public static function fromAddress(): string
    {
        return trim((string) config('mail.mailers.'.self::MAILER.'.from.address', ''));
    }

    public static function fromName(): string
    {
        return trim((string) config('mail.mailers.'.self::MAILER.'.from.name', ''));
    }
}

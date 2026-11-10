<?php

namespace App\Services\Csat;

/**
 * Normalisasi nomor WhatsApp ke format 62... supaya siap dipakai broadcast.
 */
class WhatsAppNumber
{
    public static function normalize(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', trim((string) $value)) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }

    public static function isValid(string $normalized): bool
    {
        return strlen($normalized) >= 8 && strlen($normalized) <= 15;
    }
}

<?php

namespace App\Services\Csat;

use App\Models\Workspace;
use Carbon\CarbonInterface;

/**
 * Pengaturan CSAT per workspace.
 *
 * Masa berlaku dihitung sejak email CSAT berhasil terkirim. Unit "unlimited"
 * menyimpan expires_at = null pada undangan.
 */
class CsatSettings
{
    public const UNITS = ['minutes', 'hours', 'days', 'unlimited'];

    /**
     * @return array{expiry_unit: string, expiry_value: int|null, send_mode: string}
     */
    public static function for(Workspace $workspace): array
    {
        $csat = is_array($workspace->settings['csat'] ?? null) ? $workspace->settings['csat'] : [];

        $unit = in_array($csat['expiry_unit'] ?? null, self::UNITS, true)
            ? $csat['expiry_unit']
            : 'unlimited';

        $value = $unit === 'unlimited'
            ? null
            : max(1, (int) ($csat['expiry_value'] ?? 7));

        return [
            'expiry_unit' => $unit,
            'expiry_value' => $value,
            'send_mode' => in_array($csat['send_mode'] ?? null, ['manual', 'auto'], true)
                ? $csat['send_mode']
                : 'manual',
        ];
    }

    public static function expiresAt(Workspace $workspace, CarbonInterface $sentAt): ?CarbonInterface
    {
        $settings = self::for($workspace);

        return match ($settings['expiry_unit']) {
            'minutes' => $sentAt->copy()->addMinutes($settings['expiry_value']),
            'hours' => $sentAt->copy()->addHours($settings['expiry_value']),
            'days' => $sentAt->copy()->addDays($settings['expiry_value']),
            default => null,
        };
    }

    public static function label(Workspace $workspace): string
    {
        $settings = self::for($workspace);

        if ($settings['expiry_unit'] === 'unlimited') {
            return __('Tanpa batas waktu');
        }

        $units = [
            'minutes' => __('menit'),
            'hours' => __('jam'),
            'days' => __('hari'),
        ];

        return __(':value :unit sejak email terkirim', [
            'value' => $settings['expiry_value'],
            'unit' => $units[$settings['expiry_unit']] ?? $settings['expiry_unit'],
        ]);
    }
}

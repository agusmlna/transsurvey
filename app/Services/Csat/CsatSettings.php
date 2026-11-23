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
    public const SEND_MODES = ['manual', 'auto', 'both'];
    public const CHANNELS = ['email', 'whatsapp'];
    public const DEFAULT_STATUSES = ['Close'];

    /**
     * @return array{expiry_unit: string, expiry_value: int|null, send_mode: string, autosend: bool, statuses: array<int, string>, channels: array<int, string>}
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
            'send_mode' => in_array($csat['send_mode'] ?? null, self::SEND_MODES, true)
                ? $csat['send_mode']
                : 'manual',
            // Izin per workspace untuk ikut jadwal otomatis. Pengaturan
            // global CXP_CSAT_AUTOSEND tetap menjadi saklar utama.
            'autosend' => filter_var($csat['autosend'] ?? false, FILTER_VALIDATE_BOOL),
            'statuses' => self::statuses($workspace, $csat),
            'channels' => self::channels($workspace, $csat),
        ];
    }

    /**
     * Status tiket yang dipantau autosend. Bisa lebih dari satu, misalnya
     * "Close,Resolved". Default tetap Close supaya perilaku lama tidak berubah.
     *
     * @return array<int, string>
     */
    public static function statuses(Workspace $workspace, ?array $csat = null): array
    {
        $csat ??= is_array($workspace->settings['csat'] ?? null) ? $workspace->settings['csat'] : [];
        $raw = $csat['auto_statuses'] ?? self::DEFAULT_STATUSES;
        $values = is_array($raw) ? $raw : (preg_split('/[,;\n]+/', (string) $raw) ?: []);

        $statuses = [];
        foreach ($values as $value) {
            $status = trim((string) $value);

            if ($status !== '' && strlen($status) <= 100 && ! in_array($status, $statuses, true)) {
                $statuses[] = $status;
            }
        }

        return $statuses !== [] ? array_values($statuses) : self::DEFAULT_STATUSES;
    }

    /**
     * Kanal yang diizinkan untuk workspace ini. Project email-only dari .env
     * tidak bisa diubah lewat UI.
     *
     * @return array<int, string>
     */
    public static function channels(Workspace $workspace, ?array $csat = null): array
    {
        $csat ??= is_array($workspace->settings['csat'] ?? null) ? $workspace->settings['csat'] : [];
        $raw = $csat['auto_channels'] ?? self::CHANNELS;
        $values = is_array($raw) ? $raw : (preg_split('/[,;\n]+/', (string) $raw) ?: []);

        $channels = array_values(array_intersect(self::CHANNELS, array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $values,
        )));

        if (self::isEmailOnly($workspace)) {
            return ['email'];
        }

        return $channels !== [] ? array_values(array_unique($channels)) : self::CHANNELS;
    }

    public static function isEmailOnly(Workspace $workspace): bool
    {
        $codes = array_map(static fn ($value): string => trim((string) $value), (array) config('cxp.csat.email_only_projects', []));

        return in_array(trim((string) $workspace->code), $codes, true);
    }

    public static function isDisabled(Workspace $workspace): bool
    {
        $codes = array_map(static fn ($value): string => trim((string) $value), (array) config('cxp.csat.disabled_projects', []));

        return in_array(trim((string) $workspace->code), $codes, true);
    }

    public static function autosend(Workspace $workspace): bool
    {
        $csat = is_array($workspace->settings['csat'] ?? null) ? $workspace->settings['csat'] : [];
        $enabled = filter_var($csat['autosend'] ?? false, FILTER_VALIDATE_BOOL);

        if (! $enabled) {
            return false;
        }

        // Workspace yang dibuat sebelum send_mode ada memakai autosend=true
        // sebagai satu-satunya penanda. Jangan matikan hanya karena mereka
        // belum disimpan ulang lewat UI.
        if (! array_key_exists('send_mode', $csat)) {
            return true;
        }

        return self::for($workspace)['send_mode'] !== 'manual';
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

        return __(':value :unit sejak pesan terkirim', [
            'value' => $settings['expiry_value'],
            'unit' => $units[$settings['expiry_unit']] ?? $settings['expiry_unit'],
        ]);
    }
}

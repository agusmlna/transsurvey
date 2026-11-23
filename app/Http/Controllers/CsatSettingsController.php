<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\Csat\CsatSettings;
use App\Services\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CsatSettingsController
{
    public function edit()
    {
        $workspace = $this->workspace();
        $settings = CsatSettings::for($workspace);
        $raw = is_array($workspace->settings['csat'] ?? null) ? $workspace->settings['csat'] : [];
        $legacyAutosend = filter_var($raw['autosend'] ?? false, FILTER_VALIDATE_BOOL);

        return view('csat.settings', [
            'workspace' => $workspace,
            'expiryUnit' => $settings['expiry_unit'],
            'expiryValue' => $settings['expiry_value'],
            'autosend' => $settings['autosend'],
            // Workspace lama yang sudah autosend=true tetapi belum punya
            // send_mode tetap tampil sebagai auto supaya tidak mati sendiri.
            'sendMode' => $legacyAutosend && $settings['send_mode'] === 'manual'
                ? 'auto'
                : $settings['send_mode'],
            'autoStatuses' => implode(', ', $settings['statuses']),
            'autoChannels' => $settings['channels'],
            'emailOnly' => CsatSettings::isEmailOnly($workspace),
            'disabled' => CsatSettings::isDisabled($workspace),
            'serverAutosend' => (bool) config('cxp.csat.enabled', false),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'expiry_unit' => 'required|in:minutes,hours,days,unlimited',
            'expiry_value' => 'nullable|integer|min:1|max:525600|required_unless:expiry_unit,unlimited',
            'autosend' => 'nullable|boolean',
            'send_mode' => 'nullable|in:manual,auto,both',
            'auto_statuses' => 'nullable|string|max:500',
            'auto_channels' => 'nullable|array',
            'auto_channels.*' => 'nullable|in:email,whatsapp',
        ]);

        $workspace = $this->workspace();
        $settings = $workspace->settings ?? [];
        $current = is_array($settings['csat'] ?? null) ? $settings['csat'] : [];

        $statuses = CsatSettings::statuses($workspace, $current);
        if (array_key_exists('auto_statuses', $data)) {
            $statuses = CsatSettings::statuses($workspace, ['auto_statuses' => $data['auto_statuses']]);
            if ($statuses === CsatSettings::DEFAULT_STATUSES && trim((string) $data['auto_statuses']) === '') {
                throw ValidationException::withMessages([
                    'auto_statuses' => __('Isi minimal satu status tiket, misalnya Close.'),
                ]);
            }
        }

        $channels = CsatSettings::channels($workspace, $current);
        if ($request->has('auto_channels')) {
            $channels = array_values(array_unique(array_filter(
                is_array($data['auto_channels'] ?? null) ? $data['auto_channels'] : [],
                static fn ($value): bool => is_string($value) && $value !== '',
            )));

            if ($channels === []) {
                throw ValidationException::withMessages([
                    'auto_channels' => __('Pilih minimal satu kanal: email atau WhatsApp.'),
                ]);
            }
        }

        if (CsatSettings::isEmailOnly($workspace)) {
            $channels = ['email'];
        }

        // Keep other workspace settings untouched (kunci lain yang mungkin
        // ditambahkan fase berikutnya).
        $settings['csat'] = array_merge($current, [
            'expiry_unit' => $data['expiry_unit'],
            'expiry_value' => $data['expiry_unit'] === 'unlimited' ? null : (int) $data['expiry_value'],
            'autosend' => (bool) ($data['autosend'] ?? false),
            'send_mode' => $data['send_mode'] ?? ($current['send_mode'] ?? 'manual'),
            'auto_statuses' => $statuses,
            'auto_channels' => $channels,
        ]);

        $workspace->settings = $settings;
        $workspace->save();

        return back()->with('success', __('Pengaturan CSAT disimpan.'));
    }

    private function workspace(): Workspace
    {
        return Workspace::findOrFail(app(CurrentWorkspace::class)->filterId());
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Illuminate\Http\Request;

class CsatSettingsController
{
    public function edit()
    {
        $workspace = $this->workspace();
        $csat = $workspace->settings['csat'] ?? [];

        return view('csat.settings', [
            'workspace' => $workspace,
            'expiryUnit' => $csat['expiry_unit'] ?? 'unlimited',
            'expiryValue' => $csat['expiry_value'] ?? null,
            'autosend' => filter_var($csat['autosend'] ?? false, FILTER_VALIDATE_BOOL),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'expiry_unit' => 'required|in:minutes,hours,days,unlimited',
            'expiry_value' => 'nullable|integer|min:1|max:525600|required_unless:expiry_unit,unlimited',
            'autosend' => 'nullable|boolean',
        ]);

        $workspace = $this->workspace();
        $settings = $workspace->settings ?? [];

        // Keep other workspace settings untouched (send_mode, dan kunci lain
        // yang mungkin ditambahkan fase berikutnya).
        $settings['csat'] = array_merge(
            is_array($settings['csat'] ?? null) ? $settings['csat'] : [],
            [
                'expiry_unit' => $data['expiry_unit'],
                'expiry_value' => $data['expiry_unit'] === 'unlimited' ? null : (int) $data['expiry_value'],
                'autosend' => (bool) ($data['autosend'] ?? false),
            ],
        );

        $workspace->settings = $settings;
        $workspace->save();

        return back()->with('success', __('Pengaturan masa berlaku CSAT disimpan.'));
    }

    private function workspace(): Workspace
    {
        return Workspace::findOrFail(app(CurrentWorkspace::class)->filterId());
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\{Invitation, Survey, Workspace};
use App\Services\Csat\CsatInvitationService;
use App\Services\Csat\CsatSettings;
use App\Services\Csat\WhatsAppNumber;
use App\Services\Cxp\CxpLaunchContext;
use App\Services\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Halaman "Kirim CSAT" untuk satu tiket dari TransCXP.
 *
 * Data tiket dan customer dibaca dari sesi cxp.context yang diisi saat user
 * membuka TransSurvey lewat tombol di TransCXP. Operation memilih template,
 * lalu email CSAT masuk antrean pengiriman.
 */
class CsatSendController
{
    public function show(Request $request)
    {
        $workspace = $this->workspace();
        $context = CxpLaunchContext::get($request->session());

        // Jangan tampilkan data tiket milik workspace lain setelah user berganti workspace.
        if ((int) data_get($context, 'workspace_id', 0) !== $workspace->id) {
            $context = null;
        }

        $templates = Survey::query()
            ->where('type', 'csat')
            ->where('status', 'active')
            ->orderBy('title')
            ->get();

        $history = Invitation::query()
            ->with(['survey', 'client', 'deliveries'])
            ->whereHas('survey', fn ($query) => $query->where('type', 'csat'))
            ->where('is_demo', false)
            ->latest()
            ->limit(10)
            ->get();

        return view('csat.send', [
            'workspace' => $workspace,
            'context' => $context,
            'templates' => $templates,
            'defaultId' => (int) $workspace->default_csat_survey_id,
            'expiryLabel' => CsatSettings::label($workspace),
            'history' => $history,
        ]);
    }

    public function store(Request $request, CsatInvitationService $invitations)
    {
        $workspace = $this->workspace();
        $context = CxpLaunchContext::get($request->session());

        if ((int) data_get($context, 'workspace_id', 0) !== $workspace->id) {
            $context = null;
        }

        $ticketId = trim((string) data_get($context, 'ticket.id', ''));
        $customerEmail = mb_strtolower(trim((string) data_get($context, 'customer.email', '')));
        $customerName = trim((string) data_get($context, 'customer.name', '')) ?: $customerEmail;
        $customerWa = WhatsAppNumber::normalize((string) data_get($context, 'customer.wa', ''));

        if ($ticketId === '' || $customerEmail === '') {
            throw ValidationException::withMessages([
                'context' => __('Data tiket dan customer tidak ditemukan. Buka CSAT dari tiket TransCXP terlebih dahulu.'),
            ]);
        }

        if (! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'context' => __('Email customer pada data tiket tidak valid.'),
            ]);
        }

        if ($customerWa !== '' && ! WhatsAppNumber::isValid($customerWa)) {
            throw ValidationException::withMessages([
                'context' => __('Nomor WhatsApp customer tidak valid.'),
            ]);
        }

        $data = $request->validate([
            'template_id' => ['required', 'integer', app(CurrentWorkspace::class)->existsRule('surveys')],
        ]);

        $template = Survey::query()
            ->where('type', 'csat')
            ->where('status', 'active')
            ->findOrFail($data['template_id']);

        if (! $template->isOpen()) {
            throw ValidationException::withMessages([
                'template_id' => __('Template CSAT ini sedang tidak aktif. Pilih template lain.'),
            ]);
        }

        $result = $invitations->send(
            $workspace,
            [
                'ticket_id' => $ticketId,
                'ticket_status' => (string) data_get($context, 'ticket.status', ''),
                'channel' => (string) data_get($context, 'ticket.channel', ''),
            ],
            [
                'name' => $customerName,
                'email' => $customerEmail,
                'wa' => $customerWa,
            ],
            $template,
        );

        return match ($result['outcome']) {
            CsatInvitationService::OUTCOME_COMPLETED => throw ValidationException::withMessages([
                'template_id' => __('Customer sudah mengisi CSAT untuk tiket ini.'),
            ]),
            CsatInvitationService::OUTCOME_ALREADY_SENT => back()->with('success', __('CSAT untuk tiket ini sudah dikirim. Tidak ada email baru yang dibuat.')),
            default => back()->with('success', __('Email CSAT masuk antrean. Pantau status pengiriman di riwayat bawah.')),
        };
    }

    private function workspace(): Workspace
    {
        return Workspace::findOrFail(app(CurrentWorkspace::class)->filterId());
    }
}

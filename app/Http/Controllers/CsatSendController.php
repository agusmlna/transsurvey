<?php

namespace App\Http\Controllers;

use App\Models\{Invitation, Survey, Workspace};
use App\Services\Csat\CsatInvitationService;
use App\Services\Csat\CsatManualSender;
use App\Services\Csat\CsatSettings;
use App\Services\Csat\WhatsAppNumber;
use App\Services\Cxp\CxpLaunchContext;
use App\Services\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Halaman "Kirim CSAT" untuk satu tiket dari TransCXP.
 *
 * Agent bisa membuka halaman ini langsung dari menu (mengisi tiket, nama,
 * email/nomor sendiri) atau lewat tombol CSAT di tiket TransCXP. Data tiket
 * dari sesi cxp.context dipakai sebagai nilai awal, bukan kewajiban.
 */
class CsatSendController
{
    public function show(Request $request)
    {
        $workspace = $this->workspace();
        $context = $this->context($request, $workspace);
        $settings = CsatSettings::for($workspace);
        $channels = $settings['channels'];

        $templates = Survey::query()
            ->where('type', 'csat')
            ->where('status', 'active')
            ->orderBy('title')
            ->get();

        $history = Invitation::query()
            ->with(['survey', 'client', 'deliveries', 'whatsapp', 'response'])
            ->whereHas('survey', fn ($query) => $query->where('type', 'csat'))
            ->where('is_demo', false)
            ->latest()
            ->limit(10)
            ->get();

        $contextChannel = strtolower(trim((string) data_get($context, 'ticket.channel', '')));

        return view('csat.send', [
            'workspace' => $workspace,
            'context' => $context,
            'templates' => $templates,
            'defaultId' => (int) $workspace->default_csat_survey_id,
            'expiryLabel' => CsatSettings::label($workspace),
            'channels' => $channels,
            'defaultChannel' => in_array($contextChannel, $channels, true) ? $contextChannel : 'email',
            'emailOnly' => CsatSettings::isEmailOnly($workspace),
            'prefill' => [
                'ticket_id' => (string) data_get($context, 'ticket.id', ''),
                'ticket_status' => (string) data_get($context, 'ticket.status', ''),
                'customer_name' => (string) data_get($context, 'customer.name', ''),
                'customer_email' => (string) data_get($context, 'customer.email', ''),
                'customer_wa' => (string) data_get($context, 'customer.wa', ''),
            ],
            'history' => $history,
        ]);
    }

    public function store(Request $request, CsatInvitationService $invitations, CsatManualSender $manualSender)
    {
        $workspace = $this->workspace();
        $context = $this->context($request, $workspace);

        $data = $request->validate([
            'ticket_id' => ['nullable', 'string', 'max:120'],
            'ticket_status' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email:rfc', 'max:255'],
            'customer_wa' => ['nullable', 'string', 'max:40'],
            'channel' => ['nullable', 'in:email,whatsapp'],
            'template_id' => ['required', 'integer', app(CurrentWorkspace::class)->existsRule('surveys')],
        ]);

        $ticketId = trim((string) ($data['ticket_id'] ?? data_get($context, 'ticket.id', '')));
        $ticketStatus = trim((string) ($data['ticket_status'] ?? data_get($context, 'ticket.status', '')));
        $customerEmail = mb_strtolower(trim((string) ($data['customer_email'] ?? data_get($context, 'customer.email', ''))));
        $customerName = trim((string) ($data['customer_name'] ?? data_get($context, 'customer.name', '')));
        $customerWa = WhatsAppNumber::normalize((string) ($data['customer_wa'] ?? data_get($context, 'customer.wa', '')));
        $channel = strtolower(trim((string) ($data['channel'] ?? data_get($context, 'ticket.channel', 'email'))));
        $channel = $channel === 'whatsapp' ? 'whatsapp' : 'email';

        if ($ticketId === '') {
            throw ValidationException::withMessages([
                'ticket_id' => __('Nomor/tiket CSAT wajib diisi.'),
            ]);
        }

        $channels = CsatSettings::channels($workspace);

        if (! in_array($channel, $channels, true)) {
            throw ValidationException::withMessages([
                'channel' => __('Kanal ini tidak diizinkan untuk workspace tersebut.'),
            ]);
        }

        if ($channel === 'email' && ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'customer_email' => __('Email customer wajib diisi dan valid untuk kirim lewat email.'),
            ]);
        }

        if ($channel === 'whatsapp' && ($customerWa === '' || ! WhatsAppNumber::isValid($customerWa))) {
            throw ValidationException::withMessages([
                'customer_wa' => __('Nomor WhatsApp tujuan wajib diisi dengan format +62... atau 08...'),
            ]);
        }

        if ($customerName === '') {
            $customerName = $channel === 'email' ? $customerEmail : '+'.$customerWa;
        }

        $template = Survey::query()
            ->where('type', 'csat')
            ->where('status', 'active')
            ->findOrFail($data['template_id']);

        if (! $template->isOpen()) {
            throw ValidationException::withMessages([
                'template_id' => __('Template CSAT ini sedang tidak aktif. Pilih template lain.'),
            ]);
        }

        $ticket = [
            'ticket_id' => $ticketId,
            'ticket_status' => $ticketStatus,
            'channel' => $channel,
        ];
        $customer = [
            'name' => $customerName,
            'email' => $customerEmail,
            'wa' => $customerWa,
        ];

        $result = $channel === 'whatsapp'
            ? $manualSender->whatsapp($workspace, $ticket, $customer, $template)
            : $invitations->send($workspace, $ticket, $customer, $template);

        if ($result['outcome'] === CsatInvitationService::OUTCOME_COMPLETED) {
            throw ValidationException::withMessages([
                'template_id' => __('Customer sudah mengisi CSAT untuk tiket ini.'),
            ]);
        }

        $message = match ($result['outcome']) {
            CsatInvitationService::OUTCOME_ALREADY_SENT => $channel === 'whatsapp'
                ? __('CSAT WhatsApp untuk tiket ini sudah dikirim. Tidak ada pesan baru yang dibuat.')
                : __('CSAT untuk tiket ini sudah dikirim. Tidak ada email baru yang dibuat.'),
            CsatInvitationService::OUTCOME_WHATSAPP_PENDING => __('Pesan WhatsApp sudah antre di TransCXP. Tautan tetap ditampilkan di bawah.'),
            default => $channel === 'whatsapp'
                ? __('WhatsApp CSAT terkirim. Tautan survey ditampilkan di bawah.')
                : __('Email CSAT masuk antrean. Tautan survey ditampilkan di bawah.'),
        };

        return back()->with([
            'success' => $message,
            'csat_link' => $result['invitation']->surveyUrl(),
            'csat_ticket' => $ticketId,
        ]);
    }

    private function context(Request $request, Workspace $workspace): ?array
    {
        $context = CxpLaunchContext::get($request->session());

        // Jangan tampilkan data tiket milik workspace lain setelah user berganti workspace.
        if ((int) data_get($context, 'workspace_id', 0) !== $workspace->id) {
            return null;
        }

        return $context;
    }

    private function workspace(): Workspace
    {
        return Workspace::findOrFail(app(CurrentWorkspace::class)->filterId());
    }
}

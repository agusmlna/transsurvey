<?php

namespace App\Http\Controllers;

use App\Models\{Client, Invitation, Survey, Workspace};
use App\Services\Csat\CsatSettings;
use App\Services\Cxp\CxpLaunchContext;
use App\Services\CurrentWorkspace;
use App\Services\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

    public function store(Request $request, DeliveryService $delivery)
    {
        $workspace = $this->workspace();
        $context = CxpLaunchContext::get($request->session());

        if ((int) data_get($context, 'workspace_id', 0) !== $workspace->id) {
            $context = null;
        }

        $ticketId = trim((string) data_get($context, 'ticket.id', ''));
        $customerEmail = mb_strtolower(trim((string) data_get($context, 'customer.email', '')));
        $customerName = trim((string) data_get($context, 'customer.name', '')) ?: $customerEmail;

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

        $client = $this->client($workspace, $customerName, $customerEmail);

        // Satu tiket hanya boleh menerima satu tautan CSAT, walaupun operation
        // memilih template yang berbeda pada klik berikutnya.
        $invitation = Invitation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('ticket_id', $ticketId)
            ->whereHas('survey', fn ($query) => $query->withoutGlobalScopes()->where('type', 'csat'))
            ->first();

        if ($invitation === null) {
            $token = Str::random(64);
            $invitation = Invitation::create([
                'survey_id' => $template->id,
                'client_id' => $client->id,
                'ticket_id' => $ticketId,
                'ticket_status' => (string) data_get($context, 'ticket.status', ''),
                'channel' => (string) data_get($context, 'ticket.channel', ''),
                'token' => $token,
                'token_hash' => hash('sha256', $token),
                'recipient_name' => $customerName,
                'recipient_email' => $customerEmail,
                'is_demo' => false,
            ]);
        } else {
            // Tautan yang sudah dikirim atau sudah diisi tidak boleh berubah
            // hanya karena operation memilih template lain.
            if ($invitation->completed_at) {
                throw ValidationException::withMessages([
                    'template_id' => __('Customer sudah mengisi CSAT untuk tiket ini.'),
                ]);
            }

            if ($invitation->sent_at) {
                return back()->with('success', __('CSAT untuk tiket ini sudah dikirim. Tidak ada email baru yang dibuat.'));
            }

            $invitation->fill([
                'survey_id' => $template->id,
                'ticket_status' => (string) data_get($context, 'ticket.status', ''),
                'channel' => (string) data_get($context, 'ticket.channel', ''),
                'recipient_name' => $customerName,
                'recipient_email' => $customerEmail,
            ]);
            if ($invitation->isDirty()) {
                $invitation->save();
            }
        }

        $delivery->enqueue($invitation, 'invitation');

        return back()->with('success', __('Email CSAT masuk antrean. Pantau status pengiriman di riwayat bawah.'));
    }

    private function client(Workspace $workspace, string $customerName, string $customerEmail): Client
    {
        $client = Client::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('email', $customerEmail)
            ->first();

        if ($client !== null) {
            return $client;
        }

        $client = new Client([
            'name' => $customerName,
            'contact' => $customerName,
            'email' => $customerEmail,
            'project' => $workspace->name,
            'active' => true,
        ]);
        $client->workspace_id = $workspace->id;
        $client->save();

        return $client;
    }

    private function workspace(): Workspace
    {
        return Workspace::findOrFail(app(CurrentWorkspace::class)->filterId());
    }
}

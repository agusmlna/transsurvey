<?php

namespace App\Services\Csat;

use App\Models\{Client, Invitation, Survey, Workspace};
use App\Services\DeliveryService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Membuat undangan CSAT untuk satu tiket.
 *
 * Dipakai halaman Kirim CSAT (manual) dan command cxp:csat-send (otomatis)
 * supaya aturan "satu tiket satu tautan" tidak berbeda antar jalur.
 */
class CsatInvitationService
{
    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_QUEUED = 'queued';
    public const OUTCOME_ALREADY_SENT = 'already_sent';
    public const OUTCOME_COMPLETED = 'completed';

    public function __construct(private readonly DeliveryService $delivery) {}

    /**
     * Template CSAT default workspace, hanya kalau aktif dan sedang dibuka.
     */
    public function defaultTemplate(Workspace $workspace): ?Survey
    {
        if (! $workspace->default_csat_survey_id) {
            return null;
        }

        $template = Survey::query()
            ->where('type', 'csat')
            ->where('status', 'active')
            ->find($workspace->default_csat_survey_id);

        return $template?->isOpen() ? $template : null;
    }

    /**
     * @param  array{ticket_id: string, ticket_status?: string, channel?: string}  $ticket
     * @param  array{name?: string, email: string, wa?: string}  $customer
     * @return array{outcome: string, invitation: Invitation}
     */
    public function send(Workspace $workspace, array $ticket, array $customer, Survey $template): array
    {
        $ticketId = trim((string) ($ticket['ticket_id'] ?? ''));
        $customerEmail = mb_strtolower(trim((string) ($customer['email'] ?? '')));
        $customerName = trim((string) ($customer['name'] ?? '')) ?: $customerEmail;
        $customerWa = WhatsAppNumber::normalize((string) ($customer['wa'] ?? ''));

        if ($customerWa !== '' && ! WhatsAppNumber::isValid($customerWa)) {
            $customerWa = '';
        }

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

        $client = $this->client($workspace, $customerName, $customerEmail, $customerWa);

        // Satu tiket hanya boleh menerima satu tautan CSAT, walaupun template
        // yang dipilih berbeda pada klik berikutnya.
        $invitation = Invitation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('ticket_id', $ticketId)
            ->whereHas('survey', fn ($query) => $query->withoutGlobalScopes()->where('type', 'csat'))
            ->first();

        if ($invitation !== null && $invitation->completed_at) {
            return ['outcome' => self::OUTCOME_COMPLETED, 'invitation' => $invitation];
        }

        if ($invitation !== null && $invitation->sent_at) {
            return ['outcome' => self::OUTCOME_ALREADY_SENT, 'invitation' => $invitation];
        }

        $outcome = self::OUTCOME_QUEUED;

        if ($invitation === null) {
            $token = Str::random(64);
            $invitation = Invitation::create([
                'survey_id' => $template->id,
                'client_id' => $client->id,
                'ticket_id' => $ticketId,
                'ticket_status' => (string) ($ticket['ticket_status'] ?? ''),
                'channel' => (string) ($ticket['channel'] ?? ''),
                'token' => $token,
                'token_hash' => hash('sha256', $token),
                'recipient_name' => $customerName,
                'recipient_email' => $customerEmail,
                'is_demo' => false,
            ]);
            $outcome = self::OUTCOME_CREATED;
        } else {
            $invitation->fill([
                'survey_id' => $template->id,
                'ticket_status' => (string) ($ticket['ticket_status'] ?? ''),
                'channel' => (string) ($ticket['channel'] ?? ''),
                'recipient_name' => $customerName,
                'recipient_email' => $customerEmail,
            ]);
            if ($invitation->isDirty()) {
                $invitation->save();
            }
        }

        $this->delivery->enqueue($invitation, 'invitation');

        return ['outcome' => $outcome, 'invitation' => $invitation];
    }

    private function client(Workspace $workspace, string $customerName, string $customerEmail, string $customerWa = ''): Client
    {
        $client = Client::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('email', $customerEmail)
            ->first();

        if ($client !== null) {
            // Nomor dari TransCXP dianggap sumber terbaru. Jangan menghapus
            // nomor lama kalau payload berikutnya tidak membawa nomor.
            if ($customerWa !== '' && $client->wa !== $customerWa) {
                $client->wa = $customerWa;
                $client->save();
            }

            return $client;
        }

        $client = new Client([
            'name' => $customerName,
            'contact' => $customerName,
            'email' => $customerEmail,
            'wa' => $customerWa !== '' ? $customerWa : null,
            'project' => $workspace->name,
            'active' => true,
        ]);
        $client->workspace_id = $workspace->id;
        $client->save();

        return $client;
    }
}

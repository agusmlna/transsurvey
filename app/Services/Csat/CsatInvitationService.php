<?php

namespace App\Services\Csat;

use App\Models\{Client, Invitation, Survey, WhatsAppDelivery, Workspace};
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
    public const OUTCOME_WHATSAPP_PENDING = 'whatsapp_pending';

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
     * @param  array{name?: string, email?: string, wa?: string}  $customer
     * @return array{outcome: string, invitation: Invitation}
     */
    public function send(Workspace $workspace, array $ticket, array $customer, Survey $template): array
    {
        // Jalur ini selalu menghasilkan email CSAT. Tolak lebih awal supaya
        // undangan tidak dibiarkan tanpa delivery saat SMTP CSAT belum diisi.
        if (! CsatMail::isReady()) {
            throw ValidationException::withMessages([
                'mail' => __('Aktifkan SMTP CSAT (MAIL_CSAT_*) dan SURVEY_EMAIL_ENABLED pada konfigurasi server terlebih dahulu.'),
            ]);
        }

        [$ticketId, $customerEmail, $customerName, $customerWa] = $this->recipient($ticket, $customer);

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

        // Jangan kirim email kalau WhatsApp untuk undangan ini sudah terkirim:
        // customer akan menerima dua tautan untuk tiket yang sama.
        if ($invitation !== null && $this->whatsAppSettled($invitation)) {
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

    /**
     * Buat atau ambil tautan CSAT untuk satu tiket tanpa mengirim email.
     *
     * Dipakai API TransCXP ketika operation mengirim tautan lewat kanal milik
     * TransCXP sendiri. Aturan "satu tiket satu tautan" tetap berlaku:
     * - undangan yang sudah ada dipakai ulang (tautannya sama);
     * - tautan yang sudah kedaluwarsa diganti token baru;
     * - undangan yang sudah diisi tidak boleh dipakai lagi.
     *
     * Masa berlaku dihitung sejak tautan dibuat karena tidak ada email yang
     * menjadi titik mulai pengiriman.
     *
     * @param  array{ticket_id: string, ticket_status?: string, channel?: string}  $ticket
     * @param  array{name?: string, email?: string, wa?: string}  $customer
     * @return array{invitation: Invitation, created: bool, completed: bool}
     */
    public function issueLink(Workspace $workspace, array $ticket, array $customer, Survey $template): array
    {
        [$ticketId, $customerEmail, $customerName, $customerWa] = $this->recipient($ticket, $customer, false);

        $invitation = Invitation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('ticket_id', $ticketId)
            ->whereHas('survey', fn ($query) => $query->withoutGlobalScopes()->where('type', 'csat'))
            ->first();

        if ($invitation !== null && $invitation->completed_at) {
            return ['invitation' => $invitation, 'created' => false, 'completed' => true];
        }

        $created = false;

        if ($invitation === null) {
            // Client hanya dibuat kalau undangan belum ada, supaya pemanggilan
            // ulang dengan data name-only tidak meninggalkan client yatim.
            $client = $this->client($workspace, $customerName, $customerEmail, $customerWa);

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
                'recipient_email' => $customerEmail !== '' ? $customerEmail : null,
                'expires_at' => CsatSettings::expiresAt($workspace, now()),
                'is_demo' => false,
            ]);
            $created = true;
        } else {
            $invitation->fill([
                'survey_id' => $template->id,
                'ticket_status' => (string) ($ticket['ticket_status'] ?? ''),
                'channel' => (string) ($ticket['channel'] ?? ''),
                'recipient_name' => $customerName,
                'recipient_email' => $customerEmail !== '' ? $customerEmail : null,
            ]);

            // Tautan kedaluwarsa tidak boleh dipakai lagi. Token baru juga
            // membatalkan tautan lama, jadi hanya tautan terbaru yang berlaku.
            if ($invitation->expires_at !== null && $invitation->expires_at->isPast()) {
                $token = Str::random(64);
                $invitation->token = $token;
                $invitation->token_hash = hash('sha256', $token);
                $invitation->expires_at = CsatSettings::expiresAt($workspace, now());
            }

            if ($invitation->isDirty()) {
                $invitation->save();
            }
        }

        return ['invitation' => $invitation, 'created' => $created, 'completed' => false];
    }

    /**
     * Siapkan pengiriman WhatsApp untuk satu tiket.
     *
     * Tautan dibuat/dipakai ulang lewat issueLink() supaya aturan "satu tiket
     * satu tautan" sama dengan jalur email. Pengiriman dilakukan pemanggil
     * (command) secara sinkron; setelah sukses panggil markWhatsAppSent().
     *
     * @param  array{ticket_id: string, ticket_status?: string, channel?: string}  $ticket
     * @param  array{name?: string, email?: string, wa?: string}  $customer
     * @return array{outcome: string, invitation: Invitation, delivery: WhatsAppDelivery|null, reason: string}
     */
    public function prepareWhatsApp(
        Workspace $workspace,
        array $ticket,
        array $customer,
        Survey $template,
        string $senderNumber,
        string $templateId,
    ): array {
        [$ticketId, , , $customerWa] = $this->recipient($ticket, $customer, false);

        if ($customerWa === '') {
            throw ValidationException::withMessages([
                'wa' => __('Nomor WhatsApp customer tidak tersedia.'),
            ]);
        }

        $invitation = Invitation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('ticket_id', $ticketId)
            ->whereHas('survey', fn ($query) => $query->withoutGlobalScopes()->where('type', 'csat'))
            ->first();

        if ($invitation !== null && $invitation->completed_at) {
            return ['outcome' => self::OUTCOME_COMPLETED, 'invitation' => $invitation, 'delivery' => null, 'reason' => 'completed'];
        }

        if ($invitation !== null && $invitation->sent_at) {
            return ['outcome' => self::OUTCOME_ALREADY_SENT, 'invitation' => $invitation, 'delivery' => null, 'reason' => 'sent'];
        }

        if ($invitation !== null && $this->whatsAppSettled($invitation)) {
            return ['outcome' => self::OUTCOME_ALREADY_SENT, 'invitation' => $invitation, 'delivery' => null, 'reason' => 'whatsapp_sent'];
        }

        if ($invitation !== null && $this->hasPendingEmail($invitation)) {
            return ['outcome' => self::OUTCOME_ALREADY_SENT, 'invitation' => $invitation, 'delivery' => null, 'reason' => 'email_pending'];
        }

        $issued = $this->issueLink($workspace, $ticket, $customer, $template);
        $invitation = $issued['invitation'];

        if ($issued['completed']) {
            return ['outcome' => self::OUTCOME_COMPLETED, 'invitation' => $invitation, 'delivery' => null, 'reason' => 'completed'];
        }

        $dedupeKey = 'csat-wa:'.$invitation->id;

        $delivery = WhatsAppDelivery::query()->where('dedupe_key', $dedupeKey)->first();

        if ($delivery !== null && $delivery->status === WhatsAppDelivery::STATUS_PENDING) {
            return ['outcome' => self::OUTCOME_WHATSAPP_PENDING, 'invitation' => $invitation, 'delivery' => $delivery, 'reason' => 'pending'];
        }

        if ($delivery !== null && $delivery->isSettled()) {
            return ['outcome' => self::OUTCOME_ALREADY_SENT, 'invitation' => $invitation, 'delivery' => $delivery, 'reason' => 'whatsapp_sent'];
        }

        $attributes = [
            'workspace_id' => $workspace->id,
            'invitation_id' => $invitation->id,
            'recipient_number' => '+'.$customerWa,
            'sender_number' => $senderNumber,
            'template_id' => $templateId !== '' ? $templateId : null,
            'status' => WhatsAppDelivery::STATUS_PENDING,
            'failure_reason' => null,
            'failed_at' => null,
        ];

        if ($delivery === null) {
            $delivery = WhatsAppDelivery::create($attributes + ['dedupe_key' => $dedupeKey]);
        } else {
            // Percobaan sebelumnya gagal: pakai baris yang sama supaya
            // dedupe_key tetap unik dan riwayat tidak hilang.
            $delivery->forceFill($attributes)->save();
        }

        return ['outcome' => self::OUTCOME_CREATED, 'invitation' => $invitation, 'delivery' => $delivery, 'reason' => 'ready'];
    }

    /**
     * Tandai pesan WhatsApp sudah diterima TransCXP.
     *
     * sent_at undangan baru diisi di sini, dan masa berlaku tautan dihitung
     * dari waktu kirim tersebut.
     */
    public function markWhatsAppSent(
        Workspace $workspace,
        Invitation $invitation,
        WhatsAppDelivery $delivery,
        string $messageId,
        ?\Carbon\CarbonInterface $sentAt = null,
    ): void {
        $sentAt = $sentAt ?? now();

        $delivery->forceFill([
            'provider_message_id' => $messageId !== '' ? $messageId : $delivery->provider_message_id,
            'status' => WhatsAppDelivery::STATUS_SENT,
            'sent_at' => $sentAt,
            'failed_at' => null,
            'failure_reason' => null,
            'last_checked_at' => $sentAt,
        ])->save();

        $invitation->sent_at = $invitation->sent_at ?? $sentAt;
        $invitation->expires_at = CsatSettings::expiresAt($workspace, $invitation->sent_at);
        $invitation->save();
    }

    public function markWhatsAppFailed(WhatsAppDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => WhatsAppDelivery::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => Str::limit(trim($reason), 1000),
            'last_checked_at' => now(),
        ])->save();
    }

    private function whatsAppSettled(Invitation $invitation): bool
    {
        return $invitation->whatsapp()
            ->whereIn('status', [
                WhatsAppDelivery::STATUS_SENT,
                WhatsAppDelivery::STATUS_DELIVERED,
                WhatsAppDelivery::STATUS_READ,
                'replied',
            ])
            ->exists();
    }

    private function hasPendingEmail(Invitation $invitation): bool
    {
        return $invitation->deliveries()
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * Normalisasi data tiket/customer yang dipakai semua jalur pengiriman.
     *
     * @param  array{ticket_id: string, ticket_status?: string, channel?: string}  $ticket
     * @param  array{name?: string, email?: string, wa?: string}  $customer
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function recipient(array $ticket, array $customer, bool $requireEmail = true): array
    {
        $ticketId = trim((string) ($ticket['ticket_id'] ?? ''));
        $customerEmail = mb_strtolower(trim((string) ($customer['email'] ?? '')));
        $customerName = trim((string) ($customer['name'] ?? ''));
        $customerWa = WhatsAppNumber::normalize((string) ($customer['wa'] ?? ''));

        if ($customerWa !== '' && ! WhatsAppNumber::isValid($customerWa)) {
            $customerWa = '';
        }

        // Jalur email (halaman Kirim CSAT) tetap wajib email. Jalur tautan
        // manual boleh tanpa email asalkan ada nomor WA atau nama customer.
        $missingIdentity = $requireEmail
            ? $customerEmail === ''
            : ($customerEmail === '' && $customerWa === '' && $customerName === '');

        if ($ticketId === '' || $missingIdentity) {
            throw ValidationException::withMessages([
                'context' => __('Data tiket dan customer tidak ditemukan. Buka CSAT dari tiket TransCXP terlebih dahulu.'),
            ]);
        }

        if ($customerEmail !== '' && ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'context' => __('Email customer pada data tiket tidak valid.'),
            ]);
        }

        $customerName = $customerName !== '' ? $customerName : ($customerEmail !== '' ? $customerEmail : $customerWa);

        return [$ticketId, $customerEmail, $customerName, $customerWa];
    }

    private function client(Workspace $workspace, string $customerName, string $customerEmail, string $customerWa = ''): Client
    {
        $query = Client::withoutGlobalScopes()->where('workspace_id', $workspace->id);

        // Identitas utama tetap email. Untuk tiket tanpa email, nomor WA
        // menjadi kunci supaya customer yang sama tidak terpecah.
        if ($customerEmail !== '') {
            $query->where('email', $customerEmail);
        } elseif ($customerWa !== '') {
            $query->where('wa', $customerWa);
        } else {
            $query = null;
        }

        $client = $query?->first();

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
            'email' => $customerEmail !== '' ? $customerEmail : null,
            'wa' => $customerWa !== '' ? $customerWa : null,
            'project' => $workspace->name,
            'active' => true,
        ]);
        $client->workspace_id = $workspace->id;
        $client->save();

        return $client;
    }
}

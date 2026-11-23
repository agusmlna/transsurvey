<?php

namespace App\Services\Csat;

use App\Exceptions\CxpApiException;
use App\Models\{Invitation, Survey, Workspace};
use App\Services\Cxp\CxpWhatsAppClient;
use Illuminate\Validation\ValidationException;

/**
 * Jalur kirim manual WhatsApp dari halaman Kirim CSAT.
 *
 * Command cxp:csat-send punya alur serupa, tetapi halaman manual memakai
 * nomor yang diketik agent (bukan source tiket), jadi akun dipilih eksplisit
 * untuk workspace tersebut. Aturan satu tiket satu tautan tetap memakai
 * CsatInvitationService.
 */
class CsatManualSender
{
    public function __construct(
        private readonly CsatInvitationService $invitations,
        private readonly CsatChannelRouter $router,
        private readonly CxpWhatsAppClient $waClient,
        private readonly CsatWhatsAppTemplate $waTemplate,
    ) {}

    /**
     * @param  array{ticket_id: string, ticket_status?: string, channel?: string}  $ticket
     * @param  array{name?: string, email?: string, wa?: string}  $customer
     * @return array{outcome: string, invitation: Invitation}
     */
    public function whatsapp(Workspace $workspace, array $ticket, array $customer, Survey $template): array
    {
        $wa = WhatsAppNumber::normalize((string) ($customer['wa'] ?? ''));

        if ($wa === '' || ! WhatsAppNumber::isValid($wa)) {
            throw ValidationException::withMessages([
                'customer_wa' => __('Nomor WhatsApp tujuan tidak valid. Gunakan format 08... atau +62...'),
            ]);
        }

        $route = $this->router->route('WhatsApp', (string) ($customer['email'] ?? ''), $wa, $workspace);
        $account = $route['account'];

        if ($route['channel'] !== CsatChannelRouter::CHANNEL_WHATSAPP || ! is_array($account)) {
            throw ValidationException::withMessages([
                'customer_wa' => __('Akun WhatsApp untuk workspace ini belum tersedia. Hubungi admin untuk menyiapkan kredensial project.'),
            ]);
        }

        $templateId = $this->waTemplate->templateId($workspace);

        if ($templateId === '') {
            throw ValidationException::withMessages([
                'template_id' => __('ID template WhatsApp belum diisi pada konfigurasi server.'),
            ]);
        }

        $prepared = $this->invitations->prepareWhatsApp(
            $workspace,
            $ticket,
            $customer,
            $template,
            trim((string) ($account['number'] ?? '')),
            $templateId,
        );

        $invitation = $prepared['invitation'];

        if (in_array($prepared['outcome'], [
            CsatInvitationService::OUTCOME_COMPLETED,
            CsatInvitationService::OUTCOME_ALREADY_SENT,
            CsatInvitationService::OUTCOME_WHATSAPP_PENDING,
        ], true)) {
            return ['outcome' => $prepared['outcome'], 'invitation' => $invitation];
        }

        $delivery = $prepared['delivery'];

        if ($delivery === null) {
            throw ValidationException::withMessages([
                'customer_wa' => __('Pengiriman WhatsApp tidak dapat disiapkan. Coba lagi beberapa saat.'),
            ]);
        }

        try {
            $result = $this->waClient->sendTemplate(
                $account,
                $templateId,
                (string) $invitation->recipient_name,
                (string) $route['number'],
                $this->waTemplate->components($invitation, $workspace),
            );
        } catch (CxpApiException $e) {
            $this->invitations->markWhatsAppFailed($delivery, $e->getMessage());

            throw ValidationException::withMessages([
                'customer_wa' => __('WhatsApp TransCXP menolak pengiriman. Periksa akun dan template workspace ini.'),
            ]);
        }

        $this->invitations->markWhatsAppSent($workspace, $invitation, $delivery, $result['message_id']);

        return ['outcome' => CsatInvitationService::OUTCOME_CREATED, 'invitation' => $invitation];
    }
}

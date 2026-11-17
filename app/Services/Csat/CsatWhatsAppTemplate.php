<?php

namespace App\Services\Csat;

use App\Models\Invitation;
use App\Models\Workspace;

/**
 * Menyusun components pesan template WhatsApp dari data undangan.
 *
 * Urutan parameter body diatur di CXP_WA_TEMPLATE_PARAMS karena setiap
 * template punya susunan sendiri. Parameter yang tidak dikenal tetap dikirim
 * sebagai string kosong supaya posisi parameter lain tidak bergeser.
 */
class CsatWhatsAppTemplate
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function components(Invitation $invitation, Workspace $workspace): array
    {
        $values = $this->values($invitation, $workspace);
        $components = [];

        $bodyParams = array_values(array_filter(
            array_map(fn ($key): string => trim((string) $key), (array) config('cxp.whatsapp.template_params', [])),
            fn (string $key): bool => $key !== '',
        ));

        if ($bodyParams !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $key): array => ['type' => 'text', 'text' => (string) ($values[$key] ?? '')],
                    $bodyParams,
                ),
            ];
        }

        $buttonParam = trim((string) config('cxp.whatsapp.button_url_param', ''));

        if ($buttonParam !== '') {
            $buttonValue = (string) ($values[$buttonParam] ?? '');

            if ($buttonValue !== '') {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => '0',
                    'parameters' => [['type' => 'text', 'text' => $buttonValue]],
                ];
            }
        }

        return $components;
    }

    public function templateId(): string
    {
        return trim((string) config('cxp.whatsapp.template_id', ''));
    }

    /**
     * @return array<string, string>
     */
    private function values(Invitation $invitation, Workspace $workspace): array
    {
        return [
            'customer_name' => (string) ($invitation->recipient_name ?: $invitation->client?->name ?: ''),
            'survey_url' => $invitation->surveyUrl(),
            'project_name' => (string) $workspace->name,
            'project_code' => (string) $workspace->code,
            'ticket_id' => (string) $invitation->ticket_id,
        ];
    }
}

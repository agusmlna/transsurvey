<?php

namespace App\Http\Controllers;

use App\Exceptions\CxpSsoException;
use App\Models\Workspace;
use App\Services\Csat\CsatInvitationService;
use App\Services\Cxp\{CxpAccountProvisioner, CxpClaimNormalizer};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/cxp/csat-links
 *
 * Server-to-server: TransCXP meminta tautan CSAT untuk satu tiket, lalu
 * mengirimkannya sendiri ke customer (kanal manual TransCXP). TransSurvey
 * tidak mengantrekan email pada jalur ini.
 *
 * Payload sama dengan launch session (user + project + ticket + customer)
 * supaya TransCXP memakai kontrak yang sudah ada. Aturan "satu tiket satu
 * tautan" dijaga oleh CsatInvitationService::issueLink().
 */
class CxpCsatLinkController
{
    public function __invoke(
        Request $request,
        CxpClaimNormalizer $normalizer,
        CxpAccountProvisioner $provisioner,
        CsatInvitationService $invitations,
    ) {
        $expected = (string) config('cxp.launch_api_key', '');
        if ($expected === '') {
            return response()->json([
                'message' => __('Konfigurasi API peluncuran belum lengkap.'),
            ], 500);
        }

        $provided = $request->bearerToken() ?: $request->header('X-CXP-Key');
        if (! is_string($provided) || $provided === '' || ! hash_equals($expected, $provided)) {
            Log::warning('CXP CSAT link rejected', [
                'reason' => 'bad_api_key',
                'ip' => $request->ip(),
            ]);

            return $this->error(__('API key tidak valid.'), 'bad_api_key', 401);
        }

        try {
            $payload = $normalizer->normalize($request->all());
            $identity = $provisioner->identity($payload);
        } catch (CxpSsoException $e) {
            return $this->error($e->getMessage(), $e->reason, $e->status >= 500 ? 500 : 422);
        }

        // Jangan pernah membuat workspace dari endpoint ini: kode project yang
        // salah harus gagal, bukan diam-diam membuat workspace baru.
        $workspace = Workspace::query()
            ->where('is_legacy', false)
            ->whereRaw('lower(code) = ?', [mb_strtolower($identity['project_code'])])
            ->first();

        if ($workspace === null) {
            return $this->error(
                __('Project TransCXP belum terdaftar di TransSurvey.'),
                'workspace_not_found',
                404,
            );
        }

        $template = $invitations->defaultTemplate($workspace);
        if ($template === null) {
            return $this->error(
                __('Workspace belum punya template CSAT aktif sebagai default.'),
                'template_not_ready',
                409,
            );
        }

        try {
            $result = $invitations->issueLink(
                $workspace,
                [
                    'ticket_id' => $identity['ticket_id'],
                    'ticket_status' => $identity['ticket_status'],
                    'channel' => $identity['channel'],
                ],
                [
                    'name' => $identity['customer_name'],
                    'email' => $identity['customer_email'],
                    'wa' => $identity['customer_wa'],
                ],
                $template,
            );
        } catch (ValidationException $e) {
            return $this->error(
                $e->validator->errors()->first(),
                'invalid_claim',
                422,
            );
        }

        $invitation = $result['invitation'];

        if ($result['completed']) {
            return $this->error(
                __('Customer sudah mengisi CSAT untuk tiket ini.'),
                'csat_completed',
                409,
            );
        }

        Log::info('CXP CSAT link issued', [
            'workspace' => $workspace->code,
            'ticket' => $identity['ticket_id'],
            'actor' => $identity['external_id'],
            'created' => $result['created'],
        ]);

        return response()->json([
            'project' => $workspace->code,
            'ticket_id' => $identity['ticket_id'],
            'survey_id' => $invitation->survey_id,
            'url' => $invitation->surveyUrl(),
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'created' => $result['created'],
        ], $result['created'] ? 201 : 200)->header('Cache-Control', 'no-store');
    }

    private function error(string $message, string $reason, int $status)
    {
        return response()->json([
            'message' => $message,
            'reason' => $reason,
        ], $status)->header('Cache-Control', 'no-store');
    }
}

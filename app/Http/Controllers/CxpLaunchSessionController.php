<?php

namespace App\Http\Controllers;

use App\Exceptions\CxpSsoException;
use App\Services\Cxp\{CxpAccountProvisioner, CxpClaimNormalizer, CxpLaunchCodeStore};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/cxp/launch-sessions
 *
 * Alur tanpa JWT: TransCXP mengirim identitas user/project (dan tiket opsional)
 * lewat server-to-server. TransSurvey membalas kode sekali pakai + URL. Browser
 * lalu diarahkan ke /cxp/launch?code=<kode>.
 *
 * Autentikasi memakai shared API key di header, bukan sesi.
 */
class CxpLaunchSessionController
{
    public function __invoke(
        Request $request,
        CxpClaimNormalizer $normalizer,
        CxpAccountProvisioner $provisioner,
        CxpLaunchCodeStore $codes,
    ) {
        $expected = (string) config('cxp.launch_api_key', '');
        if ($expected === '') {
            return response()->json([
                'message' => __('Konfigurasi API peluncuran belum lengkap.'),
            ], 500);
        }

        $provided = $request->bearerToken() ?: $request->header('X-CXP-Key');
        if (! is_string($provided) || $provided === '' || ! hash_equals($expected, $provided)) {
            Log::warning('CXP launch session rejected', [
                'reason' => 'bad_api_key',
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => __('API key tidak valid.')], 401);
        }

        try {
            $payload = $normalizer->normalize($request->all());
            $provisioner->validate($payload);
        } catch (CxpSsoException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ], $e->status >= 500 ? 500 : 422);
        }

        $issued = $codes->issue($payload);

        return response()->json([
            'code' => $issued['code'],
            'url' => route('cxp.launch', ['code' => $issued['code']]),
            'expires_in' => $issued['ttl'],
        ], 201)->header('Cache-Control', 'no-store');
    }
}

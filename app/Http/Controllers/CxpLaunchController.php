<?php

namespace App\Http\Controllers;

use App\Exceptions\CxpSsoException;
use App\Services\Cxp\{CxpAccountProvisioner, CxpLaunchCodeStore, CxpLaunchContext, CxpTokenVerifier};
use App\Services\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Log, Route};
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /cxp/launch?token=<JWT>, ?sso=<JWT>, atau ?code=<kode sekali pakai>
 *
 * Login otomatis dari tombol TransCXP. Endpoint ini tidak memakai middleware
 * workspace: workspace baru ditentukan setelah token/kode diverifikasi.
 */
class CxpLaunchController
{
    public function __invoke(
        Request $request,
        CxpTokenVerifier $verifier,
        CxpAccountProvisioner $provisioner,
        CxpLaunchCodeStore $codes,
    ): Response {
        // TransCXP gaya CakrawalaHub mengirim token di parameter "sso".
        $token = $request->query('token');
        if (! is_string($token) || trim($token) === '') {
            $token = $request->query('sso');
        }

        $code = $request->query('code');

        try {
            // Alur tanpa JWT: kode sekali pakai dari POST /api/cxp/launch-sessions.
            if (is_string($code) && trim($code) !== '') {
                $payload = $codes->pull($code);
                if ($payload === null) {
                    throw CxpSsoException::invalid(
                        'invalid_launch_code',
                        __('Kode peluncuran tidak valid atau sudah kedaluwarsa.')
                    );
                }
            } else {
                if (! is_string($token) || trim($token) === '' || strlen($token) > 8192) {
                    throw CxpSsoException::invalid('missing_token', __('Token SSO tidak ditemukan.'));
                }

                $payload = $verifier->verify(trim($token));
            }

            $result = $provisioner->provision($payload);

            if (Auth::check()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            Auth::login($result->user);
            $request->session()->regenerate();
            $request->session()->put(CurrentWorkspace::SESSION_KEY, $result->workspace->id);
            CxpLaunchContext::put($request->session(), $result);

            $redirect = (string) config('cxp.redirect_route', 'dashboard');

            // Tiket yang dibuka dari TransCXP langsung mengarah ke halaman Kirim CSAT.
            if (data_get($result->context, 'ticket.id') && data_get($result->context, 'customer.email')) {
                $redirect = 'csat.send.index';
            }

            return redirect()
                ->route(Route::has($redirect) ? $redirect : 'dashboard')
                ->with('success', __('Login berhasil melalui TransCXP.'));
        } catch (CxpSsoException $e) {
            Log::warning('CXP SSO rejected', [
                'reason' => $e->reason,
                'context' => $e->context,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);

            return response()->view('cxp.error', ['message' => $e->getMessage()], $e->status);
        } catch (\Throwable $e) {
            report($e);

            return response()->view('cxp.error', [
                'message' => __('Terjadi kesalahan saat membuka TransSurvey. Silakan coba lagi atau hubungi administrator.'),
            ], 500);
        }
    }
}

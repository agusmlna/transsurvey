<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Services\ResponseService;
use App\Services\SurveyAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class PublicSurveyController
{
    private function find(string $token): Invitation
    {
        abort_unless(strlen($token) === 64, 404);

        return Invitation::with(['survey', 'client'])
            ->where('token_hash', hash('sha256', $token))->firstOrFail();
    }

    private function accessPage(Invitation $invitation, int $retryAfter = 0)
    {
        return response()->view('public.access', [
            'invitation' => $invitation,
            'available' => $invitation->survey->isOpen(),
            'codeReady' => (bool) $invitation->access_code_hash,
            'retryAfter' => $retryAfter,
        ], $retryAfter > 0 ? 429 : 200, $retryAfter > 0 ? ['Retry-After' => (string) $retryAfter] : []);
    }

    public function show(Request $request, string $token, SurveyAccessService $access)
    {
        $invitation = $this->find($token);
        if ($invitation->completed_at) {
            $access->forget($request, $invitation);
            return view('public.thanks');
        }

        if (! $invitation->survey->isOpen()) {
            $access->forget($request, $invitation);
            return $this->accessPage($invitation);
        }

        if (! $access->isVerified($request, $invitation)) {
            return $this->accessPage($invitation);
        }

        $invitation->survey->load('questions');
        return view('public.survey', [
            'survey' => $invitation->survey,
            'invitation' => $invitation,
            'preview' => false,
            'closed' => false,
        ]);
    }

    public function verify(Request $request, string $token, SurveyAccessService $access)
    {
        $invitation = $this->find($token);
        if ($invitation->completed_at || ! $invitation->survey->isOpen()) {
            $access->forget($request, $invitation);
            return redirect()->route('survey.public', $token);
        }

        // Invitation-wide budget prevents bypass by switching browser/IP.
        // IP budget also limits attempts spread across many invitation links.
        $invitationKey = 'survey-code:inv:'.$invitation->token_hash;
        $ipKey = 'survey-code:ip:'.hash('sha256', (string) $request->ip());
        $limits = [$invitationKey => 5, $ipKey => 30];
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return $this->accessPage($invitation, max(1, RateLimiter::availableIn($key)));
            }
        }
        foreach ($limits as $key => $max) {
            // Use the atomic increment result as well, to reject parallel guesses.
            if (RateLimiter::increment($key, 600) > $max) {
                return $this->accessPage($invitation, max(1, RateLimiter::availableIn($key)));
            }
        }

        $value = $request->input('access_code');
        $code = is_string($value) ? trim($value) : '';
        if (! preg_match('/\A[0-9]{6}\z/', $code)
            || ! $invitation->access_code_hash
            || ! Hash::check($code, $invitation->access_code_hash)) {
            // Do not flash the access code back into session/HTML.
            return redirect()->route('survey.public', $token)
                ->withErrors(['access_code' => __('Kode tidak sesuai. Periksa 6 angka pada email undangan Anda.')]);
        }

        RateLimiter::clear($invitationKey);
        $access->grant($request, $invitation);
        return redirect()->route('survey.public', $token)->with('success', __('Kode terverifikasi. Silakan isi survei.'));
    }

    public function submit(Request $request, string $token, ResponseService $service, SurveyAccessService $access)
    {
        $invitation = $this->find($token);
        if ($invitation->completed_at || ! $invitation->survey->isOpen()) {
            $access->forget($request, $invitation);
            return redirect()->route('survey.public', $token)
                ->withErrors(['survey' => __('Survei sudah selesai atau sedang tidak menerima respons.')]);
        }

        if (! $access->isVerified($request, $invitation)) {
            return redirect()->route('survey.public', $token)
                ->withErrors(['access_code' => __('Masukkan kode dari email sebelum menyimpan atau mengirim jawaban.')]);
        }

        $request->validate(['action' => 'required|in:draft,submit', 'answers' => 'nullable|array']);
        $draft = $request->input('action') === 'draft';
        $service->save($invitation, $request->only('answers'), $draft);
        if ($draft) {
            return redirect()->route('survey.public', $token)
                ->with('success', __('Draf tersimpan. Buka tautan yang sama untuk melanjutkan.'));
        }

        $access->forget($request, $invitation);
        return redirect()->route('survey.public', $token);
    }
}

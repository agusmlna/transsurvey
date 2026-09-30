<?php

namespace App\Services;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class SurveyAccessService
{
    /** Called by SendSurveyEmail inside its transaction, with the invitation locked. */
    public function prepareForEmail(Invitation $invitation): void
    {
        if ($invitation->access_code_hash && $invitation->access_code_fingerprint && $invitation->access_code) {
            return;
        }

        // Never silently rotate a partially populated credential.
        if ($invitation->access_code_hash || $invitation->access_code_fingerprint || $invitation->access_code) {
            throw new RuntimeException('Survey access code data is incomplete.');
        }

        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('The application encryption key is not configured.');
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = (string) random_int(100000, 999999);
            // A keyed fingerprint checks uniqueness without storing a plain SHA of a short code.
            $fingerprint = hash_hmac('sha256', $code, $key);
            if (Invitation::where('access_code_fingerprint', $fingerprint)->exists()) {
                continue;
            }

            $invitation->forceFill([
                'access_code' => $code, // Encrypted by the model cast; needed for reminder emails.
                'access_code_hash' => Hash::make($code),
                'access_code_fingerprint' => $fingerprint,
            ])->save();
            // The DB unique index also guards concurrent issuance. A collision rolls back
            // this send transaction, and the existing queued job retry generates another code.
            return;
        }

        throw new RuntimeException('Unable to allocate a survey access code.');
    }

    public function sessionKey(Invitation $invitation): string
    {
        return 'survey_access.'.$invitation->getKey();
    }

    public function isVerified(Request $request, Invitation $invitation): bool
    {
        $grant = $request->session()->get($this->sessionKey($invitation));
        if (! $invitation->access_code_hash || ! is_array($grant)) {
            return false;
        }

        return is_string($grant['credential'] ?? null)
            && hash_equals(hash('sha256', $invitation->access_code_hash), $grant['credential'])
            && (int) ($grant['expires_at'] ?? 0) > now()->timestamp;
    }

    public function grant(Request $request, Invitation $invitation): void
    {
        $request->session()->regenerate();
        $request->session()->put($this->sessionKey($invitation), [
            'credential' => hash('sha256', $invitation->access_code_hash),
            'expires_at' => now()->addHours(8)->timestamp,
        ]);
    }

    public function forget(Request $request, Invitation $invitation): void
    {
        $request->session()->forget($this->sessionKey($invitation));
    }
}

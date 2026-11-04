<?php

namespace App\Services\Cxp;

use App\Exceptions\CxpSsoException;
use Illuminate\Support\Facades\Cache;

/**
 * Verifikasi JWT dari TransCXP tanpa library tambahan.
 *
 * Yang diperiksa: struktur token, algoritma yang diizinkan, tanda tangan,
 * iss/aud/iat/exp/nbf, batas umur token, dan jti sekali pakai.
 */
class CxpTokenVerifier
{
    public function verify(string $token): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3 || in_array('', $segments, true)) {
            throw CxpSsoException::invalid('malformed_token', __('Token SSO tidak valid.'));
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $header = $this->decodeJson($encodedHeader, 'header');
        $payload = $this->decodeJson($encodedPayload, 'payload');
        $signature = $this->base64UrlDecode($encodedSignature, 'signature');

        $algorithm = strtoupper((string) ($header['alg'] ?? ''));
        $allowed = array_map('strtoupper', (array) config('cxp.algorithms', ['HS256']));

        if ($algorithm === '' || $algorithm === 'NONE' || ! in_array($algorithm, $allowed, true)) {
            throw CxpSsoException::invalid('algorithm_not_allowed', __('Algoritma token SSO tidak diizinkan.'));
        }
        if (! in_array($algorithm, ['HS256', 'RS256'], true)) {
            throw CxpSsoException::invalid('algorithm_not_supported', __('Algoritma token SSO tidak didukung.'));
        }

        $this->verifySignature($algorithm, $encodedHeader.'.'.$encodedPayload, $signature);
        $this->verifyClaims($payload);
        $this->guardReplay($payload);

        return $payload;
    }

    private function verifySignature(string $algorithm, string $signingInput, string $signature): void
    {
        if ($algorithm === 'HS256') {
            $secret = (string) config('cxp.sso_secret');
            if ($secret === '') {
                throw CxpSsoException::server('sso_secret_missing', __('Konfigurasi SSO belum lengkap.'));
            }

            $expected = hash_hmac('sha256', $signingInput, $secret, true);
            if (! hash_equals($expected, $signature)) {
                throw CxpSsoException::invalid('bad_signature', __('Tanda tangan token SSO tidak valid.'));
            }

            return;
        }

        $publicKey = $this->publicKey();
        if ($publicKey === null) {
            throw CxpSsoException::server('sso_public_key_missing', __('Konfigurasi SSO belum lengkap.'));
        }
        if (openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw CxpSsoException::invalid('bad_signature', __('Tanda tangan token SSO tidak valid.'));
        }
    }

    private function publicKey(): ?string
    {
        $path = trim((string) config('cxp.sso_public_key_path'));
        if ($path !== '') {
            $contents = @file_get_contents($path);
            if ($contents === false || trim($contents) === '') {
                throw CxpSsoException::server('sso_public_key_unreadable', __('Konfigurasi SSO belum lengkap.'));
            }

            return $contents;
        }

        $key = (string) config('cxp.sso_public_key');

        return $key === '' ? null : str_replace('\n', "\n", $key);
    }

    private function verifyClaims(array $payload): void
    {
        foreach (['iss', 'aud', 'iat', 'exp', 'jti'] as $claim) {
            if (! array_key_exists($claim, $payload)) {
                throw CxpSsoException::invalid('missing_claim', __('Token SSO tidak lengkap.'), ['claim' => $claim]);
            }
        }

        $issuer = (string) config('cxp.issuer');
        if (! is_string($payload['iss']) || ! hash_equals($issuer, $payload['iss'])) {
            throw CxpSsoException::invalid('bad_issuer', __('Token SSO bukan dari sumber yang dikenal.'));
        }

        $audiences = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];
        $audiences = array_values(array_filter($audiences, 'is_string'));
        if (! in_array((string) config('cxp.audience'), $audiences, true)) {
            throw CxpSsoException::invalid('bad_audience', __('Token SSO tidak ditujukan untuk TransSurvey.'));
        }

        if (! is_numeric($payload['iat']) || ! is_numeric($payload['exp'])) {
            throw CxpSsoException::invalid('invalid_time_claim', __('Token SSO tidak valid.'));
        }

        $now = time();
        $leeway = max(0, (int) config('cxp.leeway', 60));
        $issuedAt = (int) $payload['iat'];
        $expiresAt = (int) $payload['exp'];
        $ttl = max(1, (int) config('cxp.ttl', 300));

        if ($issuedAt > $now + $leeway) {
            throw CxpSsoException::invalid('invalid_iat', __('Token SSO tidak valid.'));
        }
        if ($expiresAt <= $now - $leeway) {
            throw CxpSsoException::invalid('token_expired', __('Token SSO sudah kedaluwarsa. Silakan buka ulang dari TransCXP.'));
        }
        if ($expiresAt <= $issuedAt) {
            throw CxpSsoException::invalid('invalid_exp', __('Token SSO tidak valid.'));
        }
        if ($now - $issuedAt > $ttl + $leeway || $expiresAt - $issuedAt > $ttl + $leeway) {
            throw CxpSsoException::invalid('token_too_old', __('Token SSO sudah kedaluwarsa. Silakan buka ulang dari TransCXP.'));
        }

        if (array_key_exists('nbf', $payload)) {
            if (! is_numeric($payload['nbf'])) {
                throw CxpSsoException::invalid('invalid_nbf', __('Token SSO tidak valid.'));
            }
            if ((int) $payload['nbf'] > $now + $leeway) {
                throw CxpSsoException::invalid('token_not_yet_valid', __('Token SSO belum berlaku.'));
            }
        }

        if (! is_string($payload['jti']) || trim($payload['jti']) === '' || strlen($payload['jti']) > 255) {
            throw CxpSsoException::invalid('invalid_jti', __('Token SSO tidak valid.'));
        }
    }

    private function guardReplay(array $payload): void
    {
        $remaining = max(1, (int) $payload['exp'] - time());
        $ttl = $remaining + max(0, (int) config('cxp.leeway', 60)) + 60;
        $key = (string) config('cxp.replay_prefix', 'cxp:jti:').hash('sha256', trim((string) $payload['jti']));

        if (! Cache::add($key, true, $ttl)) {
            throw CxpSsoException::invalid('token_replayed', __('Token SSO sudah pernah digunakan. Silakan buka ulang dari TransCXP.'));
        }
    }

    private function decodeJson(string $segment, string $part): array
    {
        $decoded = $this->base64UrlDecode($segment, $part);

        try {
            $value = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw CxpSsoException::invalid('malformed_token', __('Token SSO tidak valid.'), ['part' => $part]);
        }

        if (! is_array($value)) {
            throw CxpSsoException::invalid('malformed_token', __('Token SSO tidak valid.'), ['part' => $part]);
        }

        return $value;
    }

    private function base64UrlDecode(string $segment, string $part): string
    {
        $remainder = strlen($segment) % 4;
        if ($remainder !== 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        if ($decoded === false) {
            throw CxpSsoException::invalid('malformed_token', __('Token SSO tidak valid.'), ['part' => $part]);
        }

        return $decoded;
    }
}

<?php

namespace App\Services\Cxp;

use App\Exceptions\CxpSsoException;
use Illuminate\Support\Facades\Cache;

/**
 * Verifikasi JWT dari TransCXP tanpa library tambahan.
 *
 * Mendukung HS256 (shared secret), RS256 (public key RSA), dan ES256
 * (public key ECDSA P-256 dengan signature raw R||S seperti pola CakrawalaHub).
 *
 * Yang diperiksa: struktur token, algoritma yang diizinkan, tanda tangan,
 * iss/aud (bila dikirim), iat/exp/nbf, batas umur token, dan anti-replay.
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
        if (! in_array($algorithm, ['HS256', 'RS256', 'ES256'], true)) {
            throw CxpSsoException::invalid('algorithm_not_supported', __('Algoritma token SSO tidak didukung.'));
        }

        $this->verifySignature($algorithm, $encodedHeader.'.'.$encodedPayload, $signature);
        $payload = $this->normalize($payload);
        $this->verifyClaims($payload);
        $this->guardReplay($payload, $token);

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

        if ($algorithm === 'ES256') {
            // JWT ES256 memakai signature raw R||S, sedangkan OpenSSL minta DER.
            $signature = $this->rawSignatureToDer($signature);
            if ($signature === null) {
                throw CxpSsoException::invalid('bad_signature', __('Tanda tangan token SSO tidak valid.'));
            }
        }

        if (openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw CxpSsoException::invalid('bad_signature', __('Tanda tangan token SSO tidak valid.'));
        }
    }

    /**
     * Ubah signature ECDSA mentah (32 byte R + 32 byte S) menjadi DER.
     */
    private function rawSignatureToDer(string $raw): ?string
    {
        if (strlen($raw) !== 64) {
            return null;
        }

        $encodeInteger = static function (string $value): string {
            $value = ltrim($value, "\x00");
            if ($value === '' || (ord($value[0]) & 0x80) === 0x80) {
                $value = "\x00".$value;
            }

            return "\x02".chr(strlen($value)).$value;
        };

        $sequence = $encodeInteger(substr($raw, 0, 32)).$encodeInteger(substr($raw, 32, 32));
        $length = strlen($sequence);

        if ($length < 0x80) {
            return "\x30".chr($length).$sequence;
        }
        if ($length < 0x100) {
            return "\x30\x81".chr($length).$sequence;
        }

        return "\x30\x82".chr(($length >> 8) & 0xff).chr($length & 0xff).$sequence;
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

    /**
     * Token gaya CakrawalaHub hanya mengirim email di level atas. Salin ke
     * user.email supaya provisioner tetap memakai satu bentuk klaim.
     *
     * Format TransCXP terbaru menaruh customer di dalam ticket. Ratakan ke
     * bentuk lama (ticket + customer terpisah) agar provisioner tidak perlu
     * mengenal dua bentuk. Array tiket hanya diterima bila berisi satu tiket;
     * daftar banyak tiket bukan konteks login.
     */
    private function normalize(array $payload): array
    {
        if (array_key_exists('ticket', $payload) && ! is_array($payload['ticket'])) {
            throw CxpSsoException::invalid('invalid_claim', __('Data pada token SSO tidak valid.'), ['claim' => 'ticket']);
        }

        $ticket = $payload['ticket'] ?? null;

        if (is_array($ticket) && array_is_list($ticket) && $ticket !== []) {
            if (count($ticket) > 1) {
                throw CxpSsoException::invalid(
                    'ambiguous_ticket',
                    __('Token SSO memuat lebih dari satu tiket. Buka CSAT dari satu tiket.')
                );
            }

            if (! is_array($ticket[0])) {
                throw CxpSsoException::invalid('invalid_claim', __('Data pada token SSO tidak valid.'), ['claim' => 'ticket']);
            }

            $payload['ticket'] = $ticket[0];
            $ticket = $payload['ticket'];
        }

        if (is_array($ticket) && is_array($ticket['customer'] ?? null) && ! is_array($payload['customer'] ?? null)) {
            $payload['customer'] = $ticket['customer'];
        }

        if (array_key_exists('customer', $payload) && ! is_array($payload['customer'])) {
            throw CxpSsoException::invalid('invalid_claim', __('Data pada token SSO tidak valid.'), ['claim' => 'customer']);
        }

        $email = $payload['email'] ?? null;
        if (! is_string($email) || trim($email) === '') {
            return $payload;
        }

        $email = trim($email);

        if (! is_array($payload['user'] ?? null)) {
            $payload['user'] = ['email' => $email];
        } elseif (! isset($payload['user']['email'])) {
            $payload['user']['email'] = $email;
        }

        return $payload;
    }

    private function verifyClaims(array $payload): void
    {
        $requireIssAud = (bool) config('cxp.require_iss_aud', false);
        $requireJti = ! (bool) config('cxp.replay_fallback', false);

        $required = ['iat', 'exp'];
        if ($requireIssAud) {
            $required[] = 'iss';
            $required[] = 'aud';
        }
        if ($requireJti) {
            $required[] = 'jti';
        }

        foreach ($required as $claim) {
            if (! array_key_exists($claim, $payload)) {
                throw CxpSsoException::invalid('missing_claim', __('Token SSO tidak lengkap.'), ['claim' => $claim]);
            }
        }

        $issuer = (string) config('cxp.issuer', '');
        if (array_key_exists('iss', $payload) && $issuer !== '') {
            if (! is_string($payload['iss']) || ! hash_equals($issuer, $payload['iss'])) {
                throw CxpSsoException::invalid('bad_issuer', __('Token SSO bukan dari sumber yang dikenal.'));
            }
        }

        $audience = (string) config('cxp.audience', '');
        if (array_key_exists('aud', $payload) && $audience !== '') {
            $audiences = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];
            $audiences = array_values(array_filter($audiences, 'is_string'));
            if (! in_array($audience, $audiences, true)) {
                throw CxpSsoException::invalid('bad_audience', __('Token SSO tidak ditujukan untuk TransSurvey.'));
            }
        }

        $expectedApiToken = trim((string) config('cxp.api_token_id', ''));
        if ($expectedApiToken !== '') {
            if (! array_key_exists('apiTokenId', $payload) || (string) $payload['apiTokenId'] !== $expectedApiToken) {
                throw CxpSsoException::invalid('bad_api_token_id', __('Token SSO bukan untuk TransSurvey.'));
            }
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

        if (array_key_exists('jti', $payload)) {
            if (! is_string($payload['jti']) || trim($payload['jti']) === '' || strlen($payload['jti']) > 255) {
                throw CxpSsoException::invalid('invalid_jti', __('Token SSO tidak valid.'));
            }
        }
    }

    private function guardReplay(array $payload, string $token): void
    {
        $jti = isset($payload['jti']) && is_string($payload['jti']) ? trim($payload['jti']) : '';

        if ($jti !== '') {
            $identifier = 'jti:'.$jti;
        } elseif ((bool) config('cxp.replay_fallback', false)) {
            $identifier = 'token:'.hash('sha256', $token);
        } else {
            throw CxpSsoException::invalid('invalid_jti', __('Token SSO tidak valid.'));
        }

        $remaining = max(1, (int) $payload['exp'] - time());
        $ttl = $remaining + max(0, (int) config('cxp.leeway', 60)) + 60;
        $key = (string) config('cxp.replay_prefix', 'cxp:jti:').hash('sha256', $identifier);

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

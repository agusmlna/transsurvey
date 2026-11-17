<?php

namespace App\Services\Cxp;

use App\Exceptions\CxpApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Klien TransCXP WhatsApp Broadcast API v2.
 *
 * Alur: login-user (Basic Auth) -> messages-template -> messages-template/{id}.
 * Token disimpan di cache per username dan tidak pernah ditulis ke log.
 *
 * Endpoint kirim satu pesan adalah POST /api/v2/messages-template. Dokumen
 * TransCXP juga memuat contoh curl /api/v2/messages-broadcast pada bagian yang
 * sama; path bisa diganti lewat CXP_WA_SEND_PATH tanpa mengubah kode.
 */
class CxpWhatsAppClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
        private readonly string $loginPath = '/api/v2/login-user',
        private readonly string $sendPath = '/api/v2/messages-template',
        private readonly string $statusPath = '/api/v2/messages-template',
        private readonly string $tokenCachePrefix = 'cxp:wa:token:',
        private readonly int $tokenCacheTtl = 43200,
    ) {}

    public static function fromConfig(): self
    {
        $config = (array) config('cxp.whatsapp', []);

        return new self(
            rtrim(trim((string) ($config['base_url'] ?? '')), '/'),
            max(5, (int) ($config['timeout'] ?? 30)),
            CxpApiClient::normalizePath((string) ($config['login_path'] ?? ''), '/api/v2/login-user'),
            CxpApiClient::normalizePath((string) ($config['send_path'] ?? ''), '/api/v2/messages-template'),
            CxpApiClient::normalizePath((string) ($config['status_path'] ?? ''), '/api/v2/messages-template'),
            (string) ($config['token_cache_prefix'] ?? 'cxp:wa:token:'),
            max(300, (int) ($config['token_cache_ttl'] ?? 43200)),
        );
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function sendPath(): string
    {
        return $this->sendPath;
    }

    public function statusPath(): string
    {
        return $this->statusPath;
    }

    /**
     * Login memakai Basic Auth sesuai dokumen API v2. Token yang masih aktif
     * dipakai ulang dari cache.
     *
     * @param  array{username: string, password: string, number?: string}  $account
     */
    public function login(array $account, bool $refresh = false): string
    {
        $this->assertConfigured();

        $username = trim((string) ($account['username'] ?? ''));
        $password = (string) ($account['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new CxpApiException('not_configured', 'Kredensial akun WhatsApp belum lengkap.', 0, [
                'missing' => ['username', 'password'],
            ]);
        }

        $key = $this->tokenKey($username);

        if (! $refresh) {
            $cached = Cache::get($key);

            if (is_string($cached) && trim($cached) !== '') {
                return $cached;
            }
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withBasicAuth($username, $password)
                ->timeout($this->timeout)
                ->post($this->baseUrl.$this->loginPath);
        } catch (ConnectionException) {
            throw new CxpApiException(
                'connection',
                'Tidak bisa terhubung ke API WhatsApp TransCXP.',
                0,
                ['base_url' => $this->baseUrl],
            );
        }

        $this->ensureSuccessful($response, 'login');

        $json = $response->json();
        $token = is_array($json) ? data_get($json, 'userInfo.token') : null;

        if (! is_string($token) || trim($token) === '') {
            throw new CxpApiException(
                'invalid_response',
                'Response login WhatsApp tidak memuat userInfo.token.',
                $response->status(),
                ['action' => 'login'],
            );
        }

        Cache::put($key, $token, $this->tokenTtl(data_get($json, 'userInfo.expired_date')));

        return $token;
    }

    public function forgetToken(array $account): void
    {
        Cache::forget($this->tokenKey(trim((string) ($account['username'] ?? ''))));
    }

    /**
     * Kirim satu pesan template. Mengembalikan data.message_id dari TransCXP.
     *
     * Kalau token ditolak (401), login ulang sekali lalu kirim lagi.
     *
     * @param  array{username: string, password: string, number?: string}  $account
     * @param  array<int, array<string, mixed>>  $components
     * @return array{message_id: string, recipient_number: string, status: int}
     */
    public function sendTemplate(
        array $account,
        string $templateId,
        string $recipientName,
        string $recipientNumber,
        array $components = [],
    ): array {
        $templateId = trim($templateId);

        if ($templateId === '') {
            throw new CxpApiException('not_configured', 'ID template WhatsApp belum diisi.', 0, [
                'missing' => ['CXP_WA_TEMPLATE_ID'],
            ]);
        }

        $payload = [
            'template_id' => $templateId,
            'recipient_contact' => [
                'name' => trim($recipientName) !== '' ? trim($recipientName) : $recipientNumber,
                'recipient_number' => $recipientNumber,
            ],
        ];

        if ($components !== []) {
            $payload['recipient_contact']['components'] = $components;
        }

        $token = $this->login($account);
        $response = $this->postSend($token, $payload);

        if ($response->status() === 401) {
            $this->forgetToken($account);
            $token = $this->login($account, true);
            $response = $this->postSend($token, $payload);
        }

        $this->ensureSuccessful($response, 'send');

        $json = $response->json();
        $messageId = is_array($json) ? data_get($json, 'data.message_id') : null;

        if (! is_string($messageId) || trim($messageId) === '') {
            throw new CxpApiException(
                'invalid_response',
                'Response kirim WhatsApp tidak memuat data.message_id.',
                $response->status(),
                ['action' => 'send'],
            );
        }

        return [
            'message_id' => trim($messageId),
            'recipient_number' => (string) data_get($json, 'data.recipient_number', $recipientNumber),
            'status' => $response->status(),
        ];
    }

    /**
     * @param  array{username: string, password: string, number?: string}  $account
     * @return array<string, mixed>
     */
    public function messageStatus(array $account, string $messageId): array
    {
        $messageId = trim($messageId);

        if ($messageId === '') {
            throw new CxpApiException('invalid_argument', 'message_id kosong.', 0, []);
        }

        $token = $this->login($account);
        $response = $this->getStatus($token, $messageId);

        if ($response->status() === 401) {
            $this->forgetToken($account);
            $token = $this->login($account, true);
            $response = $this->getStatus($token, $messageId);
        }

        $this->ensureSuccessful($response, 'status');

        $json = $response->json();
        $data = is_array($json) ? data_get($json, 'data') : null;

        if (! is_array($data)) {
            throw new CxpApiException(
                'invalid_response',
                'Response status WhatsApp tidak memuat data pesan.',
                $response->status(),
                ['action' => 'status'],
            );
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSend(string $token, array $payload): Response
    {
        try {
            return $this->request($token)
                ->asJson()
                ->post($this->baseUrl.$this->sendPath, $payload);
        } catch (ConnectionException) {
            throw new CxpApiException(
                'connection',
                'Tidak bisa terhubung ke API WhatsApp TransCXP.',
                0,
                ['base_url' => $this->baseUrl, 'path' => $this->sendPath],
            );
        }
    }

    private function getStatus(string $token, string $messageId): Response
    {
        try {
            return $this->request($token)->get($this->baseUrl.$this->statusPath.'/'.rawurlencode($messageId));
        } catch (ConnectionException) {
            throw new CxpApiException(
                'connection',
                'Tidak bisa terhubung ke API WhatsApp TransCXP.',
                0,
                ['base_url' => $this->baseUrl, 'path' => $this->statusPath],
            );
        }
    }

    private function request(string $token): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($this->timeout)
            ->withToken($token);
    }

    private function tokenKey(string $username): string
    {
        return $this->tokenCachePrefix.hash('sha256', mb_strtolower($username));
    }

    private function tokenTtl(mixed $expiredDate): int
    {
        if (is_string($expiredDate) || is_numeric($expiredDate)) {
            $timestamp = is_numeric($expiredDate) ? (int) $expiredDate : strtotime($expiredDate);

            if (is_int($timestamp) && $timestamp > 0) {
                // Sisakan satu menit supaya token tidak dipakai tepat saat kedaluwarsa.
                $ttl = $timestamp - time() - 60;

                if ($ttl >= 60) {
                    return min($this->tokenCacheTtl, $ttl);
                }
            }
        }

        return $this->tokenCacheTtl;
    }

    private function ensureSuccessful(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $json = $response->json();
        $message = is_array($json) ? trim((string) data_get($json, 'message', '')) : '';

        $errors = is_array($json) ? data_get($json, 'errors') : null;
        if (is_array($errors) && $errors !== []) {
            $first = collect($errors)->flatten()->first();
            if (is_string($first) && trim($first) !== '') {
                $message = trim($first);
            }
        }

        if ($message === '') {
            $message = 'Permintaan ke API WhatsApp TransCXP gagal.';
        }

        throw new CxpApiException('http_error', $message, $response->status(), [
            'action' => $action,
            'content_type' => trim((string) $response->header('Content-Type')),
        ]);
    }

    private function assertConfigured(): void
    {
        if ($this->baseUrl === '') {
            throw new CxpApiException('not_configured', 'CXP_WA_BASE_URL belum diisi.', 0, [
                'missing' => ['CXP_WA_BASE_URL'],
            ]);
        }
    }
}

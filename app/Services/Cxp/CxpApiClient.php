<?php

namespace App\Services\Cxp;

use App\Exceptions\CxpApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien read-only untuk API tiket TransCXP.
 *
 * Alur: login-user -> dataTicketcxp / detailChoose. Token hanya hidup di
 * memori selama command berjalan dan tidak pernah ditulis ke log.
 */
class CxpApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $email,
        private readonly string $password,
        private readonly int $timeout = 30,
        private readonly string $authHeader = 'Authorization',
        private readonly string $authPrefix = 'Bearer',
    ) {}

    public static function fromConfig(?string $authPreset = null): self
    {
        $config = (array) config('cxp.api', []);

        [$header, $prefix] = self::authHeaders(
            $authPreset,
            (string) ($config['auth_header'] ?? 'Authorization'),
            (string) ($config['auth_prefix'] ?? 'Bearer'),
        );

        return new self(
            rtrim((string) ($config['base_url'] ?? ''), '/'),
            trim((string) ($config['email'] ?? '')),
            (string) ($config['password'] ?? ''),
            max(5, (int) ($config['timeout'] ?? 30)),
            $header,
            $prefix,
        );
    }

    /**
     * Skema auth belum didokumentasikan TransCXP, jadi bisa diganti tanpa ubah kode.
     *
     * @return array{0: string, 1: string}
     */
    public static function authHeaders(?string $preset, string $defaultHeader, string $defaultPrefix): array
    {
        return match (strtolower(trim((string) $preset))) {
            '', 'config' => [$defaultHeader, $defaultPrefix],
            'bearer' => ['Authorization', 'Bearer'],
            'token' => ['Authorization', ''],
            'x-api-key' => ['X-API-KEY', ''],
            'x-token' => ['X-TOKEN', ''],
            default => throw new \InvalidArgumentException('Skema autentikasi tidak dikenal: '.$preset),
        };
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->email !== '' && $this->password !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function authLabel(): string
    {
        $prefix = trim($this->authPrefix);

        return $this->authHeader.': '.($prefix !== '' ? $prefix.' ' : '').'<token>';
    }

    /**
     * @return array{token: string, name: string, email: string, expired_date: mixed, status: int}
     */
    public function login(): array
    {
        $this->assertConfigured();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/api/v1/login-user', [
                    'email' => $this->email,
                    'password' => $this->password,
                ]);
        } catch (ConnectionException $e) {
            throw new CxpApiException(
                'connection',
                'Tidak bisa terhubung ke API TransCXP.',
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
                'Response login tidak memuat userInfo.token.',
                $response->status(),
                ['action' => 'login', 'keys' => is_array($json) ? array_keys($json) : []],
            );
        }

        return [
            'token' => $token,
            'name' => (string) data_get($json, 'userInfo.name', ''),
            'email' => (string) data_get($json, 'userInfo.email', ''),
            'expired_date' => data_get($json, 'userInfo.expired_date'),
            'status' => $response->status(),
        ];
    }

    public function tickets(string $token, int $page = 1, int $perPage = 10, ?string $ticketId = null): array
    {
        $query = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($ticketId !== null && trim($ticketId) !== '') {
            $query['id_ticket'] = trim($ticketId);
        }

        return $this->get('/api/v1/dataTicketcxp', $token, $query);
    }

    public function detail(string $token, string $ticketId, int $page = 1, int $perPage = 10): array
    {
        return $this->get('/api/v1/detailChoose', $token, [
            'id_ticket' => trim($ticketId),
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    private function get(string $path, string $token, array $query): array
    {
        try {
            $response = $this->request($token)->get($this->baseUrl.$path, $query);
        } catch (ConnectionException $e) {
            throw new CxpApiException(
                'connection',
                'Tidak bisa terhubung ke API TransCXP.',
                0,
                ['path' => $path, 'base_url' => $this->baseUrl],
            );
        }

        $this->ensureSuccessful($response, $path);

        $json = $response->json();

        if (! is_array($json)) {
            throw new CxpApiException(
                'invalid_response',
                'Response API TransCXP bukan JSON objek.',
                $response->status(),
                ['path' => $path],
            );
        }

        return $json;
    }

    private function request(string $token): PendingRequest
    {
        $prefix = trim($this->authPrefix);
        $value = $prefix !== '' ? $prefix.' '.$token : $token;

        return Http::acceptJson()
            ->timeout($this->timeout)
            ->withHeaders([$this->authHeader => $value]);
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
            $message = 'Permintaan ke API TransCXP gagal.';
        }

        throw new CxpApiException(
            'http_error',
            $message,
            $response->status(),
            ['action' => $action],
        );
    }

    private function assertConfigured(): void
    {
        $missing = [];

        if ($this->baseUrl === '') {
            $missing[] = 'CXP_API_BASE_URL';
        }
        if ($this->email === '') {
            $missing[] = 'CXP_API_EMAIL';
        }
        if ($this->password === '') {
            $missing[] = 'CXP_API_PASSWORD';
        }

        if ($missing !== []) {
            throw new CxpApiException(
                'not_configured',
                'Konfigurasi API TransCXP belum lengkap.',
                0,
                ['missing' => $missing],
            );
        }
    }
}

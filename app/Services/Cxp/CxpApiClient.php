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
        private readonly string $loginField = 'email',
        private readonly string $ticketPath = '/api/v2/tickets',
        private readonly string $detailPath = '/api/v2/tickets',
    ) {}

    public static function fromConfig(?string $authPreset = null, ?string $loginFieldOverride = null): self
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
            self::resolveLoginField(
                $loginFieldOverride,
                (string) ($config['login_field'] ?? 'email'),
            ),
            self::normalizePath((string) ($config['ticket_path'] ?? ''), '/api/v2/tickets'),
            self::normalizePath((string) ($config['detail_path'] ?? ''), '/api/v2/tickets'),
        );
    }

    /**
     * Path dari .env boleh ditulis dengan atau tanpa garis miring depan.
     */
    public static function normalizePath(string $path, string $default): string
    {
        $path = trim($path);

        if ($path === '') {
            $path = $default;
        }

        return '/'.trim($path, '/');
    }

    /**
     * API resmi memakai field email. Beberapa akun internal memakai username,
     * jadi field-nya bisa diganti lewat .env atau opsi command.
     */
    public static function resolveLoginField(?string $override, string $default): string
    {
        $field = strtolower(trim((string) $override));

        if ($field === '' || $field === 'config') {
            $field = strtolower(trim($default)) ?: 'email';
        }

        if (! in_array($field, ['email', 'username'], true)) {
            throw new \InvalidArgumentException('Field login tidak dikenal: '.$field);
        }

        return $field;
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

    public function identifier(): string
    {
        return $this->email;
    }

    public function loginField(): string
    {
        return $this->loginField;
    }

    public function ticketPath(): string
    {
        return $this->ticketPath;
    }

    public function detailPath(): string
    {
        return $this->detailPath;
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
                    $this->loginField => $this->email,
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

    public function tickets(string $token, int $page = 1, int $perPage = 10, ?string $ticketId = null, array $filters = []): array
    {
        $query = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        foreach ($filters as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $query[$key] = $value;
            }
        }

        if ($ticketId !== null && trim($ticketId) !== '') {
            $query['id_ticket'] = trim($ticketId);
        }

        return $this->get($this->ticketPath, $token, $query);
    }

    public function detail(string $token, string $ticketId, int $page = 1, int $perPage = 10): array
    {
        return $this->get($this->detailPath, $token, [
            'id_ticket' => trim($ticketId),
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Kirim query bebas ke endpoint tiket, dipakai untuk probe filter.
     */
    public function ticketsQuery(string $token, array $query): array
    {
        return $this->get($this->ticketPath, $token, $query);
    }

    /**
     * GET read-only ke path bebas untuk menebak endpoint yang tersedia.
     * Tidak melempar exception saat status bukan 2xx: status dan field
     * dikembalikan apa adanya supaya bisa ditampilkan sebagai hasil probe.
     *
     * @return array{status: int, content_type: string, keys: array<int, string>, message: string}
     */
    public function probeGet(string $token, string $path, array $query = []): array
    {
        $path = self::normalizePath($path, '/');

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

        $json = $response->json();

        return [
            'status' => $response->status(),
            'content_type' => trim((string) $response->header('Content-Type')),
            'keys' => is_array($json) ? array_keys($json) : [],
            'message' => is_array($json) ? trim((string) data_get($json, 'message', '')) : '',
        ];
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

        $context = [
            'action' => $action,
            'path' => $action !== 'login' ? $action : null,
            'content_type' => trim((string) $response->header('Content-Type')),
        ];

        if (is_array($json)) {
            $context['keys'] = array_keys($json);
        }

        throw new CxpApiException(
            'http_error',
            $message,
            $response->status(),
            $context,
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

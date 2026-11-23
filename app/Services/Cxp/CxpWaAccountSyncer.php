<?php

namespace App\Services\Cxp;

use App\Exceptions\CxpApiException;
use App\Services\Csat\WhatsAppNumber;

/**
 * Ambil sender_number + account_id dari /api/v1/accountWaProject/{id_project}.
 *
 * Kredensial login API v2 (username/password) TIDAK diambil dari endpoint ini
 * dan tidak pernah ditimpa. Endpoint itu hanya mengisi:
 * - number     <- whatsapp_number (catatan audit)
 * - account_id <- id (untuk jalur kirim berbasis akun)
 *
 * Kalau project punya beberapa akun aktif, semuanya disimpan di `channels`.
 * Akun dengan `active: no` dilewati. Hasil ditulis ke CXP_WA_ACCOUNTS_FILE.
 * Tidak ada fallback lintas project.
 */
class CxpWaAccountSyncer
{
    public function __construct(
        private readonly CxpApiClient $client,
        private readonly string $filePath,
        private readonly string $accountPath,
        private readonly ?string $accountBaseUrl = null,
    ) {}

    public static function fromConfig(): self
    {
        $file = trim((string) config('cxp.whatsapp.accounts_file', ''));

        if ($file === '') {
            throw new \InvalidArgumentException('CXP_WA_ACCOUNTS_FILE belum diisi di .env.');
        }

        return new self(
            CxpApiClient::fromConfig(),
            $file,
            (string) config('cxp.whatsapp.account_path', '/api/v1/accountWaProject/{id_project}'),
            trim((string) config('cxp.whatsapp.account_base_url', '')) ?: null,
        );
    }

    /**
     * @param  array<int|string, mixed>  $projects
     * @return array{updated: array<int, string>, skipped: array<int, string>, missing_credentials: array<int, string>, errors: array<string, string>}
     */
    public function sync(array $projects, bool $dryRun = false): array
    {
        $accounts = $this->load();
        $result = ['updated' => [], 'skipped' => [], 'missing_credentials' => [], 'errors' => []];

        if (! $this->client->isConfigured()) {
            throw new \InvalidArgumentException('CXP_API_EMAIL / CXP_API_PASSWORD belum diisi di .env.');
        }

        $token = $this->client->login()['token'];

        foreach ($projects as $code => $label) {
            $code = trim((string) $code);

            if ($code === '') {
                continue;
            }

            try {
                $response = $this->client->accountWaProjects(
                    $token,
                    $code,
                    $this->accountPath,
                    $this->accountBaseUrl,
                );
            } catch (CxpApiException $e) {
                // Project tanpa akun WhatsApp di TransCXP tidak boleh menggagalkan
                // sinkronisasi project lain. Kredensial yang sudah ada dibiarkan.
                $result['errors'][$code] = $e->getMessage();

                continue;
            }

            $activeAccounts = $this->extract($response);

            if ($activeAccounts === []) {
                $result['skipped'][] = $code;
                continue;
            }

            $existing = $accounts[$code] ?? [];
            if (! is_array($existing)) {
                $existing = [];
            }

            $primary = $activeAccounts[0];
            $update = [
                'number' => $primary['number'],
                'account_id' => $primary['account_id'],
            ];

            if (count($activeAccounts) > 1) {
                $update['channels'] = array_map(
                    static fn (array $account): array => [
                        'account_id' => $account['account_id'],
                        'number' => $account['number'],
                        'name' => $account['name'],
                        'active' => 'yes',
                    ],
                    $activeAccounts,
                );
            } else {
                unset($update['channels']);
            }

            $merged = array_replace($existing, $update);

            if (! isset($update['channels'])) {
                unset($merged['channels']);
            }

            $accounts[$code] = $merged;

            $result['updated'][] = $code;

            if (trim((string) ($existing['username'] ?? '')) === '' || (string) ($existing['password'] ?? '') === '') {
                $result['missing_credentials'][] = $code;
            }
        }

        if (! $dryRun) {
            $this->write($accounts);
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        if (! is_file($this->filePath)) {
            return [];
        }

        $raw = (string) file_get_contents($this->filePath);
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Isi CXP_WA_ACCOUNTS_FILE harus JSON objek.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, array<string, mixed>>  $accounts
     */
    private function write(array $accounts): void
    {
        $dir = dirname($this->filePath);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Folder CXP_WA_ACCOUNTS_FILE tidak bisa dibuat.');
        }

        $json = json_encode(
            (object) $accounts,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false || file_put_contents($this->filePath, $json."\n", LOCK_EX) === false) {
            throw new \RuntimeException('CXP_WA_ACCOUNTS_FILE gagal ditulis.');
        }

        @chmod($this->filePath, 0600);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array{account_id: string, number: string, name: string}>
     */
    private function extract(array $response): array
    {
        $data = data_get($response, 'data', $response);

        if (! is_array($data)) {
            return [];
        }

        $rows = array_is_list($data) ? $data : [$data];
        $accounts = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (strtolower(trim((string) ($row['source'] ?? ''))) !== 'whatsapp') {
                continue;
            }

            if (strtolower(trim((string) ($row['active'] ?? ''))) !== 'yes') {
                continue;
            }

            $accountId = trim((string) ($row['id'] ?? ''));
            $number = WhatsAppNumber::normalize((string) ($row['whatsapp_number'] ?? ''));

            if ($accountId === '' || ! WhatsAppNumber::isValid($number)) {
                continue;
            }

            $accounts[] = [
                'account_id' => $accountId,
                'number' => '+'.$number,
                'name' => trim((string) ($row['whatsapp_name'] ?? '')),
            ];
        }

        return $accounts;
    }
}

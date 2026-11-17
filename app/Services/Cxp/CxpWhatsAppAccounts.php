<?php

namespace App\Services\Cxp;

use App\Models\Workspace;
use App\Services\Csat\WhatsAppNumber;

/**
 * Kredensial WhatsApp TransCXP per workspace.
 *
 * Nomor pengirim berbeda per project, jadi tidak ada akun default/fallback:
 * kalau kode workspace tidak punya akun, pengiriman WhatsApp dilewati supaya
 * pesan tidak terkirim dari nomor project lain.
 *
 * Format .env (JSON):
 * {"22": {"username": "...", "password": "...", "number": "+62811..."}}
 */
class CxpWhatsAppAccounts
{
    /** @param array<string, array<string, mixed>> $accounts */
    public function __construct(private readonly array $accounts) {}

    public static function fromConfig(): self
    {
        $raw = config('cxp.whatsapp.accounts');

        if (is_string($raw) && trim($raw) !== '') {
            return new self(self::decode($raw, 'CXP_WA_ACCOUNTS'));
        }

        $file = trim((string) config('cxp.whatsapp.accounts_file', ''));

        if ($file !== '') {
            if (! is_file($file) || ! is_readable($file)) {
                throw new \InvalidArgumentException('File CXP_WA_ACCOUNTS_FILE tidak bisa dibaca.');
            }

            return new self(self::decode((string) file_get_contents($file), 'CXP_WA_ACCOUNTS_FILE'));
        }

        return new self([]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forWorkspace(Workspace|string|int $workspace): ?array
    {
        $code = $workspace instanceof Workspace ? (string) $workspace->code : (string) $workspace;
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        // JSON object memakai string key, tetapi angka bisa jadi int.
        $entry = $this->accounts[$code] ?? $this->accounts[(int) $code] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $username = trim((string) ($entry['username'] ?? $entry['user'] ?? ''));
        $password = (string) ($entry['password'] ?? '');
        $number = WhatsAppNumber::normalize((string) ($entry['number'] ?? $entry['sender'] ?? ''));

        if ($username === '' || $password === '' || ! WhatsAppNumber::isValid($number)) {
            return null;
        }

        return [
            'username' => $username,
            'password' => $password,
            'number' => '+'.$number,
        ];
    }

    public function has(Workspace|string|int $workspace): bool
    {
        return $this->forWorkspace($workspace) !== null;
    }

    /**
     * Kode workspace yang punya akun lengkap (tanpa menampilkan kredensial).
     *
     * @return array<int, string>
     */
    public function codes(): array
    {
        $codes = [];

        foreach (array_keys($this->accounts) as $code) {
            $code = (string) $code;

            if ($this->forWorkspace($code) !== null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function decode(string $json, string $source): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Isi '.$source.' harus JSON objek.');
        }

        return $decoded;
    }
}

<?php

namespace App\Services\Cxp;

use App\Models\Workspace;
use App\Services\Csat\WhatsAppNumber;

/**
 * Kredensial WhatsApp TransCXP per workspace/project.
 *
 * `username`/`password` adalah User API v2 (email + password), bukan akun WA
 * project. `number` HANYA catatan audit (sender_number): yang menentukan
 * pengirim adalah akun yang dipakai saat login.
 *
 * Format lama (satu akun per project) tetap didukung:
 * {"23": {"username": "...", "password": "...", "number": "+628111660770", "account_id": "95"}}
 *
 * Format baru (satu kredensial untuk banyak channel aktif):
 * {"9": {"username": "dkt@...", "password": "...", "channels": [
 *     {"account_id": "110", "number": "+6281113900527", "name": "Berani Berencana", "active": "yes"},
 *     {"account_id": "107", "number": "+6281119726459", "name": "DKT News", "active": "yes"},
 *     {"account_id": "20", "number": "+62811139005271", "active": "no"}
 * ]}}
 *
 * Channel dengan `active: no` tidak pernah dipakai. Kredensial bisa ditaruh di
 * project (diwariskan ke semua channel) atau di masing-masing channel.
 * Tidak ada fallback lintas workspace.
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
     * Akun utama yang dipakai saat mengirim. Untuk project dengan banyak
     * channel, kandidat pertama yang aktif dan lengkap yang dipilih.
     *
     * @return array<string, mixed>|null
     */
    public function forWorkspace(Workspace|string|int $workspace): ?array
    {
        $entry = $this->entry($workspace);

        if ($entry === null) {
            return null;
        }

        foreach ($this->candidates($entry) as $candidate) {
            $account = $this->normalize($candidate);

            if ($account !== null) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Semua channel aktif yang bisa dipakai untuk workspace/project ini.
     *
     * @return array<int, array<string, mixed>>
     */
    public function channelsForWorkspace(Workspace|string|int $workspace): array
    {
        $entry = $this->entry($workspace);

        if ($entry === null) {
            return [];
        }

        $channels = [];

        foreach ($this->candidates($entry) as $candidate) {
            $account = $this->normalize($candidate);

            if ($account !== null) {
                $channels[] = $account;
            }
        }

        return $channels;
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
     * @return array<string, mixed>|null
     */
    private function entry(Workspace|string|int $workspace): ?array
    {
        $code = $workspace instanceof Workspace ? (string) $workspace->code : (string) $workspace;
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        // JSON object memakai string key, tetapi angka bisa jadi int.
        $entry = $this->accounts[$code] ?? $this->accounts[(int) $code] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, array<string, mixed>>
     */
    private function candidates(array $entry): array
    {
        $channels = $entry['channels'] ?? null;

        if (! is_array($channels) || $channels === []) {
            return [$entry];
        }

        $candidates = [];

        foreach ($channels as $channel) {
            if (! is_array($channel)) {
                continue;
            }

            $active = strtolower(trim((string) ($channel['active'] ?? 'yes')));

            if (in_array($active, ['no', 'false', '0', 'off'], true)) {
                continue;
            }

            $merged = $entry;

            foreach ($channel as $key => $value) {
                if ($key === 'channels') {
                    continue;
                }

                if (is_string($value) && trim($value) === '') {
                    continue;
                }

                $merged[$key] = $value;
            }

            $candidates[] = $merged;
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function normalize(array $candidate): ?array
    {
        $username = trim((string) ($candidate['username'] ?? $candidate['user'] ?? ''));
        $password = (string) ($candidate['password'] ?? '');
        $number = WhatsAppNumber::normalize((string) (
            $candidate['number'] ?? $candidate['sender'] ?? $candidate['whatsapp_number'] ?? ''
        ));

        if ($username === '' || $password === '' || ! WhatsAppNumber::isValid($number)) {
            return null;
        }

        return [
            'username' => $username,
            'password' => $password,
            'number' => '+'.$number,
            'account_id' => trim((string) ($candidate['account_id'] ?? $candidate['id'] ?? '')),
            'name' => trim((string) ($candidate['name'] ?? $candidate['whatsapp_name'] ?? '')),
        ];
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

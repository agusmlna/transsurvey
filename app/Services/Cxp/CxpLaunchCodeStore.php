<?php

namespace App\Services\Cxp;

use Illuminate\Support\Facades\Cache;

/**
 * Kode peluncuran sekali pakai untuk alur tanpa JWT.
 *
 * TransCXP mengirim payload lewat API server-to-server, lalu browser hanya
 * membawa kode acak ini di URL. Kode disimpan sebentar di cache dan diambil
 * sekali; tidak ada email customer yang beredar di query string.
 */
class CxpLaunchCodeStore
{
    /**
     * @return array{code: string, ttl: int}
     */
    public function issue(array $payload): array
    {
        $code = bin2hex(random_bytes(32));
        $ttl = max(30, (int) config('cxp.launch_code_ttl', 120));

        Cache::put($this->key($code), $payload, $ttl);

        return ['code' => $code, 'ttl' => $ttl];
    }

    public function pull(string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 128) {
            return null;
        }

        $payload = Cache::pull($this->key($code));

        return is_array($payload) ? $payload : null;
    }

    private function key(string $code): string
    {
        // Simpan berdasarkan hash supaya kode mentah tidak muncul di cache key.
        return 'cxp:launch-code:'.hash('sha256', $code);
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Menampilkan isi token SSO tanpa memverifikasi tanda tangan.
 *
 * Dipakai saat menerima contoh token dari TransCXP/Cakrawala: kita perlu tahu
 * nama klaim yang dipakai sebelum menentukan mapping-nya. Command ini tidak
 * membuktikan token valid, jadi jangan dipakai sebagai pengganti verifikasi.
 */
class InspectCxpToken extends Command
{
    protected $signature = 'cxp:token-inspect
        {token? : Token JWT (opsional)}
        {--file= : Baca token dari file}';

    protected $description = 'Tampilkan header dan payload token SSO TransCXP tanpa verifikasi tanda tangan.';

    public function handle(): int
    {
        $token = $this->argument('token');

        $path = trim((string) $this->option('file'));
        if ($path !== '') {
            $contents = @file_get_contents($path);
            if ($contents === false) {
                $this->error('File tidak bisa dibaca: '.$path);

                return self::FAILURE;
            }

            $token = trim($contents);
        }

        if (! is_string($token) || trim($token) === '') {
            $token = $this->ask('Tempel token JWT');
        }

        $token = trim((string) $token);
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            $this->error('Bukan JWT yang valid: harus ada 3 bagian dipisahkan titik.');

            return self::FAILURE;
        }

        $header = $this->decodeSegment($segments[0], 'header');
        $payload = $this->decodeSegment($segments[1], 'payload');

        if ($header === null || $payload === null) {
            return self::FAILURE;
        }

        $this->warn('Tanda tangan token TIDAK diverifikasi. Ini hanya untuk membaca klaim.');
        $this->newLine();

        $this->line('<options=bold>Header</>');
        $this->line($this->toJson($header));
        $this->newLine();

        $this->line('<options=bold>Payload</>');
        $this->line($this->toJson($payload));
        $this->newLine();

        $this->line('<options=bold>Ringkasan</>');
        $this->line('alg          : '.($header['alg'] ?? '-'));
        $this->line('iss          : '.$this->scalar($payload['iss'] ?? null));
        $this->line('aud          : '.$this->audience($payload['aud'] ?? null));
        $this->line('apiTokenId   : '.$this->scalar($payload['apiTokenId'] ?? null));
        $this->line('jti          : '.($this->hasValue($payload['jti'] ?? null) ? 'ada' : 'tidak ada'));
        $this->line('iat          : '.$this->timestamp($payload['iat'] ?? null));
        $this->line('exp          : '.$this->timestamp($payload['exp'] ?? null));
        $this->line('nbf          : '.$this->timestamp($payload['nbf'] ?? null));
        $this->line('masa berlaku : '.$this->ttl($payload));
        $this->line('klaim atas   : '.implode(', ', array_keys($payload)));
        $this->line('user         : '.$this->shape($payload['user'] ?? null));
        $this->line('project      : '.$this->shape($payload['project'] ?? null));
        $this->line('ticket       : '.$this->shape($payload['ticket'] ?? null));
        $this->line('customer     : '.$this->shape($payload['customer'] ?? null));
        $this->line('email atas   : '.$this->scalar($payload['email'] ?? null));
        $this->newLine();

        $this->comment('Kirim ke tim TransSurvey cukup bagian Payload dan Ringkasan. Jangan kirim token penuh.');

        return self::SUCCESS;
    }

    private function decodeSegment(string $segment, string $part): ?array
    {
        $remainder = strlen($segment) % 4;
        if ($remainder !== 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        if ($decoded === false) {
            $this->error('Bagian '.$part.' bukan base64url yang valid.');

            return null;
        }

        $value = json_decode($decoded, true);
        if (! is_array($value)) {
            $this->error('Bagian '.$part.' bukan JSON objek.');

            return null;
        }

        return $value;
    }

    private function toJson(array $value): string
    {
        return (string) json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            $text = trim((string) $value);

            return $text === '' ? '-' : $text;
        }

        return is_array($value) ? '['.(array_is_list($value) ? 'array' : 'objek').']' : '-';
    }

    private function audience(mixed $aud): string
    {
        if (is_array($aud)) {
            return implode(', ', array_map(fn ($item) => $this->scalar($item), $aud));
        }

        return $this->scalar($aud);
    }

    private function hasValue(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function timestamp(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '-';
        }

        $seconds = (int) $value;

        return $seconds.' ('.date('Y-m-d H:i:s T', $seconds).')';
    }

    private function ttl(array $payload): string
    {
        if (! is_numeric($payload['iat'] ?? null) || ! is_numeric($payload['exp'] ?? null)) {
            return '-';
        }

        $ttl = (int) $payload['exp'] - (int) $payload['iat'];

        return $ttl.' detik';
    }

    private function shape(mixed $value): string
    {
        if (! is_array($value)) {
            return $this->scalar($value);
        }

        if (array_is_list($value)) {
            return 'array berisi '.count($value).' item';
        }

        return 'objek ('.implode(', ', array_keys($value)).')';
    }
}

<?php

namespace App\Console\Commands;

use App\Exceptions\CxpApiException;
use App\Services\Csat\WhatsAppNumber;
use App\Services\Cxp\CxpApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Uji read-only API tiket TransCXP memakai kredensial dari .env.
 *
 * Command ini tidak menyimpan token, tidak menulis data customer ke database,
 * dan tidak mengirim email/WhatsApp.
 */
class InspectCxpApi extends Command
{
    protected $signature = 'cxp:inspect
        {--ticket= : ID tiket untuk diuji, mis. 000033}
        {--project= : Filter lokal berdasarkan id_project}
        {--page=1 : Halaman data tiket}
        {--per-page=5 : Jumlah tiket per halaman (1-50)}
        {--detail : Panggil detailChoose untuk --ticket}
        {--auth=config : Skema auth: config, bearer, token, x-api-key, x-token}
        {--login-field=config : Field login: config, email, username}
        {--json : Tampilkan JSON ringkas yang sudah disanitasi}
        {--show-pii : Tampilkan email/nomor apa adanya}';

    protected $description = 'Uji read-only API TransCXP: login, daftar tiket, dan detail tiket.';

    public function handle(): int
    {
        $page = (int) $this->option('page');
        $perPage = (int) $this->option('per-page');
        $ticketId = trim((string) $this->option('ticket'));
        $project = trim((string) $this->option('project'));
        $showPii = (bool) $this->option('show-pii');
        $asJson = (bool) $this->option('json');

        if ($page < 1 || $perPage < 1 || $perPage > 50) {
            $this->error('Nilai --page minimal 1 dan --per-page harus 1-50.');

            return self::FAILURE;
        }

        try {
            $client = CxpApiClient::fromConfig(
                (string) $this->option('auth'),
                (string) $this->option('login-field'),
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Base URL : '.$client->baseUrl());
        $this->line('Auth     : '.$client->authLabel());
        $this->line('Login    : '.$client->loginField());
        $this->newLine();

        if ($client->loginField() === 'email'
            && $client->identifier() !== ''
            && filter_var($client->identifier(), FILTER_VALIDATE_EMAIL) === false) {
            $this->warn('CXP_API_EMAIL tidak berbentuk email, padahal API memvalidasi field email.');
            $this->line('Kalau akun API memakai username, coba: php artisan cxp:inspect --login-field=username');
            $this->newLine();
        }

        try {
            $login = $client->login();

            $this->info('Login berhasil.');
            $this->line('User     : '.$this->displayLoginUser($login, $showPii));
            $this->line('Token    : diterima ('.strlen($login['token']).' karakter), tidak ditampilkan.');
            if (filled($login['expired_date'])) {
                $this->line('Expired  : '.$this->scalar($login['expired_date']));
            }
            $this->newLine();

            $response = $client->tickets(
                $login['token'],
                $page,
                $perPage,
                $ticketId !== '' ? $ticketId : null,
            );

            $rows = $this->ticketRows($response);

            if ($ticketId !== '') {
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row) => (string) ($row['id_ticket'] ?? '') === $ticketId,
                ));
            }

            if ($project !== '') {
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row) => (string) ($row['id_project'] ?? '') === $project,
                ));
            }

            $this->render(
                'dataTicketcxp',
                $response,
                $rows,
                $showPii,
                $asJson,
                $ticketId,
                $project,
            );

            if ((bool) $this->option('detail')) {
                if ($ticketId === '') {
                    $this->warn('Opsi --detail membutuhkan --ticket. Langkah detail dilewati.');
                } else {
                    $this->newLine();
                    $detail = $client->detail($login['token'], $ticketId, 1, $perPage);
                    $this->render(
                        'detailChoose',
                        $detail,
                        $this->ticketRows($detail),
                        $showPii,
                        $asJson,
                        $ticketId,
                        '',
                    );
                }
            }

            $this->newLine();
            $this->comment('Read-only: tidak ada data yang disimpan dan tidak ada pesan yang dikirim.');

            return self::SUCCESS;
        } catch (CxpApiException $e) {
            $this->error($e->getMessage());

            if ($e->reason === 'not_configured') {
                $missing = array_values(array_filter((array) ($e->context['missing'] ?? []), 'is_string'));

                if ($missing !== []) {
                    $this->line('Variabel .env yang belum diisi:');
                    foreach ($missing as $name) {
                        $this->line('  - '.$name);
                    }
                }

                $this->line('Isi variabel tersebut di .env server, lalu jalankan: php artisan optimize:clear');
            }

            if ($e->status === 401 || $e->status === 403) {
                $this->line('Kalau login berhasil tetapi endpoint tiket ditolak, coba skema header lain:');
                $this->line('  php artisan cxp:inspect --auth=bearer');
                $this->line('  php artisan cxp:inspect --auth=token');
                $this->line('  php artisan cxp:inspect --auth=x-api-key');
            }

            if ($e->status === 422 && str_contains(strtolower($e->getMessage()), 'email')) {
                $this->line('API TransCXP memvalidasi field email. Kalau akun memakai username, coba:');
                $this->line('  php artisan cxp:inspect --login-field=username');
            }

            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Terjadi kesalahan saat menguji API TransCXP. Detail aman ada di log server.');

            return self::FAILURE;
        }
    }

    private function render(
        string $source,
        array $response,
        array $rows,
        bool $showPii,
        bool $asJson,
        string $ticketId,
        string $project,
    ): void {
        if ($asJson) {
            $this->line((string) json_encode([
                'source' => $source,
                'status' => $response['status'] ?? null,
                'code' => $response['code'] ?? null,
                'message' => $response['message'] ?? null,
                'pagination' => $response['pagination'] ?? null,
                'filters' => array_filter([
                    'id_ticket' => $ticketId !== '' ? $ticketId : null,
                    'id_project' => $project !== '' ? $project : null,
                ]),
                'data' => array_map(
                    fn (array $row) => $this->sanitizeRow($row, $showPii),
                    $rows,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('<options=bold>'.$source.'</>');

        $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];
        $this->line(sprintf(
            'Halaman %s dari %s | total %s | %s per halaman',
            $this->scalar($pagination['current_page'] ?? '-'),
            $this->scalar($pagination['last_page'] ?? '-'),
            $this->scalar($pagination['total'] ?? count($rows)),
            $this->scalar($pagination['per_page'] ?? '-'),
        ));

        if ($rows === []) {
            $this->warn('Tidak ada tiket pada filter ini.');

            return;
        }

        $table = [];

        foreach ($rows as $row) {
            $table[] = [
                $this->scalar($row['id_ticket'] ?? '-'),
                $this->projectLabel($row),
                $this->scalar($row['status_ticket'] ?? '-'),
                $this->scalar($row['source'] ?? '-'),
                $this->maskEmail($row['email'] ?? null, $showPii),
                $this->whatsappLabel($row),
                $this->scalar($row['end_date'] ?? '-'),
                Str::limit($this->scalar($row['subject'] ?? '-'), 32),
            ];
        }

        $this->table(
            ['ID', 'Project', 'Status', 'Sumber', 'Email', 'WA (62)', 'Selesai', 'Subjek'],
            $table,
        );

        $noEmail = count(array_filter($rows, fn (array $row) => trim((string) ($row['email'] ?? '')) === ''));
        $noWa = count(array_filter($rows, fn (array $row) => $this->normalizedWhatsapp($row) === ''));
        $solvedWithoutEnd = count(array_filter(
            $rows,
            fn (array $row) => mb_strtolower(trim((string) ($row['status_ticket'] ?? ''))) === 'solved'
                && trim((string) ($row['end_date'] ?? '')) === '',
        ));

        $this->newLine();
        $this->line('Catatan: '.$noEmail.' tanpa email, '.$noWa.' tanpa nomor WA/telepon, '
            .$solvedWithoutEnd.' berstatus Solved tetapi end_date kosong.');

        if ($noWa > 0) {
            $this->line('Nomor WA memakai fallback wa -> phone_no -> mobile_phone.');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ticketRows(array $response): array
    {
        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw new CxpApiException(
                'invalid_response',
                'Response tidak memuat array data tiket.',
                0,
                ['keys' => array_keys($response)],
            );
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function displayLoginUser(array $login, bool $showPii): string
    {
        $name = trim((string) ($login['name'] ?? ''));
        $email = trim((string) ($login['email'] ?? ''));
        $label = $name !== '' ? $name : 'Tanpa nama';

        if ($email === '') {
            return $label;
        }

        return $label.' <'.$this->maskEmail($email, $showPii).'>';
    }

    private function projectLabel(array $row): string
    {
        $id = trim((string) ($row['id_project'] ?? ''));
        $name = trim((string) ($row['project_name'] ?? ''));

        if ($id === '' && $name === '') {
            return '-';
        }

        return $id.($name !== '' ? ' - '.Str::limit($name, 18) : '');
    }

    private function whatsappLabel(array $row): string
    {
        $normalized = $this->normalizedWhatsapp($row);

        return $normalized !== '' ? $normalized : '-';
    }

    private function normalizedWhatsapp(array $row): string
    {
        foreach (['wa', 'phone_no', 'mobile_phone'] as $key) {
            $raw = trim((string) ($row[$key] ?? ''));

            if ($raw === '') {
                continue;
            }

            $normalized = WhatsAppNumber::normalize($raw);

            if ($normalized !== '' && WhatsAppNumber::isValid($normalized)) {
                return $normalized;
            }
        }

        return '';
    }

    private function sanitizeRow(array $row, bool $showPii): array
    {
        $normalized = $this->normalizedWhatsapp($row);

        $row['email'] = $this->maskEmail($row['email'] ?? null, $showPii);

        foreach (['wa', 'phone_no', 'mobile_phone'] as $key) {
            $row[$key] = $this->maskPhone($row[$key] ?? null, $showPii);
        }

        $row['wa_normalized'] = $normalized !== '' ? $normalized : null;

        return $row;
    }

    private function maskEmail(mixed $value, bool $showPii): string
    {
        $email = trim((string) $value);

        if ($email === '') {
            return '-';
        }

        if ($showPii || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 1);

        return $visible.'***@'.$domain;
    }

    private function maskPhone(mixed $value, bool $showPii): string
    {
        $phone = trim((string) $value);

        if ($phone === '') {
            return '-';
        }

        if ($showPii) {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $length = strlen($digits);

        if ($length <= 4) {
            return str_repeat('*', max(1, $length));
        }

        return substr($digits, 0, 3).str_repeat('*', max(1, $length - 7)).substr($digits, -4);
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
}

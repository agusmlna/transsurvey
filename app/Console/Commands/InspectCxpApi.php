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
    /**
     * Kemungkinan nama field untuk data yang sama. Kontrak PDF memakai bentuk
     * flat, sedangkan /api/v2/tickets menyimpan customer di objek terpisah.
     */
    private const ROW_PATHS = [
        'email' => ['email', 'customer.email', 'customer_email'],
        'status' => ['status_ticket', 'status_name', 'status', 'customer.status_name'],
        'project_id' => ['id_project', 'project.id', 'customer.id_project'],
        'project_name' => ['project_name', 'project.name', 'customer.project_name'],
        'end_date' => ['end_date', 'finish_date', 'closed_date'],
    ];

    private const WA_PATHS = [
        'wa', 'phone_no', 'mobile_phone',
        'customer.wa', 'customer.phone_no', 'customer.mobile_phone', 'customer.phone',
    ];

    protected $signature = 'cxp:inspect
        {--ticket= : ID tiket untuk diuji, mis. 000033}
        {--project= : Filter lokal berdasarkan id_project}
        {--page=1 : Halaman data tiket}
        {--per-page=5 : Jumlah tiket per halaman (1-50)}
        {--detail : Panggil detailChoose untuk --ticket}
        {--auth=config : Skema auth: config, bearer, token, x-api-key, x-token}
        {--login-field=config : Field login: config, email, username}
        {--json : Tampilkan JSON ringkas yang sudah disanitasi}
        {--keys : Tampilkan nama field respons tanpa nilainya}
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
        $this->line('Tiket    : '.$client->ticketPath());
        $this->line('Detail   : '.$client->detailPath());
        $this->newLine();

        if ($client->loginField() === 'email'
            && $client->identifier() !== ''
            && filter_var($client->identifier(), FILTER_VALIDATE_EMAIL) === false) {
            $this->warn('CXP_API_EMAIL tidak berbentuk email, padahal API memvalidasi field email.');
            $this->line('Kalau ini username, coba --login-field=username hanya sebagai diagnosis.');
            $this->line('API resmi tetap membutuhkan email akun API yang terdaftar.');
            $this->newLine();
        }

        if ($client->loginField() === 'username') {
            $this->warn('API resmi TransCXP hanya menerima field email; username hanya untuk diagnosis.');
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

            if ((bool) $this->option('keys')) {
                $this->renderKeys($response, $rows);
                $this->newLine();
                $this->comment('Read-only: tidak ada data yang disimpan dan tidak ada pesan yang dikirim.');

                return self::SUCCESS;
            }

            if ($ticketId !== '') {
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row) => (string) ($row['id_ticket'] ?? '') === $ticketId,
                ));
            }

            if ($project !== '') {
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row) => (string) $this->rowValue($row, 'project_id') === $project,
                ));
            }

            $this->render(
                'Daftar tiket ('.$client->ticketPath().')',
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
                        'Detail tiket ('.$client->detailPath().')',
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

            if ($e->reason === 'http_error') {
                $this->line('Status HTTP : '.$e->status);

                $contentType = trim((string) ($e->context['content_type'] ?? ''));
                if ($contentType !== '') {
                    $this->line('Content-Type: '.$contentType);
                }

                $keys = array_values(array_filter((array) ($e->context['keys'] ?? []), 'is_string'));
                if ($keys !== []) {
                    $this->line('Field respons: '.implode(', ', $keys));
                }

                if ($e->status === 404) {
                    $this->line('Endpoint tidak ditemukan ('.($e->context['path'] ?? 'path tidak diketahui').').');
                    $this->line('Path bisa diubah lewat CXP_API_TICKET_PATH dan CXP_API_DETAIL_PATH.');
                    $this->line('Catatan: /api/v1/dataTicketcxp di PDF sudah 404 di server TransCXP.');
                }

                if ($e->status === 405) {
                    $this->line('Method tidak diizinkan. Cek apakah endpoint production memakai GET.');
                }
            }

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

            if ($e->status === 422) {
                $message = strtolower($e->getMessage());

                if (str_contains($message, 'email field is required')) {
                    $this->line('API TransCXP hanya menerima field email, bukan username.');
                    $this->line('Isi CXP_API_EMAIL dengan email akun API yang terdaftar, lalu jalankan:');
                    $this->line('  php artisan cxp:inspect --login-field=email');
                } elseif (str_contains($message, 'email must be a valid email')) {
                    $this->line('Nilai CXP_API_EMAIL bukan email valid. API membutuhkan email akun API.');
                    $this->line('Kalau ini username, minta email akun API ke tim TransCXP.');
                    $this->line('Untuk diagnosis saja: php artisan cxp:inspect --login-field=username');
                }
            }

            return self::FAILURE;
        } catch (\Throwable $e) {
            report($e);
            $this->error('Terjadi kesalahan saat menguji API TransCXP. Detail aman ada di log server.');

            return self::FAILURE;
        }
    }

    /**
     * Cetak nama field saja, tanpa nilai, supaya aman dibagikan.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderKeys(array $response, array $rows): void
    {
        $this->line('<options=bold>Field envelope</>');
        $this->dumpShape($this->shapeOf($response, 0)['children']);

        $this->newLine();
        $this->line('<options=bold>Field tiket</> ('.count($rows).' baris)');

        if ($rows === []) {
            $this->line('  (tidak ada baris)');

            return;
        }

        $shape = [];

        foreach (array_slice($rows, 0, 5) as $row) {
            $shape = $this->mergeShapes($shape, $this->shapeOfObject($row, 1));
        }

        $this->dumpShape($shape);
    }

    /**
     * @return array{type: string, children: array<string, mixed>}
     */
    private function shapeOf(mixed $value, int $depth): array
    {
        if (! is_array($value)) {
            return ['type' => $this->valueType($value), 'children' => []];
        }

        $children = [];
        $type = array_is_list($value) ? 'list' : 'objek';

        if ($depth < 3) {
            $sample = array_is_list($value) ? ($value[0] ?? null) : $value;

            if (is_array($sample)) {
                $children = $this->shapeOfObject($sample, $depth + 1);
            }
        }

        return ['type' => $type, 'children' => $children];
    }

    /**
     * @return array<string, array{type: string, children: array<string, mixed>}>
     */
    private function shapeOfObject(array $object, int $depth): array
    {
        $shape = [];

        foreach ($object as $key => $value) {
            $shape[(string) $key] = $this->shapeOf($value, $depth);
        }

        return $shape;
    }

    /**
     * Gabungkan bentuk beberapa baris supaya tipe yang berbeda ikut terlihat.
     *
     * @param  array<string, array{type: string, children: array<string, mixed>}>  $left
     * @param  array<string, array{type: string, children: array<string, mixed>}>  $right
     * @return array<string, array{type: string, children: array<string, mixed>}>
     */
    private function mergeShapes(array $left, array $right): array
    {
        foreach ($right as $key => $node) {
            if (! isset($left[$key])) {
                $left[$key] = $node;

                continue;
            }

            $types = array_unique(array_merge(
                explode('/', $left[$key]['type']),
                explode('/', $node['type']),
            ));

            $left[$key]['type'] = implode('/', $types);
            $left[$key]['children'] = $this->mergeShapes($left[$key]['children'], $node['children']);
        }

        return $left;
    }

    /**
     * @param  array<string, array{type: string, children: array<string, mixed>}>  $shape
     */
    private function dumpShape(array $shape, string $indent = '  ', string $path = ''): void
    {
        foreach ($shape as $key => $node) {
            $full = $path === '' ? (string) $key : $path.'.'.$key;
            $this->line($indent.'- '.$full.' ('.$node['type'].')');

            if ($node['children'] !== []) {
                $this->dumpShape($node['children'], $indent.'  ', $full);
            }
        }
    }

    private function valueType(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return 'bool';
        }

        if (is_int($value)) {
            return 'int';
        }

        if (is_float($value)) {
            return 'float';
        }

        if (is_array($value)) {
            return array_is_list($value) ? 'list' : 'objek';
        }

        return 'string';
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
                'status' => $response['status'] ?? $response['statusCode'] ?? null,
                'code' => $response['code'] ?? $response['statusCode'] ?? null,
                'message' => $response['message'] ?? null,
                'pagination' => $this->pagination($response),
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

        $pagination = $this->pagination($response);
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
                $this->scalar(data_get($row, 'id_ticket') ?? '-'),
                $this->projectLabel($row),
                $this->scalar($this->rowValue($row, 'status') ?? '-'),
                $this->scalar(data_get($row, 'source') ?? '-'),
                $this->maskEmail($this->rowValue($row, 'email'), $showPii),
                $this->whatsappLabel($row),
                $this->scalar($this->rowValue($row, 'end_date') ?? '-'),
                Str::limit($this->scalar(data_get($row, 'subject') ?? '-'), 32),
            ];
        }

        $this->table(
            ['ID', 'Project', 'Status', 'Sumber', 'Email', 'WA (62)', 'Selesai', 'Subjek'],
            $table,
        );

        $noEmail = count(array_filter(
            $rows,
            fn (array $row) => trim((string) $this->rowValue($row, 'email')) === '',
        ));
        $noWa = count(array_filter($rows, fn (array $row) => $this->normalizedWhatsapp($row) === ''));
        $solvedWithoutEnd = count(array_filter(
            $rows,
            fn (array $row) => mb_strtolower(trim((string) $this->rowValue($row, 'status'))) === 'solved'
                && trim((string) $this->rowValue($row, 'end_date')) === '',
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
        $id = trim((string) $this->rowValue($row, 'project_id'));
        $name = trim((string) $this->rowValue($row, 'project_name'));

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
        foreach (self::WA_PATHS as $path) {
            $raw = trim((string) data_get($row, $path, ''));

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

    /**
     * Ambil nilai baris dengan beberapa kemungkinan nama field. Bentuk JSON
     * TransCXP berbeda antara kontrak PDF (flat) dan /api/v2/tickets (bersarang).
     */
    private function rowValue(array $row, string $field): mixed
    {
        foreach (self::ROW_PATHS[$field] ?? [] as $path) {
            $value = data_get($row, $path);

            if ($value !== null && $value !== '' && $value !== []) {
                return $value;
            }
        }

        return null;
    }

    private function pagination(array $response): array
    {
        foreach (['pagination', 'meta'] as $key) {
            if (is_array($response[$key] ?? null)) {
                return $response[$key];
            }
        }

        return [];
    }

    private function sanitizeRow(array $row, bool $showPii): array
    {
        $normalized = $this->normalizedWhatsapp($row);

        // Bentuk JSON bisa berbeda dari PDF, jadi samarkan juga field yang
        // namanya tidak kita kenal tetapi isinya email atau nomor telepon.
        $row = $this->sanitizeValue($row, null, $showPii);

        $row['email'] = $this->maskEmail($this->rowValue($row, 'email'), $showPii);
        $row['wa_normalized'] = $normalized !== '' ? $normalized : null;

        return $row;
    }

    private function sanitizeValue(mixed $value, ?string $key, bool $showPii): mixed
    {
        if (is_array($value)) {
            $clean = [];

            foreach ($value as $childKey => $childValue) {
                $clean[$childKey] = $this->sanitizeValue($childValue, (string) $childKey, $showPii);
            }

            return $clean;
        }

        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        if (str_contains($value, '@')) {
            return $this->maskEmail($value, $showPii);
        }

        if ($key !== null
            && preg_match('/phone|mobile|telp|contact|whatsapp|(^|_)wa($|_)/i', $key) === 1
            && strlen(preg_replace('/\D+/', '', $value) ?? '') >= 8) {
            return $this->maskPhone($value, $showPii);
        }

        return $value;
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

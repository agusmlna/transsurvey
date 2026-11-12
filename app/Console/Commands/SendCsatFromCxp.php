<?php

namespace App\Console\Commands;

use App\Exceptions\CxpApiException;
use App\Models\{CxpTicketSeen, Invitation, Survey, Workspace};
use App\Services\Csat\CsatInvitationService;
use App\Services\Csat\WhatsAppNumber;
use App\Services\Cxp\CxpApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Kirim CSAT otomatis untuk tiket TransCXP yang BARU masuk status Close.
 *
 * Sumber data: GET /api/v1/dataTicketByStatus?status=Close&project=<kode>.
 * Endpoint ini menyaring status dan project di server (dibuktikan pada probe
 * production 12 Nov 2026), jadi command tidak lagi membaca 1 juta baris.
 *
 * Setiap project (kode workspace) dibandingkan dengan snapshot di tabel
 * cxp_ticket_seen:
 * - run pertama untuk project hanya mengisi snapshot (baseline), tidak kirim;
 * - run berikutnya mengirim tiket yang belum ada di snapshot.
 *
 * Tiket diarahkan ke workspace yang kodenya sama dengan id_project. Project
 * yang tidak punya workspace tidak pernah dipanggil, sehingga tidak ada
 * fallback diam-diam ke workspace default.
 *
 * Tanpa opsi --send command hanya simulasi: tidak ada baris database yang
 * ditulis dan tidak ada email yang diantrekan.
 */
class SendCsatFromCxp extends Command
{
    // Server membatasi per_page di 100, walaupun nilai lebih besar diterima.
    private const MAX_PER_PAGE = 100;

    protected $signature = 'cxp:csat-send
        {--send : Antre email sungguhan; tanpa opsi ini hanya simulasi}
        {--workspace= : Batasi ke satu kode workspace; default semua workspace bertemplate}
        {--ticket= : Proses satu id_ticket saja, untuk uji (melewati snapshot)}
        {--max-pages= : Batas halaman per project (0 = semua; default CXP_CSAT_MAX_PAGES)}
        {--limit=0 : Batas email yang diantrekan (0 = semua)}';

    protected $description = 'Antrekan CSAT untuk tiket TransCXP yang baru masuk status Close.';

    public function handle(CsatInvitationService $invitations): int
    {
        $client = CxpApiClient::fromConfig();

        $send = (bool) $this->option('send');
        $ticketId = trim((string) $this->option('ticket'));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) config('cxp.csat.per_page', self::MAX_PER_PAGE)));
        $maxPagesOption = $this->option('max-pages');
        $maxPages = $maxPagesOption === null || trim((string) $maxPagesOption) === ''
            ? max(0, (int) config('cxp.csat.max_pages', 0))
            : max(0, (int) $maxPagesOption);
        $limit = max(0, (int) $this->option('limit'));

        if ($ticketId !== '' && trim((string) ($this->option('workspace') ?: config('cxp.csat.workspace'))) === '') {
            $this->error('Opsi --ticket wajib disertai --workspace=<kode> atau isi CXP_CSAT_WORKSPACE.');

            return self::FAILURE;
        }

        if (! $client->isConfigured()) {
            $this->error('Kredensial API TransCXP belum lengkap. Isi CXP_API_BASE_URL, CXP_API_EMAIL, dan CXP_API_PASSWORD.');

            return self::FAILURE;
        }

        if ($send && (! config('survey.email_enabled') || config('mail.default') !== 'smtp')) {
            $this->error('SMTP belum aktif. Isi SURVEY_EMAIL_ENABLED=true dan MAIL_MAILER=smtp sebelum memakai --send.');

            return self::FAILURE;
        }

        $workspaces = $this->workspaces();
        if ($workspaces === null) {
            return self::FAILURE;
        }

        $targets = [];
        $counts = [
            'project' => 0,
            'baseline' => 0,
            'dibaca' => 0,
            'bukan_close' => 0,
            'tanpa_id' => 0,
            'tiket_tidak_ditemukan' => 0,
            'tanpa_email' => 0,
            'email_tidak_valid' => 0,
            'tanpa_template' => 0,
            'project_gagal' => 0,
            'sudah_ada' => 0,
            'dikirim' => 0,
            'akan_dikirim' => 0,
            'gagal' => 0,
        ];

        foreach ($workspaces as $workspace) {
            $template = $invitations->defaultTemplate($workspace);

            if ($template === null) {
                $counts['tanpa_template']++;
                $this->warn('Workspace '.$workspace->code.' dilewati: belum punya template CSAT aktif sebagai default.');

                continue;
            }

            $targets[] = [$workspace, $template];
        }

        if ($targets === []) {
            $this->error('Tidak ada workspace dengan template CSAT aktif sebagai default.');

            return self::FAILURE;
        }

        $this->line('Endpoint  : '.$client->statusPath().' (status=Close, per_page='.$perPage.')');
        $this->line('Workspace : '.count($targets).' diproses');
        $this->line('Snapshot  : cxp_ticket_seen (run pertama = baseline, tidak mengirim)');
        $this->line('Mode      : '.($send ? 'KIRIM' : 'SIMULASI (tidak menulis database)'));
        $this->newLine();

        try {
            $login = $client->login();
        } catch (CxpApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $handled = [];
        $stop = false;

        foreach ($targets as [$workspace, $template]) {
            if ($stop) {
                break;
            }

            $counts['project']++;

            try {
                $stop = $this->processWorkspace(
                    $client,
                    $invitations,
                    $login['token'],
                    $workspace,
                    $template,
                    $ticketId,
                    $send,
                    $perPage,
                    $maxPages,
                    $limit,
                    $counts,
                    $handled,
                );
            } catch (CxpApiException $e) {
                // Error satu project tidak menghentikan project lain. Snapshot
                // project ini tidak ditulis, jadi tiket yang terlewat akan
                // dianggap baru pada run berikutnya.
                $counts['project_gagal']++;
                $this->warn('Project '.$workspace->code.' gagal: '.$e->getMessage());
                Log::warning('cxp.csat-send.project-failed', [
                    'workspace' => $workspace->code,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->table(
            ['Hasil', 'Jumlah'],
            [
                ['Project dibaca', $counts['project']],
                ['Tiket Close dibaca', $counts['dibaca']],
                ['Bukan status Close', $counts['bukan_close']],
                ['Tanpa id_ticket', $counts['tanpa_id']],
                ['Baseline (snapshot awal)', $counts['baseline']],
                ['Tiket tidak ditemukan', $counts['tiket_tidak_ditemukan']],
                ['Tanpa email', $counts['tanpa_email']],
                ['Email tidak valid', $counts['email_tidak_valid']],
                ['Workspace tanpa template aktif', $counts['tanpa_template']],
                ['Project gagal diproses', $counts['project_gagal']],
                ['Sudah pernah dibuatkan CSAT', $counts['sudah_ada']],
                [$send ? 'Email diantrekan' : 'Akan diantrekan', $send ? $counts['dikirim'] : $counts['akan_dikirim']],
                ['Gagal', $counts['gagal']],
            ],
        );

        if ($handled !== []) {
            $shown = array_slice($handled, 0, 30);
            $this->line('Tiket: '.implode(', ', $shown).(count($handled) > count($shown) ? ', ...' : ''));
        }

        $this->newLine();
        if ($send) {
            $this->info('CSAT diantrekan untuk '.$counts['dikirim'].' tiket.');
        } else {
            $this->comment('Simulasi selesai. Tidak ada data yang disimpan dan tidak ada email yang dikirim.');
            $this->line('Jalankan ulang dengan --send untuk mengantrekan email.');
        }

        Log::info('cxp.csat-send', [
            'send' => $send,
            'ticket' => $ticketId !== '' ? $ticketId : null,
            'counts' => $counts,
        ]);

        return self::SUCCESS;
    }

    /**
     * Proses satu workspace/project. Mengembalikan true kalau batas --limit
     * tercapai sehingga project berikutnya tidak perlu diproses.
     *
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $handled
     */
    private function processWorkspace(
        CxpApiClient $client,
        CsatInvitationService $invitations,
        string $token,
        Workspace $workspace,
        Survey $template,
        string $ticketId,
        bool $send,
        int $perPage,
        int $maxPages,
        int $limit,
        array &$counts,
        array &$handled,
    ): bool {
        $project = trim((string) $workspace->code);
        $projectKey = mb_strtolower($project);

        if ($project === '') {
            return false;
        }

        $rows = $this->closeRows($client, $token, $project, $perPage, $maxPages, $counts);

        $hasSnapshot = CxpTicketSeen::query()
            ->where('project_code', $projectKey)
            ->exists();

        if ($ticketId === '') {
            if (! $hasSnapshot) {
                // Baseline: simpan semua tiket Close saat ini, jangan kirim.
                $counts['baseline']++;
                if ($send) {
                    $this->remember($projectKey, array_keys($rows));
                }
                $this->line('Project '.$project.': baseline '.count($rows).' tiket Close (tidak mengirim).');

                return false;
            }

            $seen = CxpTicketSeen::query()
                ->where('project_code', $projectKey)
                ->pluck('ticket_id')
                ->flip();

            $newRows = array_filter(
                $rows,
                fn (array $row, string $id) => ! isset($seen[$id]),
                ARRAY_FILTER_USE_BOTH,
            );
        } else {
            if (! $hasSnapshot && $send) {
                // Uji satu tiket tidak boleh membuat sisa project terlihat
                // "baru" pada run berikutnya. Tetap simpan baseline penuh.
                $this->remember($projectKey, array_keys($rows));
            }

            if (! $hasSnapshot) {
                $counts['baseline']++;
            }

            if (! isset($rows[$ticketId])) {
                $counts['tiket_tidak_ditemukan']++;

                return false;
            }

            $newRows = [$ticketId => $rows[$ticketId]];
        }

        $newRows = array_filter(
            $newRows,
            fn (array $row) => strcasecmp($this->value($row, ['status_name', 'status_ticket', 'status']), 'Close') === 0,
        );

        $existing = $this->existingTicketIds($workspace->id, array_keys($newRows));
        $toRemember = [];

        foreach ($newRows as $rowTicketId => $row) {
            $rowTicketId = (string) $rowTicketId;
            $email = mb_strtolower($this->value($row, ['customer.email', 'email', 'customer_email']));

            if ($email === '') {
                $counts['tanpa_email']++;
                $toRemember[] = $rowTicketId;

                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $counts['email_tidak_valid']++;
                $toRemember[] = $rowTicketId;

                continue;
            }

            if (isset($existing[$rowTicketId])) {
                $counts['sudah_ada']++;
                $toRemember[] = $rowTicketId;

                continue;
            }

            $handled[] = $project.':'.$rowTicketId;

            if (! $send) {
                $counts['akan_dikirim']++;

                if ($limit > 0 && $counts['akan_dikirim'] >= $limit) {
                    return true;
                }

                continue;
            }

            try {
                $result = $invitations->send(
                    $workspace,
                    [
                        'ticket_id' => $rowTicketId,
                        'ticket_status' => $this->value($row, ['status_name', 'status_ticket', 'status']),
                        'channel' => $this->value($row, ['source', 'channel']),
                    ],
                    [
                        'name' => $this->value($row, ['customer.name_customer', 'name_customer', 'customer.name', 'customer_name']),
                        'email' => $email,
                        'wa' => $this->wa($row),
                    ],
                    $template,
                );

                if (in_array($result['outcome'], [
                    CsatInvitationService::OUTCOME_CREATED,
                    CsatInvitationService::OUTCOME_QUEUED,
                ], true)) {
                    $counts['dikirim']++;
                } else {
                    $counts['sudah_ada']++;
                }

                $toRemember[] = $rowTicketId;
            } catch (ValidationException $e) {
                // Data yang memang tidak valid tidak perlu dicoba ulang tiap jam.
                $counts['gagal']++;
                $toRemember[] = $rowTicketId;
                $this->warn('Tiket '.$rowTicketId.' dilewati: '.$e->validator->errors()->first());
            } catch (\Throwable $e) {
                // Kegagalan sementara tidak dimasukkan snapshot supaya dicoba
                // lagi pada run berikutnya. Undangan yang sudah ada tetap
                // mencegah duplikat.
                report($e);
                $counts['gagal']++;
                $this->warn('Tiket '.$rowTicketId.' gagal diproses. Detail aman ada di log server.');
            }

            if ($limit > 0 && $counts['dikirim'] >= $limit) {
                $this->remember($projectKey, $toRemember);

                return true;
            }
        }

        if ($send && $toRemember !== []) {
            $this->remember($projectKey, $toRemember);
        }

        return false;
    }

    /**
     * Baca semua halaman status Close untuk satu project.
     *
     * Kalau satu halaman gagal, seluruh project dianggap gagal (exception naik
     * ke handle) supaya snapshot tidak terisi dari data yang tidak lengkap.
     *
     * @param  array<string, int>  $counts
     * @return array<string, array<string, mixed>>
     */
    private function closeRows(
        CxpApiClient $client,
        string $token,
        string $project,
        int $perPage,
        int $maxPages,
        array &$counts,
    ): array {
        $rows = [];
        $page = 1;
        $lastPage = 1;

        do {
            $response = $client->ticketsByStatus($token, $project, 'Close', $page, $perPage);
            $lastPage = max($page, $this->lastPage($response, $page));

            foreach ((array) ($response['data'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $counts['dibaca']++;
                $id = trim((string) ($row['id_ticket'] ?? ''));

                if ($id === '') {
                    $counts['tanpa_id']++;

                    continue;
                }

                if (strcasecmp($this->value($row, ['status_name', 'status_ticket', 'status']), 'Close') !== 0) {
                    $counts['bukan_close']++;

                    continue;
                }

                // Kunci per id_ticket: duplikat antar halaman (kalau ada)
                // tidak memicu dua undangan.
                $rows[$id] = $row;
            }

            $page++;
        } while ($page <= $lastPage && ($maxPages === 0 || $page <= $maxPages));

        return $rows;
    }

    /**
     * Semua workspace non-legacy dengan template CSAT default.
     *
     * Opsi --workspace (atau CXP_CSAT_WORKSPACE) membatasi ke satu kode.
     *
     * @return Collection<int, Workspace>|null
     */
    private function workspaces(): ?Collection
    {
        $code = trim((string) ($this->option('workspace') ?: config('cxp.csat.workspace')));

        if ($code !== '') {
            $workspace = Workspace::query()
                ->where('is_legacy', false)
                ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
                ->first();

            if ($workspace === null) {
                $this->error('Workspace dengan kode '.$code.' tidak ditemukan.');

                return null;
            }

            return collect([$workspace]);
        }

        return Workspace::query()
            ->where('is_legacy', false)
            ->whereNotNull('code')
            ->where('code', '<>', '')
            ->whereNotNull('default_csat_survey_id')
            ->orderBy('code')
            ->get();
    }

    /**
     * Simpan snapshot tiket tanpa menimpa baris yang sudah ada.
     *
     * @param  array<int, string>  $ticketIds
     */
    private function remember(string $projectKey, array $ticketIds): void
    {
        $ticketIds = array_values(array_unique(array_filter(
            array_map(fn ($id) => trim((string) $id), $ticketIds),
            fn (string $id) => $id !== '',
        )));

        if ($ticketIds === []) {
            return;
        }

        $now = now();
        $rows = array_map(fn (string $id) => [
            'project_code' => $projectKey,
            'ticket_id' => $id,
            'first_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ticketIds);

        foreach (array_chunk($rows, 500) as $chunk) {
            CxpTicketSeen::query()->insertOrIgnore($chunk);
        }
    }

    /**
     * Kumpulkan id_ticket yang sudah punya undangan CSAT di workspace ini.
     *
     * @param  array<int, string>  $ticketIds
     * @return array<string, true>
     */
    private function existingTicketIds(int $workspaceId, array $ticketIds): array
    {
        $ticketIds = array_values(array_filter($ticketIds, fn ($id) => trim((string) $id) !== ''));

        if ($ticketIds === []) {
            return [];
        }

        return Invitation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('ticket_id', $ticketIds)
            ->whereHas('survey', fn ($query) => $query->withoutGlobalScopes()->where('type', 'csat'))
            ->pluck('ticket_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();
    }

    /**
     * Nomor halaman terakhir. Endpoint status memakai kunci "pagination";
     * kunci "meta" tetap didukung untuk endpoint lama.
     *
     * @param  array<string, mixed>  $response
     */
    private function lastPage(array $response, int $current): int
    {
        return (int) data_get($response, 'pagination.last_page')
            ?: (int) data_get($response, 'meta.last_page')
            ?: $current;
    }

    /**
     * Ambil nilai pertama yang tidak kosong dari beberapa path.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $paths
     */
    private function value(array $row, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($row, $path);

            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * Nomor WA memakai urutan fallback yang sama dengan cxp:inspect.
     *
     * @param  array<string, mixed>  $row
     */
    private function wa(array $row): string
    {
        foreach (['customer.wa', 'customer.phone_no', 'customer.mobile_phone', 'wa', 'phone_no', 'mobile_phone'] as $path) {
            $value = WhatsAppNumber::normalize($this->value($row, [$path]));

            if ($value !== '' && WhatsAppNumber::isValid($value)) {
                return $value;
            }
        }

        return '';
    }
}

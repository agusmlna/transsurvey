<?php

namespace App\Console\Commands;

use App\Exceptions\CxpApiException;
use App\Models\{Invitation, Workspace};
use App\Services\Csat\CsatInvitationService;
use App\Services\Csat\WhatsAppNumber;
use App\Services\Cxp\CxpApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Kirim CSAT otomatis untuk tiket TransCXP yang berstatus Close.
 *
 * Endpoint /api/v2/tickets hanya bisa memfilter status dan start_date, tidak
 * ada filter tanggal tutup maupun sort by update. Karena itu command membaca
 * seluruh halaman status=Close lalu menyaring secara lokal memakai update_date
 * sebagai perkiraan waktu tiket ditutup.
 *
 * Tanpa opsi --send command hanya simulasi: tidak ada baris database yang
 * ditulis dan tidak ada email yang diantrekan.
 */
class SendCsatFromCxp extends Command
{
    private const PER_PAGE = 20;

    protected $signature = 'cxp:csat-send
        {--send : Antre email sungguhan; tanpa opsi ini hanya simulasi}
        {--since= : Batas update_date (Y-m-d); default CXP_CSAT_SINCE}
        {--workspace= : Kode workspace; default CXP_CSAT_WORKSPACE}
        {--ticket= : Proses satu id_ticket saja, untuk uji}
        {--page=1 : Halaman awal}
        {--max-pages=0 : Batas halaman yang dibaca (0 = semua)}
        {--limit=0 : Batas email yang diantrekan (0 = semua)}';

    protected $description = 'Antrekan CSAT untuk tiket TransCXP berstatus Close yang sudah ditutup setelah tanggal batas.';

    public function handle(CsatInvitationService $invitations): int
    {
        $client = CxpApiClient::fromConfig();

        $send = (bool) $this->option('send');
        $since = trim((string) ($this->option('since') ?: config('cxp.csat.since')));
        $ticketId = trim((string) $this->option('ticket'));
        $page = max(1, (int) $this->option('page'));
        $maxPages = max(0, (int) $this->option('max-pages'));
        $limit = max(0, (int) $this->option('limit'));

        if ($since === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
            $this->error('Isi CXP_CSAT_SINCE (format Y-m-d) atau pakai --since=2026-11-11.');
            $this->line('Batas ini mencegah tiket lama ikut dikirimi CSAT saat pertama kali dijalankan.');

            return self::FAILURE;
        }

        $workspace = $this->workspace();
        if ($workspace === null) {
            return self::FAILURE;
        }

        $template = $invitations->defaultTemplate($workspace);
        if ($template === null) {
            $this->error('Workspace '.$workspace->code.' belum punya template CSAT aktif sebagai default.');
            $this->line('Buka menu CSAT > Template, lalu jadikan satu template sebagai default.');

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

        $this->line('Workspace : '.$workspace->name.' ('.$workspace->code.')');
        $this->line('Template  : '.$template->title);
        $this->line('Batas     : update_date >= '.$since);
        $this->line('Mode      : '.($send ? 'KIRIM' : 'SIMULASI (tidak menulis database)'));
        $this->newLine();

        try {
            $login = $client->login();
        } catch (CxpApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $counts = [
            'dibaca' => 0,
            'bukan_close' => 0,
            'terlalu_lama' => 0,
            'tanpa_email' => 0,
            'email_tidak_valid' => 0,
            'sudah_ada' => 0,
            'dikirim' => 0,
            'akan_dikirim' => 0,
            'gagal' => 0,
        ];
        $handled = [];
        $stop = false;
        $lastPage = $page;

        for ($current = $page; ! $stop; $current++) {
            try {
                $response = $client->tickets($login['token'], $current, self::PER_PAGE, $ticketId !== '' ? $ticketId : null, [
                    'status' => 'Close',
                ]);
            } catch (CxpApiException $e) {
                $this->error('Halaman '.$current.' gagal: '.$e->getMessage());

                return self::FAILURE;
            }

            $lastPage = max($current, (int) data_get($response, 'meta.last_page', $current));
            $rows = array_values(array_filter((array) ($response['data'] ?? []), 'is_array'));

            if ($rows === []) {
                break;
            }

            $existing = $this->existingTicketIds(
                $workspace,
                array_map(fn (array $row) => trim((string) ($row['id_ticket'] ?? '')), $rows),
            );

            foreach ($rows as $row) {
                $counts['dibaca']++;
                $rowTicketId = trim((string) ($row['id_ticket'] ?? ''));

                if ($rowTicketId === '') {
                    continue;
                }

                if (strcasecmp(trim((string) ($row['status_name'] ?? '')), 'Close') !== 0) {
                    $counts['bukan_close']++;

                    continue;
                }

                $updated = trim((string) (data_get($row, 'update_date') ?: data_get($row, 'start_date')));
                $updated = substr($updated, 0, 10);

                if ($updated === '' || $updated < $since) {
                    $counts['terlalu_lama']++;

                    continue;
                }

                $customer = (array) ($row['customer'] ?? []);
                $email = mb_strtolower(trim((string) ($customer['email'] ?? '')));

                if ($email === '') {
                    $counts['tanpa_email']++;

                    continue;
                }

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $counts['email_tidak_valid']++;

                    continue;
                }

                if (isset($existing[$rowTicketId])) {
                    $counts['sudah_ada']++;

                    continue;
                }

                $handled[] = $rowTicketId;

                if (! $send) {
                    $counts['akan_dikirim']++;

                    if ($limit > 0 && $counts['akan_dikirim'] >= $limit) {
                        $stop = true;
                        break;
                    }

                    continue;
                }

                try {
                    $invitations->send(
                        $workspace,
                        [
                            'ticket_id' => $rowTicketId,
                            'ticket_status' => (string) ($row['status_name'] ?? ''),
                            'channel' => (string) ($row['source'] ?? ''),
                        ],
                        [
                            'name' => trim((string) ($customer['name_customer'] ?? '')),
                            'email' => $email,
                            'wa' => $this->wa($customer),
                        ],
                        $template,
                    );
                    $counts['dikirim']++;
                } catch (ValidationException $e) {
                    $counts['gagal']++;
                    $this->warn('Tiket '.$rowTicketId.' dilewati: '.$e->validator->errors()->first());
                } catch (\Throwable $e) {
                    report($e);
                    $counts['gagal']++;
                    $this->warn('Tiket '.$rowTicketId.' gagal diproses. Detail aman ada di log server.');
                }

                if ($limit > 0 && $counts['dikirim'] >= $limit) {
                    $stop = true;
                    break;
                }
            }

            if ($current >= $lastPage) {
                break;
            }

            if ($maxPages > 0 && ($current - $page + 1) >= $maxPages) {
                break;
            }
        }

        $this->newLine();
        $this->table(
            ['Hasil', 'Jumlah'],
            [
                ['Tiket dibaca', $counts['dibaca']],
                ['Bukan status Close', $counts['bukan_close']],
                ['Diperbarui sebelum batas', $counts['terlalu_lama']],
                ['Tanpa email', $counts['tanpa_email']],
                ['Email tidak valid', $counts['email_tidak_valid']],
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
            'workspace' => $workspace->code,
            'send' => $send,
            'since' => $since,
            'counts' => $counts,
        ]);

        return self::SUCCESS;
    }

    private function workspace(): ?Workspace
    {
        $code = mb_strtolower(trim((string) ($this->option('workspace') ?: config('cxp.csat.workspace'))));

        if ($code === '') {
            $this->error('Isi CXP_CSAT_WORKSPACE dengan kode workspace, atau pakai --workspace=<kode>.');

            return null;
        }

        $workspace = Workspace::query()->whereRaw('lower(code) = ?', [$code])->first();

        if ($workspace === null) {
            $this->error('Workspace dengan kode '.$code.' tidak ditemukan.');

            return null;
        }

        if ($workspace->is_legacy) {
            $this->error('Workspace legacy tidak boleh dipakai untuk CSAT otomatis.');

            return null;
        }

        return $workspace;
    }

    /**
     * Kumpulkan id_ticket yang sudah punya undangan CSAT di workspace ini.
     *
     * @param  array<int, string>  $ticketIds
     * @return array<string, true>
     */
    private function existingTicketIds(Workspace $workspace, array $ticketIds): array
    {
        $ticketIds = array_values(array_filter($ticketIds, fn (string $id) => $id !== ''));

        if ($ticketIds === []) {
            return [];
        }

        return Invitation::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('ticket_id', $ticketIds)
            ->whereHas('survey', fn ($query) => $query->withoutGlobalScopes()->where('type', 'csat'))
            ->pluck('ticket_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();
    }

    /**
     * Nomor WA memakai urutan fallback yang sama dengan cxp:inspect.
     *
     * @param  array<string, mixed>  $customer
     */
    private function wa(array $customer): string
    {
        foreach (['wa', 'phone_no', 'mobile_phone'] as $key) {
            $value = WhatsAppNumber::normalize((string) ($customer[$key] ?? ''));

            if ($value !== '' && WhatsAppNumber::isValid($value)) {
                return $value;
            }
        }

        return '';
    }
}

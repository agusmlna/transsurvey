<?php

namespace App\Console\Commands;

use App\Exceptions\CxpApiException;
use App\Services\Cxp\CxpWaAccountSyncer;
use Illuminate\Console\Command;

/**
 * Isi CXP_WA_ACCOUNTS_FILE dari /api/v1/accountWaProject/{id_project}.
 *
 * Yang diambil: nomor pengirim (whatsapp_number -> number), id akun
 * (id -> account_id), dan semua channel aktif (channels). Akun dengan
 * `active: no` dilewati. Username/password API v2 tidak disentuh dan bisa
 * ditaruh di level project untuk diwariskan ke semua channel.
 *
 * `channels` hanya metadata/audit: API v2 messages-template tidak punya
 * parameter pemilih channel. Nomor pengirim tetap mengikuti akun yang dipakai
 * login /api/v2/login-user.
 */
class SyncCxpWaAccounts extends Command
{
    protected $signature = 'cxp:csat-wa-accounts-sync
        {--projects= : Daftar id_project dipisah koma; default semua CXP_CSAT_PROJECTS}
        {--dry-run : Tampilkan hasil tanpa menulis file}';

    protected $description = 'Sinkron nomor pengirim dan channel aktif per project dari API TransCXP.';

    public function handle(): int
    {
        $projects = $this->projects();

        if ($projects === []) {
            $this->error('Tidak ada project. Isi CXP_CSAT_PROJECTS atau gunakan --projects=23,8.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = CxpWaAccountSyncer::fromConfig()->sync($projects, $dryRun);
        } catch (\InvalidArgumentException | CxpApiException | \RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['updated'] as $code) {
            $this->line(sprintf('project=%s account_id + nomor tersinkron', $code));
        }

        foreach ($result['skipped'] as $code) {
            $this->warn(sprintf('project=%s dilewati: akun WhatsApp aktif tidak ditemukan', $code));
        }

        foreach ($result['errors'] as $code => $message) {
            $this->warn(sprintf('project=%s dilewati: %s', $code, $message));
        }

        foreach ($result['missing_credentials'] as $code) {
            $this->warn(sprintf('project=%s belum punya username/password API v2 di file akun', $code));
        }

        $this->info(sprintf(
            '%s: %d project tersinkron, %d dilewati.',
            $dryRun ? 'Dry-run' : 'Selesai',
            count($result['updated']),
            count($result['skipped']) + count($result['errors']),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int|string, string>
     */
    private function projects(): array
    {
        $option = trim((string) $this->option('projects'));

        if ($option !== '') {
            $projects = [];

            foreach (explode(',', $option) as $code) {
                $code = trim($code);

                if ($code !== '') {
                    $projects[$code] = $code;
                }
            }

            return $projects;
        }

        return (array) config('cxp.csat.projects', []);
    }
}

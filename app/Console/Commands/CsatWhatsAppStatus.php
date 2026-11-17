<?php

namespace App\Console\Commands;

use App\Exceptions\CxpApiException;
use App\Models\{WhatsAppDelivery, Workspace};
use App\Services\Csat\CsatSettings;
use App\Services\Cxp\CxpWhatsAppAccounts;
use App\Services\Cxp\CxpWhatsAppClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Perbarui status pesan WhatsApp CSAT dari TransCXP.
 *
 * Pengiriman dilakukan sinkron oleh cxp:csat-send, sedangkan status
 * delivered/read/failed diambil lewat command ini karena API belum punya
 * webhook.
 */
class CsatWhatsAppStatus extends Command
{
    protected $signature = 'cxp:csat-wa-status
        {--limit=50 : Jumlah pesan yang diperiksa}
        {--workspace= : Batasi ke satu kode workspace}
        {--message= : Periksa satu message_id saja}';

    protected $description = 'Perbarui status pesan WhatsApp CSAT dari TransCXP.';

    public function handle(CxpWhatsAppClient $client, CxpWhatsAppAccounts $accounts): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $workspaceCode = trim((string) $this->option('workspace'));
        $messageId = trim((string) $this->option('message'));

        if (! $client->isConfigured()) {
            $this->error('CXP_WA_BASE_URL belum diisi.');

            return self::FAILURE;
        }

        $query = WhatsAppDelivery::query()
            ->whereNotNull('provider_message_id')
            ->where('provider_message_id', '<>', '');

        if ($messageId !== '') {
            $query->where('provider_message_id', $messageId);
        } else {
            // Status final tidak perlu dipoll lagi.
            $query->whereIn('status', [
                WhatsAppDelivery::STATUS_PENDING,
                WhatsAppDelivery::STATUS_SENT,
                WhatsAppDelivery::STATUS_DELIVERED,
            ]);
        }

        if ($workspaceCode !== '') {
            $workspace = Workspace::query()
                ->whereRaw('lower(code) = ?', [mb_strtolower($workspaceCode)])
                ->first();

            if ($workspace === null) {
                $this->error('Workspace dengan kode '.$workspaceCode.' tidak ditemukan.');

                return self::FAILURE;
            }

            $query->where('workspace_id', $workspace->id);
        }

        $deliveries = $query->orderBy('last_checked_at')->limit($limit)->get();

        if ($deliveries->isEmpty()) {
            $this->info('Tidak ada pesan WhatsApp yang perlu diperiksa.');

            return self::SUCCESS;
        }

        $counts = ['diperiksa' => 0, 'diperbarui' => 0, 'tanpa_akun' => 0, 'gagal' => 0];
        $workspaces = Workspace::query()->get()->keyBy('id');

        foreach ($deliveries as $delivery) {
            $counts['diperiksa']++;

            $workspace = $workspaces->get($delivery->workspace_id);
            $account = $workspace !== null ? $accounts->forWorkspace($workspace) : null;

            if ($account === null) {
                $counts['tanpa_akun']++;
                $this->warn('Pesan '.$delivery->provider_message_id.' dilewati: akun WhatsApp workspace tidak tersedia.');

                continue;
            }

            try {
                $data = $client->messageStatus($account, (string) $delivery->provider_message_id);
            } catch (CxpApiException $e) {
                $counts['gagal']++;
                $this->warn('Status '.$delivery->provider_message_id.' gagal: '.$e->getMessage());

                continue;
            } catch (\Throwable $e) {
                $counts['gagal']++;
                report($e);
                $this->warn('Status '.$delivery->provider_message_id.' gagal diproses.');

                continue;
            }

            $status = strtolower(trim((string) ($data['status'] ?? '')));
            $attributes = ['last_checked_at' => now()];

            if ($status !== '') {
                $attributes['status'] = $status;
            }

            foreach (['sent_at', 'delivered_at', 'read_at', 'failed_at'] as $field) {
                $parsed = $this->parseDate($data[$field] ?? null);

                if ($parsed !== null) {
                    $attributes[$field] = $parsed;
                }
            }

            $reason = trim((string) ($data['failure_reason'] ?? ''));

            if ($reason !== '') {
                $attributes['failure_reason'] = $reason;
            }

            $delivery->forceFill($attributes)->save();
            $counts['diperbarui']++;

            // Pastikan undangan tidak kehilangan masa berlaku kalau
            // sent_at baru diketahui dari polling.
            $invitation = $delivery->invitation;
            if ($workspace !== null && $invitation !== null && $invitation->sent_at === null && ! empty($attributes['sent_at'])) {
                $invitation->sent_at = $attributes['sent_at'];
                $invitation->expires_at = CsatSettings::expiresAt($workspace, $invitation->sent_at);
                $invitation->save();
            }
        }

        $this->table(
            ['Hasil', 'Jumlah'],
            [
                ['Diperiksa', $counts['diperiksa']],
                ['Diperbarui', $counts['diperbarui']],
                ['Tanpa akun WhatsApp', $counts['tanpa_akun']],
                ['Gagal', $counts['gagal']],
            ],
        );

        return self::SUCCESS;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

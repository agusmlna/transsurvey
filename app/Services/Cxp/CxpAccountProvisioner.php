<?php

namespace App\Services\Cxp;

use App\Exceptions\CxpSsoException;
use App\Models\{User, Workspace};
use Illuminate\Support\Facades\DB;

/**
 * Membuat/mencocokkan workspace dan akun operation dari klaim token TransCXP.
 *
 * Aturan keamanan:
 * - akun SSO selalu dicocokkan lewat external_id, bukan email;
 * - akun yang sudah ada dengan role admin/superadmin tidak pernah ditautkan;
 * - workspace_id tidak pernah diambil dari input request.
 */
class CxpAccountProvisioner
{
    public function provision(array $payload): CxpLaunchResult
    {
        $identity = $this->identity($payload);

        return DB::transaction(function () use ($identity): CxpLaunchResult {
            $source = (string) config('cxp.external_source', 'transcxp');
            $user = User::query()
                ->where('external_source', $source)
                ->where('external_id', $identity['external_id'])
                ->first();

            if ($user !== null) {
                if ($user->role !== 'operation') {
                    throw CxpSsoException::forbidden(
                        'account_not_operation',
                        __('Akun SSO ini bukan akun operation.')
                    );
                }
            } else {
                // Jangan pernah menautkan akun yang sudah ada berdasarkan email.
                if (User::query()->where('email', $identity['email'])->exists()) {
                    throw CxpSsoException::conflict(
                        'email_conflict',
                        __('Email :email sudah dipakai akun lain. Hubungi administrator TransSurvey.', ['email' => $identity['email']])
                    );
                }
            }

            $workspace = Workspace::firstOrCreate(
                ['code' => $identity['project_code']],
                ['name' => $identity['project_name']]
            );

            if ($workspace->is_legacy) {
                throw CxpSsoException::conflict(
                    'reserved_workspace',
                    __('Kode project TransCXP tidak dapat memakai workspace data lama.')
                );
            }

            if ($user === null) {
                $user = User::create([
                    'name' => $identity['name'],
                    'email' => $identity['email'],
                    'password' => \Illuminate\Support\Str::random(64),
                    'role' => 'operation',
                    'external_source' => $source,
                    'external_id' => $identity['external_id'],
                ]);
            }

            $user->workspaces()->syncWithoutDetaching([$workspace->id]);

            $context = [
                'user' => [
                    'external_id' => $identity['external_id'],
                    'name' => $identity['name'],
                    'email' => $identity['email'],
                ],
                'project' => [
                    'code' => $identity['project_code'],
                    'name' => $identity['project_name'],
                ],
            ];

            // Token login saja tidak memuat tiket; halaman Kirim CSAT akan
            // mengambil data tiket lewat API pada Fase 4.
            if ($identity['ticket_id'] !== '' || $identity['customer_email'] !== '') {
                $context['ticket'] = [
                    'id' => $identity['ticket_id'],
                    'status' => $identity['ticket_status'],
                    'channel' => $identity['channel'],
                ];
                $context['customer'] = [
                    'id' => $identity['customer_id'],
                    'name' => $identity['customer_name'],
                    'email' => $identity['customer_email'],
                ];
            }

            return new CxpLaunchResult($user->fresh(), $workspace->fresh(), $context);
        });
    }

    /**
     * Validasi klaim tanpa menyentuh database. Dipakai endpoint launch session
     * supaya payload yang tidak valid ditolak sebelum kode dibuat.
     */
    public function validate(array $payload): void
    {
        $this->identity($payload);
    }

    private function identity(array $payload): array
    {
        $externalId = $this->claim($payload, 'user.id', 191, true);
        $email = mb_strtolower($this->claim($payload, 'user.email', 255, true));
        $name = $this->claim($payload, 'user.name', 255) ?: $email;

        $projectCode = mb_strtolower($this->claim($payload, 'project.code', 100, true));
        $projectName = $this->claim($payload, 'project.name', 255) ?: $projectCode;

        $ticketId = $this->claim($payload, 'ticket.id', 100);
        $ticketStatus = $this->claim($payload, 'ticket.status', 100);
        $channel = $this->claim($payload, 'ticket.channel', 100);

        $customerId = $this->claim($payload, 'customer.id', 191);
        $customerEmail = mb_strtolower($this->claim($payload, 'customer.email', 255));
        $customerName = $this->claim($payload, 'customer.name', 255) ?: $customerEmail;

        // Token boleh hanya berisi identitas login (user + project). Data tiket
        // menyusul lewat API pada Fase 4. Tapi kalau salah satu dikirim, keduanya
        // harus lengkap supaya tiket dan customer tidak tertukar.
        if ($ticketId === '' && $customerEmail === '') {
            // Login saja.
        } elseif ($ticketId === '' || $customerEmail === '') {
            throw CxpSsoException::invalid(
                'invalid_claim',
                __('Data pada token SSO tidak lengkap.'),
                ['claim' => $ticketId === '' ? 'ticket.id' : 'customer.email']
            );
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw CxpSsoException::invalid('invalid_claim', __('Data pengguna pada token SSO tidak valid.'), ['claim' => 'user.email']);
        }
        if ($customerEmail !== '' && ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw CxpSsoException::invalid('invalid_claim', __('Data customer pada token SSO tidak valid.'), ['claim' => 'customer.email']);
        }

        return [
            'external_id' => $externalId,
            'email' => $email,
            'name' => $name,
            'project_code' => $projectCode,
            'project_name' => $projectName,
            'ticket_id' => $ticketId,
            'ticket_status' => $ticketStatus,
            'channel' => $channel,
            'customer_id' => $customerId,
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
        ];
    }

    private function claim(array $payload, string $path, int $max, bool $required = false): string
    {
        $value = data_get($payload, $path);

        if ($value === null) {
            if ($required) {
                throw CxpSsoException::invalid('invalid_claim', __('Data pada token SSO tidak lengkap.'), ['claim' => $path]);
            }

            return '';
        }
        if (! is_scalar($value)) {
            throw CxpSsoException::invalid('invalid_claim', __('Data pada token SSO tidak valid.'), ['claim' => $path]);
        }

        $value = trim((string) $value);
        if ($required && $value === '') {
            throw CxpSsoException::invalid('invalid_claim', __('Data pada token SSO tidak lengkap.'), ['claim' => $path]);
        }
        if (mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw CxpSsoException::invalid('invalid_claim', __('Data pada token SSO tidak valid.'), ['claim' => $path]);
        }

        return $value;
    }
}

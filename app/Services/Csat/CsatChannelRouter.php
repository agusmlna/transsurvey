<?php

namespace App\Services\Csat;

use App\Models\Workspace;
use App\Services\Cxp\CxpWhatsAppAccounts;

/**
 * Menentukan kanal pengiriman CSAT dari source tiket TransCXP.
 *
 * Semua daftar sumber diatur di config/cxp.php (CXP_CSAT_*), jadi perubahan
 * kebijakan tidak memerlukan deploy kode. Nomor WA hanya dipakai kalau
 * workspace punya akun sendiri: tidak ada akun fallback.
 */
class CsatChannelRouter
{
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SKIP = 'skip';

    /** @var array<int, string> */
    private array $waSources;
    /** @var array<int, string> */
    private array $emailSources;
    /** @var array<int, string> */
    private array $emailFallbackSources;
    /** @var array<int, string> */
    private array $skipSources;
    private string $defaultChannel;

    public function __construct(private readonly CxpWhatsAppAccounts $accounts)
    {
        $this->waSources = self::normalizeList((array) config('cxp.csat.wa_sources', ['WhatsApp']));
        $this->emailSources = self::normalizeList((array) config('cxp.csat.email_sources', ['Email']));
        $this->emailFallbackSources = self::normalizeList((array) config('cxp.csat.email_fallback_sources', []));
        $this->skipSources = self::normalizeList((array) config('cxp.csat.skip_sources', []));
        $this->defaultChannel = (string) config('cxp.csat.default_channel', 'email') === self::CHANNEL_WHATSAPP
            ? self::CHANNEL_WHATSAPP
            : self::CHANNEL_EMAIL;
    }

    /**
     * @return array{channel: string, reason: string, account: array<string, mixed>|null, number: string}
     */
    public function route(
        ?string $source,
        ?string $email,
        ?string $wa,
        Workspace|string|int|null $workspace,
    ): array {
        $sourceKey = self::normalizeSource($source);
        $hasEmail = self::isValidEmail($email);

        $normalizedWa = WhatsAppNumber::normalize($wa);
        $hasNumber = $normalizedWa !== '' && WhatsAppNumber::isValid($normalizedWa);

        if (in_array($sourceKey, $this->skipSources, true)) {
            return $this->result(self::CHANNEL_SKIP, 'skip_source');
        }

        $preferWhatsApp = in_array($sourceKey, $this->waSources, true);

        if (! $preferWhatsApp) {
            $isEmailSource = in_array($sourceKey, $this->emailSources, true)
                || in_array($sourceKey, $this->emailFallbackSources, true);

            if ($isEmailSource) {
                return $hasEmail
                    ? $this->result(self::CHANNEL_EMAIL, 'email')
                    : $this->result(self::CHANNEL_SKIP, 'skip_no_email');
            }

            // Source tidak dikenal: ikuti default_channel.
            $preferWhatsApp = $this->defaultChannel === self::CHANNEL_WHATSAPP;
        }

        if (! $preferWhatsApp) {
            return $hasEmail
                ? $this->result(self::CHANNEL_EMAIL, 'email')
                : $this->result(self::CHANNEL_SKIP, 'skip_no_email');
        }

        if (! $hasNumber) {
            return $hasEmail
                ? $this->result(self::CHANNEL_EMAIL, 'fallback_email')
                : $this->result(self::CHANNEL_SKIP, 'skip_no_number');
        }

        $account = $workspace === null ? null : $this->accounts->forWorkspace($workspace);

        if ($account === null) {
            return $hasEmail
                ? $this->result(self::CHANNEL_EMAIL, 'fallback_email')
                : $this->result(self::CHANNEL_SKIP, 'skip_no_account');
        }

        return $this->result(self::CHANNEL_WHATSAPP, 'whatsapp', $account, '+'.$normalizedWa);
    }

    public function hasAccount(Workspace|string|int $workspace): bool
    {
        return $this->accounts->has($workspace);
    }

    public function hasAnyAccount(): bool
    {
        return $this->accounts->codes() !== [];
    }

    public static function normalizeSource(?string $source): string
    {
        $source = strtolower(trim((string) $source));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $source));
    }

    public static function isValidEmail(?string $email): bool
    {
        $email = trim((string) $email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private static function normalizeList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $key = self::normalizeSource((string) $value);

            if ($key !== '') {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<string, mixed>|null  $account
     * @return array{channel: string, reason: string, account: array<string, mixed>|null, number: string}
     */
    private function result(string $channel, string $reason, ?array $account = null, string $number = ''): array
    {
        return [
            'channel' => $channel,
            'reason' => $reason,
            'account' => $account,
            'number' => $number,
        ];
    }
}

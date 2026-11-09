<?php

namespace App\Services\Cxp;

use Illuminate\Contracts\Session\Session;

/**
 * Menyimpan data tiket/customer dari token atau launch session untuk dipakai
 * halaman Kirim CSAT (Fase 4).
 */
class CxpLaunchContext
{
    public const SESSION_KEY = 'cxp.context';

    public static function put(Session $session, CxpLaunchResult $result): void
    {
        $session->put(self::SESSION_KEY, $result->context + [
            'workspace_id' => $result->workspace->id,
            'launched_at' => now()->toIso8601String(),
        ]);
    }

    public static function get(Session $session): ?array
    {
        $context = $session->get(self::SESSION_KEY);

        return is_array($context) ? $context : null;
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}

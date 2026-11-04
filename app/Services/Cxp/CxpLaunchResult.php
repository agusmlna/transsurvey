<?php

namespace App\Services\Cxp;

use App\Models\{User, Workspace};

/**
 * Hasil launch yang siap dipakai controller dan Fase 4.
 */
final class CxpLaunchResult
{
    public function __construct(
        public readonly User $user,
        public readonly Workspace $workspace,
        public readonly array $context,
    ) {
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Kegagalan saat memanggil API TransCXP.
 *
 * Pesan tidak boleh memuat password atau token. Context hanya untuk diagnosis.
 */
class CxpApiException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 0,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}

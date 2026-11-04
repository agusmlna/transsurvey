<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Kegagalan SSO yang aman ditampilkan ke pengguna.
 *
 * Reason/context hanya untuk log; pesan pengguna tidak boleh memuat token.
 */
class CxpSsoException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 401,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $reason, string $message, array $context = []): self
    {
        return new self($reason, $message, 401, $context);
    }

    public static function forbidden(string $reason, string $message, array $context = []): self
    {
        return new self($reason, $message, 403, $context);
    }

    public static function conflict(string $reason, string $message, array $context = []): self
    {
        return new self($reason, $message, 409, $context);
    }

    public static function server(string $reason, string $message, array $context = []): self
    {
        return new self($reason, $message, 500, $context);
    }
}

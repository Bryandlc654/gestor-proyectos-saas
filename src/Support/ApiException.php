<?php

namespace App\Support;

use RuntimeException;

/** Error controlado de la API con código HTTP y código de error estable. */
final class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

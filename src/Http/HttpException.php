<?php

namespace App\Http;

use RuntimeException;

/**
 * Error que se devuelve al cliente como JSON {success: false, message} con el código HTTP indicado.
 */
final class HttpException extends RuntimeException
{
    public function __construct(private readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function forbidden(string $message): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, $message);
    }

    // Error de validación: se responde con 200 y success=false (formato que espera el frontend)
    public static function invalid(string $message): self
    {
        return new self(200, $message);
    }
}

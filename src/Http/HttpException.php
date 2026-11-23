<?php

namespace App\Http;

use RuntimeException;

/**
 * Error que se devuelve al cliente como JSON {success: false, error, message} con el código HTTP indicado.
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

    // Error de validación de los datos enviados
    public static function invalid(string $message): self
    {
        return new self(422, $message);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, $message);
    }
}

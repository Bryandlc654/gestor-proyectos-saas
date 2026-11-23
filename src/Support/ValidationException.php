<?php

namespace App\Support;

use DomainException;

/**
 * Datos de entrada no válidos. Hereda de DomainException para que la API la responda como 422
 * con el mensaje del primer error (ver Router::handle y api/app/_bootstrap.php).
 */
final class ValidationException extends DomainException
{
    /** @param array<string, string> $errors Mensaje por campo */
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string)(reset($errors) ?: 'Los datos enviados no son válidos.'));
    }
}

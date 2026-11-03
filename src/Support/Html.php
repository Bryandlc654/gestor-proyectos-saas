<?php

namespace App\Support;

final class Html
{
    // Escapar texto para HTML (contenido y atributos)
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

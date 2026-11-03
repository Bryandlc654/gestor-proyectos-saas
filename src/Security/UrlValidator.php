<?php

namespace App\Security;

final class UrlValidator
{
    /**
     * URL externa segura para usar en un href: solo http/https (bloquea javascript:, data:, etc.).
     */
    public static function isSafe(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }
}

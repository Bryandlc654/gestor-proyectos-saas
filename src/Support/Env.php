<?php

namespace App\Support;

/**
 * Variables de entorno cargadas desde un archivo .env (sin dependencias externas).
 * Las variables reales del servidor tienen prioridad sobre el archivo.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));

            // Quitar comillas envolventes simples o dobles
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') !== 'development';
    }
}

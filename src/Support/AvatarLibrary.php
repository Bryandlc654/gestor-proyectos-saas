<?php

namespace App\Support;

/**
 * Catálogo de avatares SVG prediseñados.
 *
 * Solo se aceptan las claves de este listado: el valor guardado en `users.avatar`
 * nunca procede directamente del formulario, evitando rutas arbitrarias.
 */
final class AvatarLibrary
{
    public const AVATARS = [
        'hombre-1' => ['file' => 'assets/avatars/hombre-1.svg', 'label' => 'Hombre 1', 'gender' => 'hombre'],
        'hombre-2' => ['file' => 'assets/avatars/hombre-2.svg', 'label' => 'Hombre 2', 'gender' => 'hombre'],
        'hombre-3' => ['file' => 'assets/avatars/hombre-3.svg', 'label' => 'Hombre 3', 'gender' => 'hombre'],
        'hombre-4' => ['file' => 'assets/avatars/hombre-4.svg', 'label' => 'Hombre 4', 'gender' => 'hombre'],
        'hombre-5' => ['file' => 'assets/avatars/hombre-5.svg', 'label' => 'Hombre 5', 'gender' => 'hombre'],
        'hombre-6' => ['file' => 'assets/avatars/hombre-6.svg', 'label' => 'Hombre 6', 'gender' => 'hombre'],
        'hombre-7' => ['file' => 'assets/avatars/hombre-7.svg', 'label' => 'Hombre 7', 'gender' => 'hombre'],
        'hombre-8' => ['file' => 'assets/avatars/hombre-8.svg', 'label' => 'Hombre 8', 'gender' => 'hombre'],
        'mujer-1' => ['file' => 'assets/avatars/mujer-1.svg', 'label' => 'Mujer 1', 'gender' => 'mujer'],
        'mujer-2' => ['file' => 'assets/avatars/mujer-2.svg', 'label' => 'Mujer 2', 'gender' => 'mujer'],
        'mujer-3' => ['file' => 'assets/avatars/mujer-3.svg', 'label' => 'Mujer 3', 'gender' => 'mujer'],
        'mujer-4' => ['file' => 'assets/avatars/mujer-4.svg', 'label' => 'Mujer 4', 'gender' => 'mujer'],
        'mujer-5' => ['file' => 'assets/avatars/mujer-5.svg', 'label' => 'Mujer 5', 'gender' => 'mujer'],
        'mujer-6' => ['file' => 'assets/avatars/mujer-6.svg', 'label' => 'Mujer 6', 'gender' => 'mujer'],
        'mujer-7' => ['file' => 'assets/avatars/mujer-7.svg', 'label' => 'Mujer 7', 'gender' => 'mujer'],
        'mujer-8' => ['file' => 'assets/avatars/mujer-8.svg', 'label' => 'Mujer 8', 'gender' => 'mujer'],
    ];

    /** @return array<string, array{file: string, label: string, gender: string}> */
    public static function all(): array
    {
        return self::AVATARS;
    }

    public static function men(): array
    {
        return array_filter(self::AVATARS, static fn(array $avatar): bool => $avatar['gender'] === 'hombre');
    }

    public static function women(): array
    {
        return array_filter(self::AVATARS, static fn(array $avatar): bool => $avatar['gender'] === 'mujer');
    }

    /** Ruta relativa segura, o null si la clave no existe o el archivo falta. */
    public static function path(string $key): ?string
    {
        if (!isset(self::AVATARS[$key])) {
            return null;
        }

        $file = self::AVATARS[$key]['file'];
        return is_file(dirname(__DIR__, 2) . '/' . $file) ? $file : null;
    }

    public static function isPreset(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        foreach (self::AVATARS as $avatar) {
            if ($avatar['file'] === $path) {
                return true;
            }
        }

        return false;
    }
}

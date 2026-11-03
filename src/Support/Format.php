<?php

namespace App\Support;

final class Format
{
    // Fecha legible: "05 Nov, 2026" o "05 Nov, 2026 - 03:30 PM"
    public static function date(?string $date, bool $withTime = false): string
    {
        if (!$date) {
            return 'Sin fecha';
        }
        return date($withTime ? 'd M, Y - h:i A' : 'd M, Y', strtotime($date));
    }

    // Tamaño legible: bytes a KB, MB, GB
    public static function bytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = (int)floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }
}

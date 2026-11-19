<?php

namespace App\Support;

/** Estados posibles de una cotización y su presentación en la interfaz. */
final class QuoteStatus
{
    public const LABELS = [
        'borrador' => 'Borrador',
        'enviada' => 'Enviada',
        'aceptada' => 'Aceptada',
        'rechazada' => 'Rechazada',
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(?string $status): bool
    {
        return isset(self::LABELS[(string)$status]);
    }

    public static function label(?string $status): string
    {
        return self::LABELS[(string)$status] ?? 'Borrador';
    }

    /** Clases de Tailwind para el badge de estado. */
    public static function badgeClasses(?string $status): string
    {
        return match ((string)$status) {
            'enviada' => 'bg-blue-50 text-blue-700 border-blue-200/80',
            'aceptada' => 'bg-emerald-50 text-emerald-700 border-emerald-200/80',
            'rechazada' => 'bg-rose-50 text-rose-700 border-rose-200/80',
            default => 'bg-slate-100 text-slate-600 border-slate-200/80',
        };
    }

    /** Color para el punto indicador del estado. */
    public static function dotClasses(?string $status): string
    {
        return match ((string)$status) {
            'enviada' => 'bg-blue-500',
            'aceptada' => 'bg-emerald-500',
            'rechazada' => 'bg-rose-500',
            default => 'bg-slate-400',
        };
    }
}

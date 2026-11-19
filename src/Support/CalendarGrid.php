<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * Cálculo de la cuadrícula de un mes para el calendario (semanas de lunes a domingo).
 */
final class CalendarGrid
{
    public const CELLS = 42;

    /** Normaliza YYYY-MM; si no es válido devuelve el mes actual. */
    public static function normalizeMonth(?string $month, ?DateTimeImmutable $now = null): string
    {
        $month = trim((string)$month);
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $matches)) {
            $year = (int)$matches[1];
            if ($year >= 1970 && $year <= 2100) {
                return sprintf('%04d-%02d', $year, (int)$matches[2]);
            }
        }

        return ($now ?? new DateTimeImmutable())->format('Y-m');
    }

    /**
     * @return array{month:string,start:DateTimeImmutable,end:DateTimeImmutable,monthStart:DateTimeImmutable,monthEnd:DateTimeImmutable}
     */
    public static function forMonth(?string $month, ?DateTimeImmutable $now = null): array
    {
        $normalized = self::normalizeMonth($month, $now);
        $monthStart = new DateTimeImmutable($normalized . '-01');
        $monthEnd = $monthStart->modify('last day of this month');

        // Semana que empieza en lunes: N devuelve 1 (lunes) a 7 (domingo)
        $offset = (int)$monthStart->format('N') - 1;
        $start = $monthStart->modify('-' . $offset . ' days');

        return [
            'month' => $normalized,
            'start' => $start,
            'end' => $start->modify('+' . (self::CELLS - 1) . ' days'),
            'monthStart' => $monthStart,
            'monthEnd' => $monthEnd,
        ];
    }

    /** @return string[] Fechas Y-m-d de las 6 semanas de la cuadrícula. */
    public static function dates(?string $month, ?DateTimeImmutable $now = null): array
    {
        $grid = self::forMonth($month, $now);
        $dates = [];
        for ($i = 0; $i < self::CELLS; $i++) {
            $dates[] = $grid['start']->modify('+' . $i . ' days')->format('Y-m-d');
        }

        return $dates;
    }
}

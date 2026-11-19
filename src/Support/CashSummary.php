<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * Cálculos puros del módulo de caja, sin acceso a base de datos.
 */
final class CashSummary
{
    private const MONTH_LABELS = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    public static function net(float $income, float $expense): float
    {
        return round($income - $expense, 2);
    }

    public static function balance(float $opening, float $income, float $expense): float
    {
        return round($opening + $income - $expense, 2);
    }

    /** Importe con signo: los egresos restan. */
    public static function signed(string $type, float $amount): float
    {
        return round($type === 'egreso' ? -abs($amount) : abs($amount), 2);
    }

    /**
     * Lista de meses entre dos fechas, ambos incluidos.
     *
     * @return array<string, string> Clave YYYY-MM => etiqueta "Jun 2026"
     */
    public static function months(string $from, string $to): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false) {
            return [];
        }

        $start = $start->modify('first day of this month');
        $end = $end->modify('first day of this month');
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $months = [];
        $cursor = $start;
        $guard = 0;
        while ($cursor <= $end && $guard < 240) {
            $key = $cursor->format('Y-m');
            $months[$key] = self::monthLabel($key);
            $cursor = $cursor->modify('+1 month');
            $guard++;
        }

        return $months;
    }

    public static function monthLabel(string $yearMonth): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
            return $yearMonth;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);
        if ($date === false) {
            return $yearMonth;
        }

        return self::MONTH_LABELS[(int)$date->format('n')] . ' ' . $date->format('Y');
    }
}

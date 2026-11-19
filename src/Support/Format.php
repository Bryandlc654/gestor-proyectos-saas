<?php

namespace App\Support;

final class Format
{
    /** Monedas admitidas en la aplicación */
    public const CURRENCIES = ['USD', 'EUR', 'MXN', 'PEN', 'COP', 'CLP', 'ARS'];

    private const CURRENCY_SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'MXN' => '$',
        'PEN' => 'S/',
        'COP' => '$',
        'CLP' => '$',
        'ARS' => '$',
        'GBP' => '£',
        'BRL' => 'R$',
    ];

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

    // Importe con su moneda y símbolo: "EUR €1,250.00"
    public static function money(float $amount, ?string $currency = 'USD'): string
    {
        $currency = self::normalizeCurrency($currency);
        $symbol = self::CURRENCY_SYMBOLS[$currency] ?? '';
        return trim($currency . ' ' . $symbol . number_format($amount, 2, '.', ','));
    }

    public static function currencySymbol(?string $currency): string
    {
        return self::CURRENCY_SYMBOLS[self::normalizeCurrency($currency)] ?? '';
    }

    public static function normalizeCurrency(?string $currency): string
    {
        $currency = strtoupper(trim((string)$currency));
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }

    public static function isValidCurrency(?string $currency): bool
    {
        return in_array(strtoupper(trim((string)$currency)), self::CURRENCIES, true);
    }

    /**
     * Fecha ISO (AAAA-MM-DD) que existe en el calendario. Rechaza 2026-02-31 o 2026-13-45,
     * que MySQL sin modo estricto guardaría como 0000-00-00 (y rompen el cronograma).
     */
    public static function isIsoDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return (int)$m[1] >= 1000 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
}

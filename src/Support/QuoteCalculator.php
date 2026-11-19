<?php

namespace App\Support;

/**
 * Cálculos de una cotización. Los importes siempre se recalculan en el servidor:
 * el navegador solo muestra una previsualización de los totales.
 */
final class QuoteCalculator
{
    public const DISCOUNT_TYPES = ['none', 'percent', 'fixed'];

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{
     *     items: array<int, array{quantity: float, unit_price: float, total: float}>,
     *     subtotal: float,
     *     discount_type: string,
     *     discount_value: float,
     *     discount_amount: float,
     *     tax_rate: float,
     *     tax_amount: float,
     *     total: float
     * }
     */
    public static function totals(
        array $items,
        string $discountType = 'none',
        float $discountValue = 0.0,
        float $taxRate = 0.0
    ): array {
        $normalized = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $quantity = max(0.0, round((float)($item['quantity'] ?? 0), 2));
            $unitPrice = max(0.0, round((float)($item['unit_price'] ?? 0), 2));
            $lineTotal = round($quantity * $unitPrice, 2);

            $normalized[] = [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $lineTotal,
            ];
            $subtotal += $lineTotal;
        }

        $subtotal = round($subtotal, 2);
        $discountType = in_array($discountType, self::DISCOUNT_TYPES, true) ? $discountType : 'none';
        $discountValue = max(0.0, round($discountValue, 2));

        $discountAmount = match ($discountType) {
            'percent' => round($subtotal * min(100.0, $discountValue) / 100, 2),
            'fixed' => round(min($subtotal, $discountValue), 2),
            default => 0.0,
        };

        $taxable = round(max(0.0, $subtotal - $discountAmount), 2);
        $taxRate = min(100.0, max(0.0, round($taxRate, 2)));
        $taxAmount = round($taxable * $taxRate / 100, 2);

        return [
            'items' => $normalized,
            'subtotal' => $subtotal,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => round($taxable + $taxAmount, 2),
        ];
    }
}

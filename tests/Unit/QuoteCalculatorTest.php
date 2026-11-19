<?php

namespace Tests\Unit;

use App\Support\QuoteCalculator;
use PHPUnit\Framework\TestCase;

final class QuoteCalculatorTest extends TestCase
{
    public function testCalculatesLinesDiscountAndTax(): void
    {
        $totals = QuoteCalculator::totals([
            ['quantity' => 2, 'unit_price' => 100],
            ['quantity' => 1, 'unit_price' => 50],
        ], 'percent', 10, 16);

        $this->assertSame(250.0, $totals['subtotal']);
        $this->assertSame(25.0, $totals['discount_amount']);
        $this->assertSame(36.0, $totals['tax_amount']);
        $this->assertSame(261.0, $totals['total']);
        $this->assertSame([200.0, 50.0], array_column($totals['items'], 'total'));
    }

    public function testFixedDiscountNeverExceedsSubtotal(): void
    {
        $totals = QuoteCalculator::totals([
            ['quantity' => 1, 'unit_price' => 80],
        ], 'fixed', 500, 0);

        $this->assertSame(80.0, $totals['discount_amount']);
        $this->assertSame(0.0, $totals['total']);
    }

    public function testPercentDiscountIsCappedAtOneHundred(): void
    {
        $totals = QuoteCalculator::totals([
            ['quantity' => 3, 'unit_price' => 33.33],
        ], 'percent', 150, 0);

        $this->assertSame(99.99, $totals['subtotal']);
        $this->assertSame(99.99, $totals['discount_amount']);
        $this->assertSame(0.0, $totals['total']);
    }

    public function testNegativeAndUnknownValuesAreNormalized(): void
    {
        $totals = QuoteCalculator::totals([
            ['quantity' => -2, 'unit_price' => 50],
            ['quantity' => 1, 'unit_price' => -10],
            ['quantity' => 1.005, 'unit_price' => 1.005],
        ], 'otro', -5, -3);

        $this->assertSame(1.02, $totals['subtotal']);
        $this->assertSame('none', $totals['discount_type']);
        $this->assertSame(0.0, $totals['discount_amount']);
        $this->assertSame(0.0, $totals['tax_rate']);
        $this->assertSame(1.02, $totals['total']);
        $this->assertSame(1.01, $totals['items'][2]['quantity']);
        $this->assertSame(1.01, $totals['items'][2]['unit_price']);
        $this->assertSame(1.02, $totals['items'][2]['total']);
    }

    public function testRoundsTaxToTwoDecimals(): void
    {
        $totals = QuoteCalculator::totals([
            ['quantity' => 1, 'unit_price' => 10.10],
        ], 'none', 0, 21);

        $this->assertSame(10.10, $totals['subtotal']);
        $this->assertSame(2.12, $totals['tax_amount']);
        $this->assertSame(12.22, $totals['total']);
    }
}

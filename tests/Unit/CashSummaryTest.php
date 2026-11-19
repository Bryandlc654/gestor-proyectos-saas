<?php

namespace Tests\Unit;

use App\Support\CashSummary;
use PHPUnit\Framework\TestCase;

final class CashSummaryTest extends TestCase
{
    public function testNetAndBalance(): void
    {
        $this->assertSame(250.0, CashSummary::net(1000.0, 750.0));
        $this->assertSame(-125.5, CashSummary::net(100.0, 225.5));
        $this->assertSame(1375.0, CashSummary::balance(500.0, 1000.0, 125.0));
        $this->assertSame(-50.0, CashSummary::balance(0.0, 100.0, 150.0));
    }

    public function testSignedAmounts(): void
    {
        $this->assertSame(120.0, CashSummary::signed('ingreso', 120.0));
        $this->assertSame(-120.0, CashSummary::signed('egreso', 120.0));
        $this->assertSame(-120.0, CashSummary::signed('egreso', -120.0));
    }

    public function testMonthsRangeIncludesBothEnds(): void
    {
        $months = CashSummary::months('2026-06-15', '2026-11-02');

        $this->assertSame([
            '2026-06' => 'Jun 2026',
            '2026-07' => 'Jul 2026',
            '2026-08' => 'Ago 2026',
            '2026-09' => 'Sep 2026',
            '2026-10' => 'Oct 2026',
            '2026-11' => 'Nov 2026',
        ], $months);
    }

    public function testMonthsSwapsReversedDates(): void
    {
        $months = CashSummary::months('2026-03-20', '2026-01-05');
        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_keys($months));
    }

    public function testInvalidDatesReturnEmpty(): void
    {
        $this->assertSame([], CashSummary::months('no-es-fecha', '2026-01-01'));
        $this->assertSame('2026-99', CashSummary::monthLabel('2026-99'));
    }
}

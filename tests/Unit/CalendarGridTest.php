<?php

namespace Tests\Unit;

use App\Support\CalendarGrid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CalendarGridTest extends TestCase
{
    public function testNormalizeMonthFallsBackToCurrentMonth(): void
    {
        $now = new DateTimeImmutable('2026-11-09');

        $this->assertSame('2026-11', CalendarGrid::normalizeMonth(null, $now));
        $this->assertSame('2026-11', CalendarGrid::normalizeMonth('2026-13', $now));
        $this->assertSame('2026-11', CalendarGrid::normalizeMonth('texto', $now));
        $this->assertSame('2026-02', CalendarGrid::normalizeMonth('2026-02', $now));
    }

    public function testGridStartsOnMondayAndCoversSixWeeks(): void
    {
        // Febrero 2026 empieza en domingo: la cuadrícula arranca el lunes 26 de enero.
        $grid = CalendarGrid::forMonth('2026-02');
        $dates = CalendarGrid::dates('2026-02');

        $this->assertSame('2026-01-26', $grid['start']->format('Y-m-d'));
        $this->assertSame('2026-03-08', $grid['end']->format('Y-m-d'));
        $this->assertCount(CalendarGrid::CELLS, $dates);
        $this->assertSame('2026-01-26', $dates[0]);
        $this->assertSame('2026-03-08', $dates[41]);
    }

    public function testGridKeepsMondayWhenMonthStartsWithMonday(): void
    {
        // Junio 2026 empieza en lunes.
        $grid = CalendarGrid::forMonth('2026-06');

        $this->assertSame('2026-06-01', $grid['start']->format('Y-m-d'));
        $this->assertSame('2026-07-12', $grid['end']->format('Y-m-d'));
    }

    public function testFebruaryOfLeapYearIncludesTwentyNineDays(): void
    {
        $grid = CalendarGrid::forMonth('2028-02');

        $this->assertSame('2028-02-01', $grid['monthStart']->format('Y-m-d'));
        $this->assertSame('2028-02-29', $grid['monthEnd']->format('Y-m-d'));
    }
}

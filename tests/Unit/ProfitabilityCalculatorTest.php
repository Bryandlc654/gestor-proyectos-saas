<?php

namespace Tests\Unit;

use App\Support\ProfitabilityCalculator;
use PHPUnit\Framework\TestCase;

final class ProfitabilityCalculatorTest extends TestCase
{
    public function testCalculatesProjectProfitability(): void
    {
        $project = ['budget' => 10000, 'currency' => 'USD'];
        $invoices = [
            ['amount' => 4000, 'currency' => 'USD', 'status' => 'pagada'],
            ['amount' => 1000, 'currency' => 'USD', 'status' => 'pendiente'],
            ['amount' => 500, 'currency' => 'USD', 'status' => 'anulada'],
        ];
        $payables = [
            ['amount' => 1500, 'paid_amount' => 500, 'currency' => 'USD', 'kind' => 'direct', 'status' => 'pendiente'],
            ['amount' => 800, 'paid_amount' => 800, 'currency' => 'USD', 'kind' => 'collaborator', 'status' => 'pagada'],
        ];

        $metrics = ProfitabilityCalculator::metrics($project, $invoices, $payables);

        $this->assertSame(5000.0, $metrics['income']['USD']);
        $this->assertSame(4000.0, $metrics['collected']['USD']);
        $this->assertSame(1000.0, $metrics['pending']['USD']);
        $this->assertSame(1500.0, $metrics['direct_expenses']['USD']);
        $this->assertSame(500.0, $metrics['direct_paid']['USD']);
        $this->assertSame(1000.0, $metrics['direct_pending']['USD']);
        $this->assertSame(800.0, $metrics['collaborator_payments']['USD']);
        $this->assertSame(800.0, $metrics['collaborator_paid']['USD']);
        $this->assertSame(2700.0, $metrics['margin']['USD']);
        $this->assertSame(54.0, $metrics['margin_percent']);
        $this->assertFalse($metrics['has_other_currencies']);
        $this->assertSame(['invoices' => 2, 'payables' => 2, 'direct' => 1, 'collaborator' => 1], $metrics['counts']);
    }

    public function testKeepsCurrenciesSeparated(): void
    {
        $project = ['budget' => 1000, 'currency' => 'USD'];
        $metrics = ProfitabilityCalculator::metrics(
            $project,
            [['amount' => 500, 'currency' => 'EUR', 'status' => 'pendiente']],
            [['amount' => 100, 'paid_amount' => 0, 'currency' => 'USD', 'kind' => 'direct', 'status' => 'pendiente']]
        );

        $this->assertSame(500.0, $metrics['income']['EUR']);
        $this->assertSame(-100.0, $metrics['margin']['USD']);
        $this->assertSame(500.0, $metrics['margin']['EUR']);
        $this->assertSame(-100.0, $metrics['margin_project']);
        $this->assertNull($metrics['margin_percent']);
        $this->assertTrue($metrics['has_other_currencies']);
    }

    public function testAggregatedTotalsMatchRowCalculation(): void
    {
        $project = ['budget' => 2500, 'currency' => 'USD'];
        $fromRows = ProfitabilityCalculator::metrics(
            $project,
            [
                ['amount' => 1200, 'currency' => 'USD', 'status' => 'pagada'],
                ['amount' => 300, 'currency' => 'USD', 'status' => 'pendiente'],
            ],
            [
                ['amount' => 400, 'paid_amount' => 100, 'currency' => 'USD', 'kind' => 'direct', 'status' => 'pendiente'],
                ['amount' => 350, 'paid_amount' => 350, 'currency' => 'USD', 'kind' => 'collaborator', 'status' => 'pagada'],
            ]
        );

        $fromSql = ProfitabilityCalculator::metricsFromAggregates(
            $project,
            [
                ['currency' => 'USD', 'income' => 1500, 'collected' => 1200, 'pending' => 300, 'count' => 2],
            ],
            [
                ['currency' => 'USD', 'kind' => 'direct', 'total_amount' => 400, 'paid_amount' => 100, 'count' => 1],
                ['currency' => 'USD', 'kind' => 'collaborator', 'total_amount' => 350, 'paid_amount' => 350, 'count' => 1],
            ]
        );

        foreach (['income', 'collected', 'pending', 'direct_expenses', 'direct_paid', 'collaborator_payments', 'collaborator_paid', 'margin'] as $key) {
            $this->assertSame($fromRows[$key], $fromSql[$key], $key);
        }
        $this->assertSame(750.0, $fromSql['margin']['USD']);
        $this->assertSame(50.0, $fromSql['margin_percent']);
        $this->assertSame($fromRows['counts'], $fromSql['counts']);
    }

    public function testPortfolioTotals(): void
    {
        $totals = ProfitabilityCalculator::totals([
            [
                'budget_by_currency' => ['USD' => 1000],
                'income' => ['USD' => 2000],
                'collected' => ['USD' => 1500],
                'pending' => ['USD' => 500],
                'direct_expenses' => ['USD' => 400],
                'collaborator_payments' => ['USD' => 600],
                'margin' => ['USD' => 1000],
                'margin_percent' => 50.0,
                'margin_project' => 1000,
            ],
            [
                'budget_by_currency' => ['USD' => 500],
                'income' => ['USD' => 300],
                'collected' => ['USD' => 0],
                'pending' => ['USD' => 300],
                'direct_expenses' => ['USD' => 800],
                'collaborator_payments' => [],
                'margin' => ['USD' => -500],
                'margin_percent' => -166.7,
                'margin_project' => -500,
            ],
        ]);

        $this->assertSame(1500.0, $totals['budget']['USD']);
        $this->assertSame(2300.0, $totals['income']['USD']);
        $this->assertSame(500.0, $totals['margin']['USD']);
        $this->assertSame(2, $totals['project_count']);
        $this->assertSame(1, $totals['positive_margin_projects']);
        $this->assertSame(1, $totals['negative_margin_projects']);
    }
}

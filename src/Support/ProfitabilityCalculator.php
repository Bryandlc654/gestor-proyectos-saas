<?php

namespace App\Support;

/**
 * Calcula la rentabilidad de un proyecto a partir de sus facturas y cuentas por pagar.
 * Trabaja con mapas por moneda para no sumar importes de monedas distintas.
 */
final class ProfitabilityCalculator
{
    /**
     * Calcula desde filas individuales (útil para la pestaña de finanzas de un proyecto).
     *
     * @param array{budget?:mixed,currency?:mixed} $project
     * @param array<int, array{amount?:mixed,currency?:mixed,status?:mixed}> $invoices
     * @param array<int, array{amount?:mixed,paid_amount?:mixed,currency?:mixed,kind?:mixed,status?:mixed}> $payables
     */
    public static function metrics(array $project, array $invoices = [], array $payables = []): array
    {
        $projectCurrency = Format::normalizeCurrency((string)($project['currency'] ?? 'USD'));
        $income = [];
        $collected = [];
        $pending = [];
        $directExpenses = [];
        $directPaid = [];
        $directPending = [];
        $collaboratorPayments = [];
        $collaboratorPaid = [];
        $collaboratorPending = [];
        $counts = ['invoices' => 0, 'payables' => 0, 'direct' => 0, 'collaborator' => 0];

        foreach ($invoices as $invoice) {
            $status = (string)($invoice['status'] ?? 'pendiente');
            if ($status === 'anulada') {
                continue;
            }

            $amount = max(0.0, round((float)($invoice['amount'] ?? 0), 2));
            $paid = array_key_exists('paid_amount', $invoice)
                ? min($amount, max(0.0, round((float)$invoice['paid_amount'], 2)))
                : ($status === 'pagada' ? $amount : 0.0);
            if ($status === 'pagada' && $paid <= 0) {
                $paid = $amount;
            }
            $balance = max(0.0, round($amount - $paid, 2));
            $currency = Format::normalizeCurrency((string)($invoice['currency'] ?? $projectCurrency));

            $counts['invoices']++;
            self::add($income, $currency, $amount);
            self::add($collected, $currency, $paid);
            self::add($pending, $currency, $balance);
        }

        foreach ($payables as $payable) {
            $status = (string)($payable['status'] ?? 'pendiente');
            if ($status === 'anulada') {
                continue;
            }

            $amount = max(0.0, round((float)($payable['amount'] ?? 0), 2));
            $paid = min($amount, max(0.0, round((float)($payable['paid_amount'] ?? 0), 2)));
            $balance = max(0.0, round($amount - $paid, 2));
            $currency = Format::normalizeCurrency((string)($payable['currency'] ?? $projectCurrency));
            $kind = (string)($payable['kind'] ?? 'direct');

            $counts['payables']++;
            if ($kind === 'collaborator') {
                $counts['collaborator']++;
                self::add($collaboratorPayments, $currency, $amount);
                self::add($collaboratorPaid, $currency, $paid);
                self::add($collaboratorPending, $currency, $balance);
            } else {
                $counts['direct']++;
                self::add($directExpenses, $currency, $amount);
                self::add($directPaid, $currency, $paid);
                self::add($directPending, $currency, $balance);
            }
        }

        return self::build(
            $project,
            $income,
            $collected,
            $pending,
            $directExpenses,
            $directPaid,
            $directPending,
            $collaboratorPayments,
            $collaboratorPaid,
            $collaboratorPending,
            $counts
        );
    }

    /**
     * Calcula desde totales agrupados en SQL (útil para la vista de cartera completa).
     *
     * @param array{budget?:mixed,currency?:mixed} $project
     * @param array<int, array{currency?:mixed,income?:mixed,collected?:mixed,pending?:mixed,count?:mixed}> $invoiceTotals
     * @param array<int, array{kind?:mixed,currency?:mixed,total_amount?:mixed,paid_amount?:mixed,count?:mixed}> $payableTotals
     */
    public static function metricsFromAggregates(array $project, array $invoiceTotals = [], array $payableTotals = []): array
    {
        $projectCurrency = Format::normalizeCurrency((string)($project['currency'] ?? 'USD'));
        $income = [];
        $collected = [];
        $pending = [];
        $directExpenses = [];
        $directPaid = [];
        $directPending = [];
        $collaboratorPayments = [];
        $collaboratorPaid = [];
        $collaboratorPending = [];
        $counts = ['invoices' => 0, 'payables' => 0, 'direct' => 0, 'collaborator' => 0];

        foreach ($invoiceTotals as $row) {
            $currency = Format::normalizeCurrency((string)($row['currency'] ?? $projectCurrency));
            $counts['invoices'] += (int)($row['count'] ?? 0);
            self::add($income, $currency, max(0.0, (float)($row['income'] ?? 0)));
            self::add($collected, $currency, max(0.0, (float)($row['collected'] ?? 0)));
            self::add($pending, $currency, max(0.0, (float)($row['pending'] ?? 0)));
        }

        foreach ($payableTotals as $row) {
            $currency = Format::normalizeCurrency((string)($row['currency'] ?? $projectCurrency));
            $amount = max(0.0, (float)($row['total_amount'] ?? 0));
            $paid = min($amount, max(0.0, (float)($row['paid_amount'] ?? 0)));
            $balance = max(0.0, round($amount - $paid, 2));
            $kind = (string)($row['kind'] ?? 'direct');
            $count = (int)($row['count'] ?? 0);

            $counts['payables'] += $count;
            if ($kind === 'collaborator') {
                $counts['collaborator'] += $count;
                self::add($collaboratorPayments, $currency, $amount);
                self::add($collaboratorPaid, $currency, $paid);
                self::add($collaboratorPending, $currency, $balance);
            } else {
                $counts['direct'] += $count;
                self::add($directExpenses, $currency, $amount);
                self::add($directPaid, $currency, $paid);
                self::add($directPending, $currency, $balance);
            }
        }

        return self::build(
            $project,
            $income,
            $collected,
            $pending,
            $directExpenses,
            $directPaid,
            $directPending,
            $collaboratorPayments,
            $collaboratorPaid,
            $collaboratorPending,
            $counts
        );
    }

    /**
     * Suma los mapas por moneda de varios proyectos (cartera de la agencia).
     *
     * @param array<int, array<string, mixed>> $projects
     * @return array<string, mixed>
     */
    public static function totals(array $projects): array
    {
        $totals = [
            'budget' => [],
            'income' => [],
            'collected' => [],
            'pending' => [],
            'direct_expenses' => [],
            'collaborator_payments' => [],
            'margin' => [],
            'project_count' => count($projects),
            'positive_margin_projects' => 0,
            'negative_margin_projects' => 0,
        ];

        foreach ($projects as $metrics) {
            foreach (['budget_by_currency' => 'budget', 'income' => 'income', 'collected' => 'collected', 'pending' => 'pending', 'direct_expenses' => 'direct_expenses', 'collaborator_payments' => 'collaborator_payments', 'margin' => 'margin'] as $source => $target) {
                foreach ((array)($metrics[$source] ?? []) as $currency => $amount) {
                    if ($target === 'margin') {
                        self::addSigned($totals[$target], (string)$currency, (float)$amount);
                    } else {
                        self::add($totals[$target], (string)$currency, (float)$amount);
                    }
                }
            }
            $projectMargin = (float)($metrics['margin_project'] ?? 0);
            if ($projectMargin > 0.005) {
                $totals['positive_margin_projects']++;
            } elseif ($projectMargin < -0.005) {
                $totals['negative_margin_projects']++;
            }
        }

        return $totals;
    }

    /**
     * @param array{budget?:mixed,currency?:mixed} $project
     * @param array<string, float> $income
     * @param array<string, float> $collected
     * @param array<string, float> $pending
     * @param array<string, float> $directExpenses
     * @param array<string, float> $directPaid
     * @param array<string, float> $directPending
     * @param array<string, float> $collaboratorPayments
     * @param array<string, float> $collaboratorPaid
     * @param array<string, float> $collaboratorPending
     * @param array{invoices:int,payables:int,direct:int,collaborator:int} $counts
     */
    private static function build(
        array $project,
        array $income,
        array $collected,
        array $pending,
        array $directExpenses,
        array $directPaid,
        array $directPending,
        array $collaboratorPayments,
        array $collaboratorPaid,
        array $collaboratorPending,
        array $counts
    ): array {
        $currency = Format::normalizeCurrency((string)($project['currency'] ?? 'USD'));
        $budget = max(0.0, round((float)($project['budget'] ?? 0), 2));

        $margin = [];
        foreach (self::currencies($income, $directExpenses, $collaboratorPayments) as $code) {
            $value = round(
                ($income[$code] ?? 0) - ($directExpenses[$code] ?? 0) - ($collaboratorPayments[$code] ?? 0),
                2
            );
            if (abs($value) >= 0.005) {
                $margin[$code] = $value;
            }
        }

        $incomeProject = round((float)($income[$currency] ?? 0), 2);
        $marginProject = round((float)($margin[$currency] ?? 0), 2);
        $marginPercent = $incomeProject > 0 ? round($marginProject / $incomeProject * 100, 1) : null;

        $usedCurrencies = self::currencies(
            $income,
            $collected,
            $pending,
            $directExpenses,
            $collaboratorPayments,
            $margin
        );
        $hasOtherCurrencies = false;
        foreach ($usedCurrencies as $code) {
            if ($code !== $currency) {
                $hasOtherCurrencies = true;
                break;
            }
        }

        return [
            'currency' => $currency,
            'budget' => $budget,
            'budget_by_currency' => $budget > 0 ? [$currency => $budget] : [],
            'income' => $income,
            'collected' => $collected,
            'pending' => $pending,
            'direct_expenses' => $directExpenses,
            'direct_paid' => $directPaid,
            'direct_pending' => $directPending,
            'collaborator_payments' => $collaboratorPayments,
            'collaborator_paid' => $collaboratorPaid,
            'collaborator_pending' => $collaboratorPending,
            'margin' => $margin,
            'income_project' => $incomeProject,
            'margin_project' => $marginProject,
            'margin_percent' => $marginPercent,
            'has_other_currencies' => $hasOtherCurrencies,
            'counts' => $counts,
        ];
    }

    /** @param array<string, float> $map */
    private static function add(array &$map, string $currency, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $map[$currency] = round(($map[$currency] ?? 0) + $amount, 2);
    }

    /** @param array<string, float> $map */
    private static function addSigned(array &$map, string $currency, float $amount): void
    {
        if (abs($amount) < 0.005) {
            return;
        }
        $map[$currency] = round(($map[$currency] ?? 0) + $amount, 2);
    }

    /** @param array<string, float> ...$maps */
    private static function currencies(array ...$maps): array
    {
        $codes = [];
        foreach ($maps as $map) {
            foreach (array_keys($map) as $code) {
                $codes[$code] = true;
            }
        }
        return array_keys($codes);
    }
}

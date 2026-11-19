<?php

namespace App\Service;

use App\Support\ProfitabilityCalculator;
use PDO;

/**
 * Rentabilidad por proyecto, siempre acotada a la agencia y a los proyectos indicados.
 * Las agregaciones se hacen en SQL para que el coste no crezca con el número de facturas.
 */
final class ProfitabilityService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param int[] $projectIds Proyectos ya autorizados para el usuario
     * @return array<int, array<string, mixed>> Métricas indexadas por id de proyecto
     */
    public function forProjects(int $agencyId, array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn(int $id): bool => $id > 0)));
        if ($agencyId <= 0 || $projectIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $this->db->prepare("
            SELECT id, name, color, status, budget, currency
            FROM projects
            WHERE agency_id = ? AND id IN ($placeholders)
        ");
        $stmt->execute([$agencyId, ...$projectIds]);

        $projects = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $projects[(int)$row['id']] = $row;
        }
        if ($projects === []) {
            return [];
        }

        $validIds = array_keys($projects);
        $validPlaceholders = implode(',', array_fill(0, count($validIds), '?'));

        $invoiceStmt = $this->db->prepare("
            SELECT project_id,
                   UPPER(COALESCE(currency, 'USD')) AS currency,
                   COALESCE(SUM(CASE WHEN status <> 'anulada' THEN amount ELSE 0 END), 0) AS income,
                   COALESCE(SUM(CASE WHEN status <> 'anulada' THEN paid_amount ELSE 0 END), 0) AS collected,
                   COALESCE(SUM(CASE WHEN status <> 'anulada' THEN GREATEST(amount - paid_amount, 0) ELSE 0 END), 0) AS pending,
                   COALESCE(SUM(CASE WHEN status <> 'anulada' THEN 1 ELSE 0 END), 0) AS `count`
            FROM invoices
            WHERE agency_id = ? AND project_id IN ($validPlaceholders)
            GROUP BY project_id, UPPER(COALESCE(currency, 'USD'))
        ");
        $invoiceStmt->execute([$agencyId, ...$validIds]);

        $invoicesByProject = [];
        foreach ($invoiceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $invoicesByProject[(int)$row['project_id']][] = $row;
        }

        $payableStmt = $this->db->prepare("
            SELECT project_id,
                   kind,
                   UPPER(COALESCE(currency, 'USD')) AS currency,
                   COALESCE(SUM(CASE WHEN status <> 'anulada' THEN amount ELSE 0 END), 0) AS total_amount,
                   COALESCE(SUM(CASE WHEN status <> 'anulada' THEN paid_amount ELSE 0 END), 0) AS paid_amount,
                   COALESCE(SUM(CASE WHEN status <> 'anulada' THEN 1 ELSE 0 END), 0) AS `count`
            FROM payables
            WHERE agency_id = ? AND project_id IN ($validPlaceholders)
            GROUP BY project_id, kind, UPPER(COALESCE(currency, 'USD'))
        ");
        $payableStmt->execute([$agencyId, ...$validIds]);

        $payablesByProject = [];
        foreach ($payableStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payablesByProject[(int)$row['project_id']][] = $row;
        }

        $result = [];
        foreach ($projects as $projectId => $project) {
            $result[$projectId] = ProfitabilityCalculator::metricsFromAggregates(
                $project,
                $invoicesByProject[$projectId] ?? [],
                $payablesByProject[$projectId] ?? []
            );
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forProject(int $agencyId, int $projectId): ?array
    {
        if ($projectId <= 0) {
            return null;
        }
        return $this->forProjects($agencyId, [$projectId])[$projectId] ?? null;
    }
}

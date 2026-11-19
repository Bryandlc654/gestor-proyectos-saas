<?php

namespace App\Service;

use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Support\Format;
use App\Support\SimplePdf;
use PDO;

/** Construcción de datasets y exportación CSV/PDF. */
final class ExportService
{
    public function __construct(
        private readonly PDO $db,
        private readonly ProjectRepository $projects,
        private readonly ActivityRepository $activity,
    ) {
    }

    /** @return array{title: string, headers: array<int, string>, rows: array<int, array<int, string>>, widths: array<int, float>} */
    public function tasks(int $userId, string $role, array $filters): array
    {
        $statusLabels = function_exists('taskStatusLabels')
            ? taskStatusLabels()
            : \App\Repository\TaskRepository::STATUS_LABELS;

        [$visible, $visibleParams] = $this->projects->visibleProjectsSql($userId, $role);
        $where = ["t.project_id IN ({$visible})"];
        $params = $visibleParams;

        $projectId = (int)($filters['project_id'] ?? 0);
        if ($projectId > 0) {
            $where[] = 't.project_id = ?';
            $params[] = $projectId;
        }
        $priority = (string)($filters['priority'] ?? '');
        if (in_array($priority, ['baja', 'media', 'alta', 'urgente'], true)) {
            $where[] = 't.priority = ?';
            $params[] = $priority;
        }
        $assigned = (string)($filters['assigned_to'] ?? '');
        if ($assigned === 'sin_asignar') {
            $where[] = 't.assigned_user_id IS NULL';
        } elseif ($assigned !== '' && ctype_digit($assigned)) {
            $where[] = 't.assigned_user_id = ?';
            $params[] = (int)$assigned;
        }
        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(t.title LIKE ? OR t.description LIKE ? OR p.name LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $stmt = $this->db->prepare("
            SELECT t.title, p.name AS project_name, COALESCE(au.name, t.assigned_to) AS assignee,
                   t.priority, t.status, t.due_date, t.created_at
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users au ON au.id = t.assigned_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.sort_order ASC, t.id ASC
        ");
        $stmt->execute($params);

        $rows = array_map(static fn(array $row) => [
            $row['title'],
            $row['project_name'],
            $row['assignee'] ?: 'Sin asignar',
            $row['priority'],
            $statusLabels[$row['status']] ?? str_replace('_', ' ', $row['status']),
            $row['due_date'] ? Format::date($row['due_date']) : '-',
            Format::date($row['created_at']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'title' => 'Tareas',
            'headers' => ['Tarea', 'Proyecto', 'Responsable', 'Prioridad', 'Estado', 'Vencimiento', 'Creada'],
            'rows' => $rows,
            'widths' => [150, 100, 85, 55, 65, 70, 65],
        ];
    }

    /** @return array{title: string, headers: array<int, string>, rows: array<int, array<int, string>>, widths: array<int, float>} */
    public function invoices(int $agencyId, int $userId, string $role, array $filters): array
    {
        [$visible, $visibleParams] = $this->projects->visibleProjectsSql($userId, $role);
        $where = ['i.agency_id = ?', "(i.project_id IN ({$visible}) OR i.project_id IS NULL)"];
        $params = [$agencyId, ...$visibleParams];

        $status = (string)($filters['status'] ?? '');
        if (in_array($status, ['pendiente', 'pagada', 'anulada'], true)) {
            $where[] = 'i.status = ?';
            $params[] = $status;
        }
        $projectId = (int)($filters['project_id'] ?? 0);
        if ($projectId > 0) {
            $where[] = 'i.project_id = ?';
            $params[] = $projectId;
        }
        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(i.invoice_number LIKE ? OR i.concept LIKE ? OR p.name LIKE ? OR i.client_name LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $stmt = $this->db->prepare("
            SELECT i.invoice_number, i.client_name, p.name AS project_name, i.concept, i.amount, i.paid_amount, i.currency,
                   i.status, i.due_date, i.paid_at, i.created_at
            FROM invoices i
            LEFT JOIN projects p ON p.id = i.project_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (i.status = 'pendiente') DESC, i.due_date ASC, i.id DESC
        ");
        $stmt->execute($params);

        $rows = array_map(static fn(array $row) => [
            $row['invoice_number'],
            $row['project_name'] ?: 'Sin proyecto',
            $row['client_name'] ?: '-',
            $row['concept'],
            number_format((float)$row['amount'], 2, '.', ''),
            number_format((float)$row['paid_amount'], 2, '.', ''),
            number_format(max(0, (float)$row['amount'] - (float)$row['paid_amount']), 2, '.', ''),
            $row['currency'],
            $row['status'],
            $row['due_date'] ? Format::date($row['due_date']) : '-',
            $row['paid_at'] ? Format::date($row['paid_at']) : '-',
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return [
            'title' => 'Facturas',
            'headers' => ['Número', 'Proyecto', 'Cliente', 'Concepto', 'Importe', 'Cobrado', 'Saldo', 'Moneda', 'Estado', 'Vencimiento', 'Pagada'],
            'rows' => $rows,
            'widths' => [70, 85, 90, 130, 55, 55, 55, 40, 55, 65, 55],
        ];
    }

    /** @return array{title: string, headers: array<int, string>, rows: array<int, array<int, string>>, widths: array<int, float>} */
    public function audit(int $agencyId, array $filters): array
    {
        $items = $this->activity->forAgency(
            $agencyId,
            500,
            (string)($filters['action'] ?? '') ?: null,
            (int)($filters['project_id'] ?? 0) ?: null
        );

        $rows = array_map(static fn(array $item) => [
            $item['created_at'],
            $item['label'],
            $item['user_name'] ?? 'Sistema',
            $item['project_name'] ?? '-',
            $item['description'] ?? '',
        ], $items);

        return [
            'title' => 'Auditoría',
            'headers' => ['Fecha', 'Acción', 'Usuario', 'Proyecto', 'Detalle'],
            'rows' => $rows,
            'widths' => [85, 110, 80, 100, 170],
        ];
    }

    /** @param array<int, string> $headers @param array<int, array<int, string>> $rows */
    public function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map([$this, 'safeCsvCell'], $row));
        }
        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);
        return $content;
    }

    private function safeCsvCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }

    /** @param array<int, string> $headers @param array<int, array<int, string>> $rows @param array<int, float> $widths */
    public function pdf(string $title, array $headers, array $rows, array $widths): string
    {
        return SimplePdf::table($title, $headers, $rows, $widths);
    }
}

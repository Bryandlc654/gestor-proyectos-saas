<?php

namespace App\Service;

use App\Repository\ProjectRepository;
use App\Support\Format;
use PDO;

/**
 * Informes de agencia: proyectos, personas, clientes y vencimientos.
 * Los importes se agrupan por moneda para no mezclar divisas.
 */
final class ReportService
{
    public const TYPES = ['projects', 'people', 'clients', 'deadlines'];

    public const TYPE_LABELS = [
        'projects' => 'Proyectos',
        'people' => 'Personas',
        'clients' => 'Clientes',
        'deadlines' => 'Vencimientos',
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly ProjectRepository $projects,
    ) {
    }

    /**
     * @return array{title:string,headers:array<int,string>,rows:array<int,array<int,string>>,widths:array<int,float>,summary:array<string,mixed>,chart:array<string,mixed>}
     */
    public function dataset(string $type, int $agencyId, int $userId, string $role, array $filters): array
    {
        return match ($type) {
            'people' => $this->people($agencyId, $userId, $role, $filters),
            'clients' => $this->clients($agencyId, $userId, $role, $filters),
            'deadlines' => $this->deadlines($agencyId, $userId, $role, $filters),
            default => $this->projectsReport($agencyId, $userId, $role, $filters),
        };
    }

    /** @return array{from:string,to:string,project_id:int} */
    public function normalizeFilters(array $filters): array
    {
        $from = $this->date($filters['from'] ?? null) ?: date('Y-m-01');
        $to = $this->date($filters['to'] ?? null) ?: date('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from,
            'to' => $to,
            'project_id' => (int)($filters['project_id'] ?? 0),
        ];
    }

    public function projectsReport(int $agencyId, int $userId, string $role, array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $ids = $this->visibleIds($userId, $role);
        $headers = ['Proyecto', 'Estado', 'Tareas', 'Completadas', 'Vencidas', 'Presupuesto', 'Facturado', 'Cobrado', 'Por cobrar', 'Costos', 'Margen'];
        $widths = [140, 55, 45, 55, 50, 75, 70, 70, 70, 65, 65];

        if ($ids === []) {
            return $this->emptyDataset('Reporte por proyecto', $headers, $widths);
        }

        $in = $this->placeholders($ids);
        [$projectWhere, $projectParams] = $this->projectWhere($ids, $agencyId, $filters['project_id']);
        $stmt = $this->db->prepare("
            SELECT p.id, p.name, p.status, p.currency, p.budget
            FROM projects p
            WHERE {$projectWhere}
            ORDER BY p.name ASC
        ");
        $stmt->execute($projectParams);
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($projects === []) {
            return $this->emptyDataset('Reporte por proyecto', $headers, $widths);
        }

        $doneKeys = $this->doneKeys($agencyId);
        $doneSql = $this->quoteKeys($doneKeys);

        $stmtTasks = $this->db->prepare("
            SELECT t.project_id,
                   COUNT(*) AS total,
                   SUM(CASE WHEN t.status IN ({$doneSql}) THEN 1 ELSE 0 END) AS done,
                   SUM(CASE WHEN t.status NOT IN ({$doneSql}) AND t.due_date IS NOT NULL AND t.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue
            FROM tasks t
            WHERE t.project_id IN ({$in})
            GROUP BY t.project_id
        ");
        $stmtTasks->execute($ids);
        $taskStats = [];
        foreach ($stmtTasks->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $taskStats[(int)$row['project_id']] = $row;
        }

        $stmtInvoices = $this->db->prepare("
            SELECT i.project_id, i.currency,
                   SUM(CASE WHEN i.status <> 'anulada' AND DATE(i.created_at) BETWEEN ? AND ? THEN i.amount ELSE 0 END) AS invoiced,
                   COALESCE(SUM(ipay.paid), 0) AS collected,
                   SUM(CASE WHEN i.status = 'pendiente' THEN GREATEST(i.amount - i.paid_amount, 0) ELSE 0 END) AS pending
            FROM invoices i
            INNER JOIN projects p ON p.id = i.project_id
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS paid
                FROM invoice_payments
                WHERE paid_at BETWEEN ? AND ?
                GROUP BY invoice_id
            ) ipay ON ipay.invoice_id = i.id
            WHERE {$projectWhere}
            GROUP BY i.project_id, i.currency
        ");
        $stmtInvoices->execute(array_merge([$filters['from'], $filters['to'], $filters['from'], $filters['to']], $projectParams));
        $money = [];
        foreach ($stmtInvoices->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['project_id'];
            $currency = strtoupper((string)($row['currency'] ?: 'USD'));
            $money[$pid]['invoiced'][$currency] = ($money[$pid]['invoiced'][$currency] ?? 0) + (float)$row['invoiced'];
            $money[$pid]['collected'][$currency] = ($money[$pid]['collected'][$currency] ?? 0) + (float)$row['collected'];
            $money[$pid]['pending'][$currency] = ($money[$pid]['pending'][$currency] ?? 0) + (float)$row['pending'];
        }

        $stmtPayables = $this->db->prepare("
            SELECT pp.project_id, pp.currency,
                   SUM(CASE WHEN DATE(pp.created_at) BETWEEN ? AND ? THEN pp.amount ELSE 0 END) AS costs,
                   SUM(CASE WHEN pp.status = 'pendiente' THEN GREATEST(pp.amount - pp.paid_amount, 0) ELSE 0 END) AS outstanding
            FROM payables pp
            INNER JOIN projects p ON p.id = pp.project_id
            WHERE {$projectWhere}
            GROUP BY pp.project_id, pp.currency
        ");
        $stmtPayables->execute(array_merge([$filters['from'], $filters['to']], $projectParams));
        foreach ($stmtPayables->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['project_id'];
            $currency = strtoupper((string)($row['currency'] ?: 'USD'));
            $money[$pid]['costs'][$currency] = ($money[$pid]['costs'][$currency] ?? 0) + (float)$row['costs'];
            $money[$pid]['outstanding'][$currency] = ($money[$pid]['outstanding'][$currency] ?? 0) + (float)$row['outstanding'];
        }

        $stmtPayments = $this->db->prepare("
            SELECT pp.project_id, pay.currency, SUM(pay.amount) AS paid
            FROM payable_payments pay
            INNER JOIN payables pp ON pp.id = pay.payable_id
            INNER JOIN projects p ON p.id = pp.project_id
            WHERE {$projectWhere} AND pay.paid_at BETWEEN ? AND ?
            GROUP BY pp.project_id, pay.currency
        ");
        $stmtPayments->execute(array_merge($projectParams, [$filters['from'], $filters['to']]));
        foreach ($stmtPayments->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['project_id'];
            $currency = strtoupper((string)($row['currency'] ?: 'USD'));
            $money[$pid]['supplier_paid'][$currency] = ($money[$pid]['supplier_paid'][$currency] ?? 0) + (float)$row['paid'];
        }

        $rows = [];
        $chartLabels = [];
        $chartDone = [];
        $chartPending = [];
        $summary = [
            'projects' => 0, 'tasks' => 0, 'done' => 0, 'overdue' => 0,
            'invoiced' => [], 'collected' => [], 'pending' => [], 'costs' => [], 'margin' => [],
        ];

        foreach ($projects as $project) {
            $pid = (int)$project['id'];
            $stats = $taskStats[$pid] ?? ['total' => 0, 'done' => 0, 'overdue' => 0];
            $invoiced = $money[$pid]['invoiced'] ?? [];
            $collected = $money[$pid]['collected'] ?? [];
            $pending = $money[$pid]['pending'] ?? [];
            $costs = $money[$pid]['costs'] ?? [];
            $margin = $this->subtractCurrencies($collected, $money[$pid]['supplier_paid'] ?? []);

            $rows[] = [
                $project['name'],
                $project['status'],
                (string)(int)$stats['total'],
                (string)(int)$stats['done'],
                (string)(int)$stats['overdue'],
                formatMoney((float)$project['budget'], $project['currency'] ?: 'USD'),
                formatMoneyTotals($invoiced),
                formatMoneyTotals($collected),
                formatMoneyTotals($pending),
                formatMoneyTotals($costs),
                formatMoneyTotals($margin),
            ];

            $chartLabels[] = $project['name'];
            $chartDone[] = (int)$stats['done'];
            $chartPending[] = max(0, (int)$stats['total'] - (int)$stats['done']);
            $summary['projects']++;
            $summary['tasks'] += (int)$stats['total'];
            $summary['done'] += (int)$stats['done'];
            $summary['overdue'] += (int)$stats['overdue'];
            $this->mergeCurrencies($summary['invoiced'], $invoiced);
            $this->mergeCurrencies($summary['collected'], $collected);
            $this->mergeCurrencies($summary['pending'], $pending);
            $this->mergeCurrencies($summary['costs'], $costs);
            $this->mergeCurrencies($summary['margin'], $margin);
        }

        return [
            'title' => 'Reporte por proyecto',
            'headers' => $headers,
            'rows' => $rows,
            'widths' => $widths,
            'summary' => $summary,
            'chart' => [
                'type' => 'bar',
                'labels' => array_slice($chartLabels, 0, 8),
                'series' => [
                    ['label' => 'Completadas', 'data' => array_slice($chartDone, 0, 8), 'color' => '#22c55e'],
                    ['label' => 'Pendientes', 'data' => array_slice($chartPending, 0, 8), 'color' => '#94a3b8'],
                ],
            ],
        ];
    }

    public function people(int $agencyId, int $userId, string $role, array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $ids = $this->visibleIds($userId, $role);
        $headers = ['Persona', 'Rol', 'Proyectos', 'Tareas', 'Completadas', 'Activas', 'Vencidas', 'Tasa'];
        $widths = [120, 75, 55, 45, 55, 45, 50, 45];

        if ($ids === []) {
            return $this->emptyDataset('Reporte por persona', $headers, $widths);
        }

        [$projectWhere, $projectParams] = $this->projectWhere($ids, $agencyId, $filters['project_id']);
        $doneKeys = $this->doneKeys($agencyId);
        $doneSql = $this->quoteKeys($doneKeys);

        $stmtUsers = $this->db->prepare("
            SELECT id, name, role
            FROM users
            WHERE agency_id = ? AND role IN ('admin', 'project_manager', 'colaborador')
            ORDER BY name ASC
        ");
        $stmtUsers->execute([$agencyId]);
        $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

        $stmtStats = $this->db->prepare("
            SELECT t.assigned_user_id,
                   COUNT(*) AS total,
                   SUM(CASE WHEN t.status IN ({$doneSql}) THEN 1 ELSE 0 END) AS done,
                   SUM(CASE WHEN t.status NOT IN ({$doneSql}) AND t.due_date IS NOT NULL AND t.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
                   COUNT(DISTINCT t.project_id) AS projects
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE {$projectWhere} AND t.assigned_user_id IS NOT NULL
              AND DATE(t.created_at) BETWEEN ? AND ?
            GROUP BY t.assigned_user_id
        ");
        $stmtStats->execute(array_merge($projectParams, [$filters['from'], $filters['to']]));
        $stats = [];
        foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats[(int)$row['assigned_user_id']] = $row;
        }

        $roleLabels = ['admin' => 'Administrador', 'project_manager' => 'Project Manager', 'colaborador' => 'Colaborador'];
        $rows = [];
        $labels = [];
        $active = [];
        $summary = ['people' => 0, 'tasks' => 0, 'done' => 0, 'overdue' => 0];
        foreach ($users as $person) {
            $row = $stats[(int)$person['id']] ?? ['total' => 0, 'done' => 0, 'overdue' => 0, 'projects' => 0];
            $total = (int)$row['total'];
            $done = (int)$row['done'];
            $activeCount = max(0, $total - $done);
            $rate = $total > 0 ? (int)round(($done / $total) * 100) : 0;

            $rows[] = [
                $person['name'],
                $roleLabels[$person['role']] ?? $person['role'],
                (string)(int)$row['projects'],
                (string)$total,
                (string)$done,
                (string)$activeCount,
                (string)(int)$row['overdue'],
                $rate . '%',
            ];
            $labels[] = $person['name'];
            $active[] = $activeCount;
            $summary['people']++;
            $summary['tasks'] += $total;
            $summary['done'] += $done;
            $summary['overdue'] += (int)$row['overdue'];
        }

        return [
            'title' => 'Reporte por persona',
            'headers' => $headers,
            'rows' => $rows,
            'widths' => $widths,
            'summary' => $summary,
            'chart' => [
                'type' => 'bar',
                'labels' => $labels,
                'series' => [
                    ['label' => 'Tareas activas', 'data' => $active, 'color' => '#0E66FF'],
                ],
            ],
        ];
    }

    public function clients(int $agencyId, int $userId, string $role, array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $ids = $this->visibleIds($userId, $role);
        $headers = ['Cliente / Deudor', 'Facturas', 'Facturado', 'Cobrado', 'Pendiente', 'Última factura'];
        $widths = [130, 55, 75, 70, 70, 70];

        $in = $ids !== [] ? $this->placeholders($ids) : '0';
        $where = ["i.agency_id = ?", "(i.project_id IS NULL OR i.project_id IN ({$in}))"];
        $params = array_merge([$agencyId], $ids);
        if ($filters['project_id'] > 0) {
            $where[] = 'i.project_id = ?';
            $params[] = $filters['project_id'];
        }
        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT COALESCE(NULLIF(i.client_name, ''), 'Sin cliente') AS client_name,
                   i.currency,
                   COUNT(*) AS invoices,
                   SUM(CASE WHEN i.status <> 'anulada' THEN i.amount ELSE 0 END) AS invoiced,
                   COALESCE(SUM(ipay.paid), 0) AS collected,
                   SUM(CASE WHEN i.status = 'pendiente' THEN GREATEST(i.amount - i.paid_amount, 0) ELSE 0 END) AS pending,
                   MAX(i.created_at) AS last_invoice
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, SUM(amount) AS paid
                FROM invoice_payments
                WHERE paid_at BETWEEN ? AND ?
                GROUP BY invoice_id
            ) ipay ON ipay.invoice_id = i.id
            WHERE {$whereSql} AND DATE(i.created_at) BETWEEN ? AND ?
            GROUP BY client_name, i.currency
            ORDER BY client_name ASC
        ");
        $stmt->execute(array_merge([$filters['from'], $filters['to']], $params, [$filters['from'], $filters['to']]));

        $clients = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)$row['client_name'];
            $currency = strtoupper((string)($row['currency'] ?: 'USD'));
            if (!isset($clients[$name])) {
                $clients[$name] = [
                    'invoices' => 0, 'invoiced' => [], 'collected' => [], 'pending' => [], 'last_invoice' => null,
                ];
            }
            $clients[$name]['invoices'] += (int)$row['invoices'];
            $clients[$name]['invoiced'][$currency] = ($clients[$name]['invoiced'][$currency] ?? 0) + (float)$row['invoiced'];
            $clients[$name]['collected'][$currency] = ($clients[$name]['collected'][$currency] ?? 0) + (float)$row['collected'];
            $clients[$name]['pending'][$currency] = ($clients[$name]['pending'][$currency] ?? 0) + (float)$row['pending'];
            $last = (string)$row['last_invoice'];
            if ($last !== '' && ($clients[$name]['last_invoice'] === null || $last > $clients[$name]['last_invoice'])) {
                $clients[$name]['last_invoice'] = $last;
            }
        }

        $rows = [];
        $chartLabels = [];
        $chartInvoices = [];
        $summary = ['clients' => 0, 'invoices' => 0, 'invoiced' => [], 'collected' => [], 'pending' => []];
        foreach ($clients as $name => $client) {
            $rows[] = [
                $name,
                (string)$client['invoices'],
                formatMoneyTotals($client['invoiced']),
                formatMoneyTotals($client['collected']),
                formatMoneyTotals($client['pending']),
                $client['last_invoice'] ? Format::date($client['last_invoice']) : '-',
            ];
            $chartLabels[] = $name;
            $chartInvoices[] = $client['invoices'];
            $summary['clients']++;
            $summary['invoices'] += $client['invoices'];
            $this->mergeCurrencies($summary['invoiced'], $client['invoiced']);
            $this->mergeCurrencies($summary['collected'], $client['collected']);
            $this->mergeCurrencies($summary['pending'], $client['pending']);
        }

        return [
            'title' => 'Reporte por cliente',
            'headers' => $headers,
            'rows' => $rows,
            'widths' => $widths,
            'summary' => $summary,
            'chart' => [
                'type' => 'bar',
                'labels' => array_slice($chartLabels, 0, 8),
                'series' => [
                    ['label' => 'Facturas', 'data' => array_slice($chartInvoices, 0, 8), 'color' => '#f59e0b'],
                ],
            ],
        ];
    }

    public function deadlines(int $agencyId, int $userId, string $role, array $filters): array
    {
        $from = $this->date($filters['from'] ?? null) ?: date('Y-m-d');
        $to = $this->date($filters['to'] ?? null) ?: date('Y-m-d', strtotime('+30 days'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $projectId = (int)($filters['project_id'] ?? 0);
        $ids = $this->visibleIds($userId, $role);
        $headers = ['Tipo', 'Elemento', 'Proyecto', 'Responsable / Cliente', 'Vencimiento', 'Días', 'Estado'];
        $widths = [50, 150, 100, 100, 70, 40, 60];

        if ($ids === []) {
            return $this->emptyDataset('Reporte de vencimientos', $headers, $widths);
        }

        [$projectWhere, $projectParams] = $this->projectWhere($ids, $agencyId, $projectId);
        $doneKeys = $this->doneKeys($agencyId);
        $doneSql = $this->quoteKeys($doneKeys);
        $rowsRaw = [];

        $stmtTasks = $this->db->prepare("
            SELECT t.title, t.due_date, COALESCE(u.name, t.assigned_to, 'Sin asignar') AS responsible, p.name AS project_name
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            LEFT JOIN users u ON u.id = t.assigned_user_id
            WHERE {$projectWhere}
              AND t.status NOT IN ({$doneSql})
              AND t.due_date IS NOT NULL
              AND (t.due_date < CURDATE() OR t.due_date BETWEEN ? AND ?)
        ");
        $stmtTasks->execute(array_merge($projectParams, [$from, $to]));
        foreach ($stmtTasks->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rowsRaw[] = ['Tarea', $row['title'], $row['project_name'], $row['responsible'], $row['due_date']];
        }

        $stmtProjects = $this->db->prepare("
            SELECT p.name, p.deadline, p.status
            FROM projects p
            WHERE {$projectWhere}
              AND p.deadline IS NOT NULL
              AND p.status <> 'completado'
              AND (p.deadline < CURDATE() OR p.deadline BETWEEN ? AND ?)
        ");
        $stmtProjects->execute(array_merge($projectParams, [$from, $to]));
        foreach ($stmtProjects->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rowsRaw[] = ['Proyecto', $row['name'], $row['name'], '—', $row['deadline']];
        }

        $stmtInvoices = $this->db->prepare("
            SELECT i.invoice_number, COALESCE(NULLIF(i.client_name, ''), 'Sin cliente') AS client_name,
                   i.due_date, p.name AS project_name
            FROM invoices i
            INNER JOIN projects p ON p.id = i.project_id
            WHERE {$projectWhere}
              AND i.status = 'pendiente'
              AND i.due_date IS NOT NULL
              AND (i.due_date < CURDATE() OR i.due_date BETWEEN ? AND ?)
        ");
        $stmtInvoices->execute(array_merge($projectParams, [$from, $to]));
        foreach ($stmtInvoices->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rowsRaw[] = ['Cobro', 'Factura ' . $row['invoice_number'], $row['project_name'], $row['client_name'], $row['due_date']];
        }

        usort($rowsRaw, static fn(array $a, array $b): int => strcmp($a[4], $b[4]));

        $todayTs = strtotime(date('Y-m-d'));
        $rows = [];
        $summary = ['overdue' => 0, 'upcoming' => 0, 'tasks' => 0, 'projects' => 0, 'invoices' => 0];
        $chart = ['Tareas' => 0, 'Proyectos' => 0, 'Cobros' => 0];
        foreach ($rowsRaw as $raw) {
            $dueTs = strtotime($raw[4]);
            $days = (int)floor(($todayTs - $dueTs) / 86400);
            $isOverdue = $days > 0;
            $rows[] = [
                $raw[0],
                $raw[1],
                $raw[2],
                $raw[3],
                Format::date($raw[4]),
                (string)abs($days),
                $isOverdue ? 'Vencido' : 'Próximo',
            ];
            if ($isOverdue) {
                $summary['overdue']++;
            } else {
                $summary['upcoming']++;
            }
            $key = ['Tarea' => 'tasks', 'Proyecto' => 'projects', 'Cobro' => 'invoices'][$raw[0]] ?? null;
            if ($key !== null) {
                $summary[$key]++;
                $chart[$raw[0] === 'Tarea' ? 'Tareas' : ($raw[0] === 'Proyecto' ? 'Proyectos' : 'Cobros')]++;
            }
        }

        return [
            'title' => 'Reporte de vencimientos',
            'headers' => $headers,
            'rows' => $rows,
            'widths' => $widths,
            'summary' => $summary,
            'chart' => [
                'type' => 'doughnut',
                'labels' => array_keys($chart),
                'series' => [
                    ['label' => 'Vencimientos', 'data' => array_values($chart), 'colors' => ['#0E66FF', '#22c55e', '#f59e0b']],
                ],
            ],
            'range' => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * @param int[] $ids
     * @return array{0:string,1:array<int,mixed>}
     */
    private function projectWhere(array $ids, int $agencyId, int $projectId = 0): array
    {
        $in = $this->placeholders($ids);
        $where = "p.id IN ({$in}) AND p.agency_id = ?";
        $params = array_merge($ids, [$agencyId]);
        if ($projectId > 0) {
            $where .= ' AND p.id = ?';
            $params[] = $projectId;
        }

        return [$where, $params];
    }

    /** @return int[] */
    private function visibleIds(int $userId, string $role): array
    {
        return array_values(array_unique(array_map('intval', $this->projects->visibleProjectIds($userId, $role))));
    }

    /** @return string[] */
    private function doneKeys(int $agencyId): array
    {
        $keys = [];
        foreach (\taskStatusKeys('done', $agencyId) as $key) {
            $keys[] = (string)$key;
        }

        return $keys;
    }

    /** @param int[] $ids */
    private function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** @param string[] $keys */
    private function quoteKeys(array $keys): string
    {
        if ($keys === []) {
            return "'__ninguna__'";
        }

        return implode(',', array_map(fn(string $key): string => $this->db->quote($key), $keys));
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || !Format::isIsoDate($value)) {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? null : date('Y-m-d', $ts);
    }

    /** @param array<string,float> $target */
    private function mergeCurrencies(array &$target, array $source): void
    {
        foreach ($source as $currency => $amount) {
            $currency = strtoupper((string)$currency);
            $target[$currency] = ($target[$currency] ?? 0) + (float)$amount;
        }
    }

    /** @return array<string,float> */
    private function subtractCurrencies(array $a, array $b): array
    {
        $result = [];
        $currencies = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($currencies as $currency) {
            $value = (float)($a[$currency] ?? 0) - (float)($b[$currency] ?? 0);
            if (abs($value) >= 0.005) {
                $result[(string)$currency] = $value;
            }
        }

        return $result;
    }

    private function emptyDataset(string $title, array $headers, array $widths): array
    {
        return [
            'title' => $title,
            'headers' => $headers,
            'rows' => [],
            'widths' => $widths,
            'summary' => [],
            'chart' => ['type' => 'bar', 'labels' => [], 'series' => []],
        ];
    }
}

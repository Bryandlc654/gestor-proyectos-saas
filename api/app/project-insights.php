<?php
// api/app/project-insights.php - Finanzas y cronograma de un proyecto para React.
require_once __DIR__ . '/_bootstrap.php';

appMethod('GET');

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0 || !canAccessProject($projectId, $userId, $role)) {
    appJson(['error' => 'Sin acceso al proyecto'], 403);
}

$stmt = getDB()->prepare('SELECT id, name, agency_id, budget, currency FROM projects WHERE id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if ($project === false) appJson(['error' => 'Proyecto no encontrado'], 404);

$isOwner = (int)$project['agency_id'] === $agencyId;
// Misma regla que api/invoices.php: módulo de facturación habilitado para el rol y gestión del proyecto.
$canSeeFinances = $isOwner && moduleAllowed('invoices') && canManageProject($projectId, $userId, $role);
$finances = null;
if ($canSeeFinances) {
    $currency = strtoupper((string)($project['currency'] ?: 'USD'));
    $budget = (float)$project['budget'];
    $invoices = [];
    $stmt = getDB()->prepare('SELECT * FROM invoices WHERE project_id = ? ORDER BY (status = \'pendiente\') DESC, due_date ASC');
    $stmt->execute([$projectId]);
    $paid = 0.0;
    $pending = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $amount = (float)$row['amount'];
        $invoicePaid = min($amount, max(0.0, (float)($row['paid_amount'] ?? 0)));
        $balance = max(0.0, round($amount - $invoicePaid, 2));
        if ($row['status'] !== 'anulada') {
            $paid += $invoicePaid;
            $pending += $balance;
        }
        $displayStatus = $row['status'] === 'pagada' ? 'pagada' : ($row['status'] === 'anulada' ? 'anulada' : ($invoicePaid > 0 ? 'parcial' : 'pendiente'));
        $invoices[] = [
            'id' => (int)$row['id'], 'number' => $row['invoice_number'], 'concept' => $row['concept'],
            'amount' => $amount, 'paid' => $invoicePaid, 'balance' => $balance,
            'currency' => strtoupper((string)$row['currency']),
            'status' => $row['status'], 'displayStatus' => $displayStatus, 'dueDate' => $row['due_date'],
            'clientName' => $row['client_name'] ?? null, 'notes' => $row['notes'] ?? null, 'paidAt' => $row['paid_at'] ?? null,
        ];
    }
    $payables = [];
    $stmt = getDB()->prepare('SELECT * FROM payables WHERE agency_id = ? AND project_id = ? ORDER BY (status = \'pendiente\') DESC, due_date ASC');
    $stmt->execute([$agencyId, $projectId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payables[] = [
            'id' => (int)$row['id'], 'supplier' => $row['supplier'], 'concept' => $row['concept'],
            'amount' => (float)$row['amount'], 'paid' => (float)$row['paid_amount'],
            'balance' => (float)$row['amount'] - (float)$row['paid_amount'],
            'currency' => strtoupper((string)$row['currency']), 'status' => $row['status'], 'dueDate' => $row['due_date'],
            'kind' => ($row['kind'] ?? 'direct') === 'collaborator' ? 'collaborator' : 'direct',
        ];
    }

    // Métricas de rentabilidad con la misma lógica que la vista consolidada.
    $profit = null;
    try {
        $metrics = profitabilityService()->forProject($agencyId, $projectId);
        if (is_array($metrics)) {
            $money = static fn($value): array => is_array($value) ? array_map('floatval', $value) : [];
            $counts = $metrics['counts'] ?? [];
            $profit = [
                'budget' => $money($metrics['budget_by_currency'] ?? [$currency => $budget]),
                'income' => $money($metrics['income'] ?? []),
                'collected' => $money($metrics['collected'] ?? []),
                'pending' => $money($metrics['pending'] ?? []),
                'direct' => $money($metrics['direct_expenses'] ?? []),
                'directPaid' => $money($metrics['direct_paid'] ?? []),
                'collaborator' => $money($metrics['collaborator_payments'] ?? []),
                'collaboratorPaid' => $money($metrics['collaborator_paid'] ?? []),
                'margin' => $money($metrics['margin'] ?? []),
                'marginPercent' => isset($metrics['margin_percent']) ? (float)$metrics['margin_percent'] : null,
                'incomeProject' => (float)($metrics['income_project'] ?? 0),
                'marginProject' => (float)($metrics['margin_project'] ?? 0),
                'invoiceCount' => (int)($counts['invoices'] ?? 0),
                'mixedCurrencies' => !empty($metrics['has_other_currencies']),
            ];
        }
    } catch (\Throwable $e) {
        error_log('Rentabilidad del proyecto: ' . $e->getMessage());
    }
    $finances = [
        'budget' => $budget,
        'currency' => $currency,
        'invoices' => $invoices,
        'payables' => $payables,
        'totals' => ['paid' => $paid, 'pending' => $pending, 'balance' => $budget - $paid],
        'profit' => $profit,
        'clientSuggestions' => financeContactSuggestions($agencyId, $projectId, false),
        'canManage' => canManageProject($projectId, $userId, $role),
    ];
}

$gantt = [];
$stmt = getDB()->prepare("
    SELECT t.id, t.title, t.status, t.priority, t.start_date, t.due_date, t.parent_task_id, t.is_milestone, u.name AS assignee
    FROM tasks t
    LEFT JOIN users u ON u.id = t.assigned_user_id
    WHERE t.project_id = ? AND (t.start_date IS NOT NULL OR t.due_date IS NOT NULL)
    ORDER BY COALESCE(t.start_date, t.due_date) ASC, t.id ASC
    LIMIT 200
");
$stmt->execute([$projectId]);
$ganttRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Dependencias y camino crítico (misma lógica que el cronograma clásico).
$stmt = getDB()->prepare("
    SELECT d.task_id, d.depends_on_task_id
    FROM task_dependencies d
    INNER JOIN tasks t ON t.id = d.task_id
    WHERE t.project_id = ?
");
$stmt->execute([$projectId]);
$dependencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
$analysis = \App\Support\GanttScheduler::analyze(
    array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'start' => $row['start_date'] ?: null,
        'due' => $row['due_date'] ?: null,
        'milestone' => (int)($row['is_milestone'] ?? 0) === 1,
    ], $ganttRows),
    $dependencies
);

foreach ($ganttRows as $row) {
    $start = $row['start_date'] ?: $row['due_date'];
    $due = $row['due_date'] ?: $row['start_date'];
    if ($start > $due) [$start, $due] = [$due, $start];
    $id = (int)$row['id'];
    $gantt[] = [
        'id' => $id, 'title' => $row['title'], 'status' => $row['status'],
        'priority' => $row['priority'], 'start' => $start, 'due' => $due,
        'parentId' => $row['parent_task_id'] !== null ? (int)$row['parent_task_id'] : null,
        'isMilestone' => (int)($row['is_milestone'] ?? 0) === 1,
        'assignee' => $row['assignee'],
        'critical' => !empty($analysis['critical'][$id]),
        'slack' => (int)($analysis['slack'][$id] ?? 0),
    ];
}
$ganttDependencies = array_map(static fn(array $row): array => [
    'from' => (int)$row['depends_on_task_id'],
    'to' => (int)$row['task_id'],
], $dependencies);

// Hitos: si la migración 0047 aún no se aplicó, se devuelve una lista vacía.
$milestones = [];
try {
    $stmt = getDB()->prepare('SELECT id, title, due_date, is_done FROM project_milestones WHERE project_id = ? ORDER BY due_date ASC, id ASC');
    $stmt->execute([$projectId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $milestones[] = ['id' => (int)$row['id'], 'title' => $row['title'], 'date' => $row['due_date'], 'done' => (bool)$row['is_done']];
    }
} catch (\PDOException $e) {
    error_log('Hitos no disponibles: ' . $e->getMessage());
}

appJson([
    'finances' => $finances,
    'gantt' => $gantt,
    'dependencies' => $ganttDependencies,
    'canEditTasks' => canEditProjectTasks($projectId, $userId, $role),
    'milestones' => $milestones,
    'canManageMilestones' => canManageProject($projectId, $userId, $role),
    'canEditFinances' => $canSeeFinances,
]);

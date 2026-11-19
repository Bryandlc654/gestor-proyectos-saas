<?php
// api/app/dashboard.php - Dashboard equivalente al panel PHP original.
require_once __DIR__ . '/_bootstrap.php';

appMethod('GET');

$visibleIds = array_values(array_map('intval', projectRepository()->visibleProjectIds($userId, $role)));
$in = $visibleIds === [] ? 'IN (0)' : 'IN (' . implode(',', $visibleIds) . ')';
$db = getDB();

$globalStats = $db->query("
    SELECT
        (SELECT COUNT(*) FROM projects WHERE id {$in}) AS total_projects,
        (SELECT COUNT(*) FROM projects WHERE id {$in} AND status = 'activo') AS active_projects,
        (SELECT COUNT(*) FROM tickets WHERE project_id {$in} AND status IN ('abierto', 'en_revision')) AS open_tickets,
        (SELECT COUNT(*) FROM tickets WHERE project_id {$in} AND status = 'resuelto') AS resolved_tickets
")->fetch(PDO::FETCH_ASSOC) ?: [];

$taskStatuses = taskStatuses();
$rawStatusCounts = $db->query("SELECT status, COUNT(*) AS total FROM tasks WHERE project_id {$in} GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$statusCounts = [];
foreach ($taskStatuses as $status) {
    $statusCounts[$status['key']] = (int)($rawStatusCounts[$status['key']] ?? 0);
}
$totalTasks = array_sum(array_map('intval', $rawStatusCounts));
$pendingTasks = 0;
$inProgressTasks = 0;
$completedTasks = 0;
$kindColors = ['open' => '#CBD5E1', 'in_progress' => '#15BDF4', 'done' => '#0E66FF'];
$statusChart = ['labels' => [], 'data' => [], 'colors' => [], 'labelsFooter' => []];
foreach ($taskStatuses as $status) {
    $count = $statusCounts[$status['key']];
    if ($status['kind'] === 'done') $completedTasks += $count;
    elseif ($status['kind'] === 'in_progress') $inProgressTasks += $count;
    else $pendingTasks += $count;
    $statusChart['labels'][] = $status['label'];
    $statusChart['data'][] = $count;
    $statusChart['colors'][] = $kindColors[$status['kind']] ?? '#94A3B8';
    $statusChart['labelsFooter'][] = ['label' => $status['label'], 'value' => $count];
}
$knownTotal = array_sum($statusCounts);
if ($totalTasks > $knownTotal) $pendingTasks += $totalTasks - $knownTotal;

$priorityRows = $db->query("SELECT priority, COUNT(*) count FROM tasks WHERE project_id {$in} GROUP BY priority")->fetchAll(PDO::FETCH_ASSOC);
$prioLabels = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'urgente' => 'Urgente'];
$prioColors = ['baja' => '#CBD5E1', 'media' => '#15BDF4', 'alta' => '#0E66FF', 'urgente' => '#F43F5E'];
$priorityChart = ['labels' => [], 'data' => [], 'colors' => []];
foreach ($prioLabels as $key => $label) {
    $found = 0;
    foreach ($priorityRows as $row) {
        if (strtolower((string)$row['priority']) === $key) { $found = (int)$row['count']; break; }
    }
    $priorityChart['labels'][] = $label;
    $priorityChart['data'][] = $found;
    $priorityChart['colors'][] = $prioColors[$key];
}

$categoryRows = $db->query("SELECT COALESCE(NULLIF(category, ''), 'General') category_name, COUNT(*) count FROM projects WHERE id {$in} GROUP BY category")->fetchAll(PDO::FETCH_ASSOC);
$categoryChart = ['labels' => [], 'data' => []];
foreach ($categoryRows as $row) {
    $categoryChart['labels'][] = $row['category_name'];
    $categoryChart['data'][] = (int)$row['count'];
}

$doneKeys = taskStatusKeys('done');
$doneExpr = $doneKeys !== []
    ? 'SUM(CASE WHEN t.status IN (' . implode(',', array_fill(0, count($doneKeys), '?')) . ') THEN 1 ELSE 0 END)'
    : '0';
$stmt = $db->prepare("
    SELECT p.id, p.name, p.color, p.category, p.status, p.agency_id, a.name AS agency_name,
           COUNT(t.id) total_tasks, {$doneExpr} completed_tasks
    FROM projects p
    INNER JOIN agencies a ON a.id = p.agency_id
    LEFT JOIN tasks t ON t.project_id = p.id
    WHERE p.id {$in}
    GROUP BY p.id, a.name
    ORDER BY total_tasks DESC
    LIMIT 6
");
$stmt->execute($doneKeys);
$projectProgress = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $project) {
    $total = (int)$project['total_tasks'];
    $completed = (int)$project['completed_tasks'];
    $projectProgress[] = [
        'id' => (int)$project['id'],
        'name' => $project['name'],
        'color' => $project['color'] ?: '#0E66FF',
        'category' => $project['category'] ?: 'General',
        'status' => $project['status'],
        'progress' => $total > 0 ? (int)round(($completed / $total) * 100) : 0,
        'tasks' => $total,
        'completedTasks' => $completed,
        'pendingTasks' => max(0, $total - $completed),
        // Proyectos de otra agencia conectada: se indica quién los compartió (no a los clientes).
        'shared' => (int)$project['agency_id'] !== $agencyId,
        'sharedBy' => (int)$project['agency_id'] !== $agencyId && $role !== 'cliente' ? $project['agency_name'] : null,
    ];
}

$finance = null;
if ($role === 'admin') {
    $budget = [];
    $collected = [];
    $pending = [];
    foreach ($db->query("SELECT currency, COALESCE(SUM(budget),0) total FROM projects WHERE agency_id = " . (int)$agencyId . " GROUP BY currency") as $row) {
        $currency = strtoupper((string)($row['currency'] ?: 'USD'));
        $budget[$currency] = ($budget[$currency] ?? 0) + (float)$row['total'];
    }
    foreach ($db->query("SELECT currency, COALESCE(SUM(paid_amount),0) total FROM invoices WHERE agency_id = " . (int)$agencyId . " AND status <> 'anulada' GROUP BY currency") as $row) {
        $currency = strtoupper((string)($row['currency'] ?: 'USD'));
        $collected[$currency] = ($collected[$currency] ?? 0) + (float)$row['total'];
    }
    foreach ($db->query("SELECT currency, COALESCE(SUM(GREATEST(amount - paid_amount, 0)),0) total FROM invoices WHERE agency_id = " . (int)$agencyId . " AND status = 'pendiente' GROUP BY currency") as $row) {
        $currency = strtoupper((string)($row['currency'] ?: 'USD'));
        $pending[$currency] = ($pending[$currency] ?? 0) + (float)$row['total'];
    }
    $paySummary = financeRepository()->summary($agencyId, $visibleIds);
    $finance = [
        'budget' => $budget,
        'collected' => $collected,
        'pending' => $pending,
        'payables' => $paySummary['outstanding'],
        'payablesCount' => (int)$paySummary['count_open'],
    ];
}

appJson([
    'stats' => [
        'totalProjects' => (int)($globalStats['total_projects'] ?? 0),
        'activeProjects' => (int)($globalStats['active_projects'] ?? 0),
        'openTickets' => (int)($globalStats['open_tickets'] ?? 0),
        'resolvedTickets' => (int)($globalStats['resolved_tickets'] ?? 0),
        'totalTasks' => $totalTasks,
        'completedTasks' => $completedTasks,
        'inProgressTasks' => $inProgressTasks,
        'pendingTasks' => $pendingTasks,
        'completionRate' => $totalTasks > 0 ? (int)round(($completedTasks / $totalTasks) * 100) : 0,
    ],
    'taskStatusChart' => $statusChart,
    'priorityChart' => $priorityChart,
    'categoryChart' => $categoryChart,
    'projectProgress' => $projectProgress,
    'finance' => $finance,
    'date' => date('Y-m-d'),
]);

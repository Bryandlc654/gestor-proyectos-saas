<?php
// api/app/calendar.php - Eventos del calendario mensual para React.
require_once __DIR__ . '/_bootstrap.php';

use App\Support\CalendarGrid;

appMethod('GET');

if (!moduleAllowed('calendar')) {
    appJson(['error' => 'Módulo de calendario no disponible'], 403);
}

$month = CalendarGrid::normalizeMonth($_GET['month'] ?? null);
$grid = CalendarGrid::forMonth($month);
$dates = CalendarGrid::dates($month);
$view = (string)($_GET['view'] ?? 'month');
if (!in_array($view, ['month', 'week', 'agenda'], true)) {
    $view = 'month';
}

$weekStartParam = trim((string)($_GET['start'] ?? ''));
$parsedStart = isIsoDate($weekStartParam)
    ? DateTimeImmutable::createFromFormat('!Y-m-d', $weekStartParam)
    : false;
$anchor = $parsedStart instanceof DateTimeImmutable ? $parsedStart : new DateTimeImmutable('today');
$weekStartDate = $anchor->modify('monday this week');
$weekEndDate = $weekStartDate->modify('+6 days');

if ($view === 'week') {
    $rangeStart = $weekStartDate->format('Y-m-d');
    $rangeEnd = $weekEndDate->format('Y-m-d');
} elseif ($view === 'agenda') {
    $rangeStart = $grid['monthStart']->format('Y-m-d');
    $rangeEnd = $grid['monthEnd']->format('Y-m-d');
} else {
    $rangeStart = $grid['start']->format('Y-m-d');
    $rangeEnd = $grid['end']->format('Y-m-d');
}

$type = (string)($_GET['type'] ?? 'all');
if (!in_array($type, ['all', 'tasks', 'projects', 'invoices'], true)) {
    $type = 'all';
}
$canViewInvoices = in_array($role, ['admin', 'project_manager'], true);
if ($type === 'invoices' && !$canViewInvoices) {
    $type = 'all';
}

$visibleIds = array_values(array_map('intval', projectRepository()->visibleProjectIds($userId, $role)));
$in = $visibleIds === [] ? 'IN (0)' : 'IN (' . implode(',', $visibleIds) . ')';
$filterProject = (int)($_GET['project_id'] ?? 0);
if ($filterProject > 0 && !in_array($filterProject, $visibleIds, true)) {
    $filterProject = 0;
}
$mine = ($_GET['mine'] ?? '') === '1';
$showDone = ($_GET['show_done'] ?? '') === '1';

$events = [];

if (in_array($type, ['all', 'tasks'], true)) {
    $where = ["t.project_id {$in}", 't.due_date IS NOT NULL', 't.due_date BETWEEN ? AND ?'];
    $params = [$rangeStart, $rangeEnd];
    if ($filterProject > 0) {
        $where[] = 't.project_id = ?';
        $params[] = $filterProject;
    }
    if ($mine) {
        $where[] = 't.assigned_user_id = ?';
        $params[] = $userId;
    }
    if (!$showDone) {
        $doneKeys = taskStatusKeys('done');
        if ($doneKeys !== []) {
            $where[] = 't.status NOT IN (' . implode(',', array_fill(0, count($doneKeys), '?')) . ')';
            $params = array_merge($params, $doneKeys);
        }
    }
    $stmt = getDB()->prepare("
        SELECT t.id, t.title, t.due_date, t.priority, t.status, t.project_id,
               p.name AS project_name, p.color AS project_color
        FROM tasks t
        INNER JOIN projects p ON p.id = t.project_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.due_date ASC, (t.priority = 'urgente') DESC, (t.priority = 'alta') DESC, t.id ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $events[] = [
            'id' => (int)$task['id'],
            'type' => 'task',
            'date' => $task['due_date'],
            'title' => $task['title'],
            'subtitle' => $task['project_name'] . ' · ' . strtoupper((string)$task['priority']),
            'projectId' => (int)$task['project_id'],
            'projectColor' => $task['project_color'] ?: '#0E66FF',
            'done' => taskIsDone($task['status']),
            'priority' => $task['priority'],
            'status' => $task['status'],
            'href' => '/project/' . (int)$task['project_id'],
        ];
    }
}

if (in_array($type, ['all', 'projects'], true)) {
    $where = ["p.id {$in}", 'p.deadline IS NOT NULL', 'p.deadline BETWEEN ? AND ?'];
    $params = [$rangeStart, $rangeEnd];
    if ($filterProject > 0) {
        $where[] = 'p.id = ?';
        $params[] = $filterProject;
    }
    $stmt = getDB()->prepare("
        SELECT p.id, p.name, p.deadline, p.status, p.color
        FROM projects p
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.deadline ASC, p.name ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $project) {
        $events[] = [
            'id' => (int)$project['id'],
            'type' => 'project',
            'date' => $project['deadline'],
            'title' => $project['name'],
            'subtitle' => ucfirst(str_replace('_', ' ', (string)$project['status'])),
            'projectId' => (int)$project['id'],
            'projectColor' => $project['color'] ?: '#0E66FF',
            'done' => false,
            'href' => '/project/' . (int)$project['id'],
        ];
    }
}

if ($canViewInvoices && in_array($type, ['all', 'invoices'], true)) {
    $where = [
        'i.agency_id = ?',
        "(i.project_id IS NULL OR i.project_id {$in})",
        'i.due_date IS NOT NULL',
        'i.due_date BETWEEN ? AND ?',
        "i.status = 'pendiente'",
    ];
    $params = [$agencyId, $rangeStart, $rangeEnd];
    if ($filterProject > 0) {
        $where[] = 'i.project_id = ?';
        $params[] = $filterProject;
    }
    $stmt = getDB()->prepare("
        SELECT i.id, i.invoice_number, i.client_name, i.amount, i.currency, i.due_date,
               i.project_id, p.name AS project_name, p.color AS project_color
        FROM invoices i
        LEFT JOIN projects p ON p.id = i.project_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.due_date ASC, i.id ASC
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
        $events[] = [
            'id' => (int)$invoice['id'],
            'type' => 'invoice',
            'date' => $invoice['due_date'],
            'title' => 'Cobro · ' . $invoice['invoice_number'],
            'subtitle' => ($invoice['client_name'] ?: ($invoice['project_name'] ?: 'Sin proyecto')) . ' · ' . formatMoney((float)$invoice['amount'], $invoice['currency']),
            'amount' => (float)$invoice['amount'],
            'currency' => $invoice['currency'],
            'projectId' => $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null,
            'projectColor' => $invoice['project_color'] ?: '#0E66FF',
            'done' => false,
            'href' => '/finance?tab=cobrar&search=' . urlencode((string)$invoice['invoice_number']),
        ];
    }
}

$overdue = [];

if (in_array($type, ['all', 'tasks'], true)) {
    $where = ["t.project_id {$in}", 't.due_date IS NOT NULL', 't.due_date < CURDATE()'];
    $params = [];
    if ($filterProject > 0) {
        $where[] = 't.project_id = ?';
        $params[] = $filterProject;
    }
    if ($mine) {
        $where[] = 't.assigned_user_id = ?';
        $params[] = $userId;
    }
    if (!$showDone) {
        $doneKeys = taskStatusKeys('done');
        if ($doneKeys !== []) {
            $where[] = 't.status NOT IN (' . implode(',', array_fill(0, count($doneKeys), '?')) . ')';
            $params = array_merge($params, $doneKeys);
        }
    }
    $stmt = getDB()->prepare("
        SELECT t.id, t.title, t.due_date, t.priority, t.status, t.project_id,
               p.name AS project_name, p.color AS project_color
        FROM tasks t
        INNER JOIN projects p ON p.id = t.project_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.due_date ASC, t.id ASC
        LIMIT 100
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
        $overdue[] = [
            'id' => (int)$task['id'],
            'type' => 'task',
            'date' => $task['due_date'],
            'title' => $task['title'],
            'subtitle' => $task['project_name'] . ' · ' . strtoupper((string)$task['priority']),
            'projectId' => (int)$task['project_id'],
            'projectColor' => $task['project_color'] ?: '#0E66FF',
            'done' => taskIsDone($task['status']),
            'priority' => $task['priority'],
            'status' => $task['status'],
            'href' => '/project/' . (int)$task['project_id'],
        ];
    }
}

if (in_array($type, ['all', 'projects'], true)) {
    $where = ["p.id {$in}", 'p.deadline IS NOT NULL', 'p.deadline < CURDATE()', "p.status <> 'completado'"];
    $params = [];
    if ($filterProject > 0) {
        $where[] = 'p.id = ?';
        $params[] = $filterProject;
    }
    $stmt = getDB()->prepare("
        SELECT p.id, p.name, p.deadline, p.status, p.color
        FROM projects p
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.deadline ASC, p.name ASC
        LIMIT 50
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $project) {
        $overdue[] = [
            'id' => (int)$project['id'],
            'type' => 'project',
            'date' => $project['deadline'],
            'title' => $project['name'],
            'subtitle' => ucfirst(str_replace('_', ' ', (string)$project['status'])),
            'projectId' => (int)$project['id'],
            'projectColor' => $project['color'] ?: '#0E66FF',
            'done' => false,
            'href' => '/project/' . (int)$project['id'],
        ];
    }
}

if ($canViewInvoices && in_array($type, ['all', 'invoices'], true)) {
    $where = [
        'i.agency_id = ?',
        "(i.project_id IS NULL OR i.project_id {$in})",
        'i.due_date IS NOT NULL',
        'i.due_date < CURDATE()',
        "i.status = 'pendiente'",
    ];
    $params = [$agencyId];
    if ($filterProject > 0) {
        $where[] = 'i.project_id = ?';
        $params[] = $filterProject;
    }
    $stmt = getDB()->prepare("
        SELECT i.id, i.invoice_number, i.client_name, i.amount, i.currency, i.due_date,
               i.project_id, p.name AS project_name, p.color AS project_color
        FROM invoices i
        LEFT JOIN projects p ON p.id = i.project_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.due_date ASC, i.id ASC
        LIMIT 100
    ");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
        $overdue[] = [
            'id' => (int)$invoice['id'],
            'type' => 'invoice',
            'date' => $invoice['due_date'],
            'title' => 'Cobro · ' . $invoice['invoice_number'],
            'subtitle' => ($invoice['client_name'] ?: ($invoice['project_name'] ?: 'Sin proyecto')) . ' · ' . formatMoney((float)$invoice['amount'], $invoice['currency']),
            'amount' => (float)$invoice['amount'],
            'currency' => $invoice['currency'],
            'projectId' => $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null,
            'projectColor' => $invoice['project_color'] ?: '#0E66FF',
            'done' => false,
            'href' => '/finance?tab=cobrar&search=' . urlencode((string)$invoice['invoice_number']),
        ];
    }
}

usort($overdue, static fn(array $a, array $b): int => [$a['date'], $a['type']] <=> [$b['date'], $b['type']]);

usort($events, static function (array $a, array $b): int {
    $order = ['task' => 0, 'project' => 1, 'invoice' => 2];
    return [$a['date'], $order[$a['type']] ?? 9] <=> [$b['date'], $order[$b['type']] ?? 9];
});

$projects = getDB()->query("SELECT id, name, color FROM projects WHERE id {$in} AND archived_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

appJson([
    'month' => $month,
    'monthStart' => $grid['monthStart']->format('Y-m-d'),
    'monthEnd' => $grid['monthEnd']->format('Y-m-d'),
    'view' => $view,
    'rangeStart' => $rangeStart,
    'rangeEnd' => $rangeEnd,
    'weekStart' => $weekStartDate->format('Y-m-d'),
    'dates' => $dates,
    'today' => date('Y-m-d'),
    'events' => $events,
    'overdue' => $overdue,
    'projects' => array_map(static fn(array $project): array => [
        'id' => (int)$project['id'],
        'name' => $project['name'],
        'color' => $project['color'] ?: '#0E66FF',
    ], $projects),
]);

<?php
// api/app/profitability.php - Rentabilidad por proyecto para React.
require_once __DIR__ . '/_bootstrap.php';

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('profitability')) {
    appJson(['error' => 'Módulo de rentabilidad no disponible'], 403);
}

appMethod('GET');

$visibleIds = array_values(array_map('intval', projectRepository()->visibleProjectIds($userId, $role)));
$in = $visibleIds === [] ? 'IN (0)' : 'IN (' . implode(',', $visibleIds) . ')';

$status = (string)($_GET['status'] ?? 'todos');
$allowed = ['todos', 'activo', 'en_espera', 'completado'];
if (!in_array($status, $allowed, true)) $status = 'todos';

$search = trim((string)($_GET['search'] ?? ''));

$sort = (string)($_GET['sort'] ?? 'margin');
$allowedSorts = ['margin', 'income', 'budget', 'name'];
if (!in_array($sort, $allowedSorts, true)) $sort = 'margin';

$where = ["p.id {$in}", 'p.agency_id = ?'];
$params = [$agencyId];
if ($status !== 'todos') {
    $where[] = 'p.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[] = 'p.name LIKE ?';
    $params[] = '%' . $search . '%';
}

$stmt = getDB()->prepare('SELECT p.id, p.name, p.color, p.status, p.budget, p.currency FROM projects p WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name ASC');
$stmt->execute($params);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$metrics = profitabilityService()->forProjects($agencyId, array_column($projects, 'id'));

$rows = [];
foreach ($projects as $project) {
    $id = (int)$project['id'];
    if (!isset($metrics[$id])) {
        continue;
    }
    $rows[] = [
        'id' => $id,
        'name' => $project['name'],
        'color' => $project['color'] ?: '#0E66FF',
        'status' => $project['status'],
        'currency' => strtoupper((string)$project['currency']),
        'metrics' => $metrics[$id],
    ];
}

usort($rows, static function (array $a, array $b) use ($sort): int {
    if ($sort === 'name') {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    }
    $aValue = match ($sort) {
        'income' => (float)$a['metrics']['income_project'],
        'budget' => (float)$a['metrics']['budget'],
        default => (float)$a['metrics']['margin_project'],
    };
    $bValue = match ($sort) {
        'income' => (float)$b['metrics']['income_project'],
        'budget' => (float)$b['metrics']['budget'],
        default => (float)$b['metrics']['margin_project'],
    };
    return $bValue <=> $aValue;
});

$totals = \App\Support\ProfitabilityCalculator::totals(array_column($rows, 'metrics'));
$hasNegativeMargin = array_sum($totals['margin']) < -0.005;

$perPage = 25;
$totalRows = count($rows);
$pages = max(1, (int)ceil($totalRows / $perPage));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$rowsPage = array_slice($rows, ($page - 1) * $perPage, $perPage);

appJson([
    'rows' => $rowsPage,
    'totals' => $totals,
    'totalRows' => $totalRows,
    'hasNegativeMargin' => $hasNegativeMargin,
    'pagination' => [
        'page' => $page,
        'perPage' => $perPage,
        'total' => $totalRows,
        'pages' => $pages,
    ],
]);

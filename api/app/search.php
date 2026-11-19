<?php
// api/app/search.php - Búsqueda global para la paleta de comandos del front React.
require_once __DIR__ . '/_bootstrap.php';

appMethod('GET');

$query = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($query) < 2) {
    appJson(['query' => $query, 'groups' => []]);
}

$like = '%' . $query . '%';
$visibleIds = array_values(array_map('intval', projectRepository()->visibleProjectIds($userId, $role)));
$in = $visibleIds === [] ? 'IN (0)' : 'IN (' . implode(',', $visibleIds) . ')';
$canManage = in_array($role, ['admin', 'project_manager'], true);
$groups = [];

$projects = getDB()->prepare("
    SELECT id, name, status, color
    FROM projects
    WHERE agency_id = ? AND archived_at IS NULL AND id {$in} AND name LIKE ?
    ORDER BY name ASC
    LIMIT 6
");
$projects->execute([$agencyId, $like]);
$projectItems = [];
foreach ($projects->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $projectItems[] = [
        'id' => (int)$row['id'],
        'title' => $row['name'],
        'subtitle' => 'Proyecto · ' . str_replace('_', ' ', (string)$row['status']),
        'href' => '/app/projects/' . (int)$row['id'],
        'color' => $row['color'] ?: '#0E66FF',
    ];
}
if ($projectItems !== []) {
    $groups[] = ['key' => 'projects', 'label' => 'Proyectos', 'items' => $projectItems];
}

if (moduleAllowed('tasks')) {
    $tasks = getDB()->prepare("
        SELECT t.id, t.title, t.status, t.due_date, p.id AS project_id, p.name AS project_name, p.color AS project_color
        FROM tasks t
        INNER JOIN projects p ON p.id = t.project_id
        WHERE p.agency_id = ? AND t.project_id {$in} AND t.title LIKE ?
        ORDER BY (t.due_date IS NULL), t.due_date ASC, t.id DESC
        LIMIT 6
    ");
    $tasks->execute([$agencyId, $like]);
    $taskItems = [];
    foreach ($tasks->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $taskItems[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'subtitle' => $row['project_name'] . ($row['due_date'] ? ' · vence ' . formatDate((string)$row['due_date']) : ''),
            'href' => '/app/projects/' . (int)$row['project_id'],
            'color' => $row['project_color'] ?: '#0E66FF',
        ];
    }
    if ($taskItems !== []) {
        $groups[] = ['key' => 'tasks', 'label' => 'Tareas', 'items' => $taskItems];
    }
}

if ($canManage && (moduleAllowed('invoices') || moduleAllowed('finance'))) {
    $invoices = getDB()->prepare("
        SELECT i.id, i.invoice_number, i.concept, i.client_name, i.amount, i.currency, i.status,
               p.name AS project_name
        FROM invoices i
        LEFT JOIN projects p ON p.id = i.project_id
        WHERE i.agency_id = ?
          AND (i.project_id IS NULL OR i.project_id {$in})
          AND (i.invoice_number LIKE ? OR i.concept LIKE ? OR i.client_name LIKE ?)
        ORDER BY (i.status = 'pendiente') DESC, i.due_date ASC, i.id DESC
        LIMIT 6
    ");
    $invoices->execute([$agencyId, $like, $like, $like]);
    $invoiceItems = [];
    foreach ($invoices->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = array_filter([
            $row['client_name'] ?: null,
            $row['project_name'] ?: null,
            formatMoney((float)$row['amount'], (string)($row['currency'] ?: 'USD')),
        ]);
        $search = moduleAllowed('finance')
            ? '/app/finance?tab=cobrar&search=' . urlencode((string)$row['invoice_number'])
            : '/app/billing';
        $invoiceItems[] = [
            'id' => (int)$row['id'],
            'title' => $row['invoice_number'] . ' · ' . $row['concept'],
            'subtitle' => implode(' · ', $details),
            'href' => $search,
        ];
    }
    if ($invoiceItems !== []) {
        $groups[] = ['key' => 'invoices', 'label' => 'Facturas', 'items' => $invoiceItems];
    }
}

if ($canManage && moduleAllowed('crm')) {
    $companies = getDB()->prepare("
        SELECT id, name, industry, email
        FROM crm_companies
        WHERE agency_id = ? AND (name LIKE ? OR email LIKE ?)
        ORDER BY name ASC
        LIMIT 5
    ");
    $companies->execute([$agencyId, $like, $like]);
    $crmItems = [];
    foreach ($companies->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $crmItems[] = [
            'id' => (int)$row['id'],
            'title' => $row['name'],
            'subtitle' => implode(' · ', array_filter(['Empresa CRM', $row['industry'] ?: null, $row['email'] ?: null])),
            'href' => '/app/crm',
        ];
    }
    if ($crmItems !== []) {
        $groups[] = ['key' => 'crm', 'label' => 'CRM', 'items' => $crmItems];
    }
}

appJson(['query' => $query, 'groups' => $groups]);

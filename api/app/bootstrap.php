<?php
// api/app/bootstrap.php - Sesión, permisos y navegación para el front React.
require_once __DIR__ . '/_bootstrap.php';

use App\Security\Permissions;

appMethod('GET');

$modules = appModules();

$navGroups = [
    [
        'key' => 'operacion',
        'label' => 'Operación',
        'items' => [
            ['module' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'LayoutDashboard', 'href' => '/app/dashboard'],
            ['module' => 'tasks', 'label' => 'Tareas', 'icon' => 'CheckSquare', 'href' => '/app/tasks'],
            ['module' => 'calendar', 'label' => 'Calendario', 'icon' => 'CalendarDays', 'href' => '/app/calendar'],
        ],
    ],
    [
        'key' => 'comercial',
        'label' => 'Comercial',
        'icon' => 'Briefcase',
        'items' => [
            ['module' => 'crm', 'label' => 'CRM', 'icon' => 'Contact', 'href' => '/app/crm'],
            ['module' => 'quotes', 'label' => 'Cotizaciones', 'icon' => 'FileText', 'href' => '/app/quotes'],
        ],
    ],
    [
        'key' => 'finanzas',
        'label' => 'Finanzas',
        'icon' => 'Wallet',
        'items' => [
            ['module' => 'invoices', 'label' => 'Facturación', 'icon' => 'Receipt', 'href' => '/app/billing'],
            ['module' => 'finance', 'label' => 'Cobros y pagos', 'icon' => 'Banknote', 'href' => '/app/finance'],
            ['module' => 'cash', 'label' => 'Caja', 'icon' => 'Landmark', 'href' => '/app/cash'],
            ['module' => 'profitability', 'label' => 'Rentabilidad', 'icon' => 'TrendingUp', 'href' => '/app/profitability'],
            ['module' => 'reports', 'label' => 'Reportes', 'icon' => 'BarChart3', 'href' => '/app/reports'],
        ],
    ],
];

if ($role === Permissions::ADMIN || $role === Permissions::PROJECT_MANAGER) {
    $navGroups[] = [
        'key' => 'administracion',
        'label' => 'Administración',
        'icon' => 'Shield',
        'items' => [
            ['module' => 'users', 'label' => 'Usuarios y roles', 'icon' => 'Users', 'href' => '/app/users'],
            ['module' => 'connections', 'label' => 'Agencias conectadas', 'icon' => 'Link2', 'href' => '/app/connections'],
            ['module' => 'api-tokens', 'label' => 'Integraciones API', 'icon' => 'KeyRound', 'href' => '/app/api-tokens'],
            ['module' => 'templates', 'label' => 'Plantillas', 'icon' => 'LayoutTemplate', 'href' => '/app/templates'],
            ['module' => 'trash', 'label' => 'Papelera', 'icon' => 'Archive', 'href' => '/app/trash'],
            ['module' => 'support', 'label' => 'Soporte', 'icon' => 'LifeBuoy', 'href' => '/app/support'],
            ['module' => 'settings', 'label' => 'Configuración', 'icon' => 'Settings', 'href' => '/app/settings'],
        ],
    ];
}

$navGroups = array_values(array_filter(array_map(static function (array $group) use ($modules): array {
    $group['items'] = array_values(array_filter(
        $group['items'],
        static fn(array $item): bool => !empty($modules[$item['module']])
    ));
    return $group;
}, $navGroups), static fn(array $group): bool => $group['items'] !== []));

$projectsGroups = [];
$visibleProjectIds = projectRepository()->visibleProjectIds($userId, $role);
if ($visibleProjectIds !== []) {
    $placeholders = implode(',', array_fill(0, count($visibleProjectIds), '?'));
    $stmt = getDB()->prepare("
        SELECT p.id, p.name, p.color, p.agency_id, p.archived_at
        FROM projects p
        WHERE p.id IN ($placeholders) AND p.archived_at IS NULL
        ORDER BY p.updated_at DESC
        LIMIT 20
    ");
    $stmt->execute(array_map('intval', $visibleProjectIds));
    $sidebarProjectRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Proyectos con el 100% de sus tareas completadas (columnas "done" de la agencia dueña).
    $completedProjectIds = [];
    if ($sidebarProjectRows !== []) {
        $projectAgency = [];
        foreach ($sidebarProjectRows as $row) {
            $projectAgency[(int)$row['id']] = (int)$row['agency_id'];
        }
        $inTasks = implode(',', array_fill(0, count($projectAgency), '?'));
        $stmtTaskCounts = getDB()->prepare("
            SELECT project_id, status, COUNT(*) AS total
            FROM tasks
            WHERE project_id IN ($inTasks)
            GROUP BY project_id, status
        ");
        $stmtTaskCounts->execute(array_keys($projectAgency));
        $doneKeysByAgency = [];
        $taskTotals = [];
        foreach ($stmtTaskCounts->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['project_id'];
            $ownerAgency = $projectAgency[$pid] ?? $agencyId;
            $doneKeysByAgency[$ownerAgency] ??= taskStatusKeys('done', $ownerAgency);
            $taskTotals[$pid] ??= ['total' => 0, 'done' => 0];
            $taskTotals[$pid]['total'] += (int)$row['total'];
            if (in_array($row['status'], $doneKeysByAgency[$ownerAgency], true)) {
                $taskTotals[$pid]['done'] += (int)$row['total'];
            }
        }
        foreach ($taskTotals as $pid => $counts) {
            if ($counts['total'] > 0 && $counts['done'] === $counts['total']) {
                $completedProjectIds[$pid] = true;
            }
        }
    }

    foreach ($sidebarProjectRows as $project) {
        $isOwn = (int)$project['agency_id'] === $agencyId;
        $key = $isOwn ? 'own' : 'agency-' . (int)$project['agency_id'];
        if (!isset($projectsGroups[$key])) {
            $projectsGroups[$key] = [
                'key' => $key,
                'name' => $isOwn ? $agency['name'] : 'Agencia compartida',
                'shared' => !$isOwn,
                'projects' => [],
            ];
        }
        $projectsGroups[$key]['projects'][] = [
            'id' => (int)$project['id'],
            'name' => $project['name'],
            'color' => $project['color'] ?: '#0E66FF',
            'shared' => !$isOwn,
            'completed' => isset($completedProjectIds[(int)$project['id']]),
        ];
    }

    // Nombres reales de las agencias invitadas.
    $sharedAgencyIds = [];
    foreach ($projectsGroups as $key => $group) {
        if ($group['shared']) {
            $sharedAgencyIds[] = (int)str_replace('agency-', '', $key);
        }
    }
    if ($sharedAgencyIds !== []) {
        $in = implode(',', array_fill(0, count($sharedAgencyIds), '?'));
        $stmtAgencies = getDB()->prepare("SELECT id, name FROM agencies WHERE id IN ($in)");
        $stmtAgencies->execute($sharedAgencyIds);
        $names = [];
        foreach ($stmtAgencies->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int)$row['id']] = $row['name'];
        }
        foreach ($projectsGroups as $key => &$group) {
            if ($group['shared']) {
                $ownerId = (int)str_replace('agency-', '', $key);
                $group['name'] = $names[$ownerId] ?? 'Agencia compartida';
            }
        }
        unset($group);
    }
}
$projectsGroups = array_values($projectsGroups);
usort($projectsGroups, static fn(array $a, array $b): int => $a['key'] === 'own' ? -1 : ($b['key'] === 'own' ? 1 : strcasecmp($a['name'], $b['name'])));

$avatar = (string)($user['avatar'] ?? '');
$avatarUrl = null;
if ($avatar !== '' && is_file(__DIR__ . '/../../' . ltrim($avatar, '/'))) {
    $avatarUrl = '/' . ltrim($avatar, '/');
}
$logo = (string)($agency['logo'] ?? '');
$logoUrl = ($logo !== '' && is_file(__DIR__ . '/../../' . ltrim($logo, '/'))) ? '/' . ltrim($logo, '/') : null;

appJson([
    'user' => [
        'id' => $userId,
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $role,
        'roleLabel' => getRoleBadge($role)['label'],
        'avatarUrl' => $avatarUrl,
    ],
    'agency' => [
        'id' => $agencyId,
        'name' => $agency['name'],
        'logoUrl' => $logoUrl,
    ],
    'csrfToken' => csrf_token(),
    'modules' => $modules,
    'navGroups' => $navGroups,
    'projectsGroups' => $projectsGroups,
    'settingsHref' => '/app/settings',
    'today' => date('Y-m-d'),
]);

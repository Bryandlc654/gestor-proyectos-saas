<?php
// dashboard.php - Panel Principal de Analítica, Estadísticas y Gráficos Visuales
require_once __DIR__ . '/config/app.php';
requireLogin();

define('PAGE_TITLE', 'Dashboard - Analíticas');
$user = currentUser();
$db = getDB();

$userRole = currentUserRole();
$roleBadge = getRoleBadge($userRole);
$userId = (int)$user['id'];

// Consultas estadísticas filtradas según el nivel de acceso (Admin, PM, Colaborador o Cliente)
if ($userRole === 'admin') {
    // 1. Métricas Globales
    $stmtGlobal = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM projects) as total_projects,
            (SELECT COUNT(*) FROM projects WHERE status = 'activo') as active_projects,
            (SELECT COUNT(*) FROM tickets WHERE status IN ('abierto', 'en_revision')) as open_tickets,
            (SELECT COUNT(*) FROM tickets WHERE status = 'resuelto') as resolved_tickets,
            COALESCE(SUM(CASE WHEN t.status = 'pendiente' THEN 1 ELSE 0 END), 0) as pending_tasks,
            COALESCE(SUM(CASE WHEN t.status = 'en_progreso' THEN 1 ELSE 0 END), 0) as in_progress_tasks,
            COALESCE(SUM(CASE WHEN t.status = 'completada' THEN 1 ELSE 0 END), 0) as completed_tasks,
            COUNT(t.id) as total_tasks
        FROM tasks t
    ");
    $globalStats = $stmtGlobal->fetch() ?: [];

    // 2. Tareas por Prioridad
    $stmtPrio = $db->query("
        SELECT priority, COUNT(*) as count 
        FROM tasks 
        GROUP BY priority
    ");
    $tasksByPriority = $stmtPrio->fetchAll();

    // 3. Proyectos por Categoría
    $stmtCat = $db->query("
        SELECT COALESCE(NULLIF(category, ''), 'General') as category_name, COUNT(*) as count 
        FROM projects 
        GROUP BY category
    ");
    $projectsByCategory = $stmtCat->fetchAll();

    // 4. Progreso y Carga de Tareas por Proyecto
    $stmtProg = $db->query("
        SELECT 
            p.id, 
            p.name, 
            p.color,
            COUNT(t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'completada' THEN 1 ELSE 0 END) as completed_tasks
        FROM projects p
        LEFT JOIN tasks t ON p.id = t.project_id
        GROUP BY p.id
        ORDER BY total_tasks DESC
        LIMIT 6
    ");
    $projectProgressList = $stmtProg->fetchAll();

    // 5. Estadísticas Financieras (Solo Admin)
    $stmtFin = $db->query("
        SELECT 
            COALESCE(SUM(budget), 0) as total_budget,
            (SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE status = 'pagada') as total_collected,
            (SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE status = 'pendiente') as total_pending
        FROM projects
    ");
    $financialStats = $stmtFin->fetch() ?: ['total_budget' => 0, 'total_collected' => 0, 'total_pending' => 0];

    // 6. Tickets por Estado
    $stmtTicketsStatus = $db->query("
        SELECT status, COUNT(*) as count 
        FROM tickets 
        GROUP BY status
    ");
    $ticketsByStatus = $stmtTicketsStatus->fetchAll();

} elseif ($userRole === 'cliente') {
    // Para el Cliente: Métricas estrictamente de los proyectos a los que pertenece
    $stmtGlobal = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM project_members WHERE user_id = ?) as total_projects,
            (SELECT COUNT(*) FROM projects p INNER JOIN project_members pm ON p.id = pm.project_id WHERE pm.user_id = ? AND p.status = 'activo') as active_projects,
            (SELECT COUNT(*) FROM tickets t INNER JOIN project_members pm ON t.project_id = pm.project_id WHERE pm.user_id = ? AND t.status IN ('abierto', 'en_revision')) as open_tickets,
            (SELECT COUNT(*) FROM tickets t INNER JOIN project_members pm ON t.project_id = pm.project_id WHERE pm.user_id = ? AND t.status = 'resuelto') as resolved_tickets,
            COALESCE(SUM(CASE WHEN t.status = 'pendiente' THEN 1 ELSE 0 END), 0) as pending_tasks,
            COALESCE(SUM(CASE WHEN t.status = 'en_progreso' THEN 1 ELSE 0 END), 0) as in_progress_tasks,
            COALESCE(SUM(CASE WHEN t.status = 'completada' THEN 1 ELSE 0 END), 0) as completed_tasks,
            COUNT(t.id) as total_tasks
        FROM tasks t
        INNER JOIN project_members pm ON t.project_id = pm.project_id
        WHERE pm.user_id = ?
    ");
    $stmtGlobal->execute([$userId, $userId, $userId, $userId, $userId]);
    $globalStats = $stmtGlobal->fetch() ?: [];

    $stmtPrio = $db->prepare("
        SELECT t.priority, COUNT(*) as count 
        FROM tasks t
        INNER JOIN project_members pm ON t.project_id = pm.project_id
        WHERE pm.user_id = ?
        GROUP BY t.priority
    ");
    $stmtPrio->execute([$userId]);
    $tasksByPriority = $stmtPrio->fetchAll();

    $stmtCat = $db->prepare("
        SELECT COALESCE(NULLIF(p.category, ''), 'General') as category_name, COUNT(*) as count 
        FROM projects p
        INNER JOIN project_members pm ON p.id = pm.project_id
        WHERE pm.user_id = ?
        GROUP BY p.category
    ");
    $stmtCat->execute([$userId]);
    $projectsByCategory = $stmtCat->fetchAll();

    $stmtProg = $db->prepare("
        SELECT 
            p.id, 
            p.name, 
            p.color,
            COUNT(t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'completada' THEN 1 ELSE 0 END) as completed_tasks
        FROM projects p
        INNER JOIN project_members pm ON p.id = pm.project_id
        LEFT JOIN tasks t ON p.id = t.project_id
        WHERE pm.user_id = ?
        GROUP BY p.id
        ORDER BY total_tasks DESC
        LIMIT 6
    ");
    $stmtProg->execute([$userId]);
    $projectProgressList = $stmtProg->fetchAll();

    $stmtTicketsStatus = $db->prepare("
        SELECT t.status, COUNT(*) as count 
        FROM tickets t
        INNER JOIN project_members pm ON t.project_id = pm.project_id
        WHERE pm.user_id = ?
        GROUP BY t.status
    ");
    $stmtTicketsStatus->execute([$userId]);
    $ticketsByStatus = $stmtTicketsStatus->fetchAll();

    $financialStats = ['total_budget' => 0, 'total_collected' => 0, 'total_pending' => 0];

} else {
    // PM y Colaborador
    $stmtGlobal = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM projects WHERE user_id = ? OR id IN (SELECT project_id FROM project_members WHERE user_id = ?)) as total_projects,
            (SELECT COUNT(*) FROM projects WHERE (user_id = ? OR id IN (SELECT project_id FROM project_members WHERE user_id = ?)) AND status = 'activo') as active_projects,
            (SELECT COUNT(*) FROM tickets t INNER JOIN projects p ON t.project_id = p.id WHERE (p.user_id = ? OR p.id IN (SELECT project_id FROM project_members WHERE user_id = ?)) AND t.status IN ('abierto', 'en_revision')) as open_tickets,
            (SELECT COUNT(*) FROM tickets t INNER JOIN projects p ON t.project_id = p.id WHERE (p.user_id = ? OR p.id IN (SELECT project_id FROM project_members WHERE user_id = ?)) AND t.status = 'resuelto') as resolved_tickets,
            COALESCE(SUM(CASE WHEN t.status = 'pendiente' THEN 1 ELSE 0 END), 0) as pending_tasks,
            COALESCE(SUM(CASE WHEN t.status = 'en_progreso' THEN 1 ELSE 0 END), 0) as in_progress_tasks,
            COALESCE(SUM(CASE WHEN t.status = 'completada' THEN 1 ELSE 0 END), 0) as completed_tasks,
            COUNT(t.id) as total_tasks
        FROM tasks t
        INNER JOIN projects p ON t.project_id = p.id
        WHERE p.user_id = ? OR p.id IN (SELECT project_id FROM project_members WHERE user_id = ?)
    ");
    $stmtGlobal->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    $globalStats = $stmtGlobal->fetch() ?: [];

    $stmtPrio = $db->prepare("
        SELECT t.priority, COUNT(*) as count 
        FROM tasks t
        INNER JOIN projects p ON t.project_id = p.id
        WHERE p.user_id = ? OR p.id IN (SELECT project_id FROM project_members WHERE user_id = ?)
        GROUP BY t.priority
    ");
    $stmtPrio->execute([$userId, $userId]);
    $tasksByPriority = $stmtPrio->fetchAll();

    $stmtCat = $db->prepare("
        SELECT COALESCE(NULLIF(p.category, ''), 'General') as category_name, COUNT(*) as count 
        FROM projects p
        WHERE p.user_id = ? OR p.id IN (SELECT project_id FROM project_members WHERE user_id = ?)
        GROUP BY p.category
    ");
    $stmtCat->execute([$userId, $userId]);
    $projectsByCategory = $stmtCat->fetchAll();

    $stmtProg = $db->prepare("
        SELECT 
            p.id, 
            p.name, 
            p.color,
            COUNT(t.id) as total_tasks,
            SUM(CASE WHEN t.status = 'completada' THEN 1 ELSE 0 END) as completed_tasks
        FROM projects p
        LEFT JOIN tasks t ON p.id = t.project_id
        WHERE p.user_id = ? OR p.id IN (SELECT project_id FROM project_members WHERE user_id = ?)
        GROUP BY p.id
        ORDER BY total_tasks DESC
        LIMIT 6
    ");
    $stmtProg->execute([$userId, $userId]);
    $projectProgressList = $stmtProg->fetchAll();

    $stmtTicketsStatus = $db->prepare("
        SELECT t.status, COUNT(*) as count 
        FROM tickets t
        INNER JOIN projects p ON t.project_id = p.id
        WHERE p.user_id = ? OR p.id IN (SELECT project_id FROM project_members WHERE user_id = ?)
        GROUP BY t.status
    ");
    $stmtTicketsStatus->execute([$userId, $userId]);
    $ticketsByStatus = $stmtTicketsStatus->fetchAll();

    $financialStats = ['total_budget' => 0, 'total_collected' => 0, 'total_pending' => 0];
}

// Variables procesadas para las tarjetas
$totalProjects   = (int)($globalStats['total_projects'] ?? 0);
$openTickets     = (int)($globalStats['open_tickets'] ?? 0);
$resolvedTickets = (int)($globalStats['resolved_tickets'] ?? 0);
$totalTasks      = (int)($globalStats['total_tasks'] ?? 0);
$pendingTasks    = (int)($globalStats['pending_tasks'] ?? 0);
$inProgressTasks = (int)($globalStats['in_progress_tasks'] ?? 0);
$completedTasks  = (int)($globalStats['completed_tasks'] ?? 0);

$completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

// Preparación de datasets JSON para Chart.js
// 1. Estado de tareas
$chartTasksData = [
    'labels' => ['Pendientes', 'En Curso', 'Completadas'],
    'data'   => [$pendingTasks, $inProgressTasks, $completedTasks],
    'colors' => ['#94A3B8', '#F59E0B', '#10B981']
];

// 2. Prioridades de tareas
$prioLabels = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'urgente' => 'Urgente'];
$prioColors = ['baja' => '#94A3B8', 'media' => '#6366F1', 'alta' => '#F59E0B', 'urgente' => '#EF4444'];
$chartPrioLabels = [];
$chartPrioData = [];
$chartPrioColors = [];
foreach ($prioLabels as $k => $label) {
    $found = 0;
    foreach ($tasksByPriority as $tp) {
        if (strtolower($tp['priority']) === $k) {
            $found = (int)$tp['count'];
            break;
        }
    }
    $chartPrioLabels[] = $label;
    $chartPrioData[] = $found;
    $chartPrioColors[] = $prioColors[$k];
}

// 3. Proyectos por Categoría
$chartCatLabels = [];
$chartCatData = [];
foreach ($projectsByCategory as $cat) {
    $chartCatLabels[] = $cat['category_name'];
    $chartCatData[] = (int)$cat['count'];
}

// 4. Progreso de Proyectos
$chartProjLabels = [];
$chartProjCompleted = [];
$chartProjPending = [];
foreach ($projectProgressList as $pr) {
    $tTotal = (int)$pr['total_tasks'];
    $tComp = (int)$pr['completed_tasks'];
    $chartProjLabels[] = strlen($pr['name']) > 18 ? substr($pr['name'], 0, 16) . '…' : $pr['name'];
    $chartProjCompleted[] = $tComp;
    $chartProjPending[] = max(0, $tTotal - $tComp);
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- Contenido Principal -->
<main class="flex-1 flex flex-col min-w-0 bg-[#F8FAFC] overflow-y-auto">
    <!-- Barra Superior -->
    <header class="min-h-[4.5rem] sm:min-h-[5rem] py-4 sm:py-5 bg-white border-b border-slate-200/80 px-6 sm:px-10 flex items-center justify-between sticky top-0 z-10">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="p-2 -ml-2 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition" title="Alternar menú lateral">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div>
                <h1 class="font-display font-bold text-lg sm:text-xl text-slate-900 tracking-tight">Estadísticas & Métricas</h1>
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/50">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>Datos en Tiempo Real</span>
            </span>
        </div>
    </header>

    <div class="p-4 sm:p-8 max-w-7xl w-full mx-auto space-y-6">

        <!-- SKELETON PRELOADER (Se oculta suavemente al cargar los gráficos) -->
        <div id="dashboard-skeleton" class="space-y-6 transition-opacity duration-300">
            <!-- 4 KPI Cards Skeleton -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="skeleton h-3 w-20 rounded"></div>
                            <div class="skeleton w-8 h-8 rounded-xl"></div>
                        </div>
                        <div class="flex items-baseline justify-between pt-1">
                            <div class="skeleton h-7 w-16 rounded-md"></div>
                            <div class="skeleton h-3 w-12 rounded"></div>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>

            <!-- Gráficos Skeleton -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <?php for ($j = 0; $j < 3; $j++): ?>
                    <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="skeleton h-4 w-28 rounded"></div>
                            <div class="skeleton h-3 w-12 rounded"></div>
                        </div>
                        <div class="skeleton h-52 w-full rounded-xl"></div>
                        <div class="skeleton h-3 w-3/4 mx-auto rounded"></div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- CONTENIDO REAL DASHBOARD (Oculto inicialmente hasta completar renderizado) -->
        <div id="dashboard-content" class="space-y-6 hidden">
            <!-- 4 Tarjetas Métricas Principales (KPIs) -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <!-- 1. Total Proyectos -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Proyectos Activos</span>
                        <div class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <i data-lucide="folder-kanban" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <span class="text-3xl font-display font-black text-slate-900"><?= $totalProjects ?></span>
                        <span class="text-xs text-slate-400 font-medium">monitoreados</span>
                    </div>
                </div>

                <!-- 2. Tasa de Finalización -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Efectividad Tareas</span>
                        <div class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i data-lucide="trending-up" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <span class="text-3xl font-display font-black text-emerald-600"><?= $completionRate ?>%</span>
                        <span class="text-xs text-slate-400 font-medium"><?= $completedTasks ?> de <?= $totalTasks ?></span>
                    </div>
                </div>

                <!-- 3. Tareas en Curso -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">En Ejecución</span>
                        <div class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                            <i data-lucide="activity" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <span class="text-3xl font-display font-black text-amber-600"><?= $inProgressTasks ?></span>
                        <span class="text-xs text-slate-400 font-medium"><?= $pendingTasks ?> en cola</span>
                    </div>
                </div>

                <!-- 4. Tickets de Soporte -->
                <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Tickets Pendientes</span>
                        <div class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                            <i data-lucide="life-buoy" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <div class="flex items-baseline justify-between">
                        <span class="text-3xl font-display font-black <?= $openTickets > 0 ? 'text-rose-600' : 'text-slate-900' ?>"><?= $openTickets ?></span>
                        <span class="text-xs text-slate-400 font-medium"><?= $resolvedTickets ?> resueltos</span>
                    </div>
                </div>
            </div>

        <?php if ($userRole === 'admin' && ((float)$financialStats['total_budget'] > 0 || (float)$financialStats['total_collected'] > 0)): ?>
        <!-- Fila Especial Financiera (Solo Admin) -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Presupuesto Global</span>
                    <span class="text-xl sm:text-2xl font-display font-black text-slate-900">
                        USD $<?= number_format(floatval($financialStats['total_budget']), 2) ?>
                    </span>
                </div>
                <div class="w-10 h-10 rounded-xl bg-slate-50 text-slate-600 flex items-center justify-center">
                    <i data-lucide="wallet" class="w-5 h-5"></i>
                </div>
            </div>

            <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Monto Cobrado</span>
                    <span class="text-xl sm:text-2xl font-display font-black text-emerald-600">
                        USD $<?= number_format(floatval($financialStats['total_collected']), 2) ?>
                    </span>
                </div>
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <i data-lucide="badge-check" class="w-5 h-5"></i>
                </div>
            </div>

            <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex items-center justify-between">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Pendiente de Cobro</span>
                    <span class="text-xl sm:text-2xl font-display font-black text-amber-600">
                        USD $<?= number_format(floatval($financialStats['total_pending']), 2) ?>
                    </span>
                </div>
                <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <i data-lucide="clock" class="w-5 h-5"></i>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- GRID DE GRÁFICOS VISUALES -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Gráfico 1: Desglose de Estado de Tareas (Doughnut) -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col justify-between">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-display font-bold text-sm text-slate-900 flex items-center gap-2">
                        <i data-lucide="pie-chart" class="w-4 h-4 text-brand-600"></i>
                        <span>Estado de Tareas</span>
                    </h3>
                    <span class="text-xs font-semibold text-slate-400"><?= $totalTasks ?> totales</span>
                </div>
                <div class="relative flex items-center justify-center h-56">
                    <canvas id="tasksStatusChart"></canvas>
                </div>
                <div class="grid grid-cols-3 gap-2 pt-4 mt-3 border-t border-slate-100 text-center text-xs">
                    <div>
                        <span class="text-[10px] text-slate-400 block uppercase font-semibold">Pendientes</span>
                        <span class="font-bold text-slate-700"><?= $pendingTasks ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 block uppercase font-semibold">En Curso</span>
                        <span class="font-bold text-amber-600"><?= $inProgressTasks ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 block uppercase font-semibold">Completas</span>
                        <span class="font-bold text-emerald-600"><?= $completedTasks ?></span>
                    </div>
                </div>
            </div>

            <!-- Gráfico 2: Carga de Tareas por Nivel de Prioridad (Bar) -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col justify-between">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-display font-bold text-sm text-slate-900 flex items-center gap-2">
                        <i data-lucide="bar-chart-2" class="w-4 h-4 text-indigo-600"></i>
                        <span>Tareas por Prioridad</span>
                    </h3>
                    <span class="text-xs font-semibold text-slate-400">Distribución</span>
                </div>
                <div class="relative flex items-center justify-center h-56">
                    <canvas id="tasksPriorityChart"></canvas>
                </div>
                <div class="flex items-center justify-around pt-4 mt-3 border-t border-slate-100 text-center text-xs">
                    <?php foreach ($chartPrioLabels as $idx => $pl): ?>
                        <div>
                            <span class="text-[10px] text-slate-400 block uppercase font-semibold"><?= $pl ?></span>
                            <span class="font-bold text-slate-800"><?= $chartPrioData[$idx] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Gráfico 3: Proyectos por Categoría (Polar/Doughnut) -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col justify-between">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-display font-bold text-sm text-slate-900 flex items-center gap-2">
                        <i data-lucide="layers" class="w-4 h-4 text-purple-600"></i>
                        <span>Categorías de Proyecto</span>
                    </h3>
                    <span class="text-xs font-semibold text-slate-400"><?= count($chartCatLabels) ?> ramas</span>
                </div>
                <div class="relative flex items-center justify-center h-56">
                    <?php if (empty($chartCatLabels)): ?>
                        <div class="text-xs text-slate-400 italic">No hay categorías registradas</div>
                    <?php else: ?>
                        <canvas id="projectsCategoryChart"></canvas>
                    <?php endif; ?>
                </div>
                <div class="pt-4 mt-3 border-t border-slate-100 text-center text-xs text-slate-500">
                    Clasificación de áreas de trabajo activas
                </div>
            </div>
        </div>

        <!-- Gráfico 4: Desempeño y Cumplimiento por Proyecto (Stacked Bar Chart Horizontal) -->
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h3 class="font-display font-bold text-base text-slate-900 flex items-center gap-2">
                        <i data-lucide="kanban" class="w-4 h-4 text-brand-600"></i>
                        <span>Progreso de Tareas por Proyecto</span>
                    </h3>
                    <p class="text-xs text-slate-400">Comparativa de tareas completadas frente a pendientes</p>
                </div>
                <div class="flex items-center gap-4 text-xs">
                    <span class="flex items-center gap-1.5 text-slate-600">
                        <span class="w-2.5 h-2.5 rounded bg-emerald-500"></span> Completadas
                    </span>
                    <span class="flex items-center gap-1.5 text-slate-600">
                        <span class="w-2.5 h-2.5 rounded bg-slate-300"></span> Pendientes
                    </span>
                </div>
            </div>

            <div class="relative h-64 sm:h-72 w-full">
                <?php if (empty($chartProjLabels)): ?>
                    <div class="h-full flex items-center justify-center text-slate-400 text-xs italic">
                        No hay proyectos con tareas para graficar
                    </div>
                <?php else: ?>
                    <canvas id="projectsComparisonChart"></canvas>
                <?php endif; ?>
            </div>
        </div>

        </div><!-- /#dashboard-content -->

    </div>
</main>

<!-- Inicialización de Gráficos con Chart.js -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Gráfico Estado de Tareas (Doughnut)
    const ctxTasks = document.getElementById('tasksStatusChart');
    if (ctxTasks) {
        new Chart(ctxTasks, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($chartTasksData['labels']) ?>,
                datasets: [{
                    data: <?= json_encode($chartTasksData['data']) ?>,
                    backgroundColor: <?= json_encode($chartTasksData['colors']) ?>,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, font: { size: 11, family: 'Inter' } }
                    }
                },
                cutout: '70%'
            }
        });
    }

    // 2. Gráfico Prioridades (Bar)
    const ctxPrio = document.getElementById('tasksPriorityChart');
    if (ctxPrio) {
        new Chart(ctxPrio, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartPrioLabels) ?>,
                datasets: [{
                    data: <?= json_encode($chartPrioData) ?>,
                    backgroundColor: <?= json_encode($chartPrioColors) ?>,
                    borderRadius: 8,
                    barThickness: 28
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: 10, family: 'Inter' } },
                        grid: { color: '#F1F5F9' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, family: 'Inter' } }
                    }
                }
            }
        });
    }

    // 3. Gráfico Categorías (Doughnut)
    const ctxCat = document.getElementById('projectsCategoryChart');
    if (ctxCat) {
        new Chart(ctxCat, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($chartCatLabels) ?>,
                datasets: [{
                    data: <?= json_encode($chartCatData) ?>,
                    backgroundColor: ['#6366F1', '#3B82F6', '#10B981', '#EC4899', '#8B5CF6', '#F59E0B'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, font: { size: 11, family: 'Inter' } }
                    }
                },
                cutout: '65%'
            }
        });
    }

    // 4. Comparativa de Proyectos (Horizontal Stacked Bar)
    const ctxProj = document.getElementById('projectsComparisonChart');
    if (ctxProj) {
        new Chart(ctxProj, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartProjLabels) ?>,
                datasets: [
                    {
                        label: 'Completadas',
                        data: <?= json_encode($chartProjCompleted) ?>,
                        backgroundColor: '#10B981',
                        borderRadius: 6,
                        stack: 'tasksStack'
                    },
                    {
                        label: 'Pendientes',
                        data: <?= json_encode($chartProjPending) ?>,
                        backgroundColor: '#CBD5E1',
                        borderRadius: 6,
                        stack: 'tasksStack'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: 10, family: 'Inter' } },
                        grid: { color: '#F1F5F9' }
                    },
                    y: {
                        stacked: true,
                        grid: { display: false },
                        ticks: { font: { size: 11, family: 'Inter' } }
                    }
                }
            }
        });
    }

    // Transición suave del Skeleton al Contenido Real
    const skel = document.getElementById('dashboard-skeleton');
    const content = document.getElementById('dashboard-content');
    if (skel && content) {
        skel.style.opacity = '0';
        setTimeout(() => {
            skel.classList.add('hidden');
            content.classList.remove('hidden');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }, 150);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

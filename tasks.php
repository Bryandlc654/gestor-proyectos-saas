<?php
// tasks.php - Módulo General de Tareas (Tablero Global Kanban y Vista Lista de Todos los Proyectos)
require_once __DIR__ . '/config/app.php';
requireLogin();

define('PAGE_TITLE', 'Módulo de Tareas Global');
$user = currentUser();
$db = getDB();

$userRole = currentUserRole();
$roleBadge = getRoleBadge($userRole);
$userId = (int)$user['id'];
$isClient = isCliente();

// Permisos generales
$canManageTasks = canManageTasks($userRole);
$canChangeStatus = canChangeTaskStatus($userRole);
$canDeleteTasks = canDeleteTasks($userRole);

// Filtros
$filterProject = (int)($_GET['project_id'] ?? 0);
$filterPriority = trim($_GET['priority'] ?? 'todas');
$filterAssigned = trim($_GET['assigned_to'] ?? 'todos');
$search = trim($_GET['search'] ?? '');
$currentView = $_GET['view'] ?? 'kanban';
if (!in_array($currentView, ['kanban', 'list'])) {
    $currentView = 'kanban';
}

// 1. Proyectos visibles para el usuario (siempre dentro de su agencia)
[$visibleSql, $visibleParams] = visibleProjectsSql();
$stmtP = $db->prepare("SELECT id, name, color FROM projects WHERE id IN ({$visibleSql}) ORDER BY name ASC");
$stmtP->execute($visibleParams);
$projectsList = $stmtP->fetchAll();
$allowedProjectIds = array_column($projectsList, 'id');

// Colaboradores para el filtro: solo miembros (o propietarios) de los proyectos visibles
if (empty($allowedProjectIds)) {
    $collaborators = [];
} else {
    $inCollab = implode(',', array_fill(0, count($allowedProjectIds), '?'));
    $stmtCollab = $db->prepare("
        SELECT DISTINCT u.id, u.name
        FROM users u
        WHERE u.id IN (SELECT user_id FROM project_members WHERE project_id IN ($inCollab))
           OR u.id IN (SELECT user_id FROM projects WHERE id IN ($inCollab))
        ORDER BY u.name ASC
    ");
    $stmtCollab->execute(array_merge($allowedProjectIds, $allowedProjectIds));
    $collaborators = $stmtCollab->fetchAll();
}

// 2. Construir consulta de Tareas Globales
$where = [];
$params = [];

if (empty($allowedProjectIds)) {
    $where[] = "1 = 0";
} else {
    if ($filterProject > 0 && in_array($filterProject, $allowedProjectIds)) {
        $where[] = "t.project_id = ?";
        $params[] = $filterProject;
    } else {
        $inPlaceholders = implode(',', array_fill(0, count($allowedProjectIds), '?'));
        $where[] = "t.project_id IN ($inPlaceholders)";
        $params = array_merge($params, $allowedProjectIds);
    }
}

if ($filterPriority !== 'todas' && in_array($filterPriority, ['baja', 'media', 'alta', 'urgente'])) {
    $where[] = "t.priority = ?";
    $params[] = $filterPriority;
}

if ($filterAssigned !== 'todos' && !empty($filterAssigned)) {
    if ($filterAssigned === 'sin_asignar') {
        $where[] = "t.assigned_user_id IS NULL AND (t.assigned_to IS NULL OR t.assigned_to = '')";
    } else {
        $where[] = "t.assigned_user_id = ?";
        $params[] = (int)$filterAssigned;
    }
}

if (!empty($search)) {
    $where[] = "(t.title LIKE ? OR t.description LIKE ? OR p.name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Totales por estado (sobre todos los resultados, no solo la página actual)
$stmtTotals = $db->prepare("
    SELECT t.status, COUNT(*) AS total
    FROM tasks t
    INNER JOIN projects p ON t.project_id = p.id
    $whereSql
    GROUP BY t.status
");
$stmtTotals->execute($params);
$statusTotals = array_merge(['pendiente' => 0, 'en_progreso' => 0, 'completada' => 0], $stmtTotals->fetchAll(PDO::FETCH_KEY_PAIR));
$totalTasksCount = array_sum($statusTotals);

$pagination = paginate($totalTasksCount, 60);

$query = "
    SELECT 
        t.*,
        COALESCE(au.name, t.assigned_to) AS assigned_to,
        p.name as project_name,
        p.color as project_color
    FROM tasks t
    INNER JOIN projects p ON t.project_id = p.id
    LEFT JOIN users au ON au.id = t.assigned_user_id
    $whereSql
    ORDER BY (t.priority = 'urgente') DESC, (t.priority = 'alta') DESC, t.due_date ASC, t.id DESC
    LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}
";

$stmtTasks = $db->prepare($query);
$stmtTasks->execute($params);
$allTasks = $stmtTasks->fetchAll();

// Contadores de recursos solo para las tareas de esta página (3 consultas agrupadas en lugar de 3 subconsultas por fila)
$taskIds = array_column($allTasks, 'id');
$resourceCounts = ['comments' => [], 'project_files' => [], 'checklists' => []];
if (!empty($taskIds)) {
    $inTasks = implode(',', array_fill(0, count($taskIds), '?'));
    foreach (array_keys($resourceCounts) as $table) {
        $stmtCount = $db->prepare("SELECT task_id, COUNT(*) FROM {$table} WHERE task_id IN ($inTasks) GROUP BY task_id");
        $stmtCount->execute($taskIds);
        $resourceCounts[$table] = $stmtCount->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
foreach ($allTasks as &$t) {
    $t['comments_count'] = (int)($resourceCounts['comments'][$t['id']] ?? 0);
    $t['files_count'] = (int)($resourceCounts['project_files'][$t['id']] ?? 0);
    $t['checklists_count'] = (int)($resourceCounts['checklists'][$t['id']] ?? 0);
}
unset($t);

// Clasificación por columnas Kanban (tareas de la página actual)
$tasksPending = array_filter($allTasks, fn($t) => $t['status'] === 'pendiente');
$tasksInProgress = array_filter($allTasks, fn($t) => $t['status'] === 'en_progreso');
$tasksCompleted = array_filter($allTasks, fn($t) => $t['status'] === 'completada');

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- Contenedor Principal de Tareas -->
<main class="flex-1 flex flex-col min-w-0 bg-slate-50 overflow-y-auto">
    <!-- Barra Superior -->
    <header class="h-16 flex-shrink-0 bg-white/90 backdrop-blur border-b border-slate-200/70 px-4 sm:px-8 flex items-center justify-between gap-3 sticky top-0 z-10">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="p-1.5 -ml-1.5 rounded-md text-slate-400 hover:text-slate-900 hover:bg-slate-100 transition" title="Alternar menú lateral">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div>
                <h1 class="font-semibold text-base sm:text-lg text-slate-900 tracking-tight">Tareas</h1>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <!-- Alternador de Vista (Kanban / Lista) -->
            <div class="bg-slate-100 p-0.5 rounded-xl flex items-center">
                <button onclick="switchGlobalView('kanban')" id="btn-view-kanban" class="px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 <?= $currentView === 'kanban' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800' ?> transition">
                    <i data-lucide="columns-3" class="w-3.5 h-3.5"></i>
                    <span class="hidden sm:inline">Tablero</span>
                </button>
                <button onclick="switchGlobalView('list')" id="btn-view-list" class="px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 <?= $currentView === 'list' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800' ?> transition">
                    <i data-lucide="list" class="w-3.5 h-3.5"></i>
                    <span class="hidden sm:inline">Lista</span>
                </button>
            </div>

            <?php if ($canManageTasks && !empty($projectsList)): ?>
                <button onclick="openModal('create-global-task-modal')" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-sm shadow-brand-500/25 transition">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    <span>Nueva Tarea</span>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <div class="p-4 sm:p-8 max-w-7xl w-full mx-auto space-y-6">

        <!-- Barra de Búsqueda y Filtros Multi-Proyecto -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs">
            <form method="GET" action="tasks.php" id="filters-form" class="flex flex-col md:flex-row items-center justify-between gap-3">
                <input type="hidden" name="view" id="filter-view-input" value="<?= e($currentView) ?>">

                <div class="relative flex-1 w-full">
                    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Buscar tarea por título, detalle o proyecto..." class="w-full pl-10 pr-4 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-brand-500 bg-white">
                </div>

                <div class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                    <!-- Filtro Proyecto -->
                    <select name="project_id" onchange="document.getElementById('filters-form').submit()" class="px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white text-slate-700 font-medium max-w-[180px] truncate">
                        <option value="0">Todos los proyectos</option>
                        <?php foreach ($projectsList as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $filterProject === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= e($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Filtro Prioridad -->
                    <select name="priority" onchange="document.getElementById('filters-form').submit()" class="px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white text-slate-700 font-medium">
                        <option value="todas" <?= $filterPriority === 'todas' ? 'selected' : '' ?>>Prioridad: Todas</option>
                        <option value="urgente" <?= $filterPriority === 'urgente' ? 'selected' : '' ?>>Urgente</option>
                        <option value="alta" <?= $filterPriority === 'alta' ? 'selected' : '' ?>>Alta</option>
                        <option value="media" <?= $filterPriority === 'media' ? 'selected' : '' ?>>Media</option>
                        <option value="baja" <?= $filterPriority === 'baja' ? 'selected' : '' ?>>Baja</option>
                    </select>

                    <!-- Filtro Responsable -->
                    <select name="assigned_to" onchange="document.getElementById('filters-form').submit()" class="px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white text-slate-700 font-medium max-w-[160px] truncate">
                        <option value="todos" <?= $filterAssigned === 'todos' ? 'selected' : '' ?>>Todos los miembros</option>
                        <option value="sin_asignar" <?= $filterAssigned === 'sin_asignar' ? 'selected' : '' ?>>Sin asignar</option>
                        <?php foreach ($collaborators as $col): ?>
                            <option value="<?= (int)$col['id'] ?>" <?= $filterAssigned === (string)$col['id'] ? 'selected' : '' ?>>
                                <?= e($col['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if (!empty($search) || $filterProject > 0 || $filterPriority !== 'todas' || $filterAssigned !== 'todos'): ?>
                        <a href="tasks.php?view=<?= e($currentView) ?>" class="px-3 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition whitespace-nowrap">
                            Limpiar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Resumen de Contadores Rápidos -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-xs font-semibold text-slate-500">
                    Total: <strong class="text-slate-900"><?= $totalTasksCount ?></strong> tareas
                </span>
                <span class="text-slate-300">•</span>
                <span class="inline-flex items-center gap-1 text-xs text-slate-500">
                    <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                    <span><?= $statusTotals['pendiente'] ?> por hacer</span>
                </span>
                <span class="inline-flex items-center gap-1 text-xs text-amber-600 font-medium">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <span><?= $statusTotals['en_progreso'] ?> en curso</span>
                </span>
                <span class="inline-flex items-center gap-1 text-xs text-emerald-600 font-medium">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span><?= $statusTotals['completada'] ?> completadas</span>
                </span>
            </div>
        </div>

        <!-- SKELETON PRELOADER PARA TAREAS -->
        <div id="tasks-skeleton" class="space-y-6 transition-opacity duration-200">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <?php for ($col = 0; $col < 3; $col++): ?>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between px-1">
                            <div class="skeleton h-4 w-24 rounded"></div>
                            <div class="skeleton h-4 w-6 rounded-full"></div>
                        </div>
                        <div class="space-y-3">
                            <?php for ($card = 0; $card < 3; $card++): ?>
                                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-3">
                                    <div class="flex items-center justify-between">
                                        <div class="skeleton h-3.5 w-20 rounded"></div>
                                        <div class="skeleton h-3.5 w-10 rounded"></div>
                                    </div>
                                    <div class="skeleton h-4 w-4/5 rounded"></div>
                                    <div class="skeleton h-3 w-full rounded"></div>
                                    <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
                                        <div class="skeleton h-3 w-16 rounded"></div>
                                        <div class="skeleton h-3 w-12 rounded"></div>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- CONTENIDO REAL DE TAREAS (Se revela al cargar) -->
        <div id="tasks-real-content" class="hidden space-y-6">

        <?php if (empty($allTasks)): ?>
            <!-- Estado Vacío -->
            <div class="bg-white rounded-3xl border border-dashed border-slate-200 p-12 text-center">
                <div class="w-14 h-14 bg-indigo-50 text-brand-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="check-square" class="w-7 h-7"></i>
                </div>
                <h3 class="text-base font-bold text-slate-800">No hay tareas que mostrar</h3>
                <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1 mb-5">
                    No se encontraron tareas con los filtros actuales o aún no hay tareas registradas en tus proyectos.
                </p>
                <?php if ($canManageTasks && !empty($projectsList)): ?>
                    <button onclick="openModal('create-global-task-modal')" class="inline-flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold rounded-xl shadow-xs transition">
                        <i data-lucide="plus" class="w-4 h-4"></i> Crear Primera Tarea
                    </button>
                <?php endif; ?>
            </div>
        <?php else: ?>

            <!-- ==================== VISTA 1: TABLERO KANBAN ==================== -->
            <div id="view-kanban-container" class="<?= $currentView === 'kanban' ? '' : 'hidden' ?>">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    
                    <!-- Columna 1: Por Hacer (Pendientes) -->
                    <div class="space-y-3">
                        <div class="flex items-center justify-between px-1">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span>
                                <h3 class="font-display font-semibold text-slate-800 text-sm">Por Hacer</h3>
                            </div>
                            <span class="text-xs font-medium text-slate-400 tabular-nums">
                                <?= $statusTotals['pendiente'] ?>
                            </span>
                        </div>

                        <div class="space-y-3 min-h-[300px]">
                            <?php if (empty($tasksPending)): ?>
                                <div class="p-6 rounded-2xl border border-dashed border-slate-200 bg-white/60 text-center text-xs text-slate-400">
                                    No hay tareas pendientes
                                </div>
                            <?php else: ?>
                                <?php foreach ($tasksPending as $task): ?>
                                    <?php include __DIR__ . '/includes/global-task-card.php'; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Columna 2: En Progreso -->
                    <div class="space-y-3">
                        <div class="flex items-center justify-between px-1">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                                <h3 class="font-display font-semibold text-slate-800 text-sm">En Progreso</h3>
                            </div>
                            <span class="text-xs font-medium text-slate-400 tabular-nums">
                                <?= $statusTotals['en_progreso'] ?>
                            </span>
                        </div>

                        <div class="space-y-3 min-h-[300px]">
                            <?php if (empty($tasksInProgress)): ?>
                                <div class="p-6 rounded-2xl border border-dashed border-slate-200 bg-white/60 text-center text-xs text-slate-400">
                                    No hay tareas en curso
                                </div>
                            <?php else: ?>
                                <?php foreach ($tasksInProgress as $task): ?>
                                    <?php include __DIR__ . '/includes/global-task-card.php'; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Columna 3: Completadas -->
                    <div class="space-y-3">
                        <div class="flex items-center justify-between px-1">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                                <h3 class="font-display font-semibold text-slate-800 text-sm">Completadas</h3>
                            </div>
                            <span class="text-xs font-medium text-slate-400 tabular-nums">
                                <?= $statusTotals['completada'] ?>
                            </span>
                        </div>

                        <div class="space-y-3 min-h-[300px]">
                            <?php if (empty($tasksCompleted)): ?>
                                <div class="p-6 rounded-2xl border border-dashed border-slate-200 bg-white/60 text-center text-xs text-slate-400">
                                    No hay tareas completadas
                                </div>
                            <?php else: ?>
                                <?php foreach ($tasksCompleted as $task): ?>
                                    <?php include __DIR__ . '/includes/global-task-card.php'; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ==================== VISTA 2: LISTA GENERAL ==================== -->
            <div id="view-list-container" class="<?= $currentView === 'list' ? '' : 'hidden' ?>">
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="bg-slate-50/70 border-b border-slate-100 text-[11px] text-slate-500 font-medium">
                                <tr>
                                    <th class="py-3 px-4">Tarea</th>
                                    <th class="py-3 px-4">Proyecto</th>
                                    <th class="py-3 px-4">Responsable</th>
                                    <th class="py-3 px-4">Prioridad</th>
                                    <th class="py-3 px-4">Vencimiento</th>
                                    <th class="py-3 px-4">Estado</th>
                                    <th class="py-3 px-4 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($allTasks as $task): ?>
                                    <?php
                                        $prioClass = match($task['priority']) {
                                            'urgente' => 'bg-rose-50 text-rose-700 border-rose-200/60',
                                            'alta'    => 'bg-amber-50 text-amber-700 border-amber-200/60',
                                            'media'   => 'bg-indigo-50 text-indigo-700 border-indigo-200/60',
                                            default   => 'bg-slate-50 text-slate-600 border-slate-200/60'
                                        };
                                    ?>
                                    <tr id="task-row-<?= $task['id'] ?>" class="hover:bg-slate-50/60 transition">
                                        <td class="py-3 px-4">
                                            <a href="project-view.php?id=<?= $task['project_id'] ?>#tasks" class="font-semibold text-slate-900 hover:text-brand-600 transition <?= $task['status'] === 'completada' ? 'line-through text-slate-400' : '' ?>">
                                                <?= e($task['title']) ?>
                                            </a>
                                            <?php if (!empty($task['description'])): ?>
                                                <div class="text-[11px] text-slate-400 truncate max-w-sm mt-0.5"><?= e($task['description']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 whitespace-nowrap">
                                            <a href="project-view.php?id=<?= $task['project_id'] ?>" class="inline-flex items-center gap-1.5 font-semibold text-slate-700 hover:text-brand-600 transition">
                                                <span class="w-2 h-2 rounded-full" style="background-color: <?= e($task['project_color'] ?: '#6366F1') ?>;"></span>
                                                <span><?= e($task['project_name']) ?></span>
                                            </a>
                                        </td>
                                        <td class="py-3 px-4 whitespace-nowrap text-slate-700">
                                            <?= e($task['assigned_to'] ?: 'Sin asignar') ?>
                                        </td>
                                        <td class="py-3 px-4 whitespace-nowrap">
                                            <span class="text-[11px] font-medium capitalize px-2 py-0.5 rounded-full <?= $prioClass ?>">
                                                <?= e($task['priority']) ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 whitespace-nowrap text-slate-500">
                                            <?= $task['due_date'] ? formatDate($task['due_date']) : '—' ?>
                                        </td>
                                        <td class="py-3 px-4 whitespace-nowrap">
                                            <?php if ($canChangeStatus): ?>
                                                <select onchange="updateTaskStatusGlobal(<?= $task['id'] ?>, <?= $task['project_id'] ?>, this.value)" class="text-[11px] font-semibold rounded-lg px-2 py-1 border border-slate-200 bg-white text-slate-700 cursor-pointer focus:ring-1 focus:ring-brand-500">
                                                    <option value="pendiente" <?= $task['status'] === 'pendiente' ? 'selected' : '' ?>>Por Hacer</option>
                                                    <option value="en_progreso" <?= $task['status'] === 'en_progreso' ? 'selected' : '' ?>>En Progreso</option>
                                                    <option value="completada" <?= $task['status'] === 'completada' ? 'selected' : '' ?>>Completada</option>
                                                </select>
                                            <?php else: ?>
                                                <span class="text-[10px] font-semibold text-slate-500 capitalize bg-slate-100 px-2 py-0.5 rounded-md">
                                                    <?= str_replace('_', ' ', $task['status']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-4 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center gap-1.5 justify-end">
                                                <a href="project-view.php?id=<?= $task['project_id'] ?>#tasks" class="p-1.5 rounded-lg text-slate-400 hover:text-brand-600 hover:bg-slate-100 transition" title="Ver en proyecto">
                                                    <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                                                </a>
                                                <?php if ($canDeleteTasks): ?>
                                                    <button onclick="deleteTaskGlobal(<?= $task['id'] ?>, <?= $task['project_id'] ?>)" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Eliminar">
                                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php require __DIR__ . '/includes/pagination.php'; ?>

        <?php endif; ?>
        </div><!-- /#tasks-real-content -->

    </div>
</main>

<!-- Modal: Crear Tarea Global -->
<div id="create-global-task-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs hidden transition-opacity">
    <div class="bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-slate-100 overflow-hidden transform transition-all">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                    <i data-lucide="check-square" class="w-4 h-4"></i>
                </div>
                <h3 class="font-display font-semibold text-slate-800 text-base">Crear Nueva Tarea</h3>
            </div>
            <button type="button" onclick="closeModal('create-global-task-modal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <form id="create-global-task-form" onsubmit="handleCreateGlobalTask(event)" class="p-6 space-y-4">
            <div>
                <label class="block text-xs font-medium text-slate-700 mb-1.5">Proyecto Destino *</label>
                <select id="global-task-project" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-brand-500 bg-white">
                    <option value="">Seleccionar proyecto...</option>
                    <?php foreach ($projectsList as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filterProject === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= e($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700 mb-1.5">Título de la Tarea *</label>
                <input type="text" id="global-task-title" required placeholder="Ej: Implementar pasarela de pagos" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-brand-500">
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700 mb-1.5">Descripción Detallada</label>
                <textarea id="global-task-desc" rows="3" placeholder="Requerimientos, especificaciones y notas..." class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-brand-500"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1.5">Prioridad</label>
                    <select id="global-task-prio" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                        <option value="baja">Baja</option>
                        <option value="media" selected>Media</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1.5">Vencimiento</label>
                    <input type="date" id="global-task-due" class="w-full px-3 py-1.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1.5">Responsable</label>
                    <select id="global-task-assigned" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                        <option value="">Sin asignar</option>
                        <?php foreach ($collaborators as $col): ?>
                            <option value="<?= (int)$col['id'] ?>"><?= e($col['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('create-global-task-modal')" class="px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 transition">Cancelar</button>
                <button type="submit" id="btn-submit-global-task" class="px-4 py-1.5 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-2xs transition">Crear Tarea</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchGlobalView(view) {
    const kanbanCont = document.getElementById('view-kanban-container');
    const listCont = document.getElementById('view-list-container');
    const btnKanban = document.getElementById('btn-view-kanban');
    const btnList = document.getElementById('btn-view-list');
    const inputView = document.getElementById('filter-view-input');

    if (view === 'kanban') {
        if (kanbanCont) kanbanCont.classList.remove('hidden');
        if (listCont) listCont.classList.add('hidden');
        btnKanban.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 bg-white text-slate-900 shadow-2xs transition';
        btnList.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 text-slate-500 hover:text-slate-800 transition';
        if (inputView) inputView.value = 'kanban';
    } else {
        if (kanbanCont) kanbanCont.classList.add('hidden');
        if (listCont) listCont.classList.remove('hidden');
        btnList.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 bg-white text-slate-900 shadow-2xs transition';
        btnKanban.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 text-slate-500 hover:text-slate-800 transition';
        if (inputView) inputView.value = 'list';
    }

    // Actualizar URL sin recargar
    const url = new URL(window.location);
    url.searchParams.set('view', view);
    window.history.replaceState({}, '', url);
}

async function updateTaskStatusGlobal(taskId, projectId, newStatus) {
    const fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('csrf_token', '<?= csrf_token() ?>');
    fd.append('project_id', projectId);
    fd.append('task_id', taskId);
    fd.append('status', newStatus);

    try {
        const res = await fetch('api/tasks.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Error al actualizar estado');
        }
    } catch (err) {
        alert('Error de conexión');
    }
}

async function deleteTaskGlobal(taskId, projectId) {
    if (!confirm('¿Deseas eliminar permanentemente esta tarea?')) return;

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf_token', '<?= csrf_token() ?>');
    fd.append('project_id', projectId);
    fd.append('task_id', taskId);

    try {
        const res = await fetch('api/tasks.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const card = document.getElementById('global-task-card-' + taskId);
            const row = document.getElementById('task-row-' + taskId);
            if (card) card.remove();
            if (row) row.remove();
        } else {
            alert(data.message || 'Error al eliminar tarea');
        }
    } catch (err) {
        alert('Error de conexión');
    }
}

async function handleCreateGlobalTask(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-submit-global-task');
    btn.disabled = true;
    btn.innerText = 'Guardando...';

    const projectId = document.getElementById('global-task-project').value;
    const title = document.getElementById('global-task-title').value.trim();
    const description = document.getElementById('global-task-desc').value.trim();
    const priority = document.getElementById('global-task-prio').value;
    const dueDate = document.getElementById('global-task-due').value;
    const assignedTo = document.getElementById('global-task-assigned').value;

    const fd = new FormData();
    fd.append('action', 'create');
    fd.append('csrf_token', '<?= csrf_token() ?>');
    fd.append('project_id', projectId);
    fd.append('title', title);
    fd.append('description', description);
    fd.append('priority', priority);
    fd.append('due_date', dueDate);
    fd.append('assigned_user_id', assignedTo);

    try {
        const res = await fetch('api/tasks.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Error al crear tarea');
        }
    } catch (err) {
        alert('Error de conexión');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Crear Tarea';
    }
}

// Transición Skeleton a Contenido Real
document.addEventListener('DOMContentLoaded', function() {
    const skel = document.getElementById('tasks-skeleton');
    const content = document.getElementById('tasks-real-content');
    if (skel && content) {
        skel.style.opacity = '0';
        setTimeout(() => {
            skel.classList.add('hidden');
            content.classList.remove('hidden');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }, 120);
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

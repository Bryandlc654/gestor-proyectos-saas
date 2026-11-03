<?php
// project-view.php - Vista Maestra con Tablero Kanban/Lista, Colaboradores, y Tareas con Comentarios, Checklists y Adjuntos
require_once __DIR__ . '/config/app.php';
requireLogin();

$user = currentUser();
$db = getDB();

$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($projectId <= 0) {
    setFlash('error', 'Proyecto no especificado.');
    header('Location: dashboard.php');
    exit;
}

// 1. Obtener datos del proyecto según permisos de acceso
$userRole = currentUserRole();
$roleBadge = getRoleBadge($userRole);

if (!canAccessProject($projectId, $user['id'], $userRole)) {
    setFlash('error', 'El proyecto no existe o no tienes permisos para acceder a él.');
    header('Location: dashboard.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$projectId]);
$project = $stmt->fetch();

if (!$project) {
    setFlash('error', 'Proyecto no encontrado.');
    header('Location: dashboard.php');
    exit;
}

if (!defined('PAGE_TITLE')) {
    define('PAGE_TITLE', $project['name']);
}

$canManageProject = canManageProject($projectId, $user['id'], $userRole);
$canManageTasks = canManageTasks($userRole);
$canDeleteTasks = canDeleteTasks($userRole);
$canChangeStatus = canChangeTaskStatus($userRole);
$canManageChecklists = canManageChecklists($userRole);
$canManageAttachments = canManageAttachments($userRole);
$isClient = isCliente();

// 2. Obtener Lista de Colaboradores (solo miembros de este proyecto y su propietario)
$stmtCollabs = $db->prepare("
    SELECT u.id, u.name
    FROM users u
    WHERE u.id IN (SELECT user_id FROM project_members WHERE project_id = ?)
       OR u.id = (SELECT user_id FROM projects WHERE id = ?)
    ORDER BY u.name ASC
");
$stmtCollabs->execute([$projectId, $projectId]);
$collaborators = $stmtCollabs->fetchAll();

// 3. Obtener Carpetas de Adjuntos
$stmtFolders = $db->prepare("SELECT * FROM project_folders WHERE project_id = ? ORDER BY created_at ASC");
$stmtFolders->execute([$projectId]);
$folders = $stmtFolders->fetchAll();

// 4. Obtener Actividades / Tareas con conteo de comentarios por tarea
$stmtTasks = $db->prepare("
    SELECT t.*, COALESCE(au.name, t.assigned_to) AS assigned_to, COUNT(c.id) as comments_count 
    FROM tasks t 
    LEFT JOIN users au ON au.id = t.assigned_user_id 
    LEFT JOIN comments c ON t.id = c.task_id 
    WHERE t.project_id = ? 
    GROUP BY t.id 
    ORDER BY (t.status = 'completada') ASC, (t.priority = 'urgente') DESC, t.created_at DESC
");
$stmtTasks->execute([$projectId]);
$tasks = $stmtTasks->fetchAll();

// Agrupar tareas para Kanban
$tasksPending = array_filter($tasks, fn($t) => $t['status'] === 'pendiente');
$tasksInProgress = array_filter($tasks, fn($t) => $t['status'] === 'en_progreso');
$tasksCompleted = array_filter($tasks, fn($t) => $t['status'] === 'completada');

// 5. Obtener Checklists y sus Items mediante Eager Loading en 2 consultas (Evita N+1 queries)
$stmtChecklists = $db->prepare("
    SELECT c.*, t.title as task_title 
    FROM checklists c 
    LEFT JOIN tasks t ON c.task_id = t.id 
    WHERE c.project_id = ? 
    ORDER BY c.created_at ASC
");
$stmtChecklists->execute([$projectId]);
$checklists = $stmtChecklists->fetchAll();

$checklistsData = [];
$totalChecklistItems = 0;
$completedChecklistItems = 0;

if (!empty($checklists)) {
    $checklistIds = array_column($checklists, 'id');
    $placeholders = implode(',', array_fill(0, count($checklistIds), '?'));
    $stmtItems = $db->prepare("SELECT * FROM checklist_items WHERE checklist_id IN ($placeholders) ORDER BY id ASC");
    $stmtItems->execute($checklistIds);
    $allItems = $stmtItems->fetchAll();

    // Indexar items por checklist_id en O(N)
    $groupedItems = [];
    foreach ($allItems as $it) {
        $groupedItems[$it['checklist_id']][] = $it;
    }

    foreach ($checklists as $chk) {
        $items = $groupedItems[$chk['id']] ?? [];
        $completedCount = 0;
        foreach ($items as $item) {
            if ($item['is_completed']) {
                $completedCount++;
                $completedChecklistItems++;
            }
            $totalChecklistItems++;
        }

        $checklistsData[] = [
            'checklist' => $chk,
            'items' => $items,
            'total' => count($items),
            'completed' => $completedCount,
            'percentage' => count($items) > 0 ? round(($completedCount / count($items)) * 100) : 0
        ];
    }
}

// 6. Obtener Tickets y conteo de respuestas
$stmtTickets = $db->prepare("
    SELECT t.*, u.name as author_name,
           (SELECT COUNT(*) FROM ticket_replies tr WHERE tr.ticket_id = t.id) as replies_count
    FROM tickets t 
    INNER JOIN users u ON t.user_id = u.id 
    WHERE t.project_id = ? 
    ORDER BY (t.status = 'abierto') DESC, (t.priority = 'critica') DESC, t.created_at DESC
");
$stmtTickets->execute([$projectId]);
$tickets = $stmtTickets->fetchAll();

// 7. Obtener Enlaces (con folder_id y task_id)
$stmtLinks = $db->prepare("
    SELECT l.*, f.name as folder_name, t.title as task_title 
    FROM project_links l 
    LEFT JOIN project_folders f ON l.folder_id = f.id 
    LEFT JOIN tasks t ON l.task_id = t.id 
    WHERE l.project_id = ? 
    ORDER BY l.created_at DESC
");
$stmtLinks->execute([$projectId]);
$links = $stmtLinks->fetchAll();

// 8. Obtener Archivos (si es cliente, solo ve sus propios adjuntos)
if ($isClient) {
    $stmtFiles = $db->prepare("
        SELECT f.*, u.name as uploader_name, fol.name as folder_name, t.title as task_title 
        FROM project_files f 
        INNER JOIN users u ON f.user_id = u.id 
        LEFT JOIN project_folders fol ON f.folder_id = fol.id 
        LEFT JOIN tasks t ON f.task_id = t.id 
        WHERE f.project_id = ? AND f.user_id = ?
        ORDER BY f.created_at DESC
    ");
    $stmtFiles->execute([$projectId, $user['id']]);
    $files = $stmtFiles->fetchAll();
    $links = []; // Los clientes no ven enlaces técnicos de tareas internas
} else {
    $stmtFiles = $db->prepare("
        SELECT f.*, u.name as uploader_name, fol.name as folder_name, t.title as task_title 
        FROM project_files f 
        INNER JOIN users u ON f.user_id = u.id 
        LEFT JOIN project_folders fol ON f.folder_id = fol.id 
        LEFT JOIN tasks t ON f.task_id = t.id 
        WHERE f.project_id = ? 
        ORDER BY f.created_at DESC
    ");
    $stmtFiles->execute([$projectId]);
    $files = $stmtFiles->fetchAll();
}

// 9. Obtener Comentarios (con task_title si pertenece a una tarea)
// Si el usuario es Cliente, solo puede ver sus propios comentarios y las respuestas a sus comentarios
if ($isClient) {
    $stmtComments = $db->prepare("
        SELECT c.*, u.name as author_name, t.title as task_title 
        FROM comments c 
        INNER JOIN users u ON c.user_id = u.id 
        LEFT JOIN tasks t ON c.task_id = t.id 
        WHERE c.project_id = ? 
          AND (c.user_id = ? OR c.parent_id IN (SELECT id FROM comments WHERE user_id = ? AND project_id = ?))
        ORDER BY c.created_at ASC
    ");
    $stmtComments->execute([$projectId, $user['id'], $user['id'], $projectId]);
} else {
    $stmtComments = $db->prepare("
        SELECT c.*, u.name as author_name, t.title as task_title 
        FROM comments c 
        INNER JOIN users u ON c.user_id = u.id 
        LEFT JOIN tasks t ON c.task_id = t.id 
        WHERE c.project_id = ? 
        ORDER BY c.created_at ASC
    ");
    $stmtComments->execute([$projectId]);
}
$allCommentsRaw = $stmtComments->fetchAll();

// Organizar comentarios en estructura jerárquica (Padres y Respuestas)
$parentComments = [];
$repliesByParent = [];
foreach ($allCommentsRaw as $comm) {
    if (!empty($comm['parent_id'])) {
        $repliesByParent[$comm['parent_id']][] = $comm;
    } else {
        $parentComments[] = $comm;
    }
}
// Para el muro principal, ordenar los hilos con los más recientes arriba
$comments = array_reverse($parentComments);

// 10. Obtener Facturas y Cobros del Proyecto
$stmtInvoices = $db->prepare("
    SELECT * FROM invoices 
    WHERE project_id = ? 
    ORDER BY (status = 'pendiente') DESC, due_date ASC, created_at DESC
");
$stmtInvoices->execute([$projectId]);
$invoices = $stmtInvoices->fetchAll();

$totalInvoiced = 0;
$totalPaid = 0;
$totalPending = 0;
foreach ($invoices as $inv) {
    if ($inv['status'] !== 'anulada') {
        $amt = floatval($inv['amount']);
        $totalInvoiced += $amt;
        if ($inv['status'] === 'pagada') {
            $totalPaid += $amt;
        } elseif ($inv['status'] === 'pendiente') {
            $totalPending += $amt;
        }
    }
}
$projectBudget = floatval($project['budget'] ?? 0);
$projectCurrency = $project['currency'] ?: 'USD';
$projectBalance = $projectBudget - $totalPaid;

// Cálculo de Progreso Global
$totalTasksCount = count($tasks);
$completedTasksCount = count($tasksCompleted);
$overallScoreTotal = $totalTasksCount + $totalChecklistItems;
$overallScoreCompleted = $completedTasksCount + $completedChecklistItems;
$projectProgress = $overallScoreTotal > 0 ? round(($overallScoreCompleted / $overallScoreTotal) * 100) : 0;

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- Contenedor Principal del Proyecto -->
<main class="flex-1 flex flex-col min-w-0 bg-[#F9FAFB] overflow-y-auto">
    <!-- Header del Proyecto (Minimalista y Pulido) -->
    <header class="bg-white border-b border-slate-200/70 px-4 sm:px-8 py-5 sticky top-0 z-10">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row md:items-center justify-between gap-4">
            
            <div class="flex items-start gap-3.5">
                <button onclick="toggleSidebar()" class="p-2 -ml-2 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition" title="Alternar menú lateral">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white flex-shrink-0 shadow-xs" style="background-color: <?= e($project['color'] ?: '#4F46E5') ?>;">
                    <i data-lucide="folder" class="w-5 h-5"></i>
                </div>

                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-display font-bold text-lg sm:text-xl text-slate-900 tracking-tight">
                            <?= e($project['name']) ?>
                        </h1>
                        <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full capitalize <?= $project['status'] === 'activo' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200/50' : 'bg-slate-100 text-slate-600' ?>">
                            <?= e($project['status']) ?>
                        </span>
                        <span class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-slate-100/80 text-slate-500">
                            <?= e($project['category']) ?>
                        </span>
                    </div>

                    <?php if (!empty($project['description'])): ?>
                        <p class="text-xs text-slate-500 mt-1 max-w-3xl leading-relaxed">
                            <?= e($project['description']) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Progreso y Botones de Acción -->
            <div class="flex items-center gap-4 self-end md:self-center">
                <?php if (isAdmin()): ?>
                    <!-- Resumen de Costo y Cobro (Solo Admin) -->
                    <div class="text-right hidden sm:block border-r border-slate-200/70 pr-4">
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Costo Proyecto</div>
                        <div class="text-xs font-bold text-slate-900 mt-0.5">
                            <?= e($projectCurrency) ?> $<?= number_format($projectBudget, 2) ?>
                        </div>
                        <?php if ($totalPending > 0): ?>
                            <div class="text-[10px] font-semibold text-amber-600 mt-0.5 flex items-center justify-end gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                <span>Por cobrar: $<?= number_format($totalPending, 2) ?></span>
                            </div>
                        <?php elseif ($projectBudget > 0 && $totalPaid >= $projectBudget): ?>
                            <div class="text-[10px] font-semibold text-emerald-600 mt-0.5 flex items-center justify-end gap-1">
                                <i data-lucide="check" class="w-3 h-3"></i>
                                <span>100% Pagado</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="text-right hidden sm:block">
                    <div class="flex items-center gap-1.5 justify-end text-xs text-slate-500">
                        <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Entrega: <?= formatDate($project['deadline']) ?></span>
                    </div>
                    <div class="flex items-center gap-2 mt-1">
                        <div class="w-24 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div id="project-progress-bar" class="h-full bg-brand-600 rounded-full transition-all duration-500" style="width: <?= $projectProgress ?>%;"></div>
                        </div>
                        <span id="project-progress-text" class="text-xs font-bold text-slate-700"><?= $projectProgress ?>%</span>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <?php if ($canManageProject): ?>
                        <button onclick="openModal('edit-project-modal')" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-50 border border-slate-200/70 transition" title="Configuración del Proyecto">
                            <i data-lucide="settings-2" class="w-4 h-4"></i>
                        </button>
                    <?php endif; ?>

                    <?php if (canDeleteProjects($userRole) && $canManageProject): ?>
                        <form action="actions/project-actions.php" method="POST" onsubmit="return confirm('¿Eliminar este proyecto y todo su contenido?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="project_id" value="<?= $project['id'] ?>">
                            <button type="submit" class="p-2 rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 border border-slate-200/70 transition" title="Eliminar Proyecto">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Pestañas de Navegación (Tabs) -->
        <div class="max-w-7xl mx-auto mt-4 flex items-center gap-1 sm:gap-2 overflow-x-auto border-b border-slate-200/70 -mb-5 pb-px">
            <button onclick="switchTab('tasks')" id="tab-btn-tasks" class="tab-btn active inline-flex items-center gap-2 px-3 sm:px-4 py-2 text-xs sm:text-sm font-medium text-slate-500 hover:text-slate-900 border-b-2 border-transparent transition-all whitespace-nowrap">
                <i data-lucide="check-square" class="w-4 h-4"></i>
                <span>Tareas</span>
                <span id="badge-tasks-count" class="px-1.5 py-0.2 rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= count($tasks) ?></span>
            </button>

            <?php if (!$isClient): ?>
                <button onclick="switchTab('checklists')" id="tab-btn-checklists" class="tab-btn inline-flex items-center gap-2 px-3 sm:px-4 py-2 text-xs sm:text-sm font-medium text-slate-500 hover:text-slate-900 border-b-2 border-transparent transition-all whitespace-nowrap">
                    <i data-lucide="list-checks" class="w-4 h-4"></i>
                    <span>Checklists</span>
                    <span id="badge-checklists-count" class="px-1.5 py-0.2 rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= count($checklists) ?></span>
                </button>
            <?php endif; ?>

            <button onclick="switchTab('tickets')" id="tab-btn-tickets" class="tab-btn inline-flex items-center gap-2 px-3 sm:px-4 py-2 text-xs sm:text-sm font-medium text-slate-500 hover:text-slate-900 border-b-2 border-transparent transition-all whitespace-nowrap">
                <i data-lucide="life-buoy" class="w-4 h-4"></i>
                <span>Tickets</span>
                <span id="badge-tickets-count" class="px-1.5 py-0.2 rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= count($tickets) ?></span>
            </button>

            <button onclick="switchTab('comments')" id="tab-btn-comments" class="tab-btn inline-flex items-center gap-2 px-3 sm:px-4 py-2 text-xs sm:text-sm font-medium text-slate-500 hover:text-slate-900 border-b-2 border-transparent transition-all whitespace-nowrap">
                <i data-lucide="message-square" class="w-4 h-4"></i>
                <span>Notas</span>
                <span id="badge-comments-count" class="px-1.5 py-0.2 rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= count($comments) ?></span>
            </button>

            <button onclick="switchTab('adjuntos')" id="tab-btn-adjuntos" class="tab-btn inline-flex items-center gap-2 px-3 sm:px-4 py-2 text-xs sm:text-sm font-medium text-slate-500 hover:text-slate-900 border-b-2 border-transparent transition-all whitespace-nowrap">
                <i data-lucide="paperclip" class="w-4 h-4"></i>
                <span>Adjuntos</span>
                <span id="badge-adjuntos-count" class="px-1.5 py-0.2 rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= count($files) + count($links) ?></span>
            </button>

            <?php if (isAdmin()): ?>
                <button onclick="switchTab('finanzas')" id="tab-btn-finanzas" class="tab-btn inline-flex items-center gap-2 px-3 sm:px-4 py-2 text-xs sm:text-sm font-medium text-slate-500 hover:text-slate-900 border-b-2 border-transparent transition-all whitespace-nowrap">
                    <i data-lucide="receipt" class="w-4 h-4"></i>
                    <span>Finanzas</span>
                    <?php if ($totalPending > 0): ?>
                        <span id="badge-finanzas-pending" class="px-1.5 py-0.2 rounded-full bg-amber-100 text-[10px] font-bold text-amber-700" title="Facturas por cobrar"><?= count(array_filter($invoices, fn($i) => $i['status'] === 'pendiente')) ?></span>
                    <?php else: ?>
                        <span id="badge-finanzas-count" class="px-1.5 py-0.2 rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= count($invoices) ?></span>
                    <?php endif; ?>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <!-- Contenido Principal Dinámico -->
    <div class="p-4 sm:p-8 max-w-7xl w-full mx-auto">

        <!-- ==================== TAB 1: TAREAS (KANBAN O LISTA) ==================== -->
        <section id="tab-tasks" class="tab-content space-y-5">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-bold text-base text-slate-900">Tareas</h2>
                    <span class="text-xs text-slate-400"><?= count($tasks) ?> en total</span>
                </div>

                <div class="flex items-center gap-2.5">
                    <div class="bg-slate-100 p-0.5 rounded-lg flex items-center">
                        <button onclick="setTaskView('kanban')" id="view-btn-kanban" class="px-2.5 py-1 rounded-md text-xs font-semibold flex items-center gap-1.5 bg-white text-slate-800 shadow-2xs transition">
                            <i data-lucide="columns-3" class="w-3.5 h-3.5"></i>
                            <span class="hidden sm:inline">Tablero</span>
                        </button>
                        <button onclick="setTaskView('list')" id="view-btn-list" class="px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 text-slate-500 hover:text-slate-800 transition">
                            <i data-lucide="list" class="w-3.5 h-3.5"></i>
                            <span class="hidden sm:inline">Lista</span>
                        </button>
                    </div>

                    <?php if ($canManageTasks): ?>
                        <button onclick="openModal('create-task-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs shadow-xs transition">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            <span>Nueva</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Estado Vacío -->
            <div id="tasks-empty-state" class="bg-white rounded-2xl border border-dashed border-slate-200 p-12 text-center <?= empty($tasks) ? '' : 'hidden' ?>">
                <div class="w-12 h-12 bg-indigo-50 text-brand-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="check-square" class="w-6 h-6"></i>
                </div>
                <h3 class="text-sm font-semibold text-slate-800">No hay tareas creadas todavía</h3>
                <p class="text-xs text-slate-500 mt-1 mb-4">Comienza agregando una tarea al proyecto.</p>
                <?php if ($canManageTasks): ?>
                    <button onclick="openModal('create-task-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 text-white text-xs font-semibold rounded-lg shadow-xs">
                        + Agregar Tarea
                    </button>
                <?php endif; ?>
            </div>

            <!-- VISTA KANBAN -->
            <div id="tasks-kanban-view" class="grid grid-cols-1 md:grid-cols-3 gap-5 <?= empty($tasks) ? 'hidden' : '' ?>">
                <!-- Columna: Pendientes -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between px-1">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span>
                            <h3 class="font-display font-bold text-slate-800 text-xs sm:text-sm">Por Hacer</h3>
                        </div>
                        <span id="count-kanban-pendiente" class="px-2 py-0.5 rounded-full bg-slate-200/70 text-[11px] font-bold text-slate-600"><?= count($tasksPending) ?></span>
                    </div>

                    <div id="kanban-col-pendiente" class="kanban-column space-y-3 min-h-[150px] p-2 bg-slate-100/50 rounded-2xl border border-slate-200/50">
                        <?php foreach ($tasksPending as $task): ?>
                            <?php include __DIR__ . '/includes/task-kanban-card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Columna: En Progreso -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between px-1">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            <h3 class="font-display font-bold text-slate-800 text-xs sm:text-sm">En Progreso</h3>
                        </div>
                        <span id="count-kanban-en_progreso" class="px-2 py-0.5 rounded-full bg-amber-100/70 text-[11px] font-bold text-amber-700"><?= count($tasksInProgress) ?></span>
                    </div>

                    <div id="kanban-col-en_progreso" class="kanban-column space-y-3 min-h-[150px] p-2 bg-slate-100/50 rounded-2xl border border-slate-200/50">
                        <?php foreach ($tasksInProgress as $task): ?>
                            <?php include __DIR__ . '/includes/task-kanban-card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Columna: Completadas -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between px-1">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            <h3 class="font-display font-bold text-slate-800 text-xs sm:text-sm">Completadas</h3>
                        </div>
                        <span id="count-kanban-completada" class="px-2 py-0.5 rounded-full bg-emerald-100/70 text-[11px] font-bold text-emerald-700"><?= count($tasksCompleted) ?></span>
                    </div>

                    <div id="kanban-col-completada" class="kanban-column space-y-3 min-h-[150px] p-2 bg-slate-100/50 rounded-2xl border border-slate-200/50">
                        <?php foreach ($tasksCompleted as $task): ?>
                            <?php include __DIR__ . '/includes/task-kanban-card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- VISTA LISTA -->
            <div id="tasks-list-view" class="hidden space-y-2">
                <div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden shadow-2xs divide-y divide-slate-100" id="tasks-list-container">
                    <?php foreach ($tasks as $task): ?>
                        <div id="task-list-row-<?= $task['id'] ?>" class="p-3.5 sm:px-5 flex items-center justify-between gap-3 hover:bg-slate-50/70 transition group">
                            <div class="flex items-center gap-3 min-w-0">
                                <?php if ($canChangeStatus): ?>
                                    <input type="checkbox" onchange="toggleTaskCompleteFromList(<?= $task['id'] ?>, this)" <?= $task['status'] === 'completada' ? 'checked' : '' ?> class="w-4 h-4 rounded text-brand-600 focus:ring-brand-500 border-slate-300 transition cursor-pointer">
                                <?php else: ?>
                                    <input type="checkbox" disabled <?= $task['status'] === 'completada' ? 'checked' : '' ?> class="w-4 h-4 rounded text-slate-300 border-slate-200 cursor-not-allowed">
                                <?php endif; ?>
                                
                                <div class="min-w-0 cursor-pointer" onclick="openTaskDetailModal(<?= $task['id'] ?>)">
                                    <h4 class="font-display font-semibold text-xs sm:text-sm text-slate-900 group-hover:text-brand-600 transition <?= $task['status'] === 'completada' ? 'line-through text-slate-400' : '' ?>">
                                        <?= e($task['title']) ?>
                                    </h4>
                                    <div class="flex items-center gap-2 mt-0.5 text-[11px] text-slate-400">
                                        <span><?= formatDate($task['due_date']) ?></span>
                                        <?php if (!empty($task['assigned_to'])): ?>
                                            <span>•</span>
                                            <span class="text-slate-600 font-medium"><?= e($task['assigned_to']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 flex-shrink-0">
                                <?php if (!$isClient): ?>
                                    <button onclick="openTaskDetailModal(<?= $task['id'] ?>)" class="flex items-center gap-1 text-[11px] text-slate-400 hover:text-brand-600 p-1 rounded transition" title="Ver comentarios">
                                        <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
                                        <span class="task-comments-count-<?= $task['id'] ?>"><?= (int)$task['comments_count'] ?></span>
                                    </button>
                                <?php endif; ?>

                                <?php
                                    $prioClass = match($task['priority']) {
                                        'urgente' => 'bg-rose-50 text-rose-700 border-rose-200/60',
                                        'alta'    => 'bg-amber-50 text-amber-700 border-amber-200/60',
                                        'media'   => 'bg-indigo-50 text-indigo-700 border-indigo-200/60',
                                        default   => 'bg-slate-50 text-slate-600 border-slate-200/60'
                                    };
                                ?>
                                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md border <?= $prioClass ?>">
                                    <?= e($task['priority']) ?>
                                </span>

                                <?php if ($canChangeStatus): ?>
                                    <select onchange="updateTaskStatus(<?= $task['id'] ?>, this.value)" class="text-[11px] font-semibold rounded-lg px-2 py-1 border border-slate-200 bg-slate-50 text-slate-700 cursor-pointer focus:outline-none focus:ring-1 focus:ring-brand-500">
                                        <option value="pendiente" <?= $task['status'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                        <option value="en_progreso" <?= $task['status'] === 'en_progreso' ? 'selected' : '' ?>>En Progreso</option>
                                        <option value="completada" <?= $task['status'] === 'completada' ? 'selected' : '' ?>>Completada</option>
                                    </select>
                                <?php else: ?>
                                    <span class="text-[10px] font-semibold text-slate-500 capitalize bg-slate-100 px-2 py-0.5 rounded-md">
                                        <?= str_replace('_', ' ', $task['status']) ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($canDeleteTasks): ?>
                                    <button onclick="deleteTask(<?= $task['id'] ?>)" class="p-1 text-slate-400 hover:text-rose-600 transition" title="Eliminar tarea">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ==================== TAB 2: CHECKLISTS ==================== -->
        <?php if (!$isClient): ?>
        <section id="tab-checklists" class="tab-content hidden space-y-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-bold text-base text-slate-900">Checklists</h2>
                    <span class="text-xs text-slate-400"><?= count($checklistsData) ?> listas</span>
                </div>
                <?php if ($canManageChecklists): ?>
                    <button onclick="openModal('create-checklist-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs shadow-xs transition">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                        <span>Nueva Lista</span>
                    </button>
                <?php endif; ?>
            </div>

            <div id="checklists-empty-state" class="bg-white rounded-2xl border border-dashed border-slate-200 p-12 text-center <?= empty($checklistsData) ? '' : 'hidden' ?>">
                <div class="w-12 h-12 bg-indigo-50 text-brand-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="list-checks" class="w-6 h-6"></i>
                </div>
                <h3 class="text-sm font-semibold text-slate-800">No hay listas de verificación</h3>
                <p class="text-xs text-slate-500 mt-1 mb-4">Crea un checklist para asegurar el cumplimiento de requisitos de calidad.</p>
                <?php if ($canManageChecklists): ?>
                    <button onclick="openModal('create-checklist-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 text-white text-xs font-semibold rounded-lg shadow-xs">
                        + Crear Checklist
                    </button>
                <?php endif; ?>
            </div>

            <div id="checklists-container" class="grid grid-cols-1 md:grid-cols-2 gap-5 <?= empty($checklistsData) ? 'hidden' : '' ?>">
                <?php foreach ($checklistsData as $cData): ?>
                    <?php $chk = $cData['checklist']; ?>
                    <div id="checklist-card-<?= $chk['id'] ?>" class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-display font-bold text-slate-900 text-sm sm:text-base"><?= e($chk['title']) ?></h3>
                                
                                <!-- Vinculación con Tarea Principal -->
                                <?php if (!empty($chk['task_id']) && !empty($chk['task_title'])): ?>
                                    <button onclick="openTaskDetailModal(<?= $chk['task_id'] ?>)" class="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 bg-brand-50 px-2 py-0.5 rounded-md hover:bg-brand-100 transition mt-1">
                                        <i data-lucide="check-square" class="w-3 h-3"></i>
                                        <span>Tarea: <?= e($chk['task_title']) ?></span>
                                    </button>
                                <?php endif; ?>

                                <p id="chk-progress-text-<?= $chk['id'] ?>" class="text-[11px] text-slate-400 mt-1">
                                    <span class="chk-completed"><?= $cData['completed'] ?></span> de <span class="chk-total"><?= $cData['total'] ?></span> pasos completados (<span class="chk-pct"><?= $cData['percentage'] ?></span>%)
                                </p>
                            </div>
                            <?php if ($canManageChecklists): ?>
                                <button onclick="deleteChecklist(<?= $chk['id'] ?>)" class="text-slate-400 hover:text-rose-600 p-1 rounded transition" title="Eliminar Checklist">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div id="chk-bar-<?= $chk['id'] ?>" class="h-full bg-emerald-500 rounded-full transition-all duration-300" style="width: <?= $cData['percentage'] ?>%;"></div>
                        </div>

                        <div id="chk-items-list-<?= $chk['id'] ?>" class="space-y-1.5">
                            <?php foreach ($cData['items'] as $item): ?>
                                <div id="chk-item-<?= $item['id'] ?>" class="flex items-center justify-between p-2 rounded-xl bg-slate-50/60 hover:bg-slate-100/70 border border-slate-100 transition group">
                                    <label class="flex items-center gap-2.5 <?= $canManageChecklists ? 'cursor-pointer' : 'cursor-not-allowed' ?> flex-1 min-w-0">
                                        <?php if ($canManageChecklists): ?>
                                            <input type="checkbox" onchange="toggleChecklistItemAjax(<?= $item['id'] ?>, <?= $chk['id'] ?>, this)" <?= $item['is_completed'] ? 'checked' : '' ?> class="w-4 h-4 rounded text-brand-600 focus:ring-brand-500 border-slate-300 transition">
                                        <?php else: ?>
                                            <input type="checkbox" disabled <?= $item['is_completed'] ? 'checked' : '' ?> class="w-4 h-4 rounded text-slate-300 border-slate-200">
                                        <?php endif; ?>
                                        <span class="text-xs text-slate-700 truncate <?= $item['is_completed'] ? 'line-through text-slate-400' : '' ?>" id="item-title-<?= $item['id'] ?>">
                                            <?= e($item['title']) ?>
                                        </span>
                                    </label>
                                    <?php if ($canManageChecklists): ?>
                                        <button onclick="deleteChecklistItem(<?= $item['id'] ?>, <?= $chk['id'] ?>)" class="opacity-0 group-hover:opacity-100 text-slate-400 hover:text-rose-600 p-1 rounded transition">
                                            <i data-lucide="x" class="w-3.5 h-3.5"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($canManageChecklists): ?>
                            <form onsubmit="addChecklistItemAjax(event, <?= $chk['id'] ?>)" class="flex items-center gap-2 pt-2 border-t border-slate-100">
                                <input type="text" id="new-item-input-<?= $chk['id'] ?>" required placeholder="+ Agregar un paso..." class="flex-1 px-3 py-1.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-brand-500">
                                <button type="submit" class="px-3 py-1.5 bg-slate-900 text-white rounded-lg text-xs font-semibold hover:bg-slate-800 transition">Añadir</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ==================== TAB 3: TICKETS & SOPORTE ==================== -->
        <section id="tab-tickets" class="tab-content hidden space-y-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-bold text-base text-slate-900">Tickets</h2>
                    <span class="text-xs text-slate-400"><?= count($tickets) ?> registrados</span>
                </div>
                <button onclick="openModal('create-ticket-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs shadow-xs transition">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                    <span>Nuevo Ticket</span>
                </button>
            </div>

            <div id="tickets-empty-state" class="bg-white rounded-2xl border border-dashed border-slate-200 p-12 text-center <?= empty($tickets) ? '' : 'hidden' ?>">
                <div class="w-12 h-12 bg-indigo-50 text-brand-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="life-buoy" class="w-6 h-6"></i>
                </div>
                <h3 class="text-sm font-semibold text-slate-800">No hay tickets reportados</h3>
                <p class="text-xs text-slate-500 mt-1 mb-4">Todo marcha según lo planeado sin incidencias abiertas.</p>
                <button onclick="openModal('create-ticket-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 text-white text-xs font-semibold rounded-lg shadow-xs">
                    + Reportar Ticket
                </button>
            </div>

            <div id="tickets-container" class="space-y-4 <?= empty($tickets) ? 'hidden' : '' ?>">
                <?php foreach ($tickets as $ticket): ?>
                    <div id="ticket-card-<?= $ticket['id'] ?>" class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-3.5">
                        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                            <div class="space-y-1.5 flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-mono font-semibold text-slate-400">#TK-<?= $ticket['id'] ?></span>
                                    <h3 class="font-display font-bold text-slate-900 text-sm sm:text-base"><?= e($ticket['title']) ?></h3>
                                    <?php
                                        $ticketPrio = match($ticket['priority']) {
                                            'critica' => 'bg-rose-50 text-rose-700 border-rose-200/60',
                                            'alta'    => 'bg-amber-50 text-amber-700 border-amber-200/60',
                                            'media'   => 'bg-blue-50 text-blue-700 border-blue-200/60',
                                            default   => 'bg-slate-50 text-slate-600 border-slate-200/60'
                                        };
                                    ?>
                                    <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md border <?= $ticketPrio ?>">
                                        <?= e($ticket['priority']) ?>
                                    </span>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line"><?= e($ticket['description']) ?></p>
                                <div class="flex items-center gap-3 text-[11px] text-slate-400 pt-0.5">
                                    <span>Reportado por: <strong class="text-slate-700"><?= e($ticket['author_name']) ?></strong></span>
                                    <span>•</span>
                                    <span><?= formatDate($ticket['created_at'], true) ?></span>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 self-end sm:self-start">
                                <select onchange="updateTicketStatus(<?= $ticket['id'] ?>, this.value)" class="text-xs font-semibold rounded-xl px-2.5 py-1.5 border border-slate-200 bg-slate-50 text-slate-800 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                    <option value="abierto" <?= $ticket['status'] === 'abierto' ? 'selected' : '' ?>>Abierto</option>
                                    <option value="en_revision" <?= $ticket['status'] === 'en_revision' ? 'selected' : '' ?>>En Revisión</option>
                                    <option value="resuelto" <?= $ticket['status'] === 'resuelto' ? 'selected' : '' ?>>Resuelto</option>
                                    <option value="cerrado" <?= $ticket['status'] === 'cerrado' ? 'selected' : '' ?>>Cerrado</option>
                                </select>

                                <button onclick="deleteTicket(<?= $ticket['id'] ?>)" class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition" title="Eliminar ticket">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Barra de Respuestas del Ticket -->
                        <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                            <button onclick="toggleTicketReplies(<?= $ticket['id'] ?>)" id="btn-toggle-replies-<?= $ticket['id'] ?>" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-600 hover:text-brand-700 transition">
                                <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
                                <span>Ver conversación</span>
                                <span id="ticket-replies-badge-<?= $ticket['id'] ?>" class="ml-1 px-1.5 py-0.2 rounded-full bg-brand-50 text-brand-700 text-[10px] font-bold">
                                    <?= (int)$ticket['replies_count'] ?>
                                </span>
                                <i data-lucide="chevron-down" id="chevron-replies-<?= $ticket['id'] ?>" class="w-3 h-3 transition-transform"></i>
                            </button>

                            <button onclick="openTicketReplyInput(<?= $ticket['id'] ?>)" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium text-slate-600 hover:text-brand-600 hover:bg-brand-50 transition">
                                <i data-lucide="reply" class="w-3.5 h-3.5"></i>
                                <span>Responder Ticket</span>
                            </button>
                        </div>

                        <!-- Panel Colapsable de Respuestas del Ticket -->
                        <div id="ticket-replies-panel-<?= $ticket['id'] ?>" class="hidden pt-2 space-y-3">
                            <div id="ticket-replies-feed-<?= $ticket['id'] ?>" class="space-y-2.5 pl-4 sm:pl-6 border-l-2 border-brand-100 max-h-80 overflow-y-auto">
                                <div class="text-xs text-slate-400 py-2">Cargando respuestas...</div>
                            </div>

                            <!-- Formulario de Respuesta al Ticket -->
                            <form onsubmit="submitTicketReplyAjax(event, <?= $ticket['id'] ?>)" class="pl-4 sm:pl-6 border-l-2 border-brand-100 pt-2 space-y-2">
                                <textarea id="ticket-reply-text-<?= $ticket['id'] ?>" rows="2" placeholder="Escribe tu respuesta o resolución para este ticket..." required class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-brand-500 bg-slate-50/60 leading-relaxed"></textarea>
                                <div class="flex items-center justify-end gap-2">
                                    <button type="submit" id="btn-submit-tk-reply-<?= $ticket['id'] ?>" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
                                        <i data-lucide="send" class="w-3 h-3"></i>
                                        <span>Enviar Respuesta</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ==================== TAB 4: COMENTARIOS & NOTAS ==================== -->
        <section id="tab-comments" class="tab-content hidden space-y-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-bold text-base text-slate-900">Notas & Acuerdos</h2>
                    <span class="text-xs text-slate-400"><?= count($comments) ?> hilos</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                <form id="comment-form" onsubmit="submitCommentAjax(event)" class="space-y-3">
                    <textarea id="comment-content-input" rows="2" placeholder="Escribe una nota rápida o pega una captura aquí con Ctrl+V..." class="w-full p-3 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:ring-1 focus:ring-brand-500 leading-relaxed"></textarea>
                    
                    <div id="comment-image-preview-container" class="hidden relative inline-block border border-slate-200 rounded-xl p-1.5 bg-slate-50">
                        <img id="comment-preview-img" src="" alt="Vista previa" class="h-24 max-w-xs object-cover rounded-lg">
                        <button type="button" onclick="clearCommentImage()" class="absolute -top-2 -right-2 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center shadow hover:bg-rose-700 transition" title="Quitar">
                            <i data-lucide="x" class="w-3 h-3"></i>
                        </button>
                    </div>

                    <input type="file" id="comment-image-file" accept="image/*" class="hidden" onchange="handleCommentImageSelect(this)">

                    <div class="flex items-center justify-between pt-1 border-t border-slate-100">
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="document.getElementById('comment-image-file').click()" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-slate-500 hover:text-brand-600 hover:bg-brand-50 text-xs font-medium transition" title="Adjuntar imagen">
                                <i data-lucide="image" class="w-4 h-4"></i>
                                <span>Imagen</span>
                            </button>
                            <span class="text-[11px] text-slate-400 hidden sm:inline">• <kbd class="px-1 py-0.5 bg-slate-100 rounded text-slate-500 font-mono text-[10px]">Ctrl + V</kbd> para pegar captura</span>
                        </div>

                        <button type="submit" id="submit-comment-btn" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
                            <i data-lucide="send" class="w-3 h-3"></i>
                            <span>Publicar</span>
                        </button>
                    </div>
                </form>
            </div>

            <div id="comments-empty-state" class="text-center py-8 text-xs text-slate-400 italic <?= empty($comments) ? '' : 'hidden' ?>">
                No hay notas publicadas en este proyecto todavía.
            </div>

            <div id="comments-feed" class="space-y-4">
                <?php foreach ($comments as $comm): ?>
                    <?php $commReplies = $repliesByParent[$comm['id']] ?? []; ?>
                    <div id="comment-card-<?= $comm['id'] ?>" class="bg-white rounded-2xl border border-slate-200/70 p-4 shadow-2xs space-y-3 group">
                        <!-- Comentario Padre -->
                        <div class="flex items-start gap-3.5">
                            <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                                <?= strtoupper(substr($comm['author_name'], 0, 2)) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center justify-between gap-1 mb-1">
                                    <div class="flex items-center gap-2">
                                        <h4 class="text-xs font-bold text-slate-900"><?= e($comm['author_name']) ?></h4>
                                        <?php if (!empty($comm['task_id']) && !empty($comm['task_title'])): ?>
                                            <button onclick="openTaskDetailModal(<?= $comm['task_id'] ?>)" class="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 bg-brand-50 px-2 py-0.5 rounded-md hover:bg-brand-100 transition">
                                                <i data-lucide="check-square" class="w-3 h-3"></i>
                                                <span class="truncate max-w-[200px]">Tarea: <?= e($comm['task_title']) ?></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] text-slate-400"><?= formatDate($comm['created_at'], true) ?></span>
                                        <?php if ($comm['user_id'] == $user['id'] || isAdmin()): ?>
                                            <div class="opacity-0 group-hover:opacity-100 transition flex items-center gap-1">
                                                <button onclick="openEditCommentModal(<?= $comm['id'] ?>, <?= htmlspecialchars(json_encode($comm['content']), ENT_QUOTES, 'UTF-8') ?>)" class="p-1 text-slate-400 hover:text-brand-600 rounded" title="Editar comentario">
                                                    <i data-lucide="edit-2" class="w-3 h-3"></i>
                                                </button>
                                                <button onclick="deleteCommentAjax(<?= $comm['id'] ?>)" class="p-1 text-slate-400 hover:text-rose-600 rounded" title="Eliminar comentario">
                                                    <i data-lucide="trash-2" class="w-3 h-3"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($comm['content'])): ?>
                                    <p id="comment-text-<?= $comm['id'] ?>" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line"><?= e($comm['content']) ?></p>
                                <?php endif; ?>

                                <?php if (!empty($comm['image_path'])): ?>
                                    <div class="mt-2.5">
                                        <img src="uploads/<?= e($comm['image_path']) ?>" alt="Imagen adjunta" class="max-h-72 rounded-xl border border-slate-200 shadow-2xs hover:opacity-95 cursor-pointer transition" onclick="openImageModal(this.src)">
                                    </div>
                                <?php endif; ?>

                                <!-- Botón Responder -->
                                <div class="mt-2 flex items-center gap-3">
                                    <button onclick="toggleReplyBox(<?= $comm['id'] ?>)" class="text-[11px] font-semibold text-brand-600 hover:text-brand-700 inline-flex items-center gap-1 transition">
                                        <i data-lucide="reply" class="w-3 h-3"></i>
                                        <span>Responder</span>
                                    </button>
                                    <?php if (!empty($commReplies)): ?>
                                        <span class="text-[10px] text-slate-400 font-medium">
                                            <?= count($commReplies) ?> <?= count($commReplies) === 1 ? 'respuesta' : 'respuestas' ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Respuestas anidadas -->
                        <div id="replies-container-<?= $comm['id'] ?>" class="space-y-2.5 pl-6 sm:pl-10 border-l-2 border-slate-100 <?= empty($commReplies) ? 'hidden' : '' ?>">
                            <?php foreach ($commReplies as $rep): ?>
                                <div id="comment-card-<?= $rep['id'] ?>" class="bg-slate-50/70 p-3 rounded-xl border border-slate-200/50 flex items-start gap-2.5 group/reply">
                                    <div class="w-6 h-6 rounded-full bg-slate-200 text-slate-700 font-bold text-[10px] flex items-center justify-center flex-shrink-0">
                                        <?= strtoupper(substr($rep['author_name'], 0, 2)) ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between mb-0.5">
                                            <h5 class="text-xs font-bold text-slate-800"><?= e($rep['author_name']) ?></h5>
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-[9px] text-slate-400"><?= formatDate($rep['created_at'], true) ?></span>
                                                <?php if ($rep['user_id'] == $user['id'] || isAdmin()): ?>
                                                    <div class="opacity-0 group-hover/reply:opacity-100 transition flex items-center gap-0.5">
                                                        <button onclick="openEditCommentModal(<?= $rep['id'] ?>, <?= htmlspecialchars(json_encode($rep['content']), ENT_QUOTES, 'UTF-8') ?>)" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                                            <i data-lucide="edit-2" class="w-2.5 h-2.5"></i>
                                                        </button>
                                                        <button onclick="deleteCommentAjax(<?= $rep['id'] ?>)" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                                            <i data-lucide="trash-2" class="w-2.5 h-2.5"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($rep['content'])): ?>
                                            <p id="comment-text-<?= $rep['id'] ?>" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line"><?= e($rep['content']) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($rep['image_path'])): ?>
                                            <div class="mt-2">
                                                <img src="uploads/<?= e($rep['image_path']) ?>" class="max-h-48 rounded-lg border border-slate-200 cursor-pointer" onclick="openImageModal(this.src)">
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Caja para responder desplegable -->
                        <div id="reply-box-<?= $comm['id'] ?>" class="hidden pl-6 sm:pl-10 pt-2 border-l-2 border-slate-100">
                            <form onsubmit="submitReplyAjax(event, <?= $comm['id'] ?>, <?= !empty($comm['task_id']) ? $comm['task_id'] : 'null' ?>)" class="space-y-2">
                                <textarea id="reply-content-<?= $comm['id'] ?>" rows="2" placeholder="Escribe tu respuesta a <?= e($comm['author_name']) ?>..." required class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-brand-500 bg-slate-50/50 leading-relaxed"></textarea>
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" onclick="toggleReplyBox(<?= $comm['id'] ?>)" class="px-3 py-1 rounded-lg text-xs text-slate-500 hover:bg-slate-100">Cancelar</button>
                                    <button type="submit" id="btn-submit-reply-<?= $comm['id'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
                                        <i data-lucide="send" class="w-3 h-3"></i>
                                        <span>Enviar Respuesta</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ==================== TAB 5: ADJUNTOS ==================== -->
        <section id="tab-adjuntos" class="tab-content hidden space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-bold text-base text-slate-900">Adjuntos y Recursos</h2>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-2xs font-semibold bg-slate-100 text-slate-500">
                        <?= count($files) + count($links) ?>
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <?php if (!$isClient && $canManageAttachments): ?>
                        <button onclick="openModal('create-folder-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs border border-slate-200/80 shadow-2xs transition">
                            <i data-lucide="folder-plus" class="w-3.5 h-3.5 text-slate-500"></i>
                            <span>Carpeta</span>
                        </button>
                    <?php endif; ?>
                    <button onclick="openModal('upload-file-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs shadow-2xs transition">
                        <i data-lucide="upload" class="w-3.5 h-3.5"></i>
                        <span>Subir</span>
                    </button>
                    <?php if (!$isClient && $canManageAttachments): ?>
                        <button onclick="openModal('create-link-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs border border-slate-200/80 shadow-2xs transition">
                            <i data-lucide="link" class="w-3.5 h-3.5 text-slate-500"></i>
                            <span>Enlace</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Navegador de Carpetas (Oculto para clientes) -->
            <?php if (!$isClient): ?>
            <div class="space-y-2">
                <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Carpetas del Proyecto</span>
                <div id="folders-bar" class="flex items-center gap-2 overflow-x-auto pb-1">
                    <button onclick="filterByFolder('all', this)" class="folder-pill active inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-brand-600 text-white shadow-2xs transition whitespace-nowrap">
                        <i data-lucide="layers" class="w-3.5 h-3.5"></i>
                        <span>Todos los adjuntos</span>
                    </button>

                    <button onclick="filterByFolder('root', this)" class="folder-pill inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-white hover:bg-slate-50 text-slate-600 border border-slate-200/80 transition whitespace-nowrap">
                        <i data-lucide="folder" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Sin carpeta (Raíz)</span>
                    </button>

                    <?php foreach ($folders as $fol): ?>
                        <div id="folder-pill-container-<?= $fol['id'] ?>" class="inline-flex items-center group">
                            <button onclick="filterByFolder(<?= $fol['id'] ?>, this)" class="folder-pill inline-flex items-center gap-2 px-3.5 py-1.5 <?= $canManageAttachments ? 'rounded-l-xl' : 'rounded-xl' ?> text-xs font-semibold bg-white hover:bg-slate-50 text-slate-700 border <?= $canManageAttachments ? 'border-r-0' : '' ?> border-slate-200/80 transition whitespace-nowrap">
                                <span class="w-2.5 h-2.5 rounded-full" style="background-color: <?= e($fol['color']) ?>;"></span>
                                <span><?= e($fol['name']) ?></span>
                            </button>
                            <?php if ($canManageAttachments): ?>
                                <button onclick="deleteFolderAjax(<?= $fol['id'] ?>)" class="px-2 py-1.5 rounded-r-xl border border-l-0 border-slate-200/80 bg-white hover:bg-rose-50 text-slate-300 hover:text-rose-600 transition" title="Eliminar carpeta">
                                    <i data-lucide="x" class="w-3 h-3"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Subsección 1: Archivos Adjuntos -->
            <div class="space-y-3">
                <div class="flex items-center gap-2">
                    <i data-lucide="file-text" class="w-4 h-4 text-brand-600"></i>
                    <h3 class="font-display font-bold text-slate-800 text-sm">Archivos Descargables</h3>
                    <span id="badge-files-count" class="px-2 py-0.5 rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= count($files) ?></span>
                </div>

                <div id="files-empty-state" class="bg-white rounded-2xl border border-dashed border-slate-200 p-8 text-center <?= empty($files) ? '' : 'hidden' ?>">
                    <i data-lucide="paperclip" class="w-7 h-7 text-slate-300 mx-auto mb-1.5"></i>
                    <h4 class="text-xs font-semibold text-slate-700">Sin archivos en esta vista</h4>
                </div>

                <div id="files-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5 <?= empty($files) ? 'hidden' : '' ?>">
                    <?php foreach ($files as $file): ?>
                        <div id="file-card-<?= $file['id'] ?>" data-folder-id="<?= $file['folder_id'] ?: 'root' ?>" class="file-item-card bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs hover:border-slate-300 transition flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="file" class="w-4 h-4"></i>
                                </div>
                                <div class="min-w-0">
                                    <h4 class="font-semibold text-slate-900 text-xs truncate" title="<?= e($file['original_name']) ?>">
                                        <?= e($file['original_name']) ?>
                                    </h4>

                                    <!-- Chip de Tarea si pertenece a una tarea -->
                                    <?php if (!empty($file['task_id']) && !empty($file['task_title'])): ?>
                                        <button onclick="openTaskDetailModal(<?= $file['task_id'] ?>)" class="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 bg-brand-50 px-1.5 py-0.5 rounded hover:bg-brand-100 transition mt-0.5">
                                            <i data-lucide="check-square" class="w-3 h-3"></i>
                                            <span class="truncate max-w-[140px]">Tarea: <?= e($file['task_title']) ?></span>
                                        </button>
                                    <?php endif; ?>

                                    <p class="text-[10px] text-slate-400 mt-0.5">
                                        <?= formatBytes($file['file_size']) ?> • <?= formatDate($file['created_at']) ?>
                                        <?php if (!empty($file['folder_name'])): ?>
                                            • <span class="text-brand-600 font-medium"><?= e($file['folder_name']) ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-1 flex-shrink-0">
                                <a href="api/files.php?action=download&id=<?= $file['id'] ?>" class="p-1.5 rounded-lg bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-600 transition" title="Descargar">
                                    <i data-lucide="download" class="w-3.5 h-3.5"></i>
                                </a>
                                <?php if ($canManageAttachments || $file['user_id'] == $user['id']): ?>
                                <button onclick="deleteFileAjax(<?= $file['id'] ?>)" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Eliminar">
                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!$isClient): ?>
            <!-- Subsección 2: Enlaces (Solo equipo interno) -->
            <div class="space-y-3 pt-3 border-t border-slate-200/60">
                <div class="flex items-center gap-2">
                    <i data-lucide="link" class="w-4 h-4 text-emerald-600"></i>
                    <h3 class="font-display font-bold text-slate-800 text-sm">Enlaces y Accesos Directos</h3>
                    <span id="badge-links-count" class="px-2 py-0.5 rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= count($links) ?></span>
                </div>

                <div id="links-empty-state" class="bg-white rounded-2xl border border-dashed border-slate-200 p-8 text-center <?= empty($links) ? '' : 'hidden' ?>">
                    <i data-lucide="link-2" class="w-7 h-7 text-slate-300 mx-auto mb-1.5"></i>
                    <h4 class="text-xs font-semibold text-slate-700">Sin enlaces en esta vista</h4>
                </div>

                <div id="links-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5 <?= empty($links) ? 'hidden' : '' ?>">
                    <?php foreach ($links as $link): ?>
                        <div id="link-card-<?= $link['id'] ?>" data-folder-id="<?= $link['folder_id'] ?: 'root' ?>" class="link-item-card bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs hover:border-slate-300 transition flex items-start justify-between gap-3 group">
                            <div class="flex items-start gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-xl bg-slate-100 group-hover:bg-indigo-50 text-slate-600 group-hover:text-brand-600 flex items-center justify-center flex-shrink-0 transition">
                                    <i data-lucide="external-link" class="w-4 h-4"></i>
                                </div>
                                <div class="min-w-0">
                                    <h4 class="font-semibold text-slate-900 text-xs truncate"><?= e($link['title']) ?></h4>

                                    <!-- Chip de Tarea si pertenece a una tarea -->
                                    <?php if (!empty($link['task_id']) && !empty($link['task_title'])): ?>
                                        <button onclick="openTaskDetailModal(<?= $link['task_id'] ?>)" class="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 bg-brand-50 px-1.5 py-0.5 rounded hover:bg-brand-100 transition mt-0.5">
                                            <i data-lucide="check-square" class="w-3 h-3"></i>
                                            <span class="truncate max-w-[140px]">Tarea: <?= e($link['task_title']) ?></span>
                                        </button>
                                    <?php endif; ?>

                                    <a href="<?= isSafeUrl($link['url']) ? e($link['url']) : '#' ?>" target="_blank" rel="noopener noreferrer" class="text-[11px] text-brand-600 hover:underline truncate block mt-0.5">
                                        <?= e($link['url']) ?>
                                    </a>
                                </div>
                            </div>
                            <button onclick="deleteLinkAjax(<?= $link['id'] ?>)" class="p-1 text-slate-400 hover:text-rose-600 transition" title="Eliminar enlace">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <!-- ==================== TAB 6: COSTO & FACTURACIÓN (Solo Admin) ==================== -->
        <?php if (isAdmin()): ?>
        <section id="tab-finanzas" class="tab-content hidden space-y-5">
            <!-- Encabezado con Botones de Acción -->
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-bold text-base text-slate-900">Finanzas</h2>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-mono"><?= e($projectCurrency) ?></span>
                </div>

                <div class="flex items-center gap-2">
                    <?php if ($canManageProject): ?>
                        <button onclick="openModal('edit-budget-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">
                            <i data-lucide="calculator" class="w-3.5 h-3.5 text-slate-500"></i>
                            <span>Costo</span>
                        </button>
                        <button onclick="openModal('create-invoice-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs shadow-xs transition">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            <span>Nueva Factura</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tarjetas de Métricas Financieras -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <!-- 1. Presupuesto / Costo Total -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 block">Costo Total</span>
                    <div class="text-lg sm:text-xl font-display font-bold text-slate-900" id="card-fin-budget">
                        <?= e($projectCurrency) ?> $<?= number_format($projectBudget, 2) ?>
                    </div>
                </div>

                <!-- 2. Total Facturado -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 block">Facturado</span>
                    <div class="text-lg sm:text-xl font-display font-bold text-blue-700" id="card-fin-invoiced">
                        <?= e($projectCurrency) ?> $<?= number_format($totalInvoiced, 2) ?>
                    </div>
                </div>

                <!-- 3. Cobrado / Pagado -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 block">Cobrado</span>
                    <div class="text-lg sm:text-xl font-display font-bold text-emerald-700" id="card-fin-paid">
                        <?= e($projectCurrency) ?> $<?= number_format($totalPaid, 2) ?>
                    </div>
                </div>

                <!-- 4. Facturas Por Cobrar (Pendiente) -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 block">Por Cobrar</span>
                    <div class="text-lg sm:text-xl font-display font-bold <?= $totalPending > 0 ? 'text-amber-600' : 'text-slate-900' ?>" id="card-fin-pending">
                        <?= e($projectCurrency) ?> $<?= number_format($totalPending, 2) ?>
                    </div>
                </div>
            </div>

            <!-- Tabla de Facturas y Cobros -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="font-display font-bold text-xs sm:text-sm text-slate-900">Historial de Cobros</h3>
                    <span class="text-xs text-slate-400"><?= count($invoices) ?> facturas</span>
                </div>

                <div id="invoices-empty-state" class="p-12 text-center <?= empty($invoices) ? '' : 'hidden' ?>">
                    <div class="w-12 h-12 bg-slate-100 text-slate-400 rounded-2xl flex items-center justify-center mx-auto mb-3">
                        <i data-lucide="receipt" class="w-6 h-6"></i>
                    </div>
                    <h4 class="text-sm font-semibold text-slate-800">No hay facturas registradas</h4>
                    <p class="text-xs text-slate-500 mt-1 mb-4">Emite la primera factura o hito de cobro para este proyecto.</p>
                    <?php if ($canManageProject): ?>
                        <button onclick="openModal('create-invoice-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 text-white text-xs font-semibold rounded-lg shadow-xs">
                            + Emitir Factura
                        </button>
                    <?php endif; ?>
                </div>

                <div id="invoices-table-container" class="overflow-x-auto <?= empty($invoices) ? 'hidden' : '' ?>">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="text-slate-400 font-semibold border-b border-slate-100 uppercase text-[10px] bg-slate-50/50">
                                <th class="py-3 px-4">N° Factura</th>
                                <th class="py-3 px-4">Concepto / Hito</th>
                                <th class="py-3 px-4">Importe</th>
                                <th class="py-3 px-4">Vencimiento</th>
                                <th class="py-3 px-4">Estado</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100" id="invoices-table-body">
                            <?php foreach ($invoices as $inv): ?>
                                <?php
                                    $statusBadge = match($inv['status']) {
                                        'pagada'    => 'bg-emerald-50 text-emerald-700 border-emerald-200/60',
                                        'pendiente' => 'bg-amber-50 text-amber-700 border-amber-200/60',
                                        'anulada'   => 'bg-rose-50 text-rose-700 border-rose-200/60',
                                        default     => 'bg-slate-50 text-slate-700 border-slate-200/60'
                                    };
                                ?>
                                <tr id="invoice-row-<?= $inv['id'] ?>" class="hover:bg-slate-50/60 transition">
                                    <td class="py-3 px-4 font-mono font-bold text-slate-900">
                                        <?= e($inv['invoice_number']) ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-slate-800"><?= e($inv['concept']) ?></div>
                                        <?php if (!empty($inv['notes'])): ?>
                                            <div class="text-[11px] text-slate-400 truncate max-w-xs mt-0.5"><?= e($inv['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 font-bold text-slate-900">
                                        <?= e($inv['currency'] ?: 'USD') ?> $<?= number_format(floatval($inv['amount']), 2) ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-500">
                                        <?= $inv['due_date'] ? formatDate($inv['due_date']) : '<span class="text-slate-300">-</span>' ?>
                                        <?php if ($inv['status'] === 'pagada' && !empty($inv['paid_at'])): ?>
                                            <div class="text-[10px] text-emerald-600 font-medium">Pagado el <?= formatDate($inv['paid_at']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span id="inv-badge-<?= $inv['id'] ?>" class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider border <?= $statusBadge ?>">
                                            <?= e($inv['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <?php if ($canManageProject): ?>
                                            <div class="inline-flex items-center gap-1">
                                                <select onchange="updateInvoiceStatusAjax(<?= $inv['id'] ?>, this.value)" class="text-[11px] font-semibold py-1 px-2 rounded-lg border border-slate-200 bg-white text-slate-700 cursor-pointer focus:ring-1 focus:ring-brand-500">
                                                    <option value="pendiente" <?= $inv['status'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                                    <option value="pagada" <?= $inv['status'] === 'pagada' ? 'selected' : '' ?>>Pagada</option>
                                                    <option value="anulada" <?= $inv['status'] === 'anulada' ? 'selected' : '' ?>>Anulada</option>
                                                </select>
                                                <button onclick="deleteInvoiceAjax(<?= $inv['id'] ?>)" class="p-1 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Eliminar factura">
                                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-400 italic">Solo lectura</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

    </div>
</main>

<!-- ==================== MODALES DEL SISTEMA ==================== -->

<!-- 1. MODAL DETALLE Y GESTIÓN DE TAREA (AMPLIADO, ESTRUCTURA INTEGRADA CON DETALLE PERMANENTE + PESTAÑAS DE ACTIVIDAD) -->
<div id="task-detail-modal" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs hidden">
    <div class="bg-white rounded-3xl max-w-5xl w-full shadow-2xl border border-slate-100 overflow-hidden flex flex-col max-h-[92vh]">
        <!-- Cabecera del Detalle -->
        <div class="px-6 py-3.5 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
            <div class="flex items-center gap-2.5">
                <span id="modal-task-priority" class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-md border"></span>
                <span id="modal-task-status-badge" class="text-xs font-semibold px-2.5 py-0.5 rounded-full capitalize"></span>
            </div>
            <div class="flex items-center gap-2">
                <?php if (!$isClient && canEditTasks()): ?>
                <button type="button" id="btn-toggle-edit-task" onclick="toggleTaskEditMode()" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 transition flex items-center gap-1.5 shadow-2xs">
                    <i data-lucide="edit-3" class="w-3.5 h-3.5 text-brand-600"></i>
                    <span id="btn-toggle-edit-task-text">Editar Tarea</span>
                </button>
                <?php endif; ?>
                <button type="button" onclick="closeModal('task-detail-modal')" class="text-slate-400 hover:text-slate-600 p-1.5 hover:bg-slate-100 rounded-lg transition">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
        </div>

        <!-- Skeleton Loader para Detalle de Tarea -->
        <div id="task-overview-skeleton" class="p-6 space-y-6 animate-pulse">
            <div>
                <div class="h-7 bg-slate-200 rounded-xl w-3/4 mb-3"></div>
                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 space-y-2">
                    <div class="h-3 bg-slate-200 rounded w-20"></div>
                    <div class="h-4 bg-slate-200 rounded w-full"></div>
                    <div class="h-4 bg-slate-200 rounded w-4/5"></div>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 space-y-2">
                    <div class="h-2.5 bg-slate-200 rounded w-16"></div>
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full bg-slate-200"></div>
                        <div class="h-4 bg-slate-200 rounded w-24"></div>
                    </div>
                </div>
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 space-y-2">
                    <div class="h-2.5 bg-slate-200 rounded w-16"></div>
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full bg-slate-200"></div>
                        <div class="h-4 bg-slate-200 rounded w-20"></div>
                    </div>
                </div>
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 space-y-2">
                    <div class="h-2.5 bg-slate-200 rounded w-16"></div>
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full bg-slate-200"></div>
                        <div class="h-4 bg-slate-200 rounded w-20"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cuerpo del Modal (Modo Vista Real) -->
        <div id="task-overview-view" class="p-6 overflow-y-auto space-y-6 flex-1 bg-white hidden">

            <!-- Modo Edición Formulario (Desplegable) -->
            <form id="task-edit-form" onsubmit="submitTaskEditAjax(event)" class="hidden p-5 rounded-2xl bg-slate-50/90 border border-slate-200/90 space-y-4 shadow-2xs">
                <div class="flex items-center justify-between pb-2 border-b border-slate-200">
                    <span class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <i data-lucide="edit-3" class="w-3.5 h-3.5 text-brand-600"></i>
                        <span>Editar Información Principal de la Tarea</span>
                    </span>
                    <button type="button" onclick="toggleTaskEditMode(false)" class="text-xs text-slate-400 hover:text-slate-600">✕ Cancelar</button>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Título de la Tarea *</label>
                    <input type="text" id="edit-task-title-input" required class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-brand-500 bg-white shadow-2xs">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Descripción</label>
                    <textarea id="edit-task-desc-input" rows="3" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:ring-1 focus:ring-brand-500 bg-white leading-relaxed shadow-2xs"></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Prioridad</label>
                        <select id="edit-task-prio-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                            <option value="baja">Baja</option>
                            <option value="media">Media</option>
                            <option value="alta">Alta</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Estado</label>
                        <select id="edit-task-status-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                            <option value="pendiente">Por Hacer</option>
                            <option value="en_progreso">En Progreso</option>
                            <option value="completada">Completada</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Fecha Límite</label>
                        <input type="date" id="edit-task-date-input" class="w-full px-3 py-1.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Asignar a</label>
                        <select id="edit-task-assigned-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                            <option value="">Sin asignar</option>
                            <?php foreach ($collaborators as $col): ?>
                                <option value="<?= (int)$col['id'] ?>"><?= e($col['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end gap-2.5 pt-2 border-t border-slate-200">
                    <button type="button" onclick="toggleTaskEditMode(false)" class="px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-200 transition">Cancelar</button>
                    <button type="submit" id="btn-save-task-edit" class="px-4 py-1.5 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-2xs transition">Guardar Cambios</button>
                </div>
            </form>

            <!-- SECCIÓN PRINCIPAL: Título, Descripción y Atributos (Siempre Visible) -->
            <div id="task-info-display" class="space-y-4">
                <div>
                    <h2 id="modal-task-title" class="font-display font-bold text-xl sm:text-2xl text-slate-900 leading-tight"></h2>
                    <div class="mt-3 p-4 rounded-2xl bg-slate-50/70 border border-slate-100">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Descripción de la Tarea</span>
                        <p id="modal-task-description" class="text-xs sm:text-sm text-slate-600 leading-relaxed whitespace-pre-line"></p>
                    </div>
                </div>

                <!-- Cuadrícula de Atributos Principales -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                    <div class="p-3.5 rounded-2xl bg-slate-50/60 border border-slate-100 flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                            <i data-lucide="user" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block leading-tight">Responsable</span>
                            <span id="modal-task-assigned" class="text-xs font-semibold text-slate-800 truncate block mt-0.5"></span>
                        </div>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-50/60 border border-slate-100 flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                            <i data-lucide="calendar" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block leading-tight">Fecha Límite</span>
                            <span id="modal-task-duedate" class="text-xs font-semibold text-slate-800 truncate block mt-0.5"></span>
                        </div>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-50/60 border border-slate-100 flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl bg-amber-50 text-amber-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                            <i data-lucide="activity" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block leading-tight">Estado Actual</span>
                            <span id="modal-task-status-text" class="text-xs font-semibold text-slate-800 capitalize truncate block mt-0.5"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN INFERIOR: TABS DE ACTIVIDADES (Comentarios, Checklists, Adjuntos) -->
            <?php if (!$isClient): ?>
            <div class="pt-3 border-t border-slate-100 space-y-4">
                <!-- Pestañas de la Tarea -->
                <div class="flex items-center gap-6 border-b border-slate-100 text-xs font-medium">
                    <button type="button" onclick="switchTaskDetailTab('comments')" id="task-tab-btn-comments" class="task-detail-tab-btn pb-2.5 font-bold text-brand-600 border-b-2 border-brand-600 flex items-center gap-1.5 transition">
                        <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
                        <span>Comentarios</span>
                        <span id="modal-task-comments-badge-count" class="bg-brand-50 text-brand-700 px-1.5 py-0.2 rounded-full text-[10px] font-bold">0</span>
                    </button>
                    <button type="button" onclick="switchTaskDetailTab('checklists')" id="task-tab-btn-checklists" class="task-detail-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5 transition">
                        <i data-lucide="list-checks" class="w-3.5 h-3.5"></i>
                        <span>Checklists</span>
                        <span id="modal-task-chk-count" class="bg-slate-100 px-1.5 py-0.2 rounded-full text-[10px] font-bold">0</span>
                    </button>
                    <button type="button" onclick="switchTaskDetailTab('adjuntos')" id="task-tab-btn-adjuntos" class="task-detail-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5 transition">
                        <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                        <span>Adjuntos</span>
                        <span id="modal-task-adj-count" class="bg-slate-100 px-1.5 py-0.2 rounded-full text-[10px] font-bold">0</span>
                    </button>
                </div>

                <!-- SUBSECCIÓN: COMENTARIOS DE LA TAREA -->
                <div id="task-detail-sec-comments" class="task-detail-sub-section space-y-4">
                    <form onsubmit="submitTaskCommentAjax(event)" class="space-y-3 bg-slate-50/70 p-3.5 rounded-2xl border border-slate-200/70">
                        <input type="hidden" id="modal-task-id-input">
                        <textarea id="modal-task-comment-input" rows="2" placeholder="Escribe un comentario en esta tarea o pega una captura con Ctrl+V..." class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:ring-1 focus:ring-brand-500 bg-white leading-relaxed"></textarea>
                        
                        <div id="modal-comment-image-preview" class="hidden relative inline-block border border-slate-200 rounded-lg p-1 bg-white">
                            <img id="modal-comment-preview-img" src="" alt="Vista previa" class="h-20 max-w-xs object-cover rounded">
                            <button type="button" onclick="clearModalCommentImage()" class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center text-[10px]">✕</button>
                        </div>

                        <input type="file" id="modal-comment-image-file" accept="image/*" class="hidden" onchange="handleModalCommentImageSelect(this)">

                        <div class="flex items-center justify-between pt-1">
                            <button type="button" onclick="document.getElementById('modal-comment-image-file').click()" class="inline-flex items-center gap-1.5 text-xs text-slate-500 hover:text-brand-600">
                                <i data-lucide="image" class="w-3.5 h-3.5"></i>
                                <span>Adjuntar imagen (o Ctrl+V)</span>
                            </button>
                            <button type="submit" id="modal-submit-comment-btn" class="px-3.5 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
                                Enviar Comentario
                            </button>
                        </div>
                    </form>

                    <div id="modal-task-comments-feed" class="space-y-3"></div>
                </div>

                <!-- SUBSECCIÓN: CHECKLISTS DE LA TAREA -->
                <div id="task-detail-sec-checklists" class="task-detail-sub-section hidden space-y-4">
                    <form onsubmit="submitTaskChecklistAjax(event)" class="flex items-center gap-2">
                        <input type="text" id="task-new-chk-title" required placeholder="+ Crear nueva lista de pasos para esta tarea..." class="flex-1 px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500">
                        <button type="submit" class="px-3 py-2 bg-brand-600 text-white text-xs font-semibold rounded-xl hover:bg-brand-700 transition">Crear Lista</button>
                    </form>

                    <div id="modal-task-checklists-container" class="space-y-4"></div>
                </div>

                <!-- SUBSECCIÓN: ADJUNTOS DE LA TAREA -->
                <div id="task-detail-sec-adjuntos" class="task-detail-sub-section hidden space-y-4">
                    <!-- Barra superior de acción rápida minimalista -->
                    <div class="flex items-center justify-between pb-1 border-b border-slate-100">
                        <span class="text-xs font-semibold text-slate-700">Archivos y Enlaces de esta tarea</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="toggleTaskAttachmentForm('file')" id="btn-toggle-task-file" class="px-2.5 py-1 text-xs font-medium text-slate-600 hover:text-brand-600 bg-slate-100 hover:bg-brand-50 rounded-lg flex items-center gap-1 transition">
                                <i data-lucide="upload-cloud" class="w-3.5 h-3.5"></i>
                                <span>Subir Archivo</span>
                            </button>
                            <button type="button" onclick="toggleTaskAttachmentForm('link')" id="btn-toggle-task-link" class="px-2.5 py-1 text-xs font-medium text-slate-600 hover:text-brand-600 bg-slate-100 hover:bg-brand-50 rounded-lg flex items-center gap-1 transition">
                                <i data-lucide="link" class="w-3.5 h-3.5"></i>
                                <span>Añadir Enlace</span>
                            </button>
                        </div>
                    </div>

                    <!-- Formulario colapsable para Subir Archivo -->
                    <form id="form-task-add-file" onsubmit="submitTaskFileAjax(event)" class="hidden p-3 bg-slate-50/80 rounded-xl border border-slate-200/80 space-y-2.5 transition-all">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-800 flex items-center gap-1.5">
                                <i data-lucide="file-up" class="w-3.5 h-3.5 text-brand-600"></i>
                                <span>Subir nuevo archivo</span>
                            </span>
                            <button type="button" onclick="toggleTaskAttachmentForm('file')" class="text-slate-400 hover:text-slate-600 text-xs">✕</button>
                        </div>
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                            <input type="file" id="task-file-input" required class="flex-1 text-xs text-slate-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-white file:text-brand-700 file:border file:border-slate-200 cursor-pointer">
                            <button type="submit" id="task-file-btn" class="px-3.5 py-1.5 bg-brand-600 text-white text-xs font-semibold rounded-lg hover:bg-brand-700 transition flex items-center justify-center gap-1.5 shadow-2xs">
                                <i data-lucide="upload" class="w-3 h-3"></i>
                                <span>Subir</span>
                            </button>
                        </div>
                    </form>

                    <!-- Formulario colapsable para Añadir Enlace -->
                    <form id="form-task-add-link" onsubmit="submitTaskLinkAjax(event)" class="hidden p-3 bg-slate-50/80 rounded-xl border border-slate-200/80 space-y-2.5 transition-all">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-800 flex items-center gap-1.5">
                                <i data-lucide="link-2" class="w-3.5 h-3.5 text-emerald-600"></i>
                                <span>Vincular enlace web</span>
                            </span>
                            <button type="button" onclick="toggleTaskAttachmentForm('link')" class="text-slate-400 hover:text-slate-600 text-xs">✕</button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <input type="text" id="task-link-title" required placeholder="Título (ej: Boceto en Figma)" class="px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white text-xs focus:ring-1 focus:ring-brand-500">
                            <input type="url" id="task-link-url" required placeholder="https://..." class="px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white text-xs focus:ring-1 focus:ring-brand-500">
                        </div>
                        <div class="flex justify-end pt-1">
                            <button type="submit" class="px-3.5 py-1.5 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition flex items-center gap-1.5 shadow-2xs">
                                <i data-lucide="plus" class="w-3 h-3"></i>
                                <span>Guardar Enlace</span>
                            </button>
                        </div>
                    </form>

                    <!-- Contenedores limpios y organizados para Archivos y Enlaces -->
                    <div class="space-y-3 pt-1">
                        <div>
                            <div class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Archivos</div>
                            <div id="modal-task-files-list" class="space-y-1.5"></div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Enlaces</div>
                            <div id="modal-task-links-list" class="space-y-1.5"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- 2. Modal: Crear Tarea (Ampliación con pestañas internas para Comentarios, Checklists y Adjuntos directos) -->
<div id="create-task-modal" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs hidden">
    <div class="bg-white rounded-3xl max-w-4xl w-full shadow-2xl border border-slate-100 overflow-hidden flex flex-col max-h-[92vh]">
        <!-- Cabecera Crear Tarea -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/60">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-brand-500"></span>
                <h3 class="font-display font-bold text-slate-900 text-base sm:text-lg">Nueva Tarea / Actividad</h3>
            </div>
            <button type="button" onclick="closeModal('create-task-modal')" class="text-slate-400 hover:text-slate-600 p-1.5 hover:bg-slate-100 rounded-lg transition">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <!-- Pestañas Superiores de Creación de Tarea -->
        <div class="px-6 pt-3 border-b border-slate-100 flex items-center gap-6 bg-white text-xs font-medium">
            <button type="button" onclick="switchCreateTaskTab('general')" id="create-task-tab-btn-general" class="create-task-tab-btn pb-2.5 font-bold text-brand-600 border-b-2 border-brand-600 flex items-center gap-1.5">
                <i data-lucide="layout" class="w-3.5 h-3.5"></i>
                <span>Información Principal *</span>
            </button>
            <button type="button" onclick="switchCreateTaskTab('comments')" id="create-task-tab-btn-comments" class="create-task-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5">
                <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
                <span>Comentario Inicial</span>
                <span id="create-task-comments-indicator" class="w-1.5 h-1.5 rounded-full bg-brand-500 hidden"></span>
            </button>
            <button type="button" onclick="switchCreateTaskTab('checklists')" id="create-task-tab-btn-checklists" class="create-task-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5">
                <i data-lucide="list-checks" class="w-3.5 h-3.5"></i>
                <span>Checklist Inicial</span>
                <span id="create-task-checklist-badge" class="bg-slate-100 px-1.5 py-0.2 rounded-full text-[10px] hidden font-bold">0</span>
            </button>
            <button type="button" onclick="switchCreateTaskTab('adjuntos')" id="create-task-tab-btn-adjuntos" class="create-task-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5">
                <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                <span>Adjuntos</span>
                <span id="create-task-adjuntos-indicator" class="w-1.5 h-1.5 rounded-full bg-emerald-500 hidden"></span>
            </button>
        </div>

        <form id="create-task-form" onsubmit="submitTaskAjax(event)" class="flex flex-col flex-1 overflow-hidden">
            <div class="p-6 overflow-y-auto space-y-5 flex-1">
                <!-- TAB CREACIÓN 1: GENERAL -->
                <div id="create-task-sec-general" class="create-task-section space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Título de la Tarea *</label>
                        <input type="text" id="task-title-input" required placeholder="Ej: Diseñar componentes del carrito y checkout" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-brand-500 bg-white shadow-2xs">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Descripción Detallada</label>
                        <textarea id="task-desc-input" rows="4" placeholder="Especificaciones, requerimientos y detalles para completar la tarea..." class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:ring-1 focus:ring-brand-500 bg-white leading-relaxed shadow-2xs"></textarea>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Prioridad</label>
                            <select id="task-prio-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                                <option value="baja">Baja</option>
                                <option value="media" selected>Media</option>
                                <option value="alta">Alta</option>
                                <option value="urgente">Urgente</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Fecha de Entrega</label>
                            <input type="date" id="task-date-input" class="w-full px-3 py-1.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Asignar a Colaborador</label>
                            <select id="task-assigned-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                                <option value="">Sin asignar</option>
                                <?php foreach ($collaborators as $col): ?>
                                    <option value="<?= (int)$col['id'] ?>"><?= e($col['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- TAB CREACIÓN 2: COMENTARIO INICIAL -->
                <div id="create-task-sec-comments" class="create-task-section hidden space-y-4">
                    <div class="p-3.5 rounded-2xl bg-slate-50/70 border border-slate-200/70 space-y-3">
                        <label class="block text-xs font-semibold text-slate-800">Primer comentario o notas de arranque (Opcional)</label>
                        <textarea id="create-task-comment-text" rows="3" placeholder="Deja una nota de inicio, instrucciones para el responsable o pega una captura..." class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:ring-1 focus:ring-brand-500 bg-white leading-relaxed"></textarea>
                        
                        <div id="create-task-comment-preview-box" class="hidden relative inline-block border border-slate-200 rounded-lg p-1 bg-white">
                            <img id="create-task-comment-preview-img" src="" alt="Vista previa" class="h-20 max-w-xs object-cover rounded">
                            <button type="button" onclick="clearCreateTaskCommentImage()" class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center text-[10px]">✕</button>
                        </div>

                        <input type="file" id="create-task-comment-image-file" accept="image/*" class="hidden" onchange="handleCreateTaskCommentImageSelect(this)">
                        
                        <div class="flex items-center justify-between">
                            <button type="button" onclick="document.getElementById('create-task-comment-image-file').click()" class="inline-flex items-center gap-1.5 text-xs text-slate-500 hover:text-brand-600">
                                <i data-lucide="image" class="w-3.5 h-3.5"></i>
                                <span>Adjuntar imagen (o Ctrl+V en el texto)</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TAB CREACIÓN 3: CHECKLIST INICIAL -->
                <div id="create-task-sec-checklists" class="create-task-section hidden space-y-4">
                    <div class="p-3.5 rounded-2xl bg-slate-50/70 border border-slate-200/70 space-y-3">
                        <label class="block text-xs font-semibold text-slate-800">Nombre de la Lista de Pasos (Opcional)</label>
                        <input type="text" id="create-task-chk-title" placeholder="Ej: Pasos de implementación inicial" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                        
                        <label class="block text-xs font-semibold text-slate-700 pt-1">Pasos o Tareas secundarias (Uno por línea)</label>
                        <textarea id="create-task-chk-items" rows="3" placeholder="Paso 1: Definir variables&#10;Paso 2: Maquetar interfaz&#10;Paso 3: Pruebas responsive" class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white font-mono"></textarea>
                    </div>
                </div>

                <!-- TAB CREACIÓN 4: ADJUNTOS INICIALES -->
                <div id="create-task-sec-adjuntos" class="create-task-section hidden space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Subir Archivo inicial -->
                        <div class="p-3.5 rounded-2xl bg-slate-50/70 border border-slate-200/70 space-y-2.5">
                            <span class="text-xs font-semibold text-slate-800 flex items-center gap-1.5">
                                <i data-lucide="file-up" class="w-3.5 h-3.5 text-brand-600"></i>
                                <span>Adjuntar un Archivo</span>
                            </span>
                            <input type="file" id="create-task-file-input" class="w-full text-xs text-slate-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-white file:text-brand-700 file:border file:border-slate-200 cursor-pointer">
                            <p class="text-[11px] text-slate-400">Se subirá y vinculará automáticamente al crear la tarea.</p>
                        </div>

                        <!-- Enlace Web inicial -->
                        <div class="p-3.5 rounded-2xl bg-slate-50/70 border border-slate-200/70 space-y-2.5">
                            <span class="text-xs font-semibold text-slate-800 flex items-center gap-1.5">
                                <i data-lucide="link" class="w-3.5 h-3.5 text-emerald-600"></i>
                                <span>Vincular un Enlace</span>
                            </span>
                            <input type="text" id="create-task-link-title" placeholder="Título (ej: Especificación)" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs bg-white">
                            <input type="url" id="create-task-link-url" placeholder="https://..." class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs bg-white">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer con Acciones -->
            <div class="px-6 py-3.5 border-t border-slate-100 flex items-center justify-end gap-2 bg-slate-50/60">
                <button type="button" onclick="closeModal('create-task-modal')" class="px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-200/80 transition">Cancelar</button>
                <button type="submit" id="submit-task-btn" class="px-4 py-1.5 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-2xs transition flex items-center gap-1.5">
                    <i data-lucide="check" class="w-3.5 h-3.5"></i>
                    <span>Crear Tarea</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Emitir Nueva Factura / Hito de Cobro -->
<div id="create-invoice-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
    <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-display font-bold text-slate-800 text-base flex items-center gap-2">
                <i data-lucide="receipt" class="w-4 h-4 text-brand-600"></i>
                <span>Emitir Factura / Cobro</span>
            </h3>
            <button type="button" onclick="closeModal('create-invoice-modal')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form onsubmit="submitCreateInvoiceAjax(event)" class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">N° Factura</label>
                    <input type="text" id="inv-number-input" placeholder="Auto si se omite" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Moneda</label>
                    <select id="inv-currency-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white font-mono">
                        <option value="USD" <?= $projectCurrency === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                        <option value="EUR" <?= $projectCurrency === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                        <option value="MXN" <?= $projectCurrency === 'MXN' ? 'selected' : '' ?>>MXN ($)</option>
                        <option value="PEN" <?= $projectCurrency === 'PEN' ? 'selected' : '' ?>>PEN (S/)</option>
                        <option value="COP" <?= $projectCurrency === 'COP' ? 'selected' : '' ?>>COP ($)</option>
                        <option value="CLP" <?= $projectCurrency === 'CLP' ? 'selected' : '' ?>>CLP ($)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Concepto o Hito de Cobro *</label>
                <input type="text" id="inv-concept-input" required placeholder="Ej: Anticipo inicial 50% o Entrega Fase 1" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-brand-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Importe *</label>
                    <input type="number" step="0.01" min="0.01" id="inv-amount-input" required placeholder="0.00" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white font-semibold">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Fecha Vencimiento</label>
                    <input type="date" id="inv-due-date-input" class="w-full px-3 py-1.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Estado</label>
                    <select id="inv-status-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                        <option value="pendiente" selected>Por cobrar (Pendiente)</option>
                        <option value="pagada">Pagada / Cobrada</option>
                        <option value="anulada">Anulada</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Notas / Referencia</label>
                    <input type="text" id="inv-notes-input" placeholder="Transferencia, cheque, etc." class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('create-invoice-modal')" class="px-4 py-2 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100">Cancelar</button>
                <button type="submit" id="btn-submit-invoice" class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-sm flex items-center gap-1.5">
                    <i data-lucide="check" class="w-4 h-4"></i>
                    <span>Guardar Factura</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Presupuesto / Costo Total del Proyecto -->
<div id="edit-budget-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
    <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-display font-bold text-slate-800 text-base flex items-center gap-2">
                <i data-lucide="coins" class="w-4 h-4 text-brand-600"></i>
                <span>Definir Costo Total del Proyecto</span>
            </h3>
            <button type="button" onclick="closeModal('edit-budget-modal')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form onsubmit="submitUpdateBudgetAjax(event)" class="p-6 space-y-4">
            <p class="text-xs text-slate-500">
                Indica el costo o presupuesto total acordado con el cliente para este proyecto.
            </p>
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Costo Total *</label>
                    <input type="number" step="0.01" min="0" id="budget-amount-input" required value="<?= htmlspecialchars((string)$projectBudget) ?>" placeholder="0.00" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-brand-500 font-bold">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Moneda</label>
                    <select id="budget-currency-input" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white font-mono">
                        <option value="USD" <?= $projectCurrency === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                        <option value="EUR" <?= $projectCurrency === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                        <option value="MXN" <?= $projectCurrency === 'MXN' ? 'selected' : '' ?>>MXN ($)</option>
                        <option value="PEN" <?= $projectCurrency === 'PEN' ? 'selected' : '' ?>>PEN (S/)</option>
                        <option value="COP" <?= $projectCurrency === 'COP' ? 'selected' : '' ?>>COP ($)</option>
                        <option value="CLP" <?= $projectCurrency === 'CLP' ? 'selected' : '' ?>>CLP ($)</option>
                    </select>
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('edit-budget-modal')" class="px-4 py-2 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100">Cancelar</button>
                <button type="submit" id="btn-submit-budget" class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-sm">Guardar Costo</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== CAPA JAVASCRIPT AJAX REACTIVA ==================== -->
<script>
    const PROJECT_ID = <?= $projectId ?>;
    const CSRF_TOKEN = '<?= csrf_token() ?>';
    let pastedImageBlob = null;
    let modalPastedImageBlob = null;
    let currentTaskDetailId = null;

    // Toast flotante
    function showToast(message, type = 'success') {
        const existing = document.getElementById('ajax-toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.id = 'ajax-toast';
        const bg = type === 'success' ? 'bg-emerald-600 shadow-emerald-500/20' : 'bg-rose-600 shadow-rose-500/20';
        const icon = type === 'success' ? 'check-circle-2' : 'alert-circle';
        
        toast.className = `fixed top-5 right-5 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-white ${bg} transition-all duration-300 transform translate-y-0`;
        toast.innerHTML = `
            <i data-lucide="${icon}" class="w-4 h-4 flex-shrink-0"></i>
            <span class="text-xs sm:text-sm font-medium">${message}</span>
        `;
        document.body.appendChild(toast);
        lucide.createIcons();

        setTimeout(() => {
            toast.classList.add('opacity-0', '-translate-y-2');
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }

    // Toggle Kanban vs Lista
    function setTaskView(view) {
        const kanban = document.getElementById('tasks-kanban-view');
        const list = document.getElementById('tasks-list-view');
        const btnKanban = document.getElementById('view-btn-kanban');
        const btnList = document.getElementById('view-btn-list');

        if (view === 'list') {
            kanban.classList.add('hidden');
            list.classList.remove('hidden');
            btnList.className = "px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 bg-brand-50 text-brand-700 transition";
            btnKanban.className = "px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 text-slate-600 hover:text-slate-900 transition";
        } else {
            list.classList.add('hidden');
            kanban.classList.remove('hidden');
            btnKanban.className = "px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 bg-brand-50 text-brand-700 transition";
            btnList.className = "px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 text-slate-600 hover:text-slate-900 transition";
        }
        lucide.createIcons();
    }

    // Tabs Principales
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));

        const target = document.getElementById('tab-' + tabId);
        const btn = document.getElementById('tab-btn-' + tabId);
        if (target && btn) {
            target.classList.remove('hidden');
            btn.classList.add('active');
            window.location.hash = tabId;
        }
    }

    // Tabs Internos del Modal de Detalle de Tarea (Comentarios, Checklists, Adjuntos)
    function switchTaskDetailTab(subTabId) {
        document.querySelectorAll('.task-detail-sub-section').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.task-detail-tab-btn').forEach(btn => {
            btn.className = "task-detail-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5 transition";
        });

        const target = document.getElementById('task-detail-sec-' + subTabId);
        const btn = document.getElementById('task-tab-btn-' + subTabId);
        if (target && btn) {
            target.classList.remove('hidden');
            btn.className = "task-detail-tab-btn pb-2.5 font-bold text-brand-600 border-b-2 border-brand-600 flex items-center gap-1.5 transition";
        }
        lucide.createIcons();
    }

    window.addEventListener('DOMContentLoaded', () => {
        let hash = window.location.hash.replace('#', '');
        if (hash === 'links' || hash === 'files') hash = 'adjuntos';
        if (hash && document.getElementById('tab-' + hash)) {
            switchTab(hash);
        }
    });

    let currentTaskData = null;
    let createTaskCommentBlob = null;

    // Toggle modo edición en el modal de ver tarea
    function toggleTaskEditMode(forceState = null) {
        const editForm = document.getElementById('task-edit-form');
        const infoDisplay = document.getElementById('task-info-display');
        const btnText = document.getElementById('btn-toggle-edit-task-text');

        const shouldEdit = forceState !== null ? forceState : (editForm ? editForm.classList.contains('hidden') : false);
        if (shouldEdit && editForm) {
            if (infoDisplay) infoDisplay.classList.add('hidden');
            editForm.classList.remove('hidden');
            if (btnText) btnText.innerText = 'Ver Detalle';
            if (currentTaskData) {
                document.getElementById('edit-task-title-input').value = currentTaskData.raw_title || currentTaskData.title || '';
                document.getElementById('edit-task-desc-input').value = currentTaskData.raw_description || currentTaskData.description || '';
                document.getElementById('edit-task-prio-input').value = currentTaskData.priority || 'media';
                document.getElementById('edit-task-status-input').value = currentTaskData.status || 'pendiente';
                document.getElementById('edit-task-date-input').value = currentTaskData.raw_due_date || '';
                document.getElementById('edit-task-assigned-input').value = currentTaskData.raw_assigned_to || '';
            }
        } else {
            if (editForm) editForm.classList.add('hidden');
            if (infoDisplay) infoDisplay.classList.remove('hidden');
            if (btnText) btnText.innerText = 'Editar Tarea';
        }
        lucide.createIcons();
    }

    // Pestañas del Modal Crear Tarea
    function switchCreateTaskTab(tabKey) {
        document.querySelectorAll('.create-task-section').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.create-task-tab-btn').forEach(btn => {
            btn.className = "create-task-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5";
        });

        const target = document.getElementById('create-task-sec-' + tabKey);
        const btn = document.getElementById('create-task-tab-btn-' + tabKey);
        if (target && btn) {
            target.classList.remove('hidden');
            btn.className = "create-task-tab-btn pb-2.5 font-bold text-brand-600 border-b-2 border-brand-600 flex items-center gap-1.5";
        }
        lucide.createIcons();
    }

    // Imagen en Comentario inicial al crear tarea (Ctrl+V)
    const createTaskCommentText = document.getElementById('create-task-comment-text');
    if (createTaskCommentText) {
        createTaskCommentText.addEventListener('paste', (e) => {
            const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
            if (!items) return;
            for (let item of items) {
                if (item.type.indexOf('image') === 0) {
                    const blob = item.getAsFile();
                    if (blob) {
                        e.preventDefault();
                        setCreateTaskCommentImagePreview(blob);
                        showToast('¡Captura pegada en el comentario inicial!', 'success');
                        break;
                    }
                }
            }
        });
    }

    function handleCreateTaskCommentImageSelect(input) {
        if (input.files && input.files[0]) {
            setCreateTaskCommentImagePreview(input.files[0]);
        }
    }

    function setCreateTaskCommentImagePreview(fileOrBlob) {
        createTaskCommentBlob = fileOrBlob;
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('create-task-comment-preview-img').src = e.target.result;
            document.getElementById('create-task-comment-preview-box').classList.remove('hidden');
            document.getElementById('create-task-comments-indicator').classList.remove('hidden');
        };
        reader.readAsDataURL(fileOrBlob);
    }

    function clearCreateTaskCommentImage() {
        createTaskCommentBlob = null;
        document.getElementById('create-task-comment-preview-img').src = '';
        document.getElementById('create-task-comment-preview-box').classList.add('hidden');
        document.getElementById('create-task-comment-image-file').value = '';
        if (!document.getElementById('create-task-comment-text').value.trim()) {
            document.getElementById('create-task-comments-indicator').classList.add('hidden');
        }
    }

    // Indicadores visuales en pestañas de crear tarea al escribir
    document.getElementById('create-task-comment-text')?.addEventListener('input', (e) => {
        const ind = document.getElementById('create-task-comments-indicator');
        if (e.target.value.trim() || createTaskCommentBlob) {
            ind.classList.remove('hidden');
        } else {
            ind.classList.add('hidden');
        }
    });

    document.getElementById('create-task-chk-items')?.addEventListener('input', (e) => {
        const badge = document.getElementById('create-task-checklist-badge');
        const lines = e.target.value.split('\n').map(l => l.trim()).filter(l => l.length > 0);
        if (lines.length > 0) {
            badge.innerText = lines.length;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    });

    document.getElementById('create-task-file-input')?.addEventListener('change', (e) => {
        const ind = document.getElementById('create-task-adjuntos-indicator');
        if (e.target.files.length > 0 || document.getElementById('create-task-link-url').value.trim()) {
            ind.classList.remove('hidden');
        } else {
            ind.classList.add('hidden');
        }
    });

    document.getElementById('create-task-link-url')?.addEventListener('input', (e) => {
        const ind = document.getElementById('create-task-adjuntos-indicator');
        if (e.target.value.trim() || document.getElementById('create-task-file-input').files.length > 0) {
            ind.classList.remove('hidden');
        } else {
            ind.classList.add('hidden');
        }
    });
    async function openTaskDetailModal(taskId) {
        currentTaskDetailId = taskId;
        toggleTaskEditMode(false); // asegurar modo vista inicialmente
        const modalTaskId = document.getElementById('modal-task-id-input');
        if (modalTaskId) modalTaskId.value = taskId;
        
        // Mostrar skeleton de overview y ocultar contenido real mientras carga
        const ovSkeleton = document.getElementById('task-overview-skeleton');
        const ovView = document.getElementById('task-overview-view');
        if (ovSkeleton) ovSkeleton.classList.remove('hidden');
        if (ovView) ovView.classList.add('hidden');

        // Skeletons de comentarios, checklists y adjuntos con animación pulse
        const commentsFeed = document.getElementById('modal-task-comments-feed');
        if (commentsFeed) {
            commentsFeed.innerHTML = `
                <div class="space-y-3 animate-pulse">
                    <div class="flex items-start gap-3 p-3 bg-slate-50/70 rounded-2xl border border-slate-100">
                        <div class="w-7 h-7 rounded-full bg-slate-200 flex-shrink-0"></div>
                        <div class="flex-1 space-y-2">
                            <div class="h-3 bg-slate-200 rounded w-24"></div>
                            <div class="h-3.5 bg-slate-200 rounded w-full"></div>
                            <div class="h-3 bg-slate-200 rounded w-4/5"></div>
                        </div>
                    </div>
                </div>
            `;
        }

        const chkContainer = document.getElementById('modal-task-checklists-container');
        if (chkContainer) {
            chkContainer.innerHTML = `
                <div class="p-3.5 bg-slate-50/70 rounded-2xl border border-slate-100 space-y-3 animate-pulse">
                    <div class="flex justify-between items-center">
                        <div class="h-3.5 bg-slate-200 rounded w-32"></div>
                        <div class="h-3 bg-slate-200 rounded w-12"></div>
                    </div>
                </div>
            `;
        }

        const filesList = document.getElementById('modal-task-files-list');
        if (filesList) {
            filesList.innerHTML = `
                <div class="space-y-2 animate-pulse">
                    <div class="h-10 bg-slate-100 rounded-xl w-full"></div>
                </div>
            `;
        }
        const linksList = document.getElementById('modal-task-links-list');
        if (linksList) {
            linksList.innerHTML = `
                <div class="space-y-2 animate-pulse">
                    <div class="h-9 bg-slate-100 rounded-xl w-full"></div>
                </div>
            `;
        }

        // Pestaña de actividad por defecto: comentarios
        if (document.getElementById('task-tab-btn-comments')) {
            switchTaskDetailTab('comments');
        }
        openModal('task-detail-modal');

        const formData = new FormData();
        formData.append('action', 'get_detail');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('task_id', taskId);

        try {
            const res = await fetch('api/tasks.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                const t = data.task;
                currentTaskData = t;

                document.getElementById('modal-task-title').innerText = t.title;
                document.getElementById('modal-task-description').innerText = t.description || 'Sin descripción detallada.';
                document.getElementById('modal-task-assigned').innerText = t.assigned_to || 'Sin asignar';
                document.getElementById('modal-task-duedate').innerText = t.due_date || 'Sin fecha';
                document.getElementById('modal-task-status-text').innerText = (t.status || 'pendiente').replace('_', ' ');

                const prioBadge = document.getElementById('modal-task-priority');
                if (prioBadge) {
                    prioBadge.innerText = t.priority;
                    prioBadge.className = "text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md border " + (
                        t.priority === 'urgente' ? 'bg-rose-50 text-rose-700 border-rose-200/60' :
                        (t.priority === 'alta' ? 'bg-amber-50 text-amber-700 border-amber-200/60' : 'bg-indigo-50 text-indigo-700 border-indigo-200/60')
                    );
                }

                const statusBadge = document.getElementById('modal-task-status-badge');
                if (statusBadge) {
                    statusBadge.innerText = (t.status || 'pendiente').replace('_', ' ');
                    statusBadge.className = "text-xs font-semibold px-2 py-0.5 rounded-full capitalize " + (
                        t.status === 'completada' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700'
                    );
                }

                // Ocultar skeleton y revelar vista con contenido
                if (ovSkeleton) ovSkeleton.classList.add('hidden');
                if (ovView) ovView.classList.remove('hidden');

                // Control de visibilidad del botón de Editar Tarea según permisos
                const btnEdit = document.getElementById('btn-toggle-edit-task');
                if (btnEdit) {
                    if (data.permissions && !data.permissions.can_edit) {
                        btnEdit.classList.add('hidden');
                    } else {
                        btnEdit.classList.remove('hidden');
                    }
                }

                // Renderizar Comentarios
                if (document.getElementById('modal-task-comments-feed')) {
                    renderTaskModalComments(data.comments);
                    const comBadge = document.getElementById('modal-task-comments-badge-count');
                    if (comBadge) comBadge.innerText = data.comments ? data.comments.length : 0;
                }

                // Renderizar Checklists de la Tarea
                if (document.getElementById('modal-task-checklists-container')) {
                    renderTaskModalChecklists(data.checklists, data.permissions);
                    const chkBadge = document.getElementById('modal-task-chk-count');
                    if (chkBadge) chkBadge.innerText = data.checklists ? data.checklists.length : 0;
                }

                // Renderizar Adjuntos de la Tarea
                if (document.getElementById('modal-task-files-list')) {
                    renderTaskModalAdjuntos(data.files, data.links, data.permissions);
                    const totalAdj = (data.files ? data.files.length : 0) + (data.links ? data.links.length : 0);
                    const adjBadge = document.getElementById('modal-task-adj-count');
                    if (adjBadge) adjBadge.innerText = totalAdj;
                }

                lucide.createIcons();
            } else {
                showToast(data.message || 'Error al cargar tarea', 'error');
            }
        } catch (err) {
            showToast('Error al cargar detalle de tarea', 'error');
        }
    }

    async function submitTaskEditAjax(e) {
        e.preventDefault();
        if (!currentTaskDetailId) return;

        const btn = document.getElementById('btn-save-task-edit');
        btn.disabled = true;
        btn.innerText = 'Guardando...';

        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('task_id', currentTaskDetailId);
        formData.append('title', document.getElementById('edit-task-title-input').value.trim());
        formData.append('description', document.getElementById('edit-task-desc-input').value.trim());
        formData.append('priority', document.getElementById('edit-task-prio-input').value);
        formData.append('status', document.getElementById('edit-task-status-input').value);
        formData.append('due_date', document.getElementById('edit-task-date-input').value);
        formData.append('assigned_user_id', document.getElementById('edit-task-assigned-input').value);

        try {
            const res = await fetch('api/tasks.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                showToast('Tarea actualizada correctamente');
                // Refrescar modal y vista
                setTimeout(() => location.reload(), 400);
            } else {
                showToast(data.message || 'Error al actualizar', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Guardar Cambios';
        }
    }

    function renderTaskModalComments(comments) {
        const feed = document.getElementById('modal-task-comments-feed');
        if (!comments || comments.length === 0) {
            feed.innerHTML = '<p class="text-xs text-slate-400 italic">No hay comentarios en esta tarea aún.</p>';
            return;
        }

        // Separar padres y respuestas
        const parents = comments.filter(c => !c.parent_id);
        const replies = {};
        comments.forEach(c => {
            if (c.parent_id) {
                if (!replies[c.parent_id]) replies[c.parent_id] = [];
                replies[c.parent_id].push(c);
            }
        });

        feed.innerHTML = parents.map(c => {
            const commReplies = replies[c.id] || [];
            return `
                <div id="modal-comment-card-${c.id}" class="bg-white p-3.5 rounded-2xl border border-slate-200/70 shadow-2xs space-y-2.5 group">
                    <div class="flex items-start gap-3">
                        <div class="w-7 h-7 rounded-full bg-brand-50 text-brand-700 font-bold text-[11px] flex items-center justify-center flex-shrink-0">
                            ${c.initials}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-0.5">
                                <h4 class="text-xs font-bold text-slate-900">${c.author_name}</h4>
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] text-slate-400">${c.created_at}</span>
                                    ${c.can_manage ? `
                                        <div class="opacity-0 group-hover:opacity-100 transition flex items-center gap-1">
                                            <button onclick="openEditCommentModal(${c.id}, ${jsAttrArg(c.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                                <i data-lucide="edit-2" class="w-3 h-3"></i>
                                            </button>
                                            <button onclick="deleteCommentAjax(${c.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                                <i data-lucide="trash-2" class="w-3 h-3"></i>
                                            </button>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                            ${c.content ? `<p id="modal-comment-text-${c.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${c.content}</p>` : ''}
                            ${c.image_url ? `<div class="mt-2"><img src="${c.image_url}" class="max-h-56 rounded-xl border border-slate-200 cursor-pointer" onclick="openImageModal(this.src)"></div>` : ''}
                            
                            <!-- Botón Responder dentro de Tarea -->
                            <div class="mt-2 flex items-center gap-3">
                                <button onclick="toggleModalReplyBox(${c.id})" class="text-[10px] font-semibold text-brand-600 hover:text-brand-700 inline-flex items-center gap-1 transition">
                                    <i data-lucide="reply" class="w-3 h-3"></i>
                                    <span>Responder</span>
                                </button>
                                ${commReplies.length > 0 ? `<span class="text-[10px] text-slate-400 font-medium">${commReplies.length} ${commReplies.length === 1 ? 'respuesta' : 'respuestas'}</span>` : ''}
                            </div>
                        </div>
                    </div>

                    <!-- Respuestas anidadas en Modal -->
                    <div id="modal-replies-container-${c.id}" class="space-y-2 pl-6 sm:pl-8 border-l-2 border-slate-100 ${commReplies.length === 0 ? 'hidden' : ''}">
                        ${commReplies.map(rep => `
                            <div id="modal-comment-card-${rep.id}" class="bg-slate-50/70 p-2.5 rounded-xl border border-slate-200/50 flex items-start gap-2.5 group/mreply">
                                <div class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 font-bold text-[9px] flex items-center justify-center flex-shrink-0">
                                    ${rep.initials}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between mb-0.5">
                                        <h5 class="text-[11px] font-bold text-slate-800">${rep.author_name}</h5>
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-[9px] text-slate-400">${rep.created_at}</span>
                                            ${rep.can_manage ? `
                                                <div class="opacity-0 group-hover/mreply:opacity-100 transition flex items-center gap-0.5">
                                                    <button onclick="openEditCommentModal(${rep.id}, ${jsAttrArg(rep.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                                        <i data-lucide="edit-2" class="w-2.5 h-2.5"></i>
                                                    </button>
                                                    <button onclick="deleteCommentAjax(${rep.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                                        <i data-lucide="trash-2" class="w-2.5 h-2.5"></i>
                                                    </button>
                                                </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                    ${rep.content ? `<p id="modal-comment-text-${rep.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${rep.content}</p>` : ''}
                                    ${rep.image_url ? `<div class="mt-2"><img src="${rep.image_url}" class="max-h-44 rounded-lg border border-slate-200 cursor-pointer" onclick="openImageModal(this.src)"></div>` : ''}
                                </div>
                            </div>
                        `).join('')}
                    </div>

                    <!-- Caja para responder en modal -->
                    <div id="modal-reply-box-${c.id}" class="hidden pl-6 sm:pl-8 pt-1 border-l-2 border-slate-100">
                        <form onsubmit="submitModalReplyAjax(event, ${c.id})" class="space-y-1.5">
                            <textarea id="modal-reply-content-${c.id}" rows="2" placeholder="Escribe tu respuesta a ${c.author_name}..." required class="w-full p-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-brand-500 bg-slate-50/50 leading-relaxed"></textarea>
                            <div class="flex items-center justify-end gap-1.5">
                                <button type="button" onclick="toggleModalReplyBox(${c.id})" class="px-2.5 py-1 rounded-lg text-xs text-slate-500 hover:bg-slate-100">Cancelar</button>
                                <button type="submit" id="btn-modal-reply-${c.id}" class="inline-flex items-center gap-1 px-3 py-1 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
                                    <i data-lucide="send" class="w-3 h-3"></i>
                                    <span>Responder</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
        }).join('');
        lucide.createIcons();
    }

    function renderTaskModalChecklists(checklists, permissions) {
        const canEdit = !permissions || permissions.can_edit !== false;
        const container = document.getElementById('modal-task-checklists-container');
        if (!checklists || checklists.length === 0) {
            container.innerHTML = '<p class="text-xs text-slate-400 italic">Esta tarea no tiene checklists aún.' + (canEdit ? ' Crea uno arriba.' : '') + '</p>';
            return;
        }

        container.innerHTML = checklists.map(chk => {
            const itemsHtml = chk.items.map(it => `
                <div class="flex items-center justify-between p-2 rounded-xl bg-slate-50/70 hover:bg-slate-100/70 border border-slate-100 transition">
                    <label class="flex items-center gap-2 ${canEdit ? 'cursor-pointer' : 'cursor-not-allowed'} flex-1">
                        <input type="checkbox" ${canEdit ? `onchange="toggleChecklistItemAjax(${it.id}, ${chk.id}, this)"` : 'disabled'} ${it.is_completed ? 'checked' : ''} class="w-4 h-4 rounded text-brand-600 border-slate-300">
                        <span class="text-xs text-slate-700 ${it.is_completed ? 'line-through text-slate-400' : ''}">${it.title}</span>
                    </label>
                    ${canEdit ? `<button onclick="deleteChecklistItem(${it.id}, ${chk.id})" class="text-slate-400 hover:text-rose-600 p-1">✕</button>` : ''}
                </div>
            `).join('');

            return `
                <div class="p-3 bg-white rounded-2xl border border-slate-200/80 space-y-2">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-xs text-slate-800">${chk.title}</h4>
                        ${canEdit ? `<button onclick="deleteChecklist(${chk.id})" class="text-slate-400 hover:text-rose-600 p-1 text-xs">Eliminar</button>` : ''}
                    </div>
                    <div class="space-y-1.5">${itemsHtml}</div>
                    ${canEdit ? `
                        <form onsubmit="addChecklistItemAjax(event, ${chk.id})" class="flex items-center gap-2 pt-1 border-t border-slate-100">
                            <input type="text" id="new-item-input-${chk.id}" required placeholder="+ Añadir paso..." class="flex-1 px-2.5 py-1 rounded-lg border border-slate-200 text-xs">
                            <button type="submit" class="px-2.5 py-1 bg-slate-900 text-white rounded-lg text-xs font-semibold">Añadir</button>
                        </form>
                    ` : ''}
                </div>
            `;
        }).join('');
    }

    function toggleTaskAttachmentForm(type) {
        const fileForm = document.getElementById('form-task-add-file');
        const linkForm = document.getElementById('form-task-add-link');
        const btnFile = document.getElementById('btn-toggle-task-file');
        const btnLink = document.getElementById('btn-toggle-task-link');

        if (type === 'file') {
            const willShow = fileForm.classList.contains('hidden');
            fileForm.classList.toggle('hidden');
            linkForm.classList.add('hidden');
            btnFile.classList.toggle('bg-brand-50', willShow);
            btnFile.classList.toggle('text-brand-600', willShow);
            btnLink.classList.remove('bg-brand-50', 'text-brand-600');
            if (willShow) document.getElementById('task-file-input').focus();
        } else if (type === 'link') {
            const willShow = linkForm.classList.contains('hidden');
            linkForm.classList.toggle('hidden');
            fileForm.classList.add('hidden');
            btnLink.classList.toggle('bg-brand-50', willShow);
            btnLink.classList.toggle('text-brand-600', willShow);
            btnFile.classList.remove('bg-brand-50', 'text-brand-600');
            if (willShow) document.getElementById('task-link-title').focus();
        }
        lucide.createIcons();
    }

    function renderTaskModalAdjuntos(files, links, permissions) {
        const canEdit = !permissions || permissions.can_edit !== false;
        const addBtnFile = document.getElementById('btn-toggle-task-file');
        const addBtnLink = document.getElementById('btn-toggle-task-link');
        if (addBtnFile) addBtnFile.style.display = canEdit ? '' : 'none';
        if (addBtnLink) addBtnLink.style.display = canEdit ? '' : 'none';

        const filesList = document.getElementById('modal-task-files-list');
        const linksList = document.getElementById('modal-task-links-list');

        // Cerrar formularios abiertos por defecto al recargar o cambiar de tarea
        document.getElementById('form-task-add-file').classList.add('hidden');
        document.getElementById('form-task-add-link').classList.add('hidden');
        document.getElementById('btn-toggle-task-file').classList.remove('bg-brand-50', 'text-brand-600');
        document.getElementById('btn-toggle-task-link').classList.remove('bg-brand-50', 'text-brand-600');

        if (!files || files.length === 0) {
            filesList.innerHTML = '<div class="py-2 px-3 rounded-lg border border-dashed border-slate-200 text-center text-[11px] text-slate-400">Sin archivos en esta tarea</div>';
        } else {
            filesList.innerHTML = files.map(f => `
                <div class="group flex items-center justify-between p-2 rounded-xl bg-slate-50/70 hover:bg-slate-100/70 border border-slate-200/60 transition">
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="w-7 h-7 rounded-lg bg-white flex items-center justify-center border border-slate-200/80 text-brand-600 flex-shrink-0">
                            <i data-lucide="file" class="w-3.5 h-3.5"></i>
                        </div>
                        <div class="min-w-0">
                            <span class="text-xs font-medium text-slate-800 truncate block">${f.original_name}</span>
                            <span class="text-[10px] text-slate-400 block">${f.file_size}</span>
                        </div>
                    </div>
                    <a href="${f.download_url}" class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-white rounded-lg transition" title="Descargar">
                        <i data-lucide="download" class="w-3.5 h-3.5"></i>
                    </a>
                </div>
            `).join('');
        }

        if (!links || links.length === 0) {
            linksList.innerHTML = '<div class="py-2 px-3 rounded-lg border border-dashed border-slate-200 text-center text-[11px] text-slate-400">Sin enlaces en esta tarea</div>';
        } else {
            linksList.innerHTML = links.map(l => `
                <div class="group flex items-center justify-between p-2 rounded-xl bg-slate-50/70 hover:bg-slate-100/70 border border-slate-200/60 transition">
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="w-7 h-7 rounded-lg bg-white flex items-center justify-center border border-slate-200/80 text-emerald-600 flex-shrink-0">
                            <i data-lucide="link" class="w-3.5 h-3.5"></i>
                        </div>
                        <div class="min-w-0">
                            <span class="text-xs font-medium text-slate-800 truncate block">${l.title}</span>
                            <span class="text-[10px] text-slate-400 truncate block">${l.url}</span>
                        </div>
                    </div>
                    <a href="${l.url}" target="_blank" rel="noopener noreferrer" class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-white rounded-lg transition" title="Abrir enlace">
                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                    </a>
                </div>
            `).join('');
        }
    }

    // Acciones de Checklists y Adjuntos directos para la Tarea
    async function submitTaskChecklistAjax(e) {
        e.preventDefault();
        const input = document.getElementById('task-new-chk-title');
        const title = input.value.trim();
        if (!title || !currentTaskDetailId) return;

        const formData = new FormData();
        formData.append('action', 'create_checklist');
        formData.append('project_id', PROJECT_ID);
        formData.append('task_id', currentTaskDetailId);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('title', title);

        try {
            const res = await fetch('api/checklists.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                showToast('Lista creada para esta tarea');
                openTaskDetailModal(currentTaskDetailId);
                setTimeout(() => switchTaskDetailTab('checklists'), 200);
            }
        } catch (err) {
            showToast('Error al crear checklist', 'error');
        }
    }

    async function submitTaskFileAjax(e) {
        e.preventDefault();
        const fileInput = document.getElementById('task-file-input');
        if (!fileInput.files.length || !currentTaskDetailId) return;

        const btn = document.getElementById('task-file-btn');
        btn.disabled = true;
        btn.innerText = 'Subiendo...';

        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('project_id', PROJECT_ID);
        formData.append('task_id', currentTaskDetailId);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('attachment', fileInput.files[0]);

        try {
            const res = await fetch('api/files.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                fileInput.value = '';
                showToast('Archivo vinculado a la tarea');
                openTaskDetailModal(currentTaskDetailId);
                setTimeout(() => switchTaskDetailTab('adjuntos'), 200);
            }
        } catch (err) {
            showToast('Error al subir archivo', 'error');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Subir a Tarea';
        }
    }

    async function submitTaskLinkAjax(e) {
        e.preventDefault();
        const title = document.getElementById('task-link-title').value.trim();
        const url = document.getElementById('task-link-url').value.trim();
        if (!title || !url || !currentTaskDetailId) return;

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('project_id', PROJECT_ID);
        formData.append('task_id', currentTaskDetailId);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('title', title);
        formData.append('url', url);

        try {
            const res = await fetch('api/links.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                document.getElementById('task-link-title').value = '';
                document.getElementById('task-link-url').value = '';
                showToast('Enlace vinculado a la tarea');
                openTaskDetailModal(currentTaskDetailId);
                setTimeout(() => switchTaskDetailTab('adjuntos'), 200);
            }
        } catch (err) {
            showToast('Error al guardar enlace', 'error');
        }
    }

    // Pegar imagen dentro del modal de tarea (Ctrl + V)
    const modalTaskCommentInput = document.getElementById('modal-task-comment-input');
    if (modalTaskCommentInput) {
        modalTaskCommentInput.addEventListener('paste', (e) => {
            const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
            if (!items) return;
            for (let item of items) {
                if (item.type.indexOf('image') === 0) {
                    const blob = item.getAsFile();
                    if (blob) {
                        e.preventDefault();
                        setModalCommentImagePreview(blob);
                        showToast('¡Captura pegada en la tarea!', 'success');
                        break;
                    }
                }
            }
        });
    }

    function handleModalCommentImageSelect(input) {
        if (input.files && input.files[0]) {
            setModalCommentImagePreview(input.files[0]);
        }
    }

    function setModalCommentImagePreview(fileOrBlob) {
        modalPastedImageBlob = fileOrBlob;
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('modal-comment-preview-img').src = e.target.result;
            document.getElementById('modal-comment-image-preview').classList.remove('hidden');
        };
        reader.readAsDataURL(fileOrBlob);
    }

    function clearModalCommentImage() {
        modalPastedImageBlob = null;
        document.getElementById('modal-comment-preview-img').src = '';
        document.getElementById('modal-comment-image-preview').classList.add('hidden');
        document.getElementById('modal-comment-image-file').value = '';
    }

    async function submitTaskCommentAjax(e) {
        e.preventDefault();
        const taskId = document.getElementById('modal-task-id-input').value;
        const content = document.getElementById('modal-task-comment-input').value.trim();
        if (!content && !modalPastedImageBlob) {
            showToast('Escribe un comentario o pega una imagen', 'error');
            return;
        }

        const btn = document.getElementById('modal-submit-comment-btn');
        btn.disabled = true;
        btn.innerText = 'Enviando...';

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('project_id', PROJECT_ID);
        formData.append('task_id', taskId);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('content', content);

        if (modalPastedImageBlob) {
            formData.append('image', modalPastedImageBlob, 'task_comment.png');
        }

        try {
            const res = await fetch('api/comments.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                const c = data.comment;
                
                const feed = document.getElementById('modal-task-comments-feed');
                const emptyMsg = feed.querySelector('p');
                if (emptyMsg) emptyMsg.remove();

                const div = document.createElement('div');
                div.id = `modal-comment-card-${c.id}`;
                div.className = "bg-white p-3 rounded-2xl border border-slate-200/70 shadow-2xs flex items-start gap-3 group";
                div.innerHTML = `
                    <div class="w-7 h-7 rounded-full bg-brand-50 text-brand-700 font-bold text-[11px] flex items-center justify-center flex-shrink-0">
                        ${c.initials}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between mb-0.5">
                            <h4 class="text-xs font-bold text-slate-900">${c.author_name}</h4>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] text-slate-400">${c.created_at}</span>
                                <div class="opacity-0 group-hover:opacity-100 transition flex items-center gap-1">
                                    <button onclick="openEditCommentModal(${c.id}, ${jsAttrArg(c.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                        <i data-lucide="edit-2" class="w-3 h-3"></i>
                                    </button>
                                    <button onclick="deleteCommentAjax(${c.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                        <i data-lucide="trash-2" class="w-3 h-3"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        ${c.content ? `<p id="modal-comment-text-${c.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${c.content}</p>` : ''}
                        ${c.image_url ? `<div class="mt-2"><img src="${c.image_url}" class="max-h-56 rounded-xl border border-slate-200 cursor-pointer" onclick="openImageModal(this.src)"></div>` : ''}
                    </div>
                `;
                feed.appendChild(div);

                document.querySelectorAll(`.task-comments-count-${taskId}`).forEach(span => {
                    span.innerText = parseInt(span.innerText || '0') + 1;
                });
                const bTaskBadge = document.getElementById('modal-task-comments-badge-count');
                if (bTaskBadge) bTaskBadge.innerText = parseInt(bTaskBadge.innerText || '0') + 1;

                const generalFeed = document.getElementById('comments-feed');
                if (generalFeed) {
                    const genCard = document.createElement('div');
                    genCard.id = `comment-card-${c.id}`;
                    genCard.className = "bg-white rounded-2xl border border-slate-200/70 p-4 shadow-2xs flex items-start gap-3.5 group";
                    genCard.innerHTML = `
                        <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                            ${c.initials}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center justify-between gap-1 mb-1">
                                <div class="flex items-center gap-2">
                                    <h4 class="text-xs font-bold text-slate-900">${c.author_name}</h4>
                                    <button onclick="openTaskDetailModal(${taskId})" class="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 bg-brand-50 px-2 py-0.5 rounded-md hover:bg-brand-100 transition">
                                        <i data-lucide="check-square" class="w-3 h-3"></i>
                                        <span class="truncate max-w-[200px]">Tarea: ${c.task_title || 'Tarea'}</span>
                                    </button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[10px] text-slate-400">${c.created_at}</span>
                                    <div class="opacity-0 group-hover:opacity-100 transition flex items-center gap-1">
                                        <button onclick="openEditCommentModal(${c.id}, ${jsAttrArg(c.raw_content || '')})" class="p-1 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                            <i data-lucide="edit-2" class="w-3 h-3"></i>
                                        </button>
                                        <button onclick="deleteCommentAjax(${c.id})" class="p-1 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                            <i data-lucide="trash-2" class="w-3 h-3"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            ${c.content ? `<p id="comment-text-${c.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${c.content}</p>` : ''}
                            ${c.image_url ? `<div class="mt-2.5"><img src="${c.image_url}" class="max-h-72 rounded-xl border border-slate-200 shadow-2xs hover:opacity-95 cursor-pointer transition" onclick="openImageModal(this.src)"></div>` : ''}
                        </div>
                    `;
                    generalFeed.prepend(genCard);
                    document.getElementById('comments-empty-state').classList.add('hidden');
                    const bComms = document.getElementById('badge-comments-count');
                    bComms.innerText = parseInt(bComms.innerText || '0') + 1;
                }

                document.getElementById('modal-task-comment-input').value = '';
                clearModalCommentImage();
                showToast('Comentario añadido a la tarea');
                lucide.createIcons();
            }
        } catch (err) {
            showToast('Error al enviar comentario', 'error');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Enviar Comentario';
        }
    }

    async function submitTaskAjax(e) {
        e.preventDefault();
        const btn = document.getElementById('submit-task-btn');
        btn.disabled = true;
        btn.innerText = 'Creando Tarea...';

        const title = document.getElementById('task-title-input').value.trim();
        const description = document.getElementById('task-desc-input').value.trim();
        const priority = document.getElementById('task-prio-input').value;
        const dueDate = document.getElementById('task-date-input').value;
        const assignedTo = document.getElementById('task-assigned-input').value;

        // Comentario inicial
        const initialComment = document.getElementById('create-task-comment-text')?.value.trim() || '';

        // Checklist inicial
        const chkTitle = document.getElementById('create-task-chk-title')?.value.trim() || '';
        const chkItemsRaw = document.getElementById('create-task-chk-items')?.value || '';
        const chkItems = chkItemsRaw.split('\n').map(l => l.trim()).filter(l => l.length > 0);

        // Adjuntos iniciales
        const fileInput = document.getElementById('create-task-file-input');
        const hasFile = fileInput && fileInput.files.length > 0;
        const linkTitle = document.getElementById('create-task-link-title')?.value.trim() || '';
        const linkUrl = document.getElementById('create-task-link-url')?.value.trim() || '';

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('title', title);
        formData.append('description', description);
        formData.append('priority', priority);
        formData.append('due_date', dueDate);
        formData.append('assigned_user_id', assignedTo);

        try {
            // 1. Crear Tarea
            const res = await fetch('api/tasks.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (!data.success) {
                showToast(data.message || 'Error al crear la tarea', 'error');
                return;
            }

            const newTaskId = data.task.id;

            // 2. Si hay comentario inicial o imagen pegada, enviarlo
            if (initialComment || createTaskCommentBlob) {
                const commentData = new FormData();
                commentData.append('action', 'create');
                commentData.append('project_id', PROJECT_ID);
                commentData.append('task_id', newTaskId);
                commentData.append('csrf_token', CSRF_TOKEN);
                commentData.append('content', initialComment);
                if (createTaskCommentBlob) {
                    commentData.append('image', createTaskCommentBlob, 'initial_comment.png');
                }
                await fetch('api/comments.php', { method: 'POST', body: commentData });
            }

            // 3. Si hay checklist inicial, crearlo y agregar sus items
            if (chkTitle || chkItems.length > 0) {
                const chkData = new FormData();
                chkData.append('action', 'create_checklist');
                chkData.append('project_id', PROJECT_ID);
                chkData.append('task_id', newTaskId);
                chkData.append('csrf_token', CSRF_TOKEN);
                chkData.append('title', chkTitle || 'Pasos iniciales');

                const chkRes = await fetch('api/checklists.php', { method: 'POST', body: chkData });
                const chkJson = await chkRes.json();
                if (chkJson.success && chkJson.checklist && chkItems.length > 0) {
                    for (const itemTitle of chkItems) {
                        const itemData = new FormData();
                        itemData.append('action', 'add_item');
                        itemData.append('project_id', PROJECT_ID);
                        itemData.append('csrf_token', CSRF_TOKEN);
                        itemData.append('checklist_id', chkJson.checklist.id);
                        itemData.append('title', itemTitle);
                        await fetch('api/checklists.php', { method: 'POST', body: itemData });
                    }
                }
            }

            // 4. Si hay archivo inicial, subirlo
            if (hasFile) {
                const fData = new FormData();
                fData.append('action', 'upload');
                fData.append('project_id', PROJECT_ID);
                fData.append('task_id', newTaskId);
                fData.append('csrf_token', CSRF_TOKEN);
                fData.append('attachment', fileInput.files[0]);
                await fetch('api/files.php', { method: 'POST', body: fData });
            }

            // 5. Si hay enlace inicial, guardarlo
            if (linkTitle && linkUrl) {
                const lData = new FormData();
                lData.append('action', 'create');
                lData.append('project_id', PROJECT_ID);
                lData.append('task_id', newTaskId);
                lData.append('csrf_token', CSRF_TOKEN);
                lData.append('title', linkTitle);
                lData.append('url', linkUrl);
                await fetch('api/links.php', { method: 'POST', body: lData });
            }

            showToast('Tarea creada con todos sus datos asociados');
            closeModal('create-task-modal');
            setTimeout(() => location.reload(), 450);

        } catch (err) {
            showToast('Error al procesar creación', 'error');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Guardar Tarea';
        }
    }

    async function updateTaskStatus(taskId, status) {
        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('task_id', taskId);
        formData.append('status', status);

        try {
            await fetch('api/tasks.php', { method: 'POST', body: formData });
            showToast('Estado de tarea actualizado');
            setTimeout(() => location.reload(), 400);
        } catch (err) {
            showToast('Error al actualizar estado', 'error');
        }
    }

    async function toggleTaskCompleteFromList(taskId, checkbox) {
        const newStatus = checkbox.checked ? 'completada' : 'pendiente';
        await updateTaskStatus(taskId, newStatus);
    }

    async function deleteTask(taskId) {
        if (!confirm('¿Eliminar esta tarea y sus comentarios?')) return;
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('task_id', taskId);

        try {
            const res = await fetch('api/tasks.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                showToast('Tarea eliminada');
                setTimeout(() => location.reload(), 300);
            }
        } catch (err) {
            showToast('Error al eliminar', 'error');
        }
    }

    // ----------------- CHECKLISTS (AJAX) -----------------
    async function submitChecklistAjax(e) {
        e.preventDefault();
        const input = document.getElementById('checklist-title-input');
        const title = input.value.trim();
        const taskId = document.getElementById('checklist-task-select').value;

        const formData = new FormData();
        formData.append('action', 'create_checklist');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('title', title);
        if (taskId) formData.append('task_id', taskId);

        try {
            const res = await fetch('api/checklists.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                closeModal('create-checklist-modal');
                location.reload();
            }
        } catch (err) {
            showToast('Error al crear checklist', 'error');
        }
    }

    async function addChecklistItemAjax(e, checklistId) {
        e.preventDefault();
        const input = document.getElementById(`new-item-input-${checklistId}`);
        const title = input.value.trim();

        const formData = new FormData();
        formData.append('action', 'add_item');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('checklist_id', checklistId);
        formData.append('title', title);

        try {
            const res = await fetch('api/checklists.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                if (currentTaskDetailId) {
                    openTaskDetailModal(currentTaskDetailId);
                    setTimeout(() => switchTaskDetailTab('checklists'), 200);
                } else {
                    location.reload();
                }
            }
        } catch (err) {
            showToast('Error al añadir paso', 'error');
        }
    }

    async function toggleChecklistItemAjax(itemId, checklistId, checkbox) {
        const isCompleted = checkbox.checked ? 1 : 0;
        const formData = new FormData();
        formData.append('action', 'toggle_item');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('item_id', itemId);
        formData.append('is_completed', isCompleted);

        try {
            await fetch('api/checklists.php', { method: 'POST', body: formData });
            recalcChecklistStats(checklistId);
        } catch (err) {
            checkbox.checked = !checkbox.checked;
        }
    }

    async function deleteChecklistItem(itemId, checklistId) {
        const formData = new FormData();
        formData.append('action', 'delete_item');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('item_id', itemId);

        try {
            const res = await fetch('api/checklists.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                if (currentTaskDetailId) {
                    openTaskDetailModal(currentTaskDetailId);
                    setTimeout(() => switchTaskDetailTab('checklists'), 200);
                } else {
                    const el = document.getElementById(`chk-item-${itemId}`);
                    if (el) el.remove();
                    recalcChecklistStats(checklistId);
                }
                showToast('Paso eliminado');
            }
        } catch (err) {
            showToast('Error al eliminar paso', 'error');
        }
    }

    async function deleteChecklist(checklistId) {
        if (!confirm('¿Eliminar esta lista completa?')) return;
        const formData = new FormData();
        formData.append('action', 'delete_checklist');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('checklist_id', checklistId);

        try {
            const res = await fetch('api/checklists.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                if (currentTaskDetailId) {
                    openTaskDetailModal(currentTaskDetailId);
                    setTimeout(() => switchTaskDetailTab('checklists'), 200);
                } else {
                    location.reload();
                }
                showToast('Lista eliminada');
            }
        } catch (err) {
            showToast('Error al eliminar lista', 'error');
        }
    }

    function recalcChecklistStats(chkId) {
        const list = document.getElementById(`chk-items-list-${chkId}`);
        if (!list) return;
        const total = list.querySelectorAll('input[type="checkbox"]').length;
        const completed = list.querySelectorAll('input[type="checkbox"]:checked').length;
        const pct = total > 0 ? Math.round((completed / total) * 100) : 0;

        const pText = document.getElementById(`chk-progress-text-${chkId}`);
        if (pText) {
            pText.querySelector('.chk-completed').innerText = completed;
            pText.querySelector('.chk-total').innerText = total;
            pText.querySelector('.chk-pct').innerText = pct;
        }
        const bar = document.getElementById(`chk-bar-${chkId}`);
        if (bar) bar.style.width = pct + '%';
    }

    // ----------------- TICKETS (AJAX) -----------------
    async function submitTicketAjax(e) {
        e.preventDefault();
        const btn = document.getElementById('submit-ticket-btn');
        btn.disabled = true;

        const title = document.getElementById('ticket-title-input').value;
        const description = document.getElementById('ticket-desc-input').value;
        const priority = document.getElementById('ticket-prio-input').value;

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('title', title);
        formData.append('description', description);
        formData.append('priority', priority);

        try {
            const res = await fetch('api/tickets.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                closeModal('create-ticket-modal');
                location.reload();
            }
        } catch (err) {
            showToast('Error al registrar ticket', 'error');
        } finally {
            btn.disabled = false;
        }
    }

    async function updateTicketStatus(ticketId, status) {
        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('ticket_id', ticketId);
        formData.append('status', status);

        try {
            await fetch('api/tickets.php', { method: 'POST', body: formData });
            showToast('Estado del ticket actualizado');
        } catch (err) {
            showToast('Error al actualizar ticket', 'error');
        }
    }

    async function deleteTicket(ticketId) {
        if (!confirm('¿Eliminar este ticket?')) return;
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('ticket_id', ticketId);

        try {
            const res = await fetch('api/tickets.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                const el = document.getElementById(`ticket-card-${ticketId}`);
                if (el) el.remove();
                showToast('Ticket eliminado');
            }
        } catch (err) {
            showToast('Error al eliminar ticket', 'error');
        }
    }

    // Respuestas en Tickets
    function openTicketReplyInput(ticketId) {
        const panel = document.getElementById(`ticket-replies-panel-${ticketId}`);
        if (panel && panel.classList.contains('hidden')) {
            toggleTicketReplies(ticketId);
        }
        setTimeout(() => {
            const txt = document.getElementById(`ticket-reply-text-${ticketId}`);
            if (txt) txt.focus();
        }, 150);
    }

    async function toggleTicketReplies(ticketId) {
        const panel = document.getElementById(`ticket-replies-panel-${ticketId}`);
        const chevron = document.getElementById(`chevron-replies-${ticketId}`);
        if (!panel) return;

        if (panel.classList.contains('hidden')) {
            panel.classList.remove('hidden');
            if (chevron) chevron.classList.add('rotate-180');
            await loadTicketReplies(ticketId);
        } else {
            panel.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
        }
    }

    async function loadTicketReplies(ticketId) {
        const feed = document.getElementById(`ticket-replies-feed-${ticketId}`);
        if (!feed) return;

        try {
            const res = await fetch(`api/tickets.php?action=list_replies&project_id=${PROJECT_ID}&ticket_id=${ticketId}&csrf_token=${CSRF_TOKEN}`);
            const data = await res.json();
            if (data.success) {
                if (!data.replies || data.replies.length === 0) {
                    feed.innerHTML = '<p class="text-xs text-slate-400 italic py-2">No hay respuestas en este ticket todavía.</p>';
                } else {
                    feed.innerHTML = data.replies.map(r => `
                        <div id="tk-reply-item-${r.id}" class="bg-slate-50/80 p-3 rounded-xl border border-slate-200/60 flex items-start gap-2.5 group">
                            <div class="w-6 h-6 rounded-full bg-slate-200 text-slate-700 font-bold text-[10px] flex items-center justify-center shrink-0">
                                ${r.initials}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-1 mb-1">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="text-xs font-bold text-slate-800">${r.author_name}</span>
                                        <span class="text-[9px] font-bold px-1.5 py-0.2 rounded border ${r.role_class}">${r.role_label}</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-[10px] text-slate-400">${r.created_at}</span>
                                        ${r.can_delete ? `
                                            <button onclick="deleteTicketReplyAjax(${r.id}, ${ticketId})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded opacity-0 group-hover:opacity-100 transition" title="Eliminar respuesta">
                                                <i data-lucide="trash-2" class="w-3 h-3"></i>
                                            </button>
                                        ` : ''}
                                    </div>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${r.message}</p>
                            </div>
                        </div>
                    `).join('');
                }
                const badge = document.getElementById(`ticket-replies-badge-${ticketId}`);
                if (badge) badge.innerText = data.replies ? data.replies.length : 0;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            } else {
                feed.innerHTML = `<p class="text-xs text-rose-500 py-2">${data.message || 'Error al cargar respuestas'}</p>`;
            }
        } catch (err) {
            feed.innerHTML = '<p class="text-xs text-rose-500 py-2">Error de conexión al cargar respuestas</p>';
        }
    }

    async function submitTicketReplyAjax(e, ticketId) {
        e.preventDefault();
        const txt = document.getElementById(`ticket-reply-text-${ticketId}`);
        const message = txt ? txt.value.trim() : '';
        if (!message) return;

        const btn = document.getElementById(`btn-submit-tk-reply-${ticketId}`);
        if (btn) {
            btn.disabled = true;
            btn.innerText = 'Enviando...';
        }

        const formData = new FormData();
        formData.append('action', 'add_reply');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('ticket_id', ticketId);
        formData.append('message', message);

        try {
            const res = await fetch('api/tickets.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                txt.value = '';
                showToast('Respuesta registrada en el ticket');
                await loadTicketReplies(ticketId);
            } else {
                showToast(data.message || 'Error al responder ticket', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerText = 'Enviar Respuesta';
            }
        }
    }

    async function deleteTicketReplyAjax(replyId, ticketId) {
        if (!confirm('¿Eliminar esta respuesta?')) return;

        const formData = new FormData();
        formData.append('action', 'delete_reply');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('reply_id', replyId);

        try {
            const res = await fetch('api/tickets.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                const el = document.getElementById(`tk-reply-item-${replyId}`);
                if (el) el.remove();
                const badge = document.getElementById(`ticket-replies-badge-${ticketId}`);
                if (badge) {
                    badge.innerText = Math.max(0, parseInt(badge.innerText || '1') - 1);
                }
                showToast('Respuesta eliminada');
            } else {
                showToast(data.message || 'Error al eliminar', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        }
    }

    // ----------------- COMENTARIOS GENERALES (AJAX) -----------------
    const commentInput = document.getElementById('comment-content-input');
    if (commentInput) {
        commentInput.addEventListener('paste', (e) => {
            const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
            if (!items) return;
            for (let item of items) {
                if (item.type.indexOf('image') === 0) {
                    const blob = item.getAsFile();
                    if (blob) {
                        e.preventDefault();
                        setImagePreview(blob);
                        showToast('¡Captura pegada!', 'success');
                        break;
                    }
                }
            }
        });
    }

    function handleCommentImageSelect(input) {
        if (input.files && input.files[0]) {
            setImagePreview(input.files[0]);
        }
    }

    function setImagePreview(fileOrBlob) {
        pastedImageBlob = fileOrBlob;
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('comment-preview-img').src = e.target.result;
            document.getElementById('comment-image-preview-container').classList.remove('hidden');
        };
        reader.readAsDataURL(fileOrBlob);
    }

    function clearCommentImage() {
        pastedImageBlob = null;
        document.getElementById('comment-preview-img').src = '';
        document.getElementById('comment-image-preview-container').classList.add('hidden');
        document.getElementById('comment-image-file').value = '';
    }

    async function submitCommentAjax(e) {
        if (e) e.preventDefault();
        const content = document.getElementById('comment-content-input').value.trim();
        if (!content && !pastedImageBlob) {
            showToast('Escribe una nota o adjunta una imagen', 'error');
            return;
        }

        const btn = document.getElementById('submit-comment-btn');
        btn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('content', content);

        if (pastedImageBlob) {
            formData.append('image', pastedImageBlob, 'comment_image.png');
        }

        try {
            const res = await fetch('api/comments.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                location.reload();
            }
        } catch (err) {
            showToast('Error al publicar nota', 'error');
        } finally {
            btn.disabled = false;
        }
    }

    function openImageModal(src) {
        document.getElementById('image-viewer-img').src = src;
        document.getElementById('image-viewer-modal').classList.remove('hidden');
    }

    function closeImageModal() {
        document.getElementById('image-viewer-modal').classList.add('hidden');
    }

    // ----------------- EDICIÓN Y ELIMINACIÓN DE COMENTARIOS -----------------
    // Serializa un valor para usarlo como argumento JS dentro de un atributo HTML (onclick="...")
    // JSON.stringify sin escapar permite romper el atributo con comillas e inyectar código.
    function jsAttrArg(value) {
        return JSON.stringify(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function openEditCommentModal(commentId, currentText) {
        document.getElementById('edit-comment-id').value = commentId;
        document.getElementById('edit-comment-content').value = currentText || '';
        openModal('edit-comment-modal');
        setTimeout(() => document.getElementById('edit-comment-content').focus(), 150);
    }

    async function submitEditCommentAjax(e) {
        e.preventDefault();
        const commentId = document.getElementById('edit-comment-id').value;
        const content = document.getElementById('edit-comment-content').value.trim();
        if (!content || !commentId) return;

        const btn = document.getElementById('btn-save-comment-edit');
        btn.disabled = true;
        btn.innerText = 'Guardando...';

        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('comment_id', commentId);
        formData.append('content', content);

        try {
            const res = await fetch('api/comments.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                // Actualizar en el muro general si existe
                const elGen = document.getElementById(`comment-text-${commentId}`);
                if (elGen) elGen.innerText = content;

                // Actualizar en el modal de tarea si existe
                const elMod = document.getElementById(`modal-comment-text-${commentId}`);
                if (elMod) elMod.innerText = content;

                closeModal('edit-comment-modal');
                showToast('Comentario actualizado');
            } else {
                showToast(data.message || 'Error al actualizar comentario', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Guardar Cambios';
        }
    }

    async function deleteCommentAjax(commentId) {
        if (!confirm('¿Eliminar este comentario?')) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('comment_id', commentId);

        try {
            const res = await fetch('api/comments.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                // Quitar de muro general
                const elGen = document.getElementById(`comment-card-${commentId}`);
                if (elGen) elGen.remove();

                // Quitar de modal de tarea
                const elMod = document.getElementById(`modal-comment-card-${commentId}`);
                if (elMod) elMod.remove();

                // Actualizar contadores
                const bComms = document.getElementById('badge-comments-count');
                if (bComms) {
                    const currentCount = Math.max(0, parseInt(bComms.innerText || '1') - 1);
                    bComms.innerText = currentCount;
                }

                if (data.task_id) {
                    document.querySelectorAll(`.task-comments-count-${data.task_id}`).forEach(span => {
                        span.innerText = Math.max(0, parseInt(span.innerText || '1') - 1);
                    });
                    const bTaskBadge = document.getElementById('modal-task-comments-badge-count');
                    if (bTaskBadge) {
                        bTaskBadge.innerText = Math.max(0, parseInt(bTaskBadge.innerText || '1') - 1);
                    }
                }

                showToast('Comentario eliminado');
            } else {
                showToast(data.message || 'Error al eliminar comentario', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        }
    }

    // ----------------- RESPUESTAS A COMENTARIOS (HILOS) -----------------
    function toggleReplyBox(parentId) {
        const box = document.getElementById(`reply-box-${parentId}`);
        if (!box) return;
        if (box.classList.contains('hidden')) {
            box.classList.remove('hidden');
            const txt = document.getElementById(`reply-content-${parentId}`);
            if (txt) setTimeout(() => txt.focus(), 100);
        } else {
            box.classList.add('hidden');
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    async function submitReplyAjax(e, parentId, taskId) {
        e.preventDefault();
        const input = document.getElementById(`reply-content-${parentId}`);
        const content = input ? input.value.trim() : '';
        if (!content) return;

        const btn = document.getElementById(`btn-submit-reply-${parentId}`);
        if (btn) {
            btn.disabled = true;
            btn.innerText = 'Enviando...';
        }

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('parent_id', parentId);
        if (taskId) formData.append('task_id', taskId);
        formData.append('content', content);

        try {
            const res = await fetch('api/comments.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                const rep = data.comment;
                input.value = '';
                toggleReplyBox(parentId);

                const repContainer = document.getElementById(`replies-container-${parentId}`);
                if (repContainer) {
                    repContainer.classList.remove('hidden');
                    const repDiv = document.createElement('div');
                    repDiv.id = `comment-card-${rep.id}`;
                    repDiv.className = "bg-slate-50/70 p-3 rounded-xl border border-slate-200/50 flex items-start gap-2.5 group/reply";
                    repDiv.innerHTML = `
                        <div class="w-6 h-6 rounded-full bg-slate-200 text-slate-700 font-bold text-[10px] flex items-center justify-center flex-shrink-0">
                            ${rep.initials}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-0.5">
                                <h5 class="text-xs font-bold text-slate-800">${rep.author_name}</h5>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[9px] text-slate-400">${rep.created_at}</span>
                                    <div class="opacity-0 group-hover/reply:opacity-100 transition flex items-center gap-0.5">
                                        <button onclick="openEditCommentModal(${rep.id}, ${jsAttrArg(rep.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                            <i data-lucide="edit-2" class="w-2.5 h-2.5"></i>
                                        </button>
                                        <button onclick="deleteCommentAjax(${rep.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                            <i data-lucide="trash-2" class="w-2.5 h-2.5"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p id="comment-text-${rep.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${rep.content}</p>
                        </div>
                    `;
                    repContainer.appendChild(repDiv);
                }

                // Si está en modal de tarea, sincronizar también
                const mRepContainer = document.getElementById(`modal-replies-container-${parentId}`);
                if (mRepContainer) {
                    mRepContainer.classList.remove('hidden');
                    const mDiv = document.createElement('div');
                    mDiv.id = `modal-comment-card-${rep.id}`;
                    mDiv.className = "bg-slate-50/70 p-2.5 rounded-xl border border-slate-200/50 flex items-start gap-2.5 group/mreply";
                    mDiv.innerHTML = `
                        <div class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 font-bold text-[9px] flex items-center justify-center flex-shrink-0">
                            ${rep.initials}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-0.5">
                                <h5 class="text-[11px] font-bold text-slate-800">${rep.author_name}</h5>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[9px] text-slate-400">${rep.created_at}</span>
                                    <div class="opacity-0 group-hover/mreply:opacity-100 transition flex items-center gap-0.5">
                                        <button onclick="openEditCommentModal(${rep.id}, ${jsAttrArg(rep.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                            <i data-lucide="edit-2" class="w-2.5 h-2.5"></i>
                                        </button>
                                        <button onclick="deleteCommentAjax(${rep.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                            <i data-lucide="trash-2" class="w-2.5 h-2.5"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p id="modal-comment-text-${rep.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${rep.content}</p>
                        </div>
                    `;
                    mRepContainer.appendChild(mDiv);
                }

                showToast('Respuesta publicada con éxito');
                if (typeof lucide !== 'undefined') lucide.createIcons();
            } else {
                showToast(data.message || 'Error al enviar respuesta', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerText = 'Enviar Respuesta';
            }
        }
    }

    function toggleModalReplyBox(parentId) {
        const box = document.getElementById(`modal-reply-box-${parentId}`);
        if (!box) return;
        if (box.classList.contains('hidden')) {
            box.classList.remove('hidden');
            const txt = document.getElementById(`modal-reply-content-${parentId}`);
            if (txt) setTimeout(() => txt.focus(), 100);
        } else {
            box.classList.add('hidden');
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    async function submitModalReplyAjax(e, parentId) {
        e.preventDefault();
        const taskId = document.getElementById('modal-task-id-input') ? document.getElementById('modal-task-id-input').value : null;
        const input = document.getElementById(`modal-reply-content-${parentId}`);
        const content = input ? input.value.trim() : '';
        if (!content) return;

        const btn = document.getElementById(`btn-modal-reply-${parentId}`);
        if (btn) {
            btn.disabled = true;
            btn.innerText = '...';
        }

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('parent_id', parentId);
        if (taskId) formData.append('task_id', taskId);
        formData.append('content', content);

        try {
            const res = await fetch('api/comments.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                const rep = data.comment;
                input.value = '';
                toggleModalReplyBox(parentId);

                const mRepContainer = document.getElementById(`modal-replies-container-${parentId}`);
                if (mRepContainer) {
                    mRepContainer.classList.remove('hidden');
                    const mDiv = document.createElement('div');
                    mDiv.id = `modal-comment-card-${rep.id}`;
                    mDiv.className = "bg-slate-50/70 p-2.5 rounded-xl border border-slate-200/50 flex items-start gap-2.5 group/mreply";
                    mDiv.innerHTML = `
                        <div class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 font-bold text-[9px] flex items-center justify-center flex-shrink-0">
                            ${rep.initials}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-0.5">
                                <h5 class="text-[11px] font-bold text-slate-800">${rep.author_name}</h5>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[9px] text-slate-400">${rep.created_at}</span>
                                    <div class="opacity-0 group-hover/mreply:opacity-100 transition flex items-center gap-0.5">
                                        <button onclick="openEditCommentModal(${rep.id}, ${jsAttrArg(rep.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                            <i data-lucide="edit-2" class="w-2.5 h-2.5"></i>
                                        </button>
                                        <button onclick="deleteCommentAjax(${rep.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                            <i data-lucide="trash-2" class="w-2.5 h-2.5"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <p id="modal-comment-text-${rep.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${rep.content}</p>
                        </div>
                    `;
                    mRepContainer.appendChild(mDiv);
                }

                showToast('Respuesta enviada');
                if (typeof lucide !== 'undefined') lucide.createIcons();
            } else {
                showToast(data.message || 'Error al enviar respuesta', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerText = 'Responder';
            }
        }
    }

    // ----------------- CARPETAS Y FILTROS EN ADJUNTOS -----------------
    async function submitFolderAjax(e) {
        e.preventDefault();
        const name = document.getElementById('folder-name-input').value.trim();
        const colorInput = document.querySelector('input[name="folder_color"]:checked');
        const color = colorInput ? colorInput.value : '#6366F1';

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('name', name);
        formData.append('color', color);

        try {
            const res = await fetch('api/folders.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                closeModal('create-folder-modal');
                location.reload();
            }
        } catch (err) {
            showToast('Error al crear carpeta', 'error');
        }
    }

    async function deleteFolderAjax(folderId) {
        if (!confirm('¿Eliminar esta carpeta? Los archivos y enlaces contenidos se conservarán en la raíz.')) return;
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('folder_id', folderId);

        try {
            const res = await fetch('api/folders.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                location.reload();
            }
        } catch (err) {
            showToast('Error al eliminar carpeta', 'error');
        }
    }

    function filterByFolder(folderId, buttonElement) {
        document.querySelectorAll('.folder-pill').forEach(btn => {
            btn.classList.remove('bg-brand-600', 'text-white', 'shadow-2xs');
            btn.classList.add('bg-white', 'text-slate-600', 'border', 'border-slate-200/80');
        });
        if (buttonElement) {
            buttonElement.classList.remove('bg-white', 'text-slate-600', 'border', 'border-slate-200/80');
            buttonElement.classList.add('bg-brand-600', 'text-white', 'shadow-2xs');
        }

        const files = document.querySelectorAll('.file-item-card');
        const links = document.querySelectorAll('.link-item-card');
        let visibleFiles = 0;
        let visibleLinks = 0;

        files.forEach(card => {
            const cardFolder = card.getAttribute('data-folder-id');
            if (folderId === 'all' || cardFolder == folderId) {
                card.classList.remove('hidden');
                visibleFiles++;
            } else {
                card.classList.add('hidden');
            }
        });

        links.forEach(card => {
            const cardFolder = card.getAttribute('data-folder-id');
            if (folderId === 'all' || cardFolder == folderId) {
                card.classList.remove('hidden');
                visibleLinks++;
            } else {
                card.classList.add('hidden');
            }
        });

        document.getElementById('files-empty-state').classList.toggle('hidden', visibleFiles > 0);
        document.getElementById('files-grid').classList.toggle('hidden', visibleFiles === 0);
        document.getElementById('links-empty-state').classList.toggle('hidden', visibleLinks > 0);
        document.getElementById('links-grid').classList.toggle('hidden', visibleLinks === 0);
    }

    async function submitFileAjax(e) {
        e.preventDefault();
        const fileInput = document.getElementById('file-attachment-input');
        if (!fileInput.files.length) return;

        const folderId = document.getElementById('file-folder-select').value;
        const taskId = document.getElementById('file-task-select').value;
        const btn = document.getElementById('submit-file-btn');
        btn.disabled = true;
        btn.innerText = 'Subiendo...';

        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('attachment', fileInput.files[0]);
        if (folderId) formData.append('folder_id', folderId);
        if (taskId) formData.append('task_id', taskId);

        try {
            const res = await fetch('api/files.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                closeModal('upload-file-modal');
                location.reload();
            } else {
                showToast(data.message || 'Error al subir archivo', 'error');
            }
        } catch (err) {
            showToast('Error al subir archivo', 'error');
        } finally {
            btn.disabled = false;
            btn.innerText = 'Subir Archivo';
        }
    }

    async function deleteFileAjax(fileId) {
        if (!confirm('¿Eliminar este archivo?')) return;
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('file_id', fileId);

        try {
            const res = await fetch('api/files.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                location.reload();
            }
        } catch (err) {
            showToast('Error al eliminar archivo', 'error');
        }
    }

    async function submitLinkAjax(e) {
        e.preventDefault();
        const title = document.getElementById('link-title-input').value.trim();
        const url = document.getElementById('link-url-input').value.trim();
        const folderId = document.getElementById('link-folder-select').value;
        const taskId = document.getElementById('link-task-select').value;

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('title', title);
        formData.append('url', url);
        if (folderId) formData.append('folder_id', folderId);
        if (taskId) formData.append('task_id', taskId);

        try {
            const res = await fetch('api/links.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                closeModal('create-link-modal');
                location.reload();
            }
        } catch (err) {
            showToast('Error al guardar enlace', 'error');
        }
    }

    async function deleteLinkAjax(linkId) {
        if (!confirm('¿Eliminar este enlace?')) return;
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('link_id', linkId);

        try {
            const res = await fetch('api/links.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                location.reload();
            }
        } catch (err) {
            showToast('Error al eliminar enlace', 'error');
        }
    }

    // ----------------- COSTOS Y FACTURACIÓN (AJAX) -----------------
    async function submitCreateInvoiceAjax(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-invoice');
        if (btn) btn.disabled = true;

        const invoiceNumber = document.getElementById('inv-number-input').value.trim();
        const currency = document.getElementById('inv-currency-input').value;
        const concept = document.getElementById('inv-concept-input').value.trim();
        const amount = document.getElementById('inv-amount-input').value;
        const dueDate = document.getElementById('inv-due-date-input').value;
        const status = document.getElementById('inv-status-input').value;
        const notes = document.getElementById('inv-notes-input').value.trim();

        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('invoice_number', invoiceNumber);
        formData.append('currency', currency);
        formData.append('concept', concept);
        formData.append('amount', amount);
        formData.append('due_date', dueDate);
        formData.append('status', status);
        formData.append('notes', notes);

        try {
            const res = await fetch('api/invoices.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                showToast(data.message || 'Factura guardada');
                closeModal('create-invoice-modal');
                location.hash = 'finanzas';
                location.reload();
            } else {
                showToast(data.message || 'Error al registrar factura', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    async function submitUpdateBudgetAjax(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-submit-budget');
        if (btn) btn.disabled = true;

        const budget = document.getElementById('budget-amount-input').value;
        const currency = document.getElementById('budget-currency-input').value;

        const formData = new FormData();
        formData.append('action', 'update_budget');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('budget', budget);
        formData.append('currency', currency);

        try {
            const res = await fetch('api/invoices.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                showToast(data.message || 'Costo actualizado');
                closeModal('edit-budget-modal');
                location.hash = 'finanzas';
                location.reload();
            } else {
                showToast(data.message || 'Error al actualizar costo', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    async function updateInvoiceStatusAjax(invoiceId, newStatus) {
        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('invoice_id', invoiceId);
        formData.append('status', newStatus);

        try {
            const res = await fetch('api/invoices.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                showToast('Estado de factura actualizado');
                const badge = document.getElementById(`inv-badge-${invoiceId}`);
                if (badge) {
                    badge.innerText = newStatus;
                    const badgeClasses = {
                        'pagada': 'bg-emerald-50 text-emerald-700 border-emerald-200/60',
                        'pendiente': 'bg-amber-50 text-amber-700 border-amber-200/60',
                        'anulada': 'bg-rose-50 text-rose-700 border-rose-200/60'
                    };
                    badge.className = `inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider border ${badgeClasses[newStatus] || 'bg-slate-50 text-slate-700 border-slate-200/60'}`;
                }
                setTimeout(() => location.reload(), 600);
            } else {
                showToast(data.message || 'Error al actualizar estado', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        }
    }

    async function deleteInvoiceAjax(invoiceId) {
        if (!confirm('¿Eliminar esta factura del proyecto?')) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('project_id', PROJECT_ID);
        formData.append('csrf_token', CSRF_TOKEN);
        formData.append('invoice_id', invoiceId);

        try {
            const res = await fetch('api/invoices.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                showToast('Factura eliminada');
                const row = document.getElementById(`invoice-row-${invoiceId}`);
                if (row) row.remove();
                setTimeout(() => location.reload(), 500);
            } else {
                showToast(data.message || 'Error al eliminar', 'error');
            }
        } catch (err) {
            showToast('Error de conexión', 'error');
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

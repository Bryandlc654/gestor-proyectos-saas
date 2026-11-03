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
<main class="flex-1 flex flex-col min-w-0 bg-slate-50 overflow-y-auto">
    <!-- Header del Proyecto (Minimalista y Pulido) -->
    <header class="bg-white border-b border-slate-200/70 px-4 sm:px-8 py-5 sticky top-0 z-10">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row md:items-center justify-between gap-4">
            
            <div class="flex items-start gap-3.5">
                <button onclick="toggleSidebar()" class="p-1.5 -ml-1.5 rounded-md text-slate-400 hover:text-slate-900 hover:bg-slate-100 transition" title="Alternar menú lateral">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>

                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white flex-shrink-0 shadow-xs" style="background-color: <?= e($project['color'] ?: '#4F46E5') ?>;">
                    <i data-lucide="folder" class="w-5 h-5"></i>
                </div>

                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-semibold text-base sm:text-lg text-slate-900 tracking-tight">
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
                        <div class="text-[11px] font-medium text-slate-500">Costo Proyecto</div>
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

        <?php require __DIR__ . '/includes/project/tab-tasks.php'; ?>

        <?php require __DIR__ . '/includes/project/tab-checklists.php'; ?>

        <?php require __DIR__ . '/includes/project/tab-tickets.php'; ?>

        <?php require __DIR__ . '/includes/project/tab-comments.php'; ?>

        <?php require __DIR__ . '/includes/project/tab-attachments.php'; ?>

        <?php require __DIR__ . '/includes/project/tab-finances.php'; ?>
    </div>
</main>

<!-- ==================== MODALES DEL SISTEMA ==================== -->

<?php require __DIR__ . '/includes/project/modal-task-detail.php'; ?>

<?php require __DIR__ . '/includes/project/modal-create-task.php'; ?>

<?php require __DIR__ . '/includes/project/modal-invoice.php'; ?>

<?php require __DIR__ . '/includes/project/modal-budget.php'; ?>

<!-- ==================== CAPA JAVASCRIPT AJAX REACTIVA ==================== -->
<script>
    window.FLOWTASKS = {
        projectId: <?= (int)$projectId ?>,
        csrfToken: <?= json_encode(csrf_token(), JSON_SCRIPT_FLAGS) ?>
    };
</script>
<script src="assets/js/project-view.js?v=<?= @filemtime(__DIR__ . '/assets/js/project-view.js') ?: 1 ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

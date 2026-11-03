<?php
// api/tasks.php - Gestión asíncrona de tareas (AJAX)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$user = currentUser();
$db = getDB();
$csrf = $_POST['csrf_token'] ?? '';
$action = $_POST['action'] ?? '';
$projectId = (int)($_POST['project_id'] ?? 0);

if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

// Si no se pasó project_id explícito pero sí un task_id, buscar el project_id de la tarea
if ($projectId <= 0 && !empty($_POST['task_id'])) {
    $stmtT = $db->prepare("SELECT project_id FROM tasks WHERE id = ?");
    $stmtT->execute([(int)$_POST['task_id']]);
    $tRow = $stmtT->fetch();
    if ($tRow) {
        $projectId = (int)$tRow['project_id'];
    }
}

// Verificar pertenencia o acceso al proyecto según rol
if (!canAccessProject($projectId, $user['id'], $user['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso denegado al proyecto']);
    exit;
}

switch ($action) {
    case 'create':
        if (!canManageTasks($user['role'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Tu nivel de acceso no permite crear tareas']);
            exit;
        }

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['baja', 'media', 'alta', 'urgente']) ? $_POST['priority'] : 'media';
        $assignedTo = trim($_POST['assigned_to'] ?? '');
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;

        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'El título de la tarea es obligatorio']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO tasks (project_id, title, description, priority, status, assigned_to, due_date) VALUES (?, ?, ?, ?, 'pendiente', ?, ?)");
        $stmt->execute([$projectId, $title, $description, $priority, $assignedTo ?: null, $dueDate]);
        $newId = (int)$db->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Tarea creada exitosamente',
            'task' => [
                'id' => $newId,
                'title' => e($title),
                'description' => e($description),
                'priority' => $priority,
                'status' => 'pendiente',
                'assigned_to' => e($assignedTo),
                'due_date' => $dueDate ? formatDate($dueDate) : 'Sin fecha',
                'comments_count' => 0
            ]
        ]);
        break;

    case 'update':
        if (!canManageTasks($user['role'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permisos para editar esta tarea']);
            exit;
        }

        $taskId = (int)($_POST['task_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['baja', 'media', 'alta', 'urgente']) ? $_POST['priority'] : 'media';
        $assignedTo = trim($_POST['assigned_to'] ?? '');
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $status = in_array($_POST['status'] ?? '', ['pendiente', 'en_progreso', 'completada']) ? $_POST['status'] : 'pendiente';

        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'El título de la tarea es obligatorio']);
            exit;
        }

        $stmt = $db->prepare("UPDATE tasks SET title = ?, description = ?, priority = ?, status = ?, assigned_to = ?, due_date = ? WHERE id = ? AND project_id = ?");
        $stmt->execute([$title, $description, $priority, $status, $assignedTo ?: null, $dueDate, $taskId, $projectId]);

        echo json_encode([
            'success' => true,
            'message' => 'Tarea actualizada exitosamente',
            'task' => [
                'id' => $taskId,
                'title' => e($title),
                'description' => e($description),
                'priority' => $priority,
                'status' => $status,
                'assigned_to' => e($assignedTo ?: 'Sin asignar'),
                'due_date' => $dueDate ? formatDate($dueDate) : 'Sin fecha',
                'raw_due_date' => $dueDate ?: ''
            ]
        ]);
        break;

    case 'update_status':
        if (!canChangeTaskStatus($user['role'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Los clientes tienen acceso de solo lectura en las tareas']);
            exit;
        }

        $taskId = (int)($_POST['task_id'] ?? 0);
        $newStatus = in_array($_POST['status'] ?? '', ['pendiente', 'en_progreso', 'completada']) ? $_POST['status'] : 'pendiente';

        $stmt = $db->prepare("UPDATE tasks SET status = ? WHERE id = ? AND project_id = ?");
        $stmt->execute([$newStatus, $taskId, $projectId]);

        echo json_encode([
            'success' => true,
            'message' => 'Estado actualizado',
            'task_id' => $taskId,
            'status' => $newStatus
        ]);
        break;

    case 'delete':
        if (!canDeleteTasks($user['role'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Solo los Administradores y Project Managers pueden eliminar tareas']);
            exit;
        }

        $taskId = (int)($_POST['task_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?");
        $stmt->execute([$taskId, $projectId]);

        echo json_encode([
            'success' => true,
            'message' => 'Tarea eliminada',
            'task_id' => $taskId
        ]);
        break;

    case 'get_detail':
        $taskId = (int)($_POST['task_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM tasks WHERE id = ? AND project_id = ?");
        $stmt->execute([$taskId, $projectId]);
        $task = $stmt->fetch();

        if (!$task) {
            echo json_encode(['success' => false, 'message' => 'Tarea no encontrada']);
            exit;
        }

        $isClient = ($user['role'] === 'cliente');
        $commentsList = [];
        $checklistsList = [];
        $filesList = [];
        $linksList = [];

        // Los clientes solo ven la actividad y su descripción (no sus comentarios, ni checklists, ni adjuntos)
        if (!$isClient) {
            // 1. Obtener comentarios de esta tarea
            $stmtComments = $db->prepare("
                SELECT c.*, u.name as author_name 
                FROM comments c 
                INNER JOIN users u ON c.user_id = u.id 
                WHERE c.task_id = ? AND c.project_id = ?
                ORDER BY c.created_at ASC
            ");
            $stmtComments->execute([$taskId, $projectId]);
            $taskComments = $stmtComments->fetchAll();

            foreach ($taskComments as $c) {
                $commentsList[] = [
                    'id' => $c['id'],
                    'parent_id' => $c['parent_id'] ? (int)$c['parent_id'] : null,
                    'user_id' => (int)$c['user_id'],
                    'author_name' => e($c['author_name']),
                    'initials' => strtoupper(substr($c['author_name'], 0, 2)),
                    'content' => nl2br(e($c['content'])),
                    'raw_content' => $c['content'],
                    'image_url' => $c['image_path'] ? 'uploads/' . $c['image_path'] : null,
                    'created_at' => formatDate($c['created_at'], true),
                    'can_manage' => ($c['user_id'] == $user['id'] || isAdmin())
                ];
            }

            // 2. Obtener checklists y sus items mediante Eager Loading (2 queries en lugar de N+1)
            $stmtChk = $db->prepare("SELECT * FROM checklists WHERE task_id = ? AND project_id = ? ORDER BY created_at ASC");
            $stmtChk->execute([$taskId, $projectId]);
            $taskChecklists = $stmtChk->fetchAll();
            if (!empty($taskChecklists)) {
                $taskChkIds = array_column($taskChecklists, 'id');
                $chkPlaceholders = implode(',', array_fill(0, count($taskChkIds), '?'));
                $stmtItems = $db->prepare("SELECT * FROM checklist_items WHERE checklist_id IN ($chkPlaceholders) ORDER BY id ASC");
                $stmtItems->execute($taskChkIds);
                $allTaskItems = $stmtItems->fetchAll();

                $groupedTaskItems = [];
                foreach ($allTaskItems as $it) {
                    $groupedTaskItems[$it['checklist_id']][] = [
                        'id' => $it['id'],
                        'title' => e($it['title']),
                        'is_completed' => (int)$it['is_completed']
                    ];
                }

                foreach ($taskChecklists as $chk) {
                    $checklistsList[] = [
                        'id' => $chk['id'],
                        'title' => e($chk['title']),
                        'items' => $groupedTaskItems[$chk['id']] ?? []
                    ];
                }
            }

            // 3. Obtener archivos adjuntos de esta tarea
            $stmtF = $db->prepare("SELECT * FROM project_files WHERE task_id = ? AND project_id = ? ORDER BY created_at DESC");
            $stmtF->execute([$taskId, $projectId]);
            $taskFiles = $stmtF->fetchAll();
            $filesList = array_map(fn($f) => [
                'id' => $f['id'],
                'original_name' => e($f['original_name']),
                'file_size' => formatBytes($f['file_size']),
                'created_at' => formatDate($f['created_at']),
                'download_url' => "actions/file-actions.php?action=download&id={$f['id']}"
            ], $taskFiles);

            // 4. Obtener enlaces de esta tarea
            $stmtL = $db->prepare("SELECT * FROM project_links WHERE task_id = ? AND project_id = ? ORDER BY created_at DESC");
            $stmtL->execute([$taskId, $projectId]);
            $taskLinks = $stmtL->fetchAll();
            $linksList = array_map(fn($l) => [
                'id' => $l['id'],
                'title' => e($l['title']),
                'url' => isSafeUrl($l['url']) ? e($l['url']) : '#',
                'created_at' => formatDate($l['created_at'])
            ], $taskLinks);
        }

        echo json_encode([
            'success' => true,
            'task' => [
                'id' => $task['id'],
                'title' => e($task['title']),
                'raw_title' => $task['title'],
                'description' => e($task['description']),
                'raw_description' => $task['description'],
                'priority' => $task['priority'],
                'status' => $task['status'],
                'assigned_to' => e($task['assigned_to'] ?: 'Sin asignar'),
                'raw_assigned_to' => $task['assigned_to'] ?: '',
                'due_date' => $task['due_date'] ? formatDate($task['due_date']) : 'Sin fecha',
                'raw_due_date' => $task['due_date'] ?: '',
            ],
            'permissions' => [
                'can_edit'   => canManageTasks($user['role']),
                'can_delete' => canDeleteTasks($user['role']),
                'can_change_status' => canChangeTaskStatus($user['role']),
                'is_cliente' => $isClient,
                'user_role'  => $user['role']
            ],
            'comments' => $commentsList,
            'checklists' => $checklistsList,
            'files' => $filesList,
            'links' => $linksList
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        break;
}
exit;

<?php
// api/app/tasks.php - Listado y cambio de estado de tareas para React.
require_once __DIR__ . '/_bootstrap.php';

$visibleIds = array_values(array_map('intval', projectRepository()->visibleProjectIds($userId, $role)));
$in = $visibleIds === [] ? 'IN (0)' : 'IN (' . implode(',', $visibleIds) . ')';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'update_status');

    if ($action === 'create') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        if ($projectId <= 0 || !in_array($projectId, $visibleIds, true)) {
            appJson(['error' => 'Proyecto no válido'], 422);
        }
        if (!canEditProjectTasks($projectId, $userId, $role)) {
            appJson(['error' => 'No tienes permisos para crear tareas en este proyecto'], 403);
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $priority = in_array($_POST['priority'] ?? '', ['baja', 'media', 'alta', 'urgente'], true) ? (string)$_POST['priority'] : 'media';
        $dueDate = trim((string)($_POST['due_date'] ?? ''));
        if ($dueDate !== '' && !isIsoDate($dueDate)) {
            appJson(['error' => 'La fecha de vencimiento no es válida.'], 422);
        }
        $dueDate = $dueDate !== '' ? $dueDate : null;
        $assignedUserId = (int)($_POST['assigned_user_id'] ?? 0);
        $assignedUserId = $assignedUserId > 0 ? $assignedUserId : null;
        $requestedStatus = trim((string)($_POST['status'] ?? ''));
        $validStatuses = array_column(taskStatuses(), 'key');
        if ($title === '' || mb_strlen($title) > 255) {
            appJson(['error' => 'El título es obligatorio (máximo 255 caracteres).'], 422);
        }
        if ($assignedUserId !== null && projectRepository()->assigneeName($assignedUserId, $projectId) === null) {
            appJson(['error' => 'El responsable debe ser miembro del proyecto'], 422);
        }

        $taskId = taskRepository()->create($projectId, $title, $description, $priority, $assignedUserId, $dueDate, null, $dueDate, false);
        if (in_array($requestedStatus, $validStatuses, true)) {
            taskRepository()->updateStatus($taskId, $projectId, $requestedStatus);
        }
        activityRepository()->logByUser($userId, $projectId, 'task.created', 'Creó la tarea "' . $title . '"');
        if ($assignedUserId !== null && $assignedUserId !== $userId) {
            try { notificationService()->notifyTaskAssignment($assignedUserId, $title, $projectId, projectRepository()->name($projectId) ?? 'un proyecto'); } catch (\Throwable $e) { error_log('Notificación tarea: ' . $e->getMessage()); }
        }

        appJson([
            'success' => true,
            'id' => $taskId,
            'status' => in_array($requestedStatus, $validStatuses, true) ? $requestedStatus : null,
            'message' => 'Tarea creada correctamente.',
        ]);
    }

    if ($action === 'reschedule') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $dueDate = trim((string)($_POST['due_date'] ?? ''));
        if ($taskId <= 0 || !isIsoDate($dueDate)) {
            appJson(['error' => 'Datos de reprogramación no válidos'], 422);
        }
        $stmt = getDB()->prepare('SELECT id, project_id, title, start_date FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($task === false || !in_array((int)$task['project_id'], $visibleIds, true)) {
            appJson(['error' => 'Tarea no encontrada'], 404);
        }
        if (!canEditProjectTasks((int)$task['project_id'], $userId, $role)) {
            appJson(['error' => 'No tienes permisos para reprogramar esta tarea'], 403);
        }
        // El cronograma envía también el inicio (mover la barra completa); el calendario solo el vencimiento.
        $postedStart = trim((string)($_POST['start_date'] ?? ''));
        $startDate = isIsoDate($postedStart) ? $postedStart : (string)($task['start_date'] ?? '');
        if ($startDate === '' || $startDate > $dueDate) $startDate = $dueDate;
        taskRepository()->reschedule($taskId, (int)$task['project_id'], $startDate, $dueDate);
        activityRepository()->logByUser($userId, (int)$task['project_id'], 'task.rescheduled', 'Reprogramó la tarea "' . $task['title'] . '" al ' . $dueDate);
        appJson(['success' => true, 'due_date' => $dueDate, 'message' => 'Tarea reprogramada.']);
    }

    if ($action === 'bulk_delete') {
        $idsRaw = $_POST['task_ids'] ?? '[]';
        if (is_string($idsRaw)) {
            $decoded = json_decode($idsRaw, true);
            $idsRaw = is_array($decoded) ? $decoded : [];
        }
        $taskIds = array_values(array_unique(array_filter(array_map('intval', is_array($idsRaw) ? $idsRaw : []))));
        if ($taskIds === []) appJson(['error' => 'Selecciona al menos una tarea'], 422);

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = getDB()->prepare("SELECT id, project_id, title FROM tasks WHERE id IN ($placeholders)");
        $stmt->execute($taskIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        projectRepository()->primeAccessContexts(array_column($rows, "project_id"), $userId, $role);
        if (count($rows) !== count($taskIds)) appJson(['error' => 'Alguna de las tareas ya no existe'], 404);
        foreach ($rows as $row) {
            if (!in_array((int)$row['project_id'], $visibleIds, true) || !canDeleteProjectTasks((int)$row['project_id'], $userId, $role)) {
                appJson(['error' => 'No tienes permisos para eliminar todas las tareas seleccionadas'], 403);
            }
        }
        foreach ($rows as $row) {
            taskRepository()->delete((int)$row['id'], (int)$row['project_id']);
        }
        activityRepository()->logByUser($userId, null, 'task.bulk_delete', 'Eliminó ' . count($rows) . ' tareas');
        appJson(['success' => true, 'deleted' => count($rows), 'message' => 'Tareas eliminadas.']);
    }

    if ($action === 'bulk_status' || $action === 'bulk_assign') {
        $idsRaw = $_POST['task_ids'] ?? '[]';
        if (is_string($idsRaw)) {
            $decoded = json_decode($idsRaw, true);
            $idsRaw = is_array($decoded) ? $decoded : [];
        }
        $taskIds = array_values(array_unique(array_filter(array_map('intval', is_array($idsRaw) ? $idsRaw : []))));
        if ($taskIds === []) {
            appJson(['error' => 'Selecciona al menos una tarea'], 422);
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = getDB()->prepare("SELECT id, project_id, title FROM tasks WHERE id IN ($placeholders)");
        $stmt->execute($taskIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        projectRepository()->primeAccessContexts(array_column($rows, "project_id"), $userId, $role);
        if (count($rows) !== count($taskIds)) {
            appJson(['error' => 'Alguna de las tareas ya no existe'], 404);
        }
        foreach ($rows as $row) {
            if (!in_array((int)$row['project_id'], $visibleIds, true) || !canEditProjectTasks((int)$row['project_id'], $userId, $role)) {
                appJson(['error' => 'No tienes permisos sobre todas las tareas seleccionadas'], 403);
            }
        }

        if ($action === 'bulk_status') {
            $status = trim((string)($_POST['status'] ?? ''));
            if (!in_array($status, array_column(taskStatuses(), 'key'), true)) {
                appJson(['error' => 'Estado no válido'], 422);
            }
            foreach ($rows as $row) {
                taskRepository()->updateStatus((int)$row['id'], (int)$row['project_id'], $status);
            }
            activityRepository()->logByUser($userId, null, 'task.bulk_status', 'Cambió ' . count($rows) . ' tareas a ' . taskStatusLabel($status));
            appJson(['success' => true, 'updated' => count($rows), 'message' => 'Tareas actualizadas.']);
        }

        $assignedUserId = (int)($_POST['assigned_user_id'] ?? 0);
        $assignee = $assignedUserId > 0 ? $assignedUserId : null;
        if ($assignee !== null) {
            $checkedProjects = [];
            foreach ($rows as $row) {
                $projectId = (int)$row['project_id'];
                if (isset($checkedProjects[$projectId])) continue;
                if (projectRepository()->assigneeName($assignee, $projectId) === null) {
                    appJson(['error' => 'El responsable debe pertenecer a todos los proyectos seleccionados'], 422);
                }
                $checkedProjects[$projectId] = true;
            }
        }

        $update = getDB()->prepare("UPDATE tasks SET assigned_user_id = ?, assigned_to = NULL, updated_at = NOW() WHERE id IN ($placeholders)");
        $update->execute(array_merge([$assignee], $taskIds));
        activityRepository()->logByUser($userId, null, 'task.bulk_assign', 'Reasignó ' . count($rows) . ' tareas');
        appJson(['success' => true, 'updated' => count($rows), 'message' => 'Responsable actualizado.']);
    }

    $taskId = (int)($_POST['task_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $validStatuses = array_column(taskStatuses(), 'key');
    if ($taskId <= 0 || !in_array($status, $validStatuses, true)) {
        appJson(['error' => 'Tarea o estado no válido'], 422);
    }

    $stmt = getDB()->prepare('SELECT id, project_id, title FROM tasks WHERE id = ?');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($task === false || !in_array((int)$task['project_id'], $visibleIds, true)) {
        appJson(['error' => 'Tarea no encontrada'], 404);
    }
    if (!canEditProjectTasks((int)$task['project_id'], $userId, $role)) {
        appJson(['error' => 'No tienes permisos para cambiar esta tarea'], 403);
    }

    taskRepository()->updateStatus($taskId, (int)$task['project_id'], $status);

    activityRepository()->logByUser(
        $userId,
        (int)$task['project_id'],
        'task.status_changed',
        'Cambió la tarea "' . $task['title'] . '" a ' . taskStatusLabel($status)
    );

    appJson([
        'success' => true,
        'task' => [
            'id' => $taskId,
            'status' => $status,
            'statusLabel' => taskStatusLabel($status),
        ],
    ]);
}

appMethod('GET');

$filterProject = (int)($_GET['project_id'] ?? 0);
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterPriority = trim((string)($_GET['priority'] ?? 'todas'));
$filterAssigned = trim((string)($_GET['assigned_to'] ?? 'todos'));
$search = trim((string)($_GET['search'] ?? ''));
$mine = ($_GET['mine'] ?? '') === '1';

$where = ["t.project_id {$in}"];
$params = [];
if ($filterProject > 0 && in_array($filterProject, $visibleIds, true)) {
    $where[] = 't.project_id = ?';
    $params[] = $filterProject;
}
if ($filterStatus !== '' && in_array($filterStatus, array_column(taskStatuses(), 'key'), true)) {
    $where[] = 't.status = ?';
    $params[] = $filterStatus;
}
if (in_array($filterPriority, ['baja', 'media', 'alta', 'urgente'], true)) {
    $where[] = 't.priority = ?';
    $params[] = $filterPriority;
}
if ($filterAssigned === 'sin_asignar') {
    $where[] = 't.assigned_user_id IS NULL AND (t.assigned_to IS NULL OR t.assigned_to = \'\')';
} elseif ($filterAssigned !== 'todos' && $filterAssigned !== '') {
    $where[] = 't.assigned_user_id = ?';
    $params[] = (int)$filterAssigned;
}
if ($mine) {
    $where[] = 't.assigned_user_id = ?';
    $params[] = $userId;
}
if ($search !== '') {
    $where[] = '(t.title LIKE ? OR t.description LIKE ? OR p.name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$statusCounts = array_fill_keys(array_column(taskStatuses(), 'key'), 0);
$stmtCounts = getDB()->prepare("
    SELECT t.status, COUNT(*) total
    FROM tasks t
    INNER JOIN projects p ON p.id = t.project_id
    {$whereSql}
    GROUP BY t.status
");
$stmtCounts->execute($params);
foreach ($stmtCounts->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($statusCounts[$row['status']])) $statusCounts[$row['status']] = (int)$row['total'];
}

$stmt = getDB()->prepare("
    SELECT t.id, t.title, t.description, t.status, t.priority, t.due_date, t.created_at, t.parent_task_id,
           t.assigned_user_id, t.sort_order,
           p.id AS project_id, p.name AS project_name, p.color AS project_color,
           p.agency_id AS agency_id, a.name AS agency_name,
           u.name AS assignee_name, t.assigned_to
    FROM tasks t
    INNER JOIN projects p ON p.id = t.project_id
    INNER JOIN agencies a ON a.id = p.agency_id
    LEFT JOIN users u ON u.id = t.assigned_user_id
    {$whereSql}
    ORDER BY t.sort_order ASC, t.id ASC
    LIMIT 500
");
$stmt->execute($params);

$tasks = [];
$taskRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Permisos de todos los proyectos del listado en 2 consultas (antes: 1-4 por cada tarea)
projectRepository()->primeAccessContexts(array_column($taskRows, "project_id"), $userId, $role);
foreach ($taskRows as $task) {
    $tasks[] = [
        'id' => (int)$task['id'],
        'title' => $task['title'],
        'description' => $task['description'],
        'status' => $task['status'],
        'statusLabel' => taskStatusLabel($task['status']),
        'priority' => $task['priority'],
        'dueDate' => $task['due_date'],
        'createdAt' => $task['created_at'],
        'projectId' => (int)$task['project_id'],
        'projectName' => $task['project_name'],
        'projectColor' => $task['project_color'] ?: '#0E66FF',
        'agencyId' => (int)$task['agency_id'],
        'agencyName' => $task['agency_name'],
        'assignee' => $task['assignee_name'] ?: $task['assigned_to'],
        'assignedUserId' => $task['assigned_user_id'] !== null ? (int)$task['assigned_user_id'] : null,
        'sortOrder' => (int)$task['sort_order'],
        'parentTaskId' => $task['parent_task_id'] !== null ? (int)$task['parent_task_id'] : null,
        'editable' => canEditProjectTasks((int)$task['project_id'], $userId, $role),
    ];
}

$collaborators = getDB()->query("
    SELECT DISTINCT u.id, u.name
    FROM users u
    WHERE u.id IN (SELECT user_id FROM project_members WHERE project_id IN (SELECT id FROM projects WHERE id {$in}))
       OR u.id IN (SELECT user_id FROM projects WHERE id {$in})
    ORDER BY u.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

appJson([
    'tasks' => $tasks,
    'statusCounts' => $statusCounts,
    'statuses' => array_map(static fn(array $status): array => [
        'key' => $status['key'],
        'label' => $status['label'],
        'kind' => $status['kind'],
    ], taskStatuses()),
    'collaborators' => array_map(static fn(array $user): array => ['id' => (int)$user['id'], 'name' => $user['name']], $collaborators),
    'projects' => array_values(array_map(static function (array $project): array {
        return [
            'id' => (int)$project['id'],
            'name' => $project['name'],
            'color' => $project['color'] ?: '#0E66FF',
        ];
    }, getDB()->query("SELECT id, name, color FROM projects WHERE id {$in} AND archived_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC))),
]);

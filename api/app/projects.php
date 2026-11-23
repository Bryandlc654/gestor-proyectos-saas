<?php
// api/app/projects.php - Listado y detalle de proyectos para React.
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        if (!canCreateProjects($role)) {
            appJson(['error' => 'Tu nivel de acceso no permite crear proyectos'], 403);
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $clientName = trim((string)($_POST['client_name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['activo', 'en_espera', 'completado'], true) ? (string)$_POST['status'] : 'activo';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : '#0E66FF';
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $deadline = trim((string)($_POST['deadline'] ?? ''));
        $budget = max(0, round((float)($_POST['budget'] ?? 0), 2));
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
        if (!isValidCurrency($currency)) {
            $currency = 'USD';
        }

        if ($name === '' || mb_strlen($name) > 150) {
            appJson(['error' => 'El nombre del proyecto es obligatorio (máximo 150 caracteres).'], 422);
        }
        if ($clientName !== '' && mb_strlen($clientName) > 150) {
            $clientName = mb_substr($clientName, 0, 150);
        }
        $category = $category === '' ? 'General' : mb_substr($category, 0, 50);
        foreach (['start_date' => $startDate, 'deadline' => $deadline] as $field => $value) {
            if ($value !== '' && !isIsoDate($value)) {
                appJson(['error' => 'La fecha de ' . ($field === 'start_date' ? 'inicio' : 'entrega') . ' no es válida.'], 422);
            }
        }
        if ($startDate !== '' && $deadline !== '' && $startDate > $deadline) {
            appJson(['error' => 'La fecha de inicio no puede ser posterior a la fecha de entrega.'], 422);
        }

        $teamRaw = $_POST['team'] ?? '[]';
        if (is_string($teamRaw)) {
            $decoded = json_decode($teamRaw, true);
            $teamRaw = is_array($decoded) ? $decoded : [];
        }
        $team = [];
        if (is_array($teamRaw)) {
            foreach ($teamRaw as $member) {
                if (!is_array($member)) continue;
                $memberId = (int)($member['id'] ?? $member['user_id'] ?? 0);
                $memberRole = (string)($member['role'] ?? 'colaborador');
                if ($memberId > 0 && in_array($memberRole, ['project_manager', 'colaborador', 'cliente'], true)) {
                    $team[$memberId] = $memberRole;
                }
            }
        }
        if ($team !== []) {
            $ids = array_keys($team);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtUsers = getDB()->prepare("SELECT id FROM users WHERE agency_id = ? AND id IN ($placeholders)");
            $stmtUsers->execute(array_merge([$agencyId], $ids));
            $validIds = array_flip(array_map('intval', $stmtUsers->fetchAll(PDO::FETCH_COLUMN)));
            $team = array_filter($team, static fn(int $memberId): bool => isset($validIds[$memberId]), ARRAY_FILTER_USE_KEY);
        }

        $db = getDB();
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO projects (agency_id, user_id, name, client_name, description, category, color, start_date, deadline, budget, currency, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $agencyId,
                $userId,
                $name,
                $clientName !== '' ? $clientName : null,
                $description,
                $category,
                $color,
                $startDate !== '' ? $startDate : null,
                $deadline !== '' ? $deadline : null,
                $budget,
                $currency,
                $status,
            ]);
            $newId = (int)$db->lastInsertId();

            $insertMember = $db->prepare('INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)');
            $insertMember->execute([$newId, $userId, 'project_manager']);
            foreach ($team as $memberId => $memberRole) {
                $insertMember->execute([$newId, $memberId, $memberRole]);
            }

            $db->commit();
            projectRepository()->clearCache();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        activityRepository()->logByUser($userId, $newId, 'project.created', 'Creó el proyecto "' . $name . '"');
        try {
            notificationService()->notifyAgencyAdmins(
                $agencyId,
                'project.created',
                'Nuevo proyecto creado',
                $user['name'] . ' creó el proyecto "' . $name . '".',
                '/project/' . $newId,
                true,
                $userId
            );
            foreach ($team as $memberId => $memberRole) {
                if ($memberId === $userId) continue;
                notificationService()->notifyUser(
                    $memberId,
                    $agencyId,
                    'project.member_added',
                    'Te añadieron a un proyecto',
                    'Ahora formas parte de "' . $name . '".',
                    '/project/' . $newId,
                    false
                );
            }
        } catch (\Throwable $e) {
            error_log('Notificación proyecto: ' . $e->getMessage());
        }

        appJson(['success' => true, 'projectId' => $newId, 'message' => 'Proyecto creado correctamente.']);
    }

    if ($action === 'archive') {
        $targetId = (int)($_POST['project_id'] ?? 0);
        if ($targetId <= 0 || !canManageProject($targetId, $userId, $role)) {
            appJson(['error' => 'No puedes archivar este proyecto'], 403);
        }
        if (!canDeleteProjects($role)) {
            appJson(['error' => 'No tienes permisos para archivar proyectos'], 403);
        }
        $stmtProject = getDB()->prepare('SELECT name, archived_at FROM projects WHERE id = ? AND agency_id = ?');
        $stmtProject->execute([$targetId, $agencyId]);
        $target = $stmtProject->fetch(PDO::FETCH_ASSOC);
        if ($target === false) {
            appJson(['error' => 'Proyecto no encontrado'], 404);
        }
        if ($target['archived_at'] !== null) {
            appJson(['error' => 'El proyecto ya estaba archivado.'], 409);
        }
        getDB()->prepare('UPDATE projects SET archived_at = NOW(), archived_by = ? WHERE id = ? AND agency_id = ? AND archived_at IS NULL')->execute([$userId, $targetId, $agencyId]);
        activityRepository()->logByUser($userId, $targetId, 'project.archived', 'Archivó el proyecto "' . $target['name'] . '"');
        appJson(['success' => true, 'message' => 'Proyecto archivado. Podrás restaurarlo desde la Papelera.']);
    }
    if ($action === 'update') {
        $targetId = (int)($_POST['project_id'] ?? 0);
        if ($targetId <= 0 || !canManageProject($targetId, $userId, $role)) {
            appJson(['error' => 'No puedes modificar este proyecto'], 403);
        }
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            appJson(['error' => 'Datos inválidos para actualizar el proyecto.'], 422);
        }
        $description = trim((string)($_POST['description'] ?? ''));
        $rawStatus = (string)($_POST['status'] ?? '');
        if ($rawStatus !== '' && !in_array($rawStatus, ['activo', 'en_espera', 'completado'], true)) {
            appJson(['error' => 'Estado de proyecto no válido.'], 422);
        }

        // Fechas: se validan igual que al crear y solo se tocan si el formulario las envía.
        $deadline = trim((string)($_POST['deadline'] ?? ''));
        if ($deadline !== '' && !isIsoDate($deadline)) {
            appJson(['error' => 'La fecha de entrega no es válida.'], 422);
        }
        $startDate = null;
        if (array_key_exists('start_date', $_POST)) {
            $startDate = trim((string)$_POST['start_date']);
            if ($startDate !== '' && !isIsoDate($startDate)) {
                appJson(['error' => 'La fecha de inicio no es válida.'], 422);
            }
        }
        $stmtCurrent = getDB()->prepare('SELECT start_date, deadline FROM projects WHERE id = ?');
        $stmtCurrent->execute([$targetId]);
        $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC) ?: [];
        $effectiveStart = $startDate !== null ? $startDate : (string)($current['start_date'] ?? '');
        if ($effectiveStart !== '' && $deadline !== '' && $effectiveStart > $deadline) {
            appJson(['error' => 'La fecha de inicio no puede ser posterior a la fecha de entrega.'], 422);
        }
        $deadline = $deadline !== '' ? $deadline : null;
        $budget = isset($_POST['budget']) ? (float)$_POST['budget'] : null;
        $currency = null;
        if (array_key_exists('currency', $_POST)) {
            $rawCurrency = strtoupper(trim((string)$_POST['currency']));
            if (isValidCurrency($rawCurrency)) {
                $currency = $rawCurrency;
            }
        }
        $category = array_key_exists('category', $_POST) ? trim((string)$_POST['category']) : null;
        if ($category !== null) {
            $category = $category === '' ? 'General' : mb_substr($category, 0, 50);
        }
        $color = (isset($_POST['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$_POST['color'])) ? (string)$_POST['color'] : null;

        $fields = ['name = ?', 'description = ?', 'deadline = ?'];
        $params = [$name, $description, $deadline];
        if ($rawStatus !== '') {
            $fields[] = 'status = ?';
            $params[] = $rawStatus;
        }
        if (array_key_exists('client_name', $_POST)) {
            $fields[] = 'client_name = ?';
            $params[] = trim((string)$_POST['client_name']) !== '' ? mb_substr(trim((string)$_POST['client_name']), 0, 150) : null;
        }
        if ($startDate !== null) {
            $fields[] = 'start_date = ?';
            $params[] = $startDate !== '' ? $startDate : null;
        }
        if ($budget !== null) {
            $fields[] = 'budget = ?';
            $params[] = max(0, $budget);
        }
        if ($currency !== null) {
            $fields[] = 'currency = ?';
            $params[] = $currency;
        }
        if ($category !== null) {
            $fields[] = 'category = ?';
            $params[] = $category;
        }
        if ($color !== null) {
            $fields[] = 'color = ?';
            $params[] = $color;
        }
        $params[] = $targetId;
        getDB()->prepare('UPDATE projects SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        activityRepository()->logByUser($userId, $targetId, 'project.updated', 'Actualizó el proyecto "' . $name . '"');
        appJson(['success' => true, 'message' => 'Proyecto actualizado correctamente.']);
    }

    if ($action === 'duplicate') {
        $targetId = (int)($_POST['project_id'] ?? 0);
        if ($targetId <= 0 || !canCreateProjects($role) || !canManageProject($targetId, $userId, $role)) {
            appJson(['error' => 'No tienes permisos para duplicar este proyecto'], 403);
        }
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $name = (projectRepository()->name($targetId) ?? 'Proyecto') . ' (copia)';
        }
        try {
            $newId = projectTemplateRepository()->duplicateProject($targetId, $agencyId, $userId, $name);
        } catch (\DomainException $e) {
            appJson(['error' => $e->getMessage()], 422);
        }
        activityRepository()->logByUser($userId, $newId, 'project.duplicated', 'Duplicó el proyecto como "' . $name . '"');
        appJson(['success' => true, 'projectId' => $newId, 'message' => 'Proyecto duplicado correctamente.']);
    }

    if ($action === 'save_template') {
        $targetId = (int)($_POST['project_id'] ?? 0);
        if ($targetId <= 0 || !in_array($role, ['admin', 'project_manager'], true) || !canManageProject($targetId, $userId, $role)) {
            appJson(['error' => 'No tienes permisos para guardar este proyecto como plantilla'], 403);
        }
        if (!moduleAllowed('templates')) {
            appJson(['error' => 'Módulo de plantillas no disponible'], 403);
        }
        $templateName = trim((string)($_POST['template_name'] ?? ''));
        try {
            $templateId = projectTemplateRepository()->saveFromProject($targetId, $agencyId, $userId, $templateName);
        } catch (\DomainException $e) {
            appJson(['error' => $e->getMessage()], 422);
        }
        activityRepository()->logByUser($userId, null, 'template.created', 'Guardó la plantilla "' . $templateName . '"');
        appJson(['success' => true, 'templateId' => $templateId, 'message' => 'Plantilla guardada con tareas y checklists.']);
    }

    appJson(['error' => 'Acción no válida'], 400);
}

appMethod('GET');

$visibleIds = array_values(array_map('intval', projectRepository()->visibleProjectIds($userId, $role)));
if ($visibleIds === []) {
    appJson(['projects' => [], 'project' => null, 'team' => [], 'categories' => [], 'clients' => []]);
}

$in = 'IN (' . implode(',', $visibleIds) . ')';
$projectId = (int)($_GET['id'] ?? 0);

if ($projectId > 0) {
    if (!in_array($projectId, $visibleIds, true)) {
        appJson(['error' => 'Sin acceso al proyecto'], 403);
    }

    $stmt = getDB()->prepare("
        SELECT p.*, a.name AS agency_name
        FROM projects p
        INNER JOIN agencies a ON a.id = p.agency_id
        WHERE p.id = ?
    ");
    $stmt->execute([$projectId]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($project === false) {
        appJson(['error' => 'Proyecto no encontrado'], 404);
    }

    $isOwnerAgency = (int)$project['agency_id'] === $agencyId;
    $canManage = canManageProject($projectId, $userId, $role);

    $stmtMembers = getDB()->prepare("
        SELECT u.id, u.name, u.email, u.role, u.avatar, pm.role AS project_role
        FROM project_members pm
        INNER JOIN users u ON u.id = pm.user_id
        WHERE pm.project_id = ?
        ORDER BY u.name ASC
    ");
    $stmtMembers->execute([$projectId]);
    $members = [];
    foreach ($stmtMembers->fetchAll(PDO::FETCH_ASSOC) as $member) {
        $avatar = (string)($member['avatar'] ?? '');
        $members[] = [
            'id' => (int)$member['id'],
            'name' => $member['name'],
            'email' => $member['email'],
            'role' => $member['role'],
            'projectRole' => $member['project_role'] ?? null,
            'avatarUrl' => $avatar !== '' ? '/' . ltrim($avatar, '/') : null,
        ];
    }

    $stmtTasks = getDB()->prepare("
        SELECT t.*, u.name AS assignee_name,
               (SELECT COUNT(*) FROM comments c WHERE c.task_id = t.id) AS comments_count
        FROM tasks t
        LEFT JOIN users u ON u.id = t.assigned_user_id
        WHERE t.project_id = ?
        ORDER BY (t.due_date IS NULL), t.due_date ASC, t.id DESC
        LIMIT 300
    ");
    $stmtTasks->execute([$projectId]);
    $tasks = [];
    $doneStatuses = array_flip(taskStatusKeys('done'));
    $doneCount = 0;
    foreach ($stmtTasks->fetchAll(PDO::FETCH_ASSOC) as $task) {
        if (isset($doneStatuses[$task['status']])) {
            $doneCount++;
        }
        $tasks[] = [
            'id' => (int)$task['id'],
            'title' => $task['title'],
            'description' => $task['description'],
            'status' => $task['status'],
            'statusLabel' => taskStatusLabel($task['status']),
            'priority' => $task['priority'],
            'dueDate' => $task['due_date'],
            'startDate' => $task['start_date'] ?? null,
            'assignee' => $task['assignee_name'],
            'assignedUserId' => $task['assigned_user_id'] !== null ? (int)$task['assigned_user_id'] : null,
            'commentsCount' => (int)$task['comments_count'],
            'sortOrder' => (int)($task['sort_order'] ?? 0),
        ];
    }

    $checklistTotal = 0;
    $checklistDone = 0;
    try {
        $stmtChecklist = getDB()->prepare("
            SELECT COUNT(ci.id) AS total, COALESCE(SUM(ci.is_completed), 0) AS done
            FROM checklist_items ci
            INNER JOIN checklists c ON c.id = ci.checklist_id
            WHERE c.project_id = ?
        ");
        $stmtChecklist->execute([$projectId]);
        $checklistRow = $stmtChecklist->fetch(PDO::FETCH_ASSOC) ?: [];
        $checklistTotal = (int)($checklistRow['total'] ?? 0);
        $checklistDone = (int)($checklistRow['done'] ?? 0);
    } catch (\Throwable $e) {
        error_log('Checklist progreso: ' . $e->getMessage());
    }
    // Los totales se cuentan en SQL: la lista de tareas está limitada a 300 filas.
    $taskTotal = count($tasks);
    if ($taskTotal >= 300) {
        $doneKeysDetail = taskStatusKeys('done');
        $doneIn = $doneKeysDetail === [] ? "'__ninguna__'" : implode(',', array_fill(0, count($doneKeysDetail), '?'));
        $stmtTaskCounts = getDB()->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status IN ($doneIn)), 0) AS done FROM tasks WHERE project_id = ?");
        $stmtTaskCounts->execute([...$doneKeysDetail, $projectId]);
        $taskCounts = $stmtTaskCounts->fetch(PDO::FETCH_ASSOC) ?: [];
        $taskTotal = (int)($taskCounts['total'] ?? $taskTotal);
        $doneCount = (int)($taskCounts['done'] ?? $doneCount);
    }
    $overallTotal = $taskTotal + $checklistTotal;
    $overallDone = $doneCount + $checklistDone;

    appJson([
        'project' => [
            'id' => (int)$project['id'],
            'name' => $project['name'],
            'clientName' => $project['client_name'] ?? null,
            'description' => $project['description'],
            'status' => $project['status'],
            'category' => $project['category'],
            'color' => $project['color'] ?: '#0E66FF',
            'currency' => $project['currency'] ?: 'USD',
            'agencyName' => $project['agency_name'],
            'isOwnerAgency' => $isOwnerAgency,
            'shared' => !$isOwnerAgency,
            'canManage' => $canManage,
            'canEditTasks' => canEditProjectTasks($projectId, $userId, $role),
            'canManageShare' => canManageProjectShare($projectId, $userId, $role),
            'canManageShareMembers' => canManageProjectShareMembers($projectId, $userId, $role),
            'sharedWithCount' => $isOwnerAgency ? count(projectShareRepository()->forProject($projectId)) : 0,
            'sharedBy' => !$isOwnerAgency && !isCliente() ? $project['agency_name'] : null,
            'budget' => $isOwnerAgency && $canManage ? (float)$project['budget'] : null,
            'startDate' => $project['start_date'] ?? null,
            'dueDate' => $project['deadline'] ?? null,
            'createdAt' => $project['created_at'] ?? null,
        ],
        'members' => $members,
        'tasks' => $tasks,
        'stats' => [
            'total' => $taskTotal,
            'done' => $doneCount,
            'progress' => $overallTotal > 0 ? (int)round(($overallDone / $overallTotal) * 100) : 0,
            'checklistTotal' => $checklistTotal,
            'checklistDone' => $checklistDone,
        ],
        'isClient' => isCliente(),
        'canManageChecklists' => canManageProjectChecklists($projectId, $userId, $role),
        'canChangeStatus' => canChangeProjectTaskStatus($projectId, $userId, $role),
        'canDeleteTasks' => canDeleteProjectTasks($projectId, $userId, $role),
        'statuses' => array_map(static fn(array $status): array => [
            'key' => $status['key'],
            'label' => $status['label'],
            'kind' => $status['kind'],
        ], taskStatuses()),
    ]);
}

$doneKeys = taskStatusKeys('done');
$donePlaceholders = implode(',', array_fill(0, count($doneKeys), '?'));
$closedSql = $doneKeys === []
    ? '0'
    : "SUM(CASE WHEN t.status IN ({$donePlaceholders}) THEN 1 ELSE 0 END)";
$overdueSql = $doneKeys === []
    ? 'SUM(CASE WHEN t.due_date IS NOT NULL AND t.due_date < CURDATE() THEN 1 ELSE 0 END)'
    : "SUM(CASE WHEN t.due_date IS NOT NULL AND t.due_date < CURDATE() AND t.status NOT IN ({$donePlaceholders}) THEN 1 ELSE 0 END)";

$stmt = getDB()->prepare("
    SELECT p.id, p.name, p.client_name, p.status, p.category, p.color, p.agency_id, p.currency, p.start_date, p.deadline, p.budget,
           a.name AS agency_name,
           COUNT(t.id) AS total_tasks,
           {$closedSql} AS closed_tasks,
           {$overdueSql} AS overdue_tasks,
           (SELECT COUNT(*) FROM project_members pm WHERE pm.project_id = p.id) AS members_count,
           (SELECT COUNT(ci.id) FROM checklist_items ci INNER JOIN checklists cl ON cl.id = ci.checklist_id WHERE cl.project_id = p.id) AS checklist_total,
           (SELECT COALESCE(SUM(ci.is_completed), 0) FROM checklist_items ci INNER JOIN checklists cl ON cl.id = ci.checklist_id WHERE cl.project_id = p.id) AS checklist_done
    FROM projects p
    INNER JOIN agencies a ON a.id = p.agency_id
    LEFT JOIN tasks t ON t.project_id = p.id
    WHERE p.id {$in} AND p.archived_at IS NULL
    GROUP BY p.id
    ORDER BY p.updated_at DESC
");
$stmt->execute(array_merge($doneKeys, $doneKeys));

$canSeeBudget = in_array($role, ['admin', 'project_manager'], true);
$projects = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $project) {
    $total = (int)$project['total_tasks'];
    $closed = (int)$project['closed_tasks'];
    // Mismo cálculo que el detalle del proyecto: tareas + pasos de checklist
    $progressTotal = $total + (int)$project['checklist_total'];
    $progressDone = $closed + (int)$project['checklist_done'];
    $projects[] = [
        'id' => (int)$project['id'],
        'name' => $project['name'],
        'clientName' => $project['client_name'] ?? null,
        'status' => $project['status'],
        'category' => $project['category'],
        'color' => $project['color'] ?: '#0E66FF',
        'agencyName' => $project['agency_name'],
        'shared' => (int)$project['agency_id'] !== $agencyId,
        'currency' => $project['currency'] ?: 'USD',
        'startDate' => $project['start_date'] ?? null,
        'dueDate' => $project['deadline'] ?? null,
        'budget' => $canSeeBudget && (int)$project['agency_id'] === $agencyId ? (float)$project['budget'] : null,
        'tasks' => $total,
        'members' => (int)$project['members_count'],
        'overdueTasks' => (int)$project['overdue_tasks'],
        'progress' => $progressTotal > 0 ? (int)round(($progressDone / $progressTotal) * 100) : 0,
    ];
}

$team = [];
$categories = [];
$clients = [];
if (in_array($role, ['admin', 'project_manager'], true)) {
    $stmtTeam = getDB()->prepare("SELECT id, name, email, role FROM users WHERE agency_id = ? ORDER BY name ASC");
    $stmtTeam->execute([$agencyId]);
    $team = array_map(static fn(array $member): array => [
        'id' => (int)$member['id'],
        'name' => $member['name'],
        'email' => $member['email'],
        'role' => $member['role'],
    ], $stmtTeam->fetchAll(PDO::FETCH_ASSOC));

    $stmtCategories = getDB()->prepare("SELECT DISTINCT category FROM projects WHERE agency_id = ? AND category IS NOT NULL AND category <> '' ORDER BY category ASC");
    $stmtCategories->execute([$agencyId]);
    $categories = $stmtCategories->fetchAll(PDO::FETCH_COLUMN);

    $stmtClients = getDB()->prepare("
        SELECT name FROM crm_companies WHERE agency_id = ? AND name <> ''
        UNION SELECT name FROM crm_contacts WHERE agency_id = ? AND name <> ''
        UNION SELECT client_name AS name FROM projects WHERE agency_id = ? AND client_name IS NOT NULL AND client_name <> ''
        UNION SELECT i.client_name AS name FROM invoices i INNER JOIN projects p ON p.id = i.project_id WHERE p.agency_id = ? AND i.client_name IS NOT NULL AND i.client_name <> ''
        ORDER BY name ASC LIMIT 300
    ");
    $stmtClients->execute([$agencyId, $agencyId, $agencyId, $agencyId]);
    $clients = $stmtClients->fetchAll(PDO::FETCH_COLUMN);
}

appJson(['projects' => $projects, 'team' => $team, 'categories' => $categories, 'clients' => $clients]);

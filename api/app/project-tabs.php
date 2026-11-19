<?php
// api/app/project-tabs.php - Comentarios, adjuntos, tickets, checklists y actividad de un proyecto.
require_once __DIR__ . '/_bootstrap.php';

appMethod('GET');

$projectId = (int)($_GET['id'] ?? 0);
if ($projectId <= 0 || !canAccessProject($projectId, $userId, $role)) {
    appJson(['error' => 'Sin acceso al proyecto'], 403);
}

$db = getDB();

// Mismas reglas de visibilidad que la vista clásica (project-view.php):
// - Cliente: solo sus notas (y las respuestas a ellas) y sus propios archivos; sin enlaces técnicos ni checklists.
// - Documentos de factura: nunca aquí (viven en Finanzas, solo para quien gestiona el proyecto).
// - Registro de actividad: solo administradores y PM de la agencia dueña.
$projectPermissions = projectRepository()->permissionsFor($projectId, $userId, $role) ?? [];
$isClientView = isCliente();
$showActivity = (bool)($projectPermissions['show_activity'] ?? false);

$comments = [];
$commentScope = '';
$commentParams = [$projectId];
if ($isClientView) {
    $commentScope = 'AND (c.user_id = ? OR c.parent_id IN (SELECT own.id FROM comments own WHERE own.user_id = ? AND own.project_id = ?))';
    array_push($commentParams, $userId, $userId, $projectId);
}
$stmt = $db->prepare("
    SELECT c.*, u.name AS user_name, u.avatar, t.title AS task_title
    FROM comments c
    LEFT JOIN users u ON u.id = c.user_id
    LEFT JOIN tasks t ON t.id = c.task_id
    WHERE c.project_id = ? {$commentScope}
    ORDER BY c.created_at ASC, c.id ASC
    LIMIT 200
");
$stmt->execute($commentParams);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $avatar = (string)($row['avatar'] ?? '');
    $comments[] = [
        'id' => (int)$row['id'],
        'parentId' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
        'taskId' => $row['task_id'] !== null ? (int)$row['task_id'] : null,
        'taskTitle' => $row['task_title'],
        'userId' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
        'content' => $row['content'],
        'imagePath' => $row['image_path'] ? '/uploads/' . ltrim($row['image_path'], '/') : null,
        'user' => $row['user_name'] ?? 'Sistema',
        'avatarUrl' => $avatar !== '' ? '/' . ltrim($avatar, '/') : null,
        'createdAt' => $row['created_at'],
    ];
}

$files = [];
$stmt = $db->prepare("
    SELECT f.*, u.name AS user_name, tk.title AS task_title
    FROM project_files f
    LEFT JOIN users u ON u.id = f.user_id
    LEFT JOIN tasks tk ON tk.id = f.task_id
    WHERE f.project_id = ? AND f.invoice_id IS NULL" . ($isClientView ? ' AND f.user_id = ?' : '') . "
    ORDER BY f.created_at DESC
    LIMIT 100
");
$stmt->execute($isClientView ? [$projectId, $userId] : [$projectId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $files[] = [
        'id' => (int)$row['id'],
        'name' => $row['original_name'],
        'size' => (int)$row['file_size'],
        'type' => $row['file_type'],
        'user' => $row['user_name'],
        'userId' => $row['user_id'] !== null ? (int)$row['user_id'] : null,
        'folderId' => $row['folder_id'] !== null ? (int)$row['folder_id'] : null,
        'taskId' => !empty($row['task_id']) ? (int)$row['task_id'] : null,
        'taskTitle' => $row['task_title'] ?? null,
        'createdAt' => $row['created_at'],
        'downloadUrl' => '/api/files.php?action=download&id=' . (int)$row['id'],
    ];
}

$folders = [];
$stmt = getDB()->prepare('SELECT * FROM project_folders WHERE project_id = ? ORDER BY name ASC');
$stmt->execute([$projectId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $folders[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'color' => $row['color']];
}

$links = [];
$stmt = $db->prepare('SELECT l.*, tk.title AS task_title FROM project_links l LEFT JOIN tasks tk ON tk.id = l.task_id WHERE l.project_id = ? ORDER BY l.created_at DESC LIMIT 100');
$stmt->execute([$projectId]);
foreach ($isClientView ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $links[] = [
        'id' => (int)$row['id'], 'title' => $row['title'], 'url' => $row['url'], 'createdAt' => $row['created_at'],
        'taskId' => !empty($row['task_id']) ? (int)$row['task_id'] : null,
        'taskTitle' => $row['task_title'] ?? null,
    ];
}

$tickets = [];$stmt = $db->prepare("
    SELECT t.*, u.name AS user_name,
           (SELECT COUNT(*) FROM ticket_replies tr WHERE tr.ticket_id = t.id) AS replies_count
    FROM tickets t
    LEFT JOIN users u ON u.id = t.user_id
    WHERE t.project_id = ?
    ORDER BY (t.status IN ('resuelto', 'cerrado')), t.created_at DESC
    LIMIT 100
");
$stmt->execute([$projectId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $tickets[] = [
        'id' => (int)$row['id'],
        'title' => $row['title'],
        'description' => $row['description'],
        'priority' => $row['priority'],
        'status' => $row['status'],
        'user' => $row['user_name'],
        'createdAt' => $row['created_at'],
        'externalSource' => $row['external_source'] ?? null,
        'externalRequesterName' => $row['external_requester_name'] ?? null,
        'externalUrl' => $row['external_url'] ?? null,
        'repliesCount' => (int)($row['replies_count'] ?? 0),
    ];
}

$activity = [];
foreach ($showActivity ? activityRepository()->forProject($projectId, 60) : [] as $row) {
    $activity[] = [
        'id' => (int)$row['id'],
        'label' => $row['label'] ?? $row['action'],
        'description' => $row['description'],
        'user' => $row['user_name'] ?? null,
        'createdAt' => $row['created_at'],
    ];
}

$itemsByChecklist = [];
$stmtItems = getDB()->prepare("
    SELECT ci.*
    FROM checklist_items ci
    INNER JOIN checklists c ON c.id = ci.checklist_id
    WHERE c.project_id = ?
    ORDER BY ci.id ASC
");
$stmtItems->execute([$projectId]);
foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
    $itemsByChecklist[(int)$item['checklist_id']][] = [
        'id' => (int)$item['id'],
        'title' => $item['title'],
        'isCompleted' => (int)$item['is_completed'] === 1,
    ];
}

$checklists = [];
$stmtChecklists = getDB()->prepare("
    SELECT c.*, t.title AS task_title
    FROM checklists c
    LEFT JOIN tasks t ON t.id = c.task_id
    WHERE c.project_id = ?
    ORDER BY c.id DESC
");
$stmtChecklists->execute([$projectId]);
foreach ($isClientView ? [] : $stmtChecklists->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $items = $itemsByChecklist[(int)$row['id']] ?? [];
    $completed = count(array_filter($items, static fn(array $item): bool => $item['isCompleted']));
    $total = count($items);
    $checklists[] = [
        'id' => (int)$row['id'],
        'title' => $row['title'],
        'taskId' => $row['task_id'] !== null ? (int)$row['task_id'] : null,
        'taskTitle' => $row['task_title'],
        'items' => $items,
        'completed' => $completed,
        'total' => $total,
        'percentage' => $total > 0 ? (int)round(($completed / $total) * 100) : 0,
    ];
}

appJson([
    'comments' => $comments,
    'files' => $files,
    'folders' => $folders,
    'links' => $links,
    'tickets' => $tickets,
    'checklists' => $checklists,
    'activity' => $activity,
    'permissions' => [
        'canComment' => canCommentOnProject($projectId, $userId, $role),
        'canManageStructure' => canManageProjectStructure($projectId, $userId, $role),
        'canCreateTickets' => canCreateProjectTickets($projectId, $userId, $role),
        'canUploadFiles' => canManageProjectTaskAttachments($projectId, $userId, $role),
        'canManageChecklists' => canManageProjectChecklists($projectId, $userId, $role),
        'canChangeTicketStatus' => canChangeProjectTicketStatus($projectId, $userId, $role),
        'canDeleteTickets' => canDeleteProjectTickets($projectId, $userId, $role),
        'canModerateComments' => canModerateProjectContent($projectId, $userId, $role),
    ],
    'currentUserId' => $userId,
    'isClient' => isCliente(),
]);

<?php
// api/app/milestones.php - Hitos del proyecto (crear, marcar como cumplido y eliminar).
// Leer los hitos se hace desde project-insights.php; aquí solo escrituras.
require_once __DIR__ . '/_bootstrap.php';

appMethod('POST');

$projectId = (int)($_POST['project_id'] ?? 0);
if ($projectId <= 0 || !canAccessProject($projectId, $userId, $role)) {
    appJson(['error' => 'Sin acceso al proyecto'], 403);
}
if (!canManageProject($projectId, $userId, $role)) {
    appJson(['error' => 'Solo quien gestiona el proyecto puede modificar sus hitos'], 403);
}

$db = getDB();
$action = (string)($_POST['action'] ?? '');

if ($action === 'create') {
    $title = trim((string)($_POST['title'] ?? ''));
    $dueDate = trim((string)($_POST['due_date'] ?? ''));
    if ($title === '' || mb_strlen($title) > 150) {
        appJson(['error' => 'El nombre del hito es obligatorio (máximo 150 caracteres).'], 422);
    }
    if (!isIsoDate($dueDate)) {
        appJson(['error' => 'Indica una fecha válida para el hito.'], 422);
    }
    $db->prepare('INSERT INTO project_milestones (project_id, title, due_date, created_by) VALUES (?, ?, ?, ?)')
        ->execute([$projectId, $title, $dueDate, $userId]);
    $id = (int)$db->lastInsertId();
    activityRepository()->logByUser($userId, $projectId, 'milestone.created', 'Creó el hito "' . $title . '" para el ' . $dueDate);
    appJson(['success' => true, 'id' => $id, 'message' => 'Hito creado.']);
}

$milestoneId = (int)($_POST['milestone_id'] ?? 0);
$stmt = $db->prepare('SELECT id, title FROM project_milestones WHERE id = ? AND project_id = ?');
$stmt->execute([$milestoneId, $projectId]);
$milestone = $stmt->fetch(PDO::FETCH_ASSOC);
if ($milestone === false) {
    appJson(['error' => 'Hito no encontrado'], 404);
}

if ($action === 'toggle') {
    $done = !empty($_POST['is_done']) && $_POST['is_done'] !== '0' ? 1 : 0;
    $db->prepare('UPDATE project_milestones SET is_done = ? WHERE id = ?')->execute([$done, $milestoneId]);
    if ($done) {
        activityRepository()->logByUser($userId, $projectId, 'milestone.done', 'Marcó como cumplido el hito "' . $milestone['title'] . '"');
    }
    appJson(['success' => true, 'message' => $done ? 'Hito cumplido.' : 'Hito reabierto.']);
}

if ($action === 'delete') {
    $db->prepare('DELETE FROM project_milestones WHERE id = ?')->execute([$milestoneId]);
    activityRepository()->logByUser($userId, $projectId, 'milestone.deleted', 'Eliminó el hito "' . $milestone['title'] . '"');
    appJson(['success' => true, 'message' => 'Hito eliminado.']);
}

appJson(['error' => 'Acción no válida'], 400);

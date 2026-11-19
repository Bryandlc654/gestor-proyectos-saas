<?php
// api/app/baselines.php - Líneas base del cronograma (front React).
// GET  ?project_id=&baseline_id=  -> lista de líneas base y fechas de la seleccionada (por defecto la más reciente)
// POST action=save (name) | delete (baseline_id)
require_once __DIR__ . '/_bootstrap.php';

$projectId = (int)($_POST['project_id'] ?? ($_GET['project_id'] ?? 0));
if ($projectId <= 0 || !canAccessProject($projectId, $userId, $role)) {
    appJson(['error' => 'Sin acceso al proyecto'], 403);
}

$db = getDB();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!canManageProject($projectId, $userId, $role)) {
        appJson(['error' => 'Solo los administradores y project managers pueden gestionar líneas base.'], 403);
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            $name = 'Línea base ' . date('d/m/Y H:i');
        }
        $stmt = $db->prepare('SELECT id, start_date, due_date FROM tasks WHERE project_id = ? AND (start_date IS NOT NULL OR due_date IS NOT NULL)');
        $stmt->execute([$projectId]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($tasks === []) {
            appJson(['error' => 'No hay tareas con fechas para guardar en la línea base.'], 422);
        }

        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO project_baselines (project_id, name, created_by) VALUES (?, ?, ?)')
                ->execute([$projectId, mb_substr($name, 0, 120), $userId]);
            $baselineId = (int)$db->lastInsertId();
            $insert = $db->prepare('INSERT INTO baseline_tasks (baseline_id, task_id, start_date, due_date) VALUES (?, ?, ?, ?)');
            foreach ($tasks as $task) {
                $start = $task['start_date'] ?: $task['due_date'];
                $due = $task['due_date'] ?: $task['start_date'];
                $insert->execute([$baselineId, (int)$task['id'], $start, $due]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Línea base: ' . $e->getMessage());
            appJson(['error' => 'No se pudo guardar la línea base.'], 500);
        }
        activityRepository()->logByUser($userId, $projectId, 'project.baseline_created', 'Guardó la línea base "' . $name . '"');
        appJson(['success' => true, 'baselineId' => $baselineId, 'message' => 'Línea base guardada.']);
    }

    if ($action === 'delete') {
        $baselineId = (int)($_POST['baseline_id'] ?? 0);
        $stmt = $db->prepare('SELECT id, name FROM project_baselines WHERE id = ? AND project_id = ?');
        $stmt->execute([$baselineId, $projectId]);
        $baseline = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($baseline === false) {
            appJson(['error' => 'Línea base no encontrada.'], 404);
        }
        $db->prepare('DELETE FROM project_baselines WHERE id = ? AND project_id = ?')->execute([$baselineId, $projectId]);
        activityRepository()->logByUser($userId, $projectId, 'project.baseline_deleted', 'Eliminó la línea base "' . $baseline['name'] . '"');
        appJson(['success' => true, 'message' => 'Línea base eliminada.']);
    }

    appJson(['error' => 'Acción no válida'], 400);
}

appMethod('GET');

$stmt = $db->prepare('
    SELECT b.id, b.name, b.created_at, u.name AS author_name
    FROM project_baselines b
    LEFT JOIN users u ON u.id = b.created_by
    WHERE b.project_id = ?
    ORDER BY b.created_at DESC, b.id DESC
');
$stmt->execute([$projectId]);
$baselines = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'name' => $row['name'],
    'createdAt' => $row['created_at'],
    'author' => $row['author_name'],
], $stmt->fetchAll(PDO::FETCH_ASSOC));

$requested = (int)($_GET['baseline_id'] ?? 0);
$selected = null;
foreach ($baselines as $baseline) {
    if ($requested > 0 && $baseline['id'] === $requested) {
        $selected = $baseline;
        break;
    }
}
if ($selected === null && $baselines !== []) {
    $selected = $baselines[0];
}

$tasks = [];
if ($selected !== null) {
    $stmt = $db->prepare('SELECT task_id, start_date, due_date FROM baseline_tasks WHERE baseline_id = ?');
    $stmt->execute([$selected['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['start_date']) && !empty($row['due_date'])) {
            $tasks[(string)(int)$row['task_id']] = ['start' => $row['start_date'], 'due' => $row['due_date']];
        }
    }
}

appJson([
    'baselines' => $baselines,
    'selectedId' => $selected['id'] ?? null,
    'tasks' => (object)$tasks,
    'canManage' => canManageProject($projectId, $userId, $role),
]);

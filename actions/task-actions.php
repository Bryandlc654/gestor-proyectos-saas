<?php
// actions/task-actions.php
require_once __DIR__ . '/../config/app.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

$user = currentUser();
$db = getDB();
$action = $_POST['action'] ?? '';
$csrf = $_POST['csrf_token'] ?? '';
$projectId = (int)($_POST['project_id'] ?? 0);

if (!verify_csrf($csrf)) {
    setFlash('error', 'Token de seguridad inválido.');
    header("Location: ../project-view.php?id={$projectId}#tasks");
    exit;
}

// Verificar acceso al proyecto según rol (propietario, miembro o admin)
if (!canAccessProject($projectId, $user['id'], $user['role'])) {
    setFlash('error', 'Acceso no autorizado.');
    header('Location: ../dashboard.php');
    exit;
}

// Permisos por acción según rol
$allowed = match ($action) {
    'create'        => canManageTasks($user['role']),
    'update_status' => canChangeTaskStatus($user['role']),
    'delete'        => canDeleteTasks($user['role']),
    default         => false,
};
if (!$allowed) {
    setFlash('error', 'Tu nivel de acceso no permite realizar esta acción.');
    header("Location: ../project-view.php?id={$projectId}#tasks");
    exit;
}

switch ($action) {
    case 'create':
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['baja', 'media', 'alta', 'urgente']) ? $_POST['priority'] : 'media';
        $assignedTo = trim($_POST['assigned_to'] ?? '');
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;

        if (empty($title)) {
            setFlash('error', 'El título de la tarea es obligatorio.');
        } else {
            $stmt = $db->prepare("INSERT INTO tasks (project_id, title, description, priority, status, assigned_to, due_date) VALUES (?, ?, ?, ?, 'pendiente', ?, ?)");
            $stmt->execute([$projectId, $title, $description, $priority, $assignedTo ?: null, $dueDate]);
            setFlash('success', 'Tarea creada exitosamente.');
        }
        break;

    case 'update_status':
        $taskId = (int)($_POST['task_id'] ?? 0);
        $newStatus = in_array($_POST['status'] ?? '', ['pendiente', 'en_progreso', 'completada']) ? $_POST['status'] : 'pendiente';

        if ($taskId > 0) {
            $stmt = $db->prepare("UPDATE tasks SET status = ? WHERE id = ? AND project_id = ?");
            $stmt->execute([$newStatus, $taskId, $projectId]);
            setFlash('success', 'Estado de la tarea actualizado.');
        }
        break;

    case 'delete':
        $taskId = (int)($_POST['task_id'] ?? 0);
        if ($taskId > 0) {
            $stmt = $db->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?");
            $stmt->execute([$taskId, $projectId]);
            setFlash('success', 'Tarea eliminada.');
        }
        break;
}

header("Location: ../project-view.php?id={$projectId}#tasks");
exit;

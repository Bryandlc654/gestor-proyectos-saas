<?php
// api/checklists.php - Gestión asíncrona de checklists y pasos
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

// Verificar pertenencia o acceso al proyecto según rol
if (!canAccessProject($projectId, $user['id'], $user['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso denegado al proyecto']);
    exit;
}

// Los clientes solo tienen permisos de lectura en checklists
if (!canManageChecklists($user['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Los clientes tienen acceso de solo lectura en las listas de verificación']);
    exit;
}

switch ($action) {
    case 'create_checklist':
        $title = trim($_POST['title'] ?? '');
        $taskId = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;

        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'El título de la lista es obligatorio']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO checklists (project_id, task_id, title) VALUES (?, ?, ?)");
        $stmt->execute([$projectId, $taskId, $title]);
        $newId = (int)$db->lastInsertId();

        $taskTitle = null;
        if ($taskId) {
            $stT = $db->prepare("SELECT title FROM tasks WHERE id = ?");
            $stT->execute([$taskId]);
            $taskTitle = $stT->fetchColumn();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Lista de verificación creada',
            'checklist' => [
                'id' => $newId,
                'task_id' => $taskId,
                'task_title' => $taskTitle ? e($taskTitle) : null,
                'title' => e($title),
            ]
        ]);
        break;

    case 'delete_checklist':
        $checklistId = (int)($_POST['checklist_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM checklists WHERE id = ? AND project_id = ?");
        $stmt->execute([$checklistId, $projectId]);

        echo json_encode([
            'success' => true,
            'message' => 'Lista eliminada',
            'checklist_id' => $checklistId
        ]);
        break;

    case 'add_item':
        $checklistId = (int)($_POST['checklist_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if ($checklistId <= 0 || empty($title)) {
            echo json_encode(['success' => false, 'message' => 'El nombre del paso es obligatorio']);
            exit;
        }

        $checkOwner = $db->prepare("SELECT id FROM checklists WHERE id = ? AND project_id = ?");
        $checkOwner->execute([$checklistId, $projectId]);
        if (!$checkOwner->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Lista no encontrada']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO checklist_items (checklist_id, title, is_completed) VALUES (?, ?, 0)");
        $stmt->execute([$checklistId, $title]);
        $itemId = (int)$db->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Paso agregado',
            'item' => [
                'id' => $itemId,
                'checklist_id' => $checklistId,
                'title' => e($title),
                'is_completed' => 0
            ]
        ]);
        break;

    case 'toggle_item':
        $itemId = (int)($_POST['item_id'] ?? 0);
        $isCompleted = !empty($_POST['is_completed']) ? 1 : 0;

        $stmt = $db->prepare("
            UPDATE checklist_items ci
            INNER JOIN checklists c ON ci.checklist_id = c.id
            SET ci.is_completed = ?
            WHERE ci.id = ? AND c.project_id = ?
        ");
        $stmt->execute([$isCompleted, $itemId, $projectId]);

        echo json_encode([
            'success' => true,
            'item_id' => $itemId,
            'is_completed' => $isCompleted
        ]);
        break;

    case 'delete_item':
        $itemId = (int)($_POST['item_id'] ?? 0);
        $stmt = $db->prepare("
            DELETE ci FROM checklist_items ci
            INNER JOIN checklists c ON ci.checklist_id = c.id
            WHERE ci.id = ? AND c.project_id = ?
        ");
        $stmt->execute([$itemId, $projectId]);

        echo json_encode([
            'success' => true,
            'message' => 'Paso eliminado',
            'item_id' => $itemId
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        break;
}
exit;

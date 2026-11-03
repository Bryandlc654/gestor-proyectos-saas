<?php
// api/links.php - Gestión asíncrona de enlaces
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

if (!canManageAttachments($user['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Los clientes tienen acceso de solo lectura']);
    exit;
}

switch ($action) {
    case 'create':
        $title = trim($_POST['title'] ?? '');
        $url = trim($_POST['url'] ?? '');

        if (empty($title) || empty($url)) {
            echo json_encode(['success' => false, 'message' => 'Título y URL requeridos']);
            exit;
        }

        if (!isSafeUrl($url)) {
            echo json_encode(['success' => false, 'message' => 'URL no válida (incluye http:// o https://)']);
            exit;
        }

        $folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
        $taskId = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;

        if (($folderId && !folderBelongsToProject($folderId, $projectId)) || ($taskId && !taskBelongsToProject($taskId, $projectId))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'La carpeta o tarea no pertenece a este proyecto']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO project_links (project_id, folder_id, task_id, title, url) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$projectId, $folderId, $taskId, $title, $url]);
        $linkId = (int)$db->lastInsertId();

        $taskTitle = null;
        if ($taskId) {
            $stT = $db->prepare("SELECT title FROM tasks WHERE id = ?");
            $stT->execute([$taskId]);
            $taskTitle = $stT->fetchColumn();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Enlace guardado',
            'link' => [
                'id' => $linkId,
                'folder_id' => $folderId,
                'task_id' => $taskId,
                'task_title' => $taskTitle ? e($taskTitle) : null,
                'title' => e($title),
                'url' => e($url),
                'created_at' => formatDate(date('Y-m-d'))
            ]
        ]);
        break;

    case 'delete':
        $linkId = (int)($_POST['link_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM project_links WHERE id = ? AND project_id = ?");
        $stmt->execute([$linkId, $projectId]);

        echo json_encode([
            'success' => true,
            'message' => 'Enlace eliminado',
            'link_id' => $linkId
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        break;
}
exit;

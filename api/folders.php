<?php
// api/folders.php - Gestión asíncrona de carpetas de adjuntos
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
        $name = trim($_POST['name'] ?? '');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#6366F1';

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'El nombre de la carpeta es obligatorio']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO project_folders (project_id, name, color) VALUES (?, ?, ?)");
        $stmt->execute([$projectId, $name, $color]);
        $folderId = (int)$db->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Carpeta creada exitosamente',
            'folder' => [
                'id' => $folderId,
                'name' => e($name),
                'color' => e($color)
            ]
        ]);
        break;

    case 'delete':
        $folderId = (int)($_POST['folder_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM project_folders WHERE id = ? AND project_id = ?");
        $stmt->execute([$folderId, $projectId]);

        echo json_encode([
            'success' => true,
            'message' => 'Carpeta eliminada',
            'folder_id' => $folderId
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        break;
}
exit;

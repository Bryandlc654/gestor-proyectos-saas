<?php
// api/files.php - Subida y eliminación asíncrona de archivos
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

switch ($action) {
    case 'upload':
        if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Error al subir el archivo']);
            exit;
        }

        $file = $_FILES['attachment'];
        $maxSize = 20 * 1024 * 1024; // 20 MB

        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'message' => 'El archivo supera el límite de 20 MB']);
            exit;
        }

        $origName = basename($file['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        $forbiddenExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'cgi', 'exe', 'bat', 'cmd', 'sh', 'pl', 'py'];
        if (in_array($ext, $forbiddenExtensions)) {
            echo json_encode(['success' => false, 'message' => 'No se permite subir archivos ejecutables o scripts']);
            exit;
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . ($ext ?: 'bin');
        $uploadDir = __DIR__ . '/../uploads/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $destination = $uploadDir . $storedName;

        $folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
        $taskId = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
        if ($user['role'] === 'cliente') {
            $taskId = null;
        }

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $stmt = $db->prepare("INSERT INTO project_files (project_id, user_id, folder_id, task_id, original_name, stored_name, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $projectId,
                $user['id'],
                $folderId,
                $taskId,
                $origName,
                $storedName,
                $file['size'],
                $file['type'] ?: 'application/octet-stream'
            ]);
            $fileId = (int)$db->lastInsertId();

            $taskTitle = null;
            if ($taskId) {
                $stT = $db->prepare("SELECT title FROM tasks WHERE id = ?");
                $stT->execute([$taskId]);
                $taskTitle = $stT->fetchColumn();
            }

            echo json_encode([
                'success' => true,
                'message' => 'Archivo subido con éxito',
                'file' => [
                    'id' => $fileId,
                    'folder_id' => $folderId,
                    'task_id' => $taskId,
                    'task_title' => $taskTitle ? e($taskTitle) : null,
                    'original_name' => e($origName),
                    'file_size' => formatBytes($file['size']),
                    'created_at' => formatDate(date('Y-m-d')),
                    'download_url' => "actions/file-actions.php?action=download&id={$fileId}&csrf_token={$csrf}"
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No se pudo guardar el archivo en el servidor']);
        }
        break;

    case 'delete':
        $fileId = (int)($_POST['file_id'] ?? 0);
        $stmt = $db->prepare("SELECT id, user_id, stored_name FROM project_files WHERE id = ? AND project_id = ?");
        $stmt->execute([$fileId, $projectId]);
        $f = $stmt->fetch();

        if (!$f) {
            echo json_encode(['success' => false, 'message' => 'Archivo no encontrado']);
            exit;
        }

        // Si es cliente, solo puede eliminar sus propios adjuntos
        if ($user['role'] === 'cliente' && $f['user_id'] != $user['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Solo puedes eliminar tus propios adjuntos']);
            exit;
        }

        $filePath = __DIR__ . '/../uploads/' . $f['stored_name'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $del = $db->prepare("DELETE FROM project_files WHERE id = ?");
        $del->execute([$fileId]);

        echo json_encode([
            'success' => true,
            'message' => 'Archivo eliminado',
            'file_id' => $fileId
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        break;
}
exit;

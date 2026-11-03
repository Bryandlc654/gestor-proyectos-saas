<?php
// api/files.php - Descarga, subida y eliminación de archivos adjuntos
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$user = currentUser();
$db = getDB();

// Descarga segura vía GET (lectura: protegida por sesión y permisos, sin token en la URL)
if (($_GET['action'] ?? '') === 'download') {
    header('Content-Type: text/plain; charset=utf-8');
    $fileId = (int)($_GET['id'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM project_files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();

    // Un cliente solo puede descargar sus propios adjuntos
    if (!$file
        || !canAccessProject($file['project_id'], $user['id'], $user['role'])
        || ($user['role'] === 'cliente' && $file['user_id'] != $user['id'])) {
        http_response_code(404);
        exit('Archivo no encontrado o acceso denegado.');
    }

    $filePath = __DIR__ . '/../uploads/' . $file['stored_name'];
    if (!is_file($filePath)) {
        http_response_code(404);
        exit('El archivo no existe en el servidor.');
    }

    // Nombre de descarga saneado (evita inyección de cabeceras) + versión UTF-8 (RFC 5987)
    $downloadName = basename($file['original_name']);
    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName);

    // Desactivar la compresión gzip global: el cuerpo debe coincidir con Content-Length
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header_remove('Content-Encoding');

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Cache-Control: private, must-revalidate');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}
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
        $folderId = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
        $taskId = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
        if ($user['role'] === 'cliente') {
            $taskId = null;
        }

        if (($folderId && !folderBelongsToProject($folderId, $projectId)) || ($taskId && !taskBelongsToProject($taskId, $projectId))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'La carpeta o tarea no pertenece a este proyecto']);
            exit;
        }

        $upload = storeUploadedAttachment($_FILES['attachment'] ?? []);
        if (!$upload['ok']) {
            echo json_encode(['success' => false, 'message' => $upload['error']]);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO project_files (project_id, user_id, folder_id, task_id, original_name, stored_name, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $projectId,
            $user['id'],
            $folderId,
            $taskId,
            $upload['original_name'],
            $upload['stored_name'],
            $upload['size'],
            $upload['mime']
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
                'original_name' => e($upload['original_name']),
                'file_size' => formatBytes($upload['size']),
                'created_at' => formatDate(date('Y-m-d')),
                'download_url' => "api/files.php?action=download&id={$fileId}"
            ]
        ]);
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

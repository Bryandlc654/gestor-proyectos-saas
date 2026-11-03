<?php
// api/comments.php - Publicación asíncrona de comentarios con soporte para imágenes
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
$projectId = (int)($_POST['project_id'] ?? 0);
$taskId = !empty($_POST['task_id']) ? (int)$_POST['task_id'] : null;
$content = trim($_POST['content'] ?? '');

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

$action = $_POST['action'] ?? 'create';

switch ($action) {
    case 'update':
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $newContent = trim($_POST['content'] ?? '');

        if (empty($newContent)) {
            echo json_encode(['success' => false, 'message' => 'El comentario no puede estar vacío']);
            exit;
        }

        // Verificar pertenencia del comentario
        $stmtC = $db->prepare("SELECT id, user_id, task_id, image_path FROM comments WHERE id = ? AND project_id = ?");
        $stmtC->execute([$commentId, $projectId]);
        $comm = $stmtC->fetch();

        if (!$comm) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Comentario no encontrado']);
            exit;
        }

        if ($comm['user_id'] != $user['id'] && !isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permiso para editar este comentario']);
            exit;
        }

        $stmtUp = $db->prepare("UPDATE comments SET content = ? WHERE id = ?");
        $stmtUp->execute([$newContent, $commentId]);

        echo json_encode([
            'success' => true,
            'message' => 'Comentario actualizado',
            'comment' => [
                'id' => $commentId,
                'content' => nl2br(e($newContent)),
                'raw_content' => $newContent
            ]
        ]);
        exit;

    case 'delete':
        $commentId = (int)($_POST['comment_id'] ?? 0);

        $stmtC = $db->prepare("SELECT id, user_id, task_id, image_path FROM comments WHERE id = ? AND project_id = ?");
        $stmtC->execute([$commentId, $projectId]);
        $comm = $stmtC->fetch();

        if (!$comm) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Comentario no encontrado']);
            exit;
        }

        if ($comm['user_id'] != $user['id'] && !isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permiso para eliminar este comentario']);
            exit;
        }

        // Eliminar archivo de imagen físico si existía
        if (!empty($comm['image_path'])) {
            $imgFullPath = __DIR__ . '/../uploads/' . $comm['image_path'];
            if (file_exists($imgFullPath)) {
                @unlink($imgFullPath);
            }
        }

        $stmtDel = $db->prepare("DELETE FROM comments WHERE id = ?");
        $stmtDel->execute([$commentId]);

        echo json_encode([
            'success' => true,
            'message' => 'Comentario eliminado',
            'comment_id' => $commentId,
            'task_id' => $comm['task_id']
        ]);
        exit;

    case 'create':
    default:
        $hasImage = isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK;
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

        if (empty($content) && !$hasImage) {
            echo json_encode(['success' => false, 'message' => 'Escribe una nota o adjunta una imagen']);
            exit;
        }

        // Si es una respuesta a un comentario, verificar que el comentario padre exista y pertenezca al proyecto
        if ($parentId !== null) {
            $stmtP = $db->prepare("SELECT id, user_id, task_id FROM comments WHERE id = ? AND project_id = ?");
            $stmtP->execute([$parentId, $projectId]);
            $parentComm = $stmtP->fetch();
            if (!$parentComm) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'El comentario al que intentas responder no existe']);
                exit;
            }
            // Si la respuesta no traía task_id explícito pero el padre sí lo tenía, asociar al mismo task
            if (!$taskId && $parentComm['task_id']) {
                $taskId = (int)$parentComm['task_id'];
            }
        }

        $imagePath = null;

        if ($hasImage) {
            $imgFile = $_FILES['image'];
            $maxSize = 10 * 1024 * 1024; // 10 MB

            if ($imgFile['size'] > $maxSize) {
                echo json_encode(['success' => false, 'message' => 'La imagen supera el límite de 10 MB']);
                exit;
            }

            $origName = basename($imgFile['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (empty($ext) || !in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $ext = 'png';
            }

            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowedExts)) {
                echo json_encode(['success' => false, 'message' => 'Formato de imagen no soportado (usa PNG, JPG, WEBP o GIF)']);
                exit;
            }

            $uploadDir = __DIR__ . '/../uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $storedImgName = 'comment_' . bin2hex(random_bytes(12)) . '.' . $ext;
            $destination = $uploadDir . $storedImgName;

            if (move_uploaded_file($imgFile['tmp_name'], $destination)) {
                $imagePath = $storedImgName;
            } else {
                echo json_encode(['success' => false, 'message' => 'No se pudo guardar la imagen']);
                exit;
            }
        }

        $stmt = $db->prepare("INSERT INTO comments (project_id, task_id, parent_id, user_id, content, image_path) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$projectId, $taskId, $parentId, $user['id'], $content, $imagePath]);
        $commId = (int)$db->lastInsertId();

        $taskTitle = null;
        if ($taskId) {
            $stmtT = $db->prepare("SELECT title FROM tasks WHERE id = ?");
            $stmtT->execute([$taskId]);
            $taskTitle = $stmtT->fetchColumn();
        }

        echo json_encode([
            'success' => true,
            'message' => $parentId ? 'Respuesta publicada con éxito' : 'Nota publicada con éxito',
            'comment' => [
                'id' => $commId,
                'parent_id' => $parentId,
                'user_id' => (int)$user['id'],
                'can_manage' => true,
                'task_id' => $taskId,
                'task_title' => $taskTitle ? e($taskTitle) : null,
                'author_name' => e($user['name']),
                'initials' => strtoupper(substr($user['name'], 0, 2)),
                'content' => nl2br(e($content)),
                'raw_content' => $content,
                'image_url' => $imagePath ? 'uploads/' . $imagePath : null,
                'created_at' => formatDate(date('Y-m-d H:i:s'), true)
            ]
        ]);
        exit;
}

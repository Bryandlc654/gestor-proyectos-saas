<?php
// api/comments.php - Publicación asíncrona de comentarios con soporte para imágenes
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (requestExceededPostLimit()) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'La imagen es demasiado grande para el servidor (máximo 10 MB).']);
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

// Ver y comentar: la agencia invitada solo puede comentar con acceso de colaboración
if (!canCommentOnProject($projectId, (int)$user['id'], $user['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Tu acceso a este proyecto es de solo lectura']);
    exit;
}

$canModerateComments = canModerateProjectContent($projectId, (int)$user['id'], $user['role']);

function notifyCommentMentions(string $content, int $projectId, array $author, ?int $taskId): void
{
    if (!preg_match_all('/@([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $content, $matches)) {
        return;
    }

    $emails = array_map('strtolower', array_unique($matches[1]));
    $users = projectRepository()->mentionableUsers($projectId);
    $projectName = projectRepository()->name($projectId) ?? 'un proyecto';
    $taskTitle = null;
    if ($taskId !== null) {
        $stmt = getDB()->prepare("SELECT title FROM tasks WHERE id = ? AND project_id = ?");
        $stmt->execute([$taskId, $projectId]);
        $taskTitle = $stmt->fetchColumn() ?: null;
    }

    foreach ($users as $mentioned) {
        if ($mentioned['id'] === (int)$author['id'] || !in_array(strtolower($mentioned['email']), $emails, true)) {
            continue;
        }
        notificationService()->notifyMention(
            $mentioned['id'],
            $mentioned['agency_id'],
            $author['name'],
            $projectName,
            $projectId,
            $taskTitle
        );
    }
}

// La tarea indicada debe pertenecer al mismo proyecto
if ($taskId && !taskBelongsToProject($taskId, $projectId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'La tarea no pertenece a este proyecto']);
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

        if ($comm['user_id'] != $user['id'] && !$canModerateComments) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permiso para editar este comentario']);
            exit;
        }

        $stmtUp = $db->prepare("UPDATE comments SET content = ? WHERE id = ?");
        $stmtUp->execute([$newContent, $commentId]);
        notifyCommentMentions($newContent, $projectId, $user, $comm['task_id'] !== null ? (int)$comm['task_id'] : null);

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

        if ($comm['user_id'] != $user['id'] && !$canModerateComments) {
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
        $imageError = isset($_FILES['image']) ? (int)$_FILES['image']['error'] : UPLOAD_ERR_NO_FILE;
        // Si se intentó adjuntar una imagen y falló, avisar en vez de publicar la nota sin ella
        if ($imageError !== UPLOAD_ERR_OK && $imageError !== UPLOAD_ERR_NO_FILE) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => uploadErrorMessage($imageError)]);
            exit;
        }
        $hasImage = $imageError === UPLOAD_ERR_OK;
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

            // La extensión se decide por el contenido real del archivo, no por el nombre enviado
            $mime = is_uploaded_file($imgFile['tmp_name']) ? detectMime($imgFile['tmp_name']) : '';
            if (!isset(ALLOWED_IMAGE_MIMES[$mime])) {
                echo json_encode(['success' => false, 'message' => 'Formato de imagen no soportado (usa PNG, JPG, WEBP o GIF)']);
                exit;
            }
            $ext = ALLOWED_IMAGE_MIMES[$mime];

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
        notifyCommentMentions($content, $projectId, $user, $taskId);

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

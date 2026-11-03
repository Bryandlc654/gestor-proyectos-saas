<?php
// actions/file-actions.php
require_once __DIR__ . '/../config/app.php';
requireLogin();

$user = currentUser();
$db = getDB();

// Módulo de Descarga Segura vía GET (lectura: protegida por sesión y permisos, sin token en la URL)
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $fileId = (int)($_GET['id'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM project_files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();

    if (!$file || !canAccessProject($file['project_id'], $user['id'], $user['role'])) {
        die('Archivo no encontrado o acceso denegado.');
    }

    // Si es cliente, solo puede acceder a sus propios adjuntos
    if ($user['role'] === 'cliente' && $file['user_id'] != $user['id']) {
        die('Acceso restringido: Solo puedes acceder a tus propios adjuntos.');
    }

    $filePath = __DIR__ . '/../uploads/' . $file['stored_name'];
    if (!file_exists($filePath)) {
        die('El archivo físico no existe en el servidor.');
    }

    // Nombre de descarga saneado (evita inyección de cabeceras) + versión UTF-8 (RFC 5987)
    $downloadName = basename($file['original_name']);
    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName);

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// Acciones POST (Upload y Delete)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

$action = $_POST['action'] ?? '';
$csrf = $_POST['csrf_token'] ?? '';
$projectId = (int)($_POST['project_id'] ?? 0);

if (!verify_csrf($csrf)) {
    setFlash('error', 'Token de seguridad inválido.');
    header("Location: ../project-view.php?id={$projectId}#adjuntos");
    exit;
}

// Verificar pertenencia o acceso al proyecto según rol
if (!canAccessProject($projectId, $user['id'], $user['role'])) {
    setFlash('error', 'Acceso no autorizado al proyecto.');
    header('Location: ../dashboard.php');
    exit;
}

switch ($action) {
    case 'upload':
        $upload = storeUploadedAttachment($_FILES['attachment'] ?? []);
        if (!$upload['ok']) {
            setFlash('error', $upload['error']);
            break;
        }

        $stmt = $db->prepare("INSERT INTO project_files (project_id, user_id, original_name, stored_name, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $projectId,
            $user['id'],
            $upload['original_name'],
            $upload['stored_name'],
            $upload['size'],
            $upload['mime']
        ]);
        setFlash('success', 'Archivo subido y adjuntado al proyecto.');
        break;

    case 'delete':
        $fileId = (int)($_POST['file_id'] ?? 0);
        if ($fileId > 0) {
            $stmt = $db->prepare("SELECT id, user_id, stored_name FROM project_files WHERE id = ? AND project_id = ?");
            $stmt->execute([$fileId, $projectId]);
            $f = $stmt->fetch();

            if ($f) {
                // Si es cliente, solo puede eliminar sus propios adjuntos
                if ($user['role'] === 'cliente' && $f['user_id'] != $user['id']) {
                    setFlash('error', 'Solo puedes eliminar tus propios adjuntos.');
                    break;
                }

                $filePath = __DIR__ . '/../uploads/' . $f['stored_name'];
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
                $del = $db->prepare("DELETE FROM project_files WHERE id = ?");
                $del->execute([$fileId]);
                setFlash('success', 'Archivo eliminado.');
            }
        }
        break;
}

header("Location: ../project-view.php?id={$projectId}#adjuntos");
exit;

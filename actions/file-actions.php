<?php
// actions/file-actions.php
require_once __DIR__ . '/../config/app.php';
requireLogin();

$user = currentUser();
$db = getDB();

// Módulo de Descarga Segura vía GET
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $fileId = (int)($_GET['id'] ?? 0);
    $csrf = $_GET['csrf_token'] ?? '';

    if (!verify_csrf($csrf)) {
        die('Token de seguridad inválido.');
    }

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

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file['original_name']) . '"');
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
        if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'Error al recibir el archivo. Asegúrate de que no exceda el límite permitido.');
            break;
        }

        $file = $_FILES['attachment'];
        $maxSize = 20 * 1024 * 1024; // 20 MB

        if ($file['size'] > $maxSize) {
            setFlash('error', 'El archivo no debe superar los 20 MB.');
            break;
        }

        $origName = basename($file['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // Lista negra de extensiones ejecutables o riesgosas
        $forbiddenExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'cgi', 'exe', 'bat', 'cmd', 'sh', 'pl', 'py'];
        if (in_array($ext, $forbiddenExtensions)) {
            setFlash('error', 'Por motivos de seguridad, no se permite subir archivos ejecutables o scripts.');
            break;
        }

        // Generar nombre de almacenamiento aleatorio y seguro
        $storedName = bin2hex(random_bytes(16)) . '.' . ($ext ?: 'bin');
        $uploadDir = __DIR__ . '/../uploads/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $destination = $uploadDir . $storedName;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $stmt = $db->prepare("INSERT INTO project_files (project_id, user_id, original_name, stored_name, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $projectId,
                $user['id'],
                $origName,
                $storedName,
                $file['size'],
                $file['type'] ?: 'application/octet-stream'
            ]);
            setFlash('success', 'Archivo subido y adjuntado al proyecto.');
        } else {
            setFlash('error', 'No se pudo guardar el archivo en el servidor.');
        }
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

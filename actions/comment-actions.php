<?php
// actions/comment-actions.php
require_once __DIR__ . '/../config/app.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

$user = currentUser();
$db = getDB();
$csrf = $_POST['csrf_token'] ?? '';
$projectId = (int)($_POST['project_id'] ?? 0);
$content = trim($_POST['content'] ?? '');

if (!verify_csrf($csrf)) {
    setFlash('error', 'Token de seguridad inválido.');
    header("Location: ../project-view.php?id={$projectId}#comments");
    exit;
}

// Verificar acceso al proyecto según rol (propietario, miembro o admin)
if (!canAccessProject($projectId, $user['id'], $user['role'])) {
    setFlash('error', 'Acceso no autorizado.');
    header('Location: ../dashboard.php');
    exit;
}

if (!empty($content)) {
    $stmt = $db->prepare("INSERT INTO comments (project_id, user_id, content) VALUES (?, ?, ?)");
    $stmt->execute([$projectId, $user['id'], $content]);
    setFlash('success', 'Comentario publicado en el muro.');
} else {
    setFlash('error', 'El comentario no puede estar vacío.');
}

header("Location: ../project-view.php?id={$projectId}#comments");
exit;

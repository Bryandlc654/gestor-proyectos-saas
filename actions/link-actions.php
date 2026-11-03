<?php
// actions/link-actions.php
require_once __DIR__ . '/../config/app.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

$user = currentUser();
$db = getDB();
$action = $_POST['action'] ?? '';
$csrf = $_POST['csrf_token'] ?? '';
$projectId = (int)($_POST['project_id'] ?? 0);

if (!verify_csrf($csrf)) {
    setFlash('error', 'Token de seguridad inválido.');
    header("Location: ../project-view.php?id={$projectId}#adjuntos");
    exit;
}

// Verificar pertenencia del proyecto
$stmtProj = $db->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
$stmtProj->execute([$projectId, $user['id']]);
if (!$stmtProj->fetch()) {
    setFlash('error', 'Acceso no autorizado.');
    header('Location: ../dashboard.php');
    exit;
}

switch ($action) {
    case 'create':
        $title = trim($_POST['title'] ?? '');
        $url = trim($_POST['url'] ?? '');

        if (empty($title) || empty($url)) {
            setFlash('error', 'El nombre y la URL del enlace son obligatorios.');
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            setFlash('error', 'La URL ingresada no es válida (debe incluir http:// o https://).');
        } else {
            $stmt = $db->prepare("INSERT INTO project_links (project_id, title, url) VALUES (?, ?, ?)");
            $stmt->execute([$projectId, $title, $url]);
            setFlash('success', 'Enlace agregado.');
        }
        break;

    case 'delete':
        $linkId = (int)($_POST['link_id'] ?? 0);
        if ($linkId > 0) {
            $stmt = $db->prepare("DELETE FROM project_links WHERE id = ? AND project_id = ?");
            $stmt->execute([$linkId, $projectId]);
            setFlash('success', 'Enlace eliminado.');
        }
        break;
}

header("Location: ../project-view.php?id={$projectId}#adjuntos");
exit;

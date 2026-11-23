<?php
// api/app/meetings.php - Actas de reunión del proyecto.
// GET  ?project_id=N                      → listado de actas
// GET  ?action=download&id=N              → descarga del archivo del acta
// POST action=create (multipart)          → crea un acta con enlace y/o archivo
// POST action=delete, meeting_id          → elimina el acta (y su archivo)
// Lectura: cualquiera con acceso al proyecto. Escritura: quien gestiona la estructura del proyecto.
require_once __DIR__ . '/_bootstrap.php';

$db = getDB();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uploadDir = dirname(__DIR__, 2) . '/uploads/';

function meetingPreviewType(?string $mime): ?string {
    return \App\Upload\AttachmentStorage::previewContentType($mime);
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'view') {
    $stmt = $db->prepare('SELECT project_id, original_name, stored_name, file_type FROM project_meetings WHERE id = ?');
    $stmt->execute([(int)($_GET['id'] ?? 0)]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($meeting === false || empty($meeting['stored_name']) || !canAccessProject((int)$meeting['project_id'], $userId, $role)) {
        appJson(['error' => 'Acta no encontrada'], 404);
    }
    $contentType = meetingPreviewType($meeting['file_type']);
    if ($contentType === null) {
        appJson(['error' => 'Este tipo de archivo no admite vista previa'], 415);
    }
    $path = $uploadDir . basename((string)$meeting['stored_name']);
    if (!is_file($path)) {
        appJson(['error' => 'El archivo no existe en el servidor'], 404);
    }

    \App\Upload\AttachmentStorage::sendInline($path, (string)$meeting['original_name'], $contentType);
}

if ($method === 'GET' && ($_GET['action'] ?? '') === 'download') {
    $stmt = $db->prepare('SELECT project_id, original_name, stored_name FROM project_meetings WHERE id = ?');
    $stmt->execute([(int)($_GET['id'] ?? 0)]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($meeting === false || empty($meeting['stored_name']) || !canAccessProject((int)$meeting['project_id'], $userId, $role)) {
        appJson(['error' => 'Acta no encontrada'], 404);
    }
    $path = $uploadDir . basename((string)$meeting['stored_name']);
    if (!is_file($path)) {
        appJson(['error' => 'El archivo no existe en el servidor'], 404);
    }

    $downloadName = basename((string)$meeting['original_name']);
    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header_remove('Content-Encoding');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Cache-Control: private, must-revalidate');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$projectId = (int)($method === 'GET' ? ($_GET['project_id'] ?? 0) : ($_POST['project_id'] ?? 0));
if ($projectId <= 0 || !canAccessProject($projectId, $userId, $role)) {
    appJson(['error' => 'Sin acceso al proyecto'], 403);
}
$canManage = canManageProjectStructure($projectId, $userId, $role);

if ($method === 'GET') {
    $stmt = $db->prepare('
        SELECT m.*, u.name AS user_name
        FROM project_meetings m
        LEFT JOIN users u ON u.id = m.created_by
        WHERE m.project_id = ?
        ORDER BY m.meeting_date DESC, m.id DESC
        LIMIT 200
    ');
    $stmt->execute([$projectId]);
    $meetings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hasFile = !empty($row['stored_name']);
        $meetings[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'meetingDate' => $row['meeting_date'],
            'attendees' => $row['attendees'],
            'summary' => $row['summary'],
            'linkUrl' => $row['link_url'],
            'fileName' => $hasFile ? $row['original_name'] : null,
            'fileSize' => $hasFile ? (int)$row['file_size'] : null,
            'fileType' => $hasFile ? $row['file_type'] : null,
            'downloadUrl' => $hasFile ? '/api/app/meetings.php?action=download&id=' . (int)$row['id'] : null,
            'previewUrl' => $hasFile && meetingPreviewType($row['file_type']) !== null
                ? '/api/app/meetings.php?action=view&id=' . (int)$row['id']
                : null,
            'user' => $row['user_name'],
            'createdAt' => $row['created_at'],
        ];
    }
    appJson(['meetings' => $meetings, 'canManage' => $canManage]);
}

appMethod('POST');

if (requestExceededPostLimit()) {
    appJson(['error' => 'El archivo es demasiado grande para el servidor.'], 413);
}
if (!$canManage) {
    appJson(['error' => 'Tu acceso a este proyecto no permite gestionar actas'], 403);
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'create') {
    $title = trim((string)($_POST['title'] ?? ''));
    $meetingDate = trim((string)($_POST['meeting_date'] ?? ''));
    $attendees = trim((string)($_POST['attendees'] ?? ''));
    $summary = trim((string)($_POST['summary'] ?? ''));
    $linkUrl = trim((string)($_POST['link_url'] ?? ''));
    $file = $_FILES['file'] ?? null;
    $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($title === '' || mb_strlen($title) > 150) {
        appJson(['error' => 'El título del acta es obligatorio (máximo 150 caracteres).'], 422);
    }
    if (!isIsoDate($meetingDate)) {
        appJson(['error' => 'Indica una fecha de reunión válida.'], 422);
    }
    if (mb_strlen($attendees) > 500) {
        appJson(['error' => 'La lista de asistentes no debe superar los 500 caracteres.'], 422);
    }
    if (mb_strlen($summary) > 20000) {
        appJson(['error' => 'El resumen es demasiado largo.'], 422);
    }
    if ($linkUrl !== '' && (mb_strlen($linkUrl) > 500 || !isSafeUrl($linkUrl))) {
        appJson(['error' => 'Enlace no válido (incluye http:// o https://).'], 422);
    }
    if ($linkUrl === '' && !$hasFile) {
        appJson(['error' => 'Adjunta un archivo o indica un enlace al acta.'], 422);
    }

    $upload = null;
    if ($hasFile) {
        $upload = storeUploadedAttachment($file);
        if (!$upload['ok']) {
            appJson(['error' => $upload['error']], 422);
        }
    }

    $db->prepare('
        INSERT INTO project_meetings (project_id, title, meeting_date, attendees, summary, link_url, original_name, stored_name, file_size, file_type, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $projectId,
        $title,
        $meetingDate,
        $attendees !== '' ? $attendees : null,
        $summary !== '' ? $summary : null,
        $linkUrl !== '' ? $linkUrl : null,
        $upload['original_name'] ?? null,
        $upload['stored_name'] ?? null,
        $upload['size'] ?? null,
        $upload['mime'] ?? null,
        $userId,
    ]);
    $id = (int)$db->lastInsertId();
    activityRepository()->logByUser($userId, $projectId, 'meeting.created', 'Registró el acta de reunión "' . $title . '" del ' . $meetingDate);
    appJson(['success' => true, 'id' => $id, 'message' => 'Acta registrada.']);
}

if ($action === 'delete') {
    $meetingId = (int)($_POST['meeting_id'] ?? 0);
    $stmt = $db->prepare('SELECT id, title, stored_name FROM project_meetings WHERE id = ? AND project_id = ?');
    $stmt->execute([$meetingId, $projectId]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($meeting === false) {
        appJson(['error' => 'Acta no encontrada'], 404);
    }
    $db->prepare('DELETE FROM project_meetings WHERE id = ?')->execute([$meetingId]);
    if (!empty($meeting['stored_name'])) {
        $path = $uploadDir . basename((string)$meeting['stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    activityRepository()->logByUser($userId, $projectId, 'meeting.deleted', 'Eliminó el acta de reunión "' . $meeting['title'] . '"');
    appJson(['success' => true, 'message' => 'Acta eliminada.']);
}

appJson(['error' => 'Acción no válida'], 400);

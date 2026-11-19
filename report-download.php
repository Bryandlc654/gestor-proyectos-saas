<?php
// report-download.php - Descarga firmada de un reporte programado.
require_once __DIR__ . '/config/app.php';

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
    http_response_code(404);
    exit('Reporte no encontrado.');
}

$stmt = getDB()->prepare("
    SELECT id, last_file
    FROM report_schedules
    WHERE last_token = ? AND token_expires_at IS NOT NULL AND token_expires_at > NOW()
    LIMIT 1
");
$stmt->execute([$token]);
$schedule = $stmt->fetch(PDO::FETCH_ASSOC);
if ($schedule === false || empty($schedule['last_file'])) {
    http_response_code(404);
    exit('Reporte no encontrado o enlace caducado.');
}

$filename = basename((string)$schedule['last_file']);
$path = __DIR__ . '/storage/reports/' . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit('El archivo ya no está disponible.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
exit;

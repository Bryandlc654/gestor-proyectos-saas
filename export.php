<?php
// export.php - Exportación CSV/PDF de tareas, facturas, auditoría y reportes
use App\Service\ReportService;

require_once __DIR__ . '/config/app.php';
requireLogin();

$user = currentUser();
$role = currentUserRole();
$type = (string)($_GET['type'] ?? '');
$format = (string)($_GET['format'] ?? 'csv');
$format = in_array($format, ['csv', 'pdf'], true) ? $format : 'csv';
$report = (string)($_GET['report'] ?? '');
$isReportExport = $type === 'report';

if (!in_array($type, ['tasks', 'invoices', 'audit', 'report'], true)) {
    http_response_code(400);
    exit('Tipo de exportación no válido.');
}

if ($isReportExport && !in_array($report, ReportService::TYPES, true)) {
    http_response_code(400);
    exit('Reporte no válido.');
}

if (($type === 'invoices' || $isReportExport) && !in_array($role, ['admin', 'project_manager'], true)) {
    http_response_code(403);
    exit('No tienes permisos para exportar este reporte.');
}
if ($type === 'audit' && $role !== 'admin') {
    http_response_code(403);
    exit('No tienes permisos para exportar la auditoría.');
}
if ($type === 'tasks' && !moduleAllowed('tasks')) {
    http_response_code(403);
    exit('El módulo de tareas está desactivado para tu rol.');
}
if ($type === 'invoices' && !moduleAllowed('invoices')) {
    http_response_code(403);
    exit('El módulo de facturación está desactivado para tu rol.');
}
if ($isReportExport && !moduleAllowed('reports')) {
    http_response_code(403);
    exit('El módulo de reportes está desactivado para tu rol.');
}

$service = exportService();
$filters = $_GET;
if ($isReportExport) {
    $dataset = reportService()->dataset($report, currentAgencyId(), (int)$user['id'], $role, $filters);
} elseif ($type === 'tasks') {
    $dataset = $service->tasks((int)$user['id'], $role, $filters);
} elseif ($type === 'invoices') {
    $dataset = $service->invoices(currentAgencyId(), (int)$user['id'], $role, $filters);
} else {
    $dataset = $service->audit(currentAgencyId(), $filters);
}

$filename = 'vertice-' . ($isReportExport ? 'reporte-' . $report : $type) . '-' . date('Ymd-His') . '.' . $format;

while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($format === 'csv') {
    $content = "\xEF\xBB\xBF" . $service->csv($dataset['headers'], $dataset['rows']);
    header('Content-Type: text/csv; charset=UTF-8');
} else {
    $content = $service->pdf($dataset['title'], $dataset['headers'], $dataset['rows'], $dataset['widths']);
    header('Content-Type: application/pdf');
}

header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: private, no-store');
echo $content;
exit;

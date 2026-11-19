<?php
// quote-pdf.php - Descarga la cotización como PDF con el logo de la agencia.
require_once __DIR__ . '/config/app.php';
requireLogin();

use App\Support\QuotePdf;

$user = currentUser();
$userRole = currentUserRole();
$agencyId = currentAgencyId();

if (!in_array($userRole, ['admin', 'project_manager'], true)) {
    http_response_code(403);
    exit('No tienes permisos para descargar esta cotización.');
}
if (!moduleAllowed('quotes')) {
    http_response_code(403);
    exit('No tienes acceso al módulo de cotizaciones.');
}

$quoteId = (int)($_GET['id'] ?? 0);
if ($quoteId <= 0) {
    http_response_code(404);
    exit('Cotización no encontrada.');
}

$db = getDB();
$stmt = $db->prepare("
    SELECT q.*, p.name AS project_name
    FROM quotes q
    LEFT JOIN projects p ON p.id = q.project_id
    WHERE q.id = ? AND q.agency_id = ?
");
$stmt->execute([$quoteId, $agencyId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quote) {
    http_response_code(404);
    exit('Cotización no encontrada.');
}

$itemStmt = $db->prepare("SELECT description, quantity, unit_price, total FROM quote_items WHERE quote_id = ? ORDER BY sort_order ASC, id ASC");
$itemStmt->execute([$quoteId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$agency = currentAgency();
$agency['logo_path'] = !empty($agency['logo'])
    ? __DIR__ . '/' . ltrim((string)$agency['logo'], '/')
    : '';

$pdf = QuotePdf::render($quote, $items, $agency);

$filename = 'Cotizacion-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$quote['quote_number']) . '.pdf';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
$disposition = !empty($_GET['preview']) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
exit;

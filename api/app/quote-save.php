<?php
// api/app/quote-save.php - Crear o actualizar una cotización desde React.
require_once __DIR__ . '/_bootstrap.php';

use App\Support\QuoteCalculator;
use App\Support\QuoteStatus;

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('quotes')) {
    appJson(['error' => 'Módulo de cotizaciones no disponible'], 403);
}

appMethod('POST');

$quoteId = (int)($_POST['id'] ?? 0);
$title = trim((string)($_POST['title'] ?? ''));
$clientName = trim((string)($_POST['client_name'] ?? ''));
$clientCompany = trim((string)($_POST['client_company'] ?? ''));
$clientEmail = trim((string)($_POST['client_email'] ?? ''));
$issueDate = trim((string)($_POST['issue_date'] ?? date('Y-m-d')));
$validUntil = trim((string)($_POST['valid_until'] ?? ''));
$currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
$discountType = (string)($_POST['discount_type'] ?? 'none');
$discountValue = (float)($_POST['discount_value'] ?? 0);
$taxRate = (float)($_POST['tax_rate'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));
$terms = trim((string)($_POST['terms'] ?? ''));
$status = (string)($_POST['status'] ?? 'borrador');
$projectId = (int)($_POST['project_id'] ?? 0);

if ($title === '' || $clientName === '') appJson(['error' => 'Título y cliente son obligatorios.'], 422);
if (!isIsoDate($issueDate)) appJson(['error' => 'La fecha de emisión no es válida.'], 422);
if ($validUntil !== '' && !isIsoDate($validUntil)) appJson(['error' => 'La fecha de validez no es válida.'], 422);
if ($clientEmail !== '' && !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) appJson(['error' => 'El email del cliente no es válido.'], 422);
if ($validUntil !== '' && $issueDate !== '' && $validUntil < $issueDate) appJson(['error' => 'La fecha de validez no puede ser anterior a la fecha de emisión.'], 422);
if (!isValidCurrency($currency)) $currency = 'USD';
if (!in_array($discountType, QuoteCalculator::DISCOUNT_TYPES, true)) $discountType = 'none';
if ($taxRate < 0 || $taxRate > 100) appJson(['error' => 'El impuesto debe estar entre 0 y 100.'], 422);
if (!QuoteStatus::isValid($status)) $status = 'borrador';

$visibleIds = projectRepository()->visibleProjectIds($userId, $role);
$projectId = ($projectId > 0 && in_array($projectId, $visibleIds, true) && projectRepository()->ownerAgencyId($projectId) === $agencyId)
    ? $projectId
    : null;

// Oportunidad del CRM vinculada (propuesta comercial). Solo se acepta una de la agencia.
$dealPosted = array_key_exists('deal_id', $_POST);
$dealId = (int)($_POST['deal_id'] ?? 0);
$dealId = $dealId > 0 && crmRepository()->deal($dealId, $agencyId) !== null ? $dealId : null;

$items = [];
foreach (($_POST['items'] ?? []) as $index => $row) {
    if (!is_array($row)) continue;
    $description = trim((string)($row['description'] ?? ''));
    $quantity = (float)($row['quantity'] ?? 0);
    $unitPrice = (float)($row['unit_price'] ?? 0);
    if ($description === '' && $quantity <= 0 && $unitPrice <= 0) continue;
    if ($description === '') appJson(['error' => 'Cada ítem necesita una descripción.'], 422);
    if ($quantity <= 0) appJson(['error' => 'La cantidad debe ser mayor que cero.'], 422);
    if ($unitPrice < 0) appJson(['error' => 'El precio no puede ser negativo.'], 422);
    $items[] = ['description' => $description, 'quantity' => $quantity, 'unit_price' => $unitPrice];
}
if ($items === []) appJson(['error' => 'Añade al menos un ítem.'], 422);

$totals = QuoteCalculator::totals($items, $discountType, $discountValue, $taxRate);
$existing = null;

if ($quoteId > 0) {
    $stmt = getDB()->prepare('SELECT * FROM quotes WHERE id = ? AND agency_id = ?');
    $stmt->execute([$quoteId, $agencyId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing === false) appJson(['error' => 'Cotización no encontrada.'], 404);
}

$quoteNumber = trim((string)($_POST['quote_number'] ?? ''));
if ($quoteNumber === '') {
    if ($existing !== null) {
        $quoteNumber = (string)$existing['quote_number'];
    } else {
        $year = (int)date('Y');
        $prefix = 'COT-' . $year . '-';
        $stmt = getDB()->prepare('SELECT quote_number FROM quotes WHERE agency_id = ? AND quote_number LIKE ?');
        $stmt->execute([$agencyId, $prefix . '%']);
        $max = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $number) {
            if (preg_match('/^COT-' . $year . '-(\d+)$/', (string)$number, $m)) $max = max($max, (int)$m[1]);
        }
        $quoteNumber = $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
    }
}

if (mb_strlen($quoteNumber) > 40) appJson(['error' => 'El número de cotización admite como máximo 40 caracteres.'], 422);
$stmtDuplicate = getDB()->prepare('SELECT 1 FROM quotes WHERE agency_id = ? AND quote_number = ? AND id <> ?');
$stmtDuplicate->execute([$agencyId, $quoteNumber, $existing !== null ? $quoteId : 0]);
if ($stmtDuplicate->fetchColumn()) {
    appJson(['error' => 'Ya existe otra cotización con el número ' . $quoteNumber . '.'], 409);
}

$db = getDB();
$db->beginTransaction();
try {
    if ($existing !== null) {
        $db->prepare("
            UPDATE quotes SET project_id = ?, quote_number = ?, title = ?, client_name = ?, client_company = ?,
                client_email = ?, issue_date = ?, valid_until = ?, currency = ?, subtotal = ?, discount_type = ?,
                discount_value = ?, discount_amount = ?, tax_rate = ?, tax_amount = ?, total = ?, notes = ?, terms = ?, status = ?,
                sent_at = CASE WHEN ? = 'enviada' THEN COALESCE(sent_at, NOW()) ELSE sent_at END,
                accepted_at = CASE WHEN ? = 'aceptada' THEN COALESCE(accepted_at, NOW()) ELSE NULL END,
                rejected_at = CASE WHEN ? = 'rechazada' THEN COALESCE(rejected_at, NOW()) ELSE NULL END
            WHERE id = ? AND agency_id = ?
        ")->execute([
            $projectId, $quoteNumber, $title, $clientName, $clientCompany !== '' ? $clientCompany : null,
            $clientEmail !== '' ? $clientEmail : null, $issueDate, $validUntil !== '' ? $validUntil : null,
            $currency, $totals['subtotal'], $totals['discount_type'], $totals['discount_value'],
            $totals['discount_amount'], $totals['tax_rate'], $totals['tax_amount'], $totals['total'],
            $notes !== '' ? $notes : null, $terms !== '' ? $terms : null, $status,
            $status, $status, $status, $quoteId, $agencyId,
        ]);
        if ($dealPosted) {
            $db->prepare('UPDATE quotes SET deal_id = ? WHERE id = ? AND agency_id = ?')->execute([$dealId, $quoteId, $agencyId]);
        }
        $db->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$quoteId]);
        $savedId = $quoteId;
    } else {
        $db->prepare("
            INSERT INTO quotes (agency_id, project_id, deal_id, quote_number, title, client_name, client_company,
                client_email, issue_date, valid_until, currency, subtotal, discount_type, discount_value,
                discount_amount, tax_rate, tax_amount, total, notes, terms, status, created_by,
                sent_at, accepted_at, rejected_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $agencyId, $projectId, $dealId, $quoteNumber, $title, $clientName, $clientCompany !== '' ? $clientCompany : null,
            $clientEmail !== '' ? $clientEmail : null, $issueDate, $validUntil !== '' ? $validUntil : null,
            $currency, $totals['subtotal'], $totals['discount_type'], $totals['discount_value'],
            $totals['discount_amount'], $totals['tax_rate'], $totals['tax_amount'], $totals['total'],
            $notes !== '' ? $notes : null, $terms !== '' ? $terms : null, $status, $userId,
            $status === 'enviada' ? date('Y-m-d H:i:s') : null,
            $status === 'aceptada' ? date('Y-m-d H:i:s') : null,
            $status === 'rechazada' ? date('Y-m-d H:i:s') : null,
        ]);
        $savedId = (int)$db->lastInsertId();
    }

    $itemStmt = $db->prepare('INSERT INTO quote_items (quote_id, description, quantity, unit_price, total, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($items as $index => $item) {
        $line = $totals['items'][$index];
        $itemStmt->execute([$savedId, $item['description'], $line['quantity'], $line['unit_price'], $line['total'], $index]);
    }
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('quote-save: ' . $e->getMessage());
    appJson(['error' => 'No se pudo guardar la cotización.'], 500);
}

activityRepository()->logByUser($userId, $projectId, $existing ? 'quote.updated' : 'quote.created', ($existing ? 'Actualizó' : 'Creó') . ' la cotización ' . $quoteNumber);

// Si la propuesta nace de una oportunidad que aún no estaba en esa etapa, la movemos a "Propuesta".
if ($dealId !== null) {
    try {
        $deal = crmRepository()->deal($dealId, $agencyId);
        if ($deal !== null && in_array($deal['stage'], ['nuevo', 'contactado'], true)) {
            crmRepository()->moveDeal($dealId, $agencyId, 'propuesta');
            activityRepository()->logByUser(
                $userId,
                null,
                'crm.deal_stage_changed',
                'Movió la oportunidad "' . $deal['title'] . '" a ' . \App\Repository\CrmRepository::STAGE_LABELS['propuesta'] . ' al crear una cotización'
            );
        }
    } catch (\Throwable $e) {
        error_log('quote-save deal stage: ' . $e->getMessage());
    }
}

appJson(['success' => true, 'id' => $savedId, 'number' => $quoteNumber]);

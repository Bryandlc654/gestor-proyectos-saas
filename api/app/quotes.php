<?php
// api/app/quotes.php - Cotizaciones para React.
require_once __DIR__ . '/_bootstrap.php';

use App\Support\QuotePdf;
use App\Support\QuoteStatus;

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('quotes')) {
    appJson(['error' => 'Módulo de cotizaciones no disponible'], 403);
}

$visibleIds = array_values(array_map('intval', projectRepository()->visibleProjectIds($userId, $role)));
$in = $visibleIds === [] ? 'IN (0)' : 'IN (' . implode(',', $visibleIds) . ')';

$nextQuoteNumber = static function () use ($agencyId): string {
    $year = (int)date('Y');
    $prefix = 'COT-' . $year . '-';
    $stmt = getDB()->prepare('SELECT quote_number FROM quotes WHERE agency_id = ? AND quote_number LIKE ?');
    $stmt->execute([$agencyId, $prefix . '%']);
    $max = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $number) {
        if (preg_match('/^COT-' . $year . '-(\d+)$/', (string)$number, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $quoteId = (int)($_POST['quote_id'] ?? 0);
    $stmt = getDB()->prepare('SELECT * FROM quotes WHERE id = ? AND agency_id = ?');
    $stmt->execute([$quoteId, $agencyId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($quote === false) appJson(['error' => 'Cotización no encontrada'], 404);

    if ($action === 'set_status') {
        $newStatus = (string)($_POST['status'] ?? '');
        if (!QuoteStatus::isValid($newStatus)) appJson(['error' => 'Estado no válido'], 422);
        getDB()->prepare("
            UPDATE quotes
            SET status = ?,
                sent_at = CASE WHEN ? = 'enviada' THEN COALESCE(sent_at, NOW()) ELSE sent_at END,
                accepted_at = CASE WHEN ? = 'aceptada' THEN COALESCE(accepted_at, NOW()) ELSE NULL END,
                rejected_at = CASE WHEN ? = 'rechazada' THEN COALESCE(rejected_at, NOW()) ELSE NULL END
            WHERE id = ? AND agency_id = ?
        ")->execute([$newStatus, $newStatus, $newStatus, $newStatus, $quoteId, $agencyId]);
        activityRepository()->logByUser($userId, null, 'quote.status_changed', 'Cambió la cotización ' . $quote['quote_number'] . ' a ' . QuoteStatus::label($newStatus));
        appJson(['success' => true, 'status' => $newStatus, 'label' => QuoteStatus::label($newStatus)]);
    }

    if ($action === 'send') {
        $email = strtolower(trim((string)$quote['client_email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            appJson(['error' => 'La cotización no tiene un email de cliente válido. Edítala antes de enviarla.'], 422);
        }
        $subject = 'Cotización ' . $quote['quote_number'] . ' · ' . $quote['title'];
        $text = 'Hola ' . $quote['client_name'] . ",\n\n"
            . 'Te compartimos la cotización ' . $quote['quote_number'] . ' por un total de '
            . formatMoney((float)$quote['total'], (string)$quote['currency']) . ".\n\n"
            . 'Quedamos atentos a cualquier duda.';
        $html = '<div style="font-family:Arial,sans-serif;color:#0f172a;line-height:1.6">'
            . '<h2 style="margin:0 0 12px">Cotización ' . e((string)$quote['quote_number']) . '</h2>'
            . '<p>Hola ' . e((string)$quote['client_name']) . ',</p>'
            . '<p>Te compartimos la cotización <strong>' . e((string)$quote['title']) . '</strong> por un total de <strong>'
            . e(formatMoney((float)$quote['total'], (string)$quote['currency'])) . '</strong>.</p>'
            . '<p>Adjuntamos el PDF con el detalle completo.</p>'
            . '<p>Quedamos atentos a cualquier duda.</p></div>';

        $attachments = [];
        try {
            $stmtItems = getDB()->prepare('SELECT description, quantity, unit_price, total FROM quote_items WHERE quote_id = ? ORDER BY sort_order ASC, id ASC');
            $stmtItems->execute([$quoteId]);
            $agency = currentAgency();
            $agency['logo_path'] = !empty($agency['logo']) ? __DIR__ . '/../../' . ltrim((string)$agency['logo'], '/') : '';
            $pdf = QuotePdf::render($quote, $stmtItems->fetchAll(PDO::FETCH_ASSOC), $agency);
            $attachments[] = [
                'filename' => 'Cotizacion-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$quote['quote_number']) . '.pdf',
                'content' => $pdf,
                'mime' => 'application/pdf',
            ];
        } catch (\Throwable $e) {
            error_log('quote pdf attachment: ' . $e->getMessage());
        }

        $sent = false;
        try { $sent = mailer()->send($email, $subject, $text, $html, $attachments); } catch (\Throwable $e) { error_log('quote send: ' . $e->getMessage()); }
        if (!$sent) {
            appJson(['error' => 'No se pudo enviar el email. Revisa la configuración de correo.'], 422);
        }
        getDB()->prepare("
            UPDATE quotes
            SET status = CASE WHEN status = 'borrador' THEN 'enviada' ELSE status END,
                sent_at = COALESCE(sent_at, NOW()), last_emailed_at = NOW(), email_count = email_count + 1
            WHERE id = ? AND agency_id = ?
        ")->execute([$quoteId, $agencyId]);
        activityRepository()->logByUser($userId, null, 'quote.sent', 'Envió la cotización ' . $quote['quote_number'] . ' a ' . $email);
        appJson(['success' => true, 'message' => 'Cotización enviada a ' . $email]);
    }

    if ($action === 'duplicate') {
        $itemsStmt = getDB()->prepare('SELECT description, quantity, unit_price, total, sort_order FROM quote_items WHERE quote_id = ? ORDER BY sort_order ASC, id ASC');
        $itemsStmt->execute([$quoteId]);
        $sourceItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $newNumber = $nextQuoteNumber();
        $newValidUntil = !empty($quote['valid_until']) && $quote['valid_until'] >= date('Y-m-d')
            ? $quote['valid_until']
            : date('Y-m-d', strtotime('+15 days'));
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO quotes (agency_id, project_id, deal_id, quote_number, title, client_name, client_company, client_email,
                    client_phone, client_address, issue_date, valid_until, currency, subtotal, discount_type, discount_value,
                    discount_amount, tax_rate, tax_amount, total, notes, terms, status, version, parent_quote_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'borrador', ?, ?, ?)
            ")->execute([
                $agencyId, $quote['project_id'], $quote['deal_id'], $newNumber, $quote['title'], $quote['client_name'],
                $quote['client_company'], $quote['client_email'], $quote['client_phone'], $quote['client_address'],
                date('Y-m-d'), $newValidUntil, $quote['currency'], $quote['subtotal'], $quote['discount_type'],
                $quote['discount_value'], $quote['discount_amount'], $quote['tax_rate'], $quote['tax_amount'],
                $quote['total'], $quote['notes'], $quote['terms'], ((int)$quote['version'] + 1), $quoteId, $userId,
            ]);
            $newId = (int)$db->lastInsertId();
            $itemInsert = $db->prepare('INSERT INTO quote_items (quote_id, description, quantity, unit_price, total, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($sourceItems as $item) {
                $itemInsert->execute([$newId, $item['description'], $item['quantity'], $item['unit_price'], $item['total'], $item['sort_order']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('quote duplicate: ' . $e->getMessage());
            appJson(['error' => 'No se pudo duplicar la cotización.'], 500);
        }
        activityRepository()->logByUser($userId, null, 'quote.duplicated', 'Duplicó la cotización ' . $quote['quote_number'] . ' como ' . $newNumber);
        appJson(['success' => true, 'id' => $newId, 'number' => $newNumber, 'message' => 'Cotización duplicada.']);
    }

    if ($action === 'convert_project') {
        if (!empty($quote['project_id'])) {
            appJson(['success' => true, 'project_id' => (int)$quote['project_id'], 'already' => true, 'message' => 'La cotización ya tiene un proyecto asociado.']);
        }
        if ((string)$quote['status'] !== 'aceptada') {
            appJson(['error' => 'Marca la cotización como aceptada antes de crear el proyecto.'], 422);
        }
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->prepare("
                INSERT INTO projects (agency_id, user_id, name, client_name, description, category, status, color, deadline, budget, currency)
                VALUES (?, ?, ?, ?, ?, 'General', 'activo', '#0E66FF', ?, ?, ?)
            ")->execute([
                $agencyId,
                $userId,
                mb_substr((string)$quote['title'], 0, 150),
                $quote['client_company'] ?: $quote['client_name'],
                'Proyecto creado desde la cotización ' . $quote['quote_number'] . '.',
                $quote['valid_until'] ?: null,
                (float)$quote['total'],
                $quote['currency'] ?: 'USD',
            ]);
            $newProjectId = (int)$db->lastInsertId();
            $db->prepare("INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'project_manager')")->execute([$newProjectId, $userId]);
            $db->prepare('UPDATE quotes SET project_id = ? WHERE id = ? AND agency_id = ?')->execute([$newProjectId, $quoteId, $agencyId]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('quote project: ' . $e->getMessage());
            appJson(['error' => 'No se pudo crear el proyecto desde la cotización.'], 500);
        }
        activityRepository()->logByUser($userId, $newProjectId, 'quote.project_created', 'Creó el proyecto desde la cotización ' . $quote['quote_number']);
        appJson(['success' => true, 'project_id' => $newProjectId, 'already' => false, 'message' => 'Proyecto creado desde la cotización.']);
    }

    if ($action === 'generate_invoice') {
        if (!empty($quote['invoice_id'])) {
            appJson(['success' => true, 'invoice_id' => (int)$quote['invoice_id'], 'already' => true, 'message' => 'La cotización ya tiene una factura asociada.']);
        }
        if ((string)$quote['status'] !== 'aceptada') {
            appJson(['error' => 'Marca la cotización como aceptada antes de generar la factura.'], 422);
        }
        $created = invoiceService()->create($agencyId, $userId, !empty($quote['project_id']) ? (int)$quote['project_id'] : null, [
            'client_name' => $quote['client_name'] ?: null,
            'concept' => mb_substr('Cotización ' . $quote['quote_number'] . ' · ' . $quote['title'], 0, 255),
            'amount' => (float)$quote['total'],
            'currency' => (string)($quote['currency'] ?: 'USD'),
            'due_date' => $quote['valid_until'] ?: null,
            'status' => 'pendiente',
            'notes' => 'Generada desde la cotización ' . $quote['quote_number'] . '.',
        ], (string)$user['name']);
        $invoiceId = $created['id'];
        $invoiceNumber = $created['number'];
        getDB()->prepare('UPDATE quotes SET invoice_id = ? WHERE id = ? AND agency_id = ?')->execute([$invoiceId, $quoteId, $agencyId]);
        activityRepository()->logByUser($userId, !empty($quote['project_id']) ? (int)$quote['project_id'] : null, 'quote.invoice_created', 'Generó una factura desde la cotización ' . $quote['quote_number']);
        appJson(['success' => true, 'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'already' => false, 'message' => 'Factura generada desde la cotización.']);
    }

    if ($action === 'delete') {
        getDB()->prepare('DELETE FROM quotes WHERE id = ? AND agency_id = ?')->execute([$quoteId, $agencyId]);
        activityRepository()->logByUser($userId, null, 'quote.deleted', 'Eliminó la cotización ' . $quote['quote_number']);
        appJson(['success' => true]);
    }
    appJson(['error' => 'Acción no válida'], 400);
}

appMethod('GET');

$quoteId = (int)($_GET['id'] ?? 0);
if ($quoteId > 0) {
    $stmt = getDB()->prepare("
        SELECT q.*, p.name AS project_name, p.color AS project_color, u.name AS created_by_name,
               d.id AS deal_id, d.title AS deal_title, c.id AS company_id, c.name AS company_name,
               (SELECT i.invoice_number FROM invoices i WHERE i.id = q.invoice_id) AS invoice_number
        FROM quotes q
        LEFT JOIN projects p ON p.id = q.project_id
        LEFT JOIN users u ON u.id = q.created_by
        LEFT JOIN crm_deals d ON d.id = q.deal_id
        LEFT JOIN crm_companies c ON c.id = d.company_id
        WHERE q.id = ? AND q.agency_id = ?
    ");
    $stmt->execute([$quoteId, $agencyId]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($quote === false) appJson(['error' => 'Cotización no encontrada'], 404);

    $items = [];
    $stmtItems = getDB()->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY sort_order ASC, id ASC');
    $stmtItems->execute([$quoteId]);
    foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $items[] = [
            'id' => (int)$item['id'],
            'description' => $item['description'],
            'quantity' => (float)$item['quantity'],
            'unitPrice' => (float)$item['unit_price'],
            'total' => (float)$item['total'],
        ];
    }

    appJson([
        'quote' => [
            'id' => (int)$quote['id'],
            'number' => $quote['quote_number'],
            'title' => $quote['title'],
            'clientName' => $quote['client_name'],
            'clientCompany' => $quote['client_company'],
            'clientEmail' => $quote['client_email'],
            'issueDate' => $quote['issue_date'],
            'validUntil' => $quote['valid_until'],
            'currency' => strtoupper((string)$quote['currency']),
            'subtotal' => (float)$quote['subtotal'],
            'discountType' => $quote['discount_type'],
            'discountValue' => (float)$quote['discount_value'],
            'discountAmount' => (float)$quote['discount_amount'],
            'taxRate' => (float)$quote['tax_rate'],
            'taxAmount' => (float)$quote['tax_amount'],
            'total' => (float)$quote['total'],
            'notes' => $quote['notes'],
            'terms' => $quote['terms'],
            'status' => $quote['status'],
            'statusLabel' => QuoteStatus::label($quote['status']),
            'projectName' => $quote['project_name'],
            'projectId' => $quote['project_id'] !== null ? (int)$quote['project_id'] : null,
            'projectColor' => $quote['project_color'] ?: '#0E66FF',
            'dealTitle' => $quote['deal_title'],
            'dealId' => $quote['deal_id'] !== null ? (int)$quote['deal_id'] : null,
            'companyId' => $quote['company_id'] !== null ? (int)$quote['company_id'] : null,
            'companyName' => $quote['company_name'],
            'createdBy' => $quote['created_by_name'],
            'createdAt' => $quote['created_at'],
            'sentAt' => $quote['sent_at'] ?? null,
            'acceptedAt' => $quote['accepted_at'] ?? null,
            'rejectedAt' => $quote['rejected_at'] ?? null,
            'lastEmailedAt' => $quote['last_emailed_at'] ?? null,
            'emailCount' => (int)($quote['email_count'] ?? 0),
            'version' => (int)($quote['version'] ?? 1),
            'parentQuoteId' => $quote['parent_quote_id'] !== null ? (int)$quote['parent_quote_id'] : null,
            'invoiceId' => $quote['invoice_id'] !== null ? (int)$quote['invoice_id'] : null,
            'invoiceNumber' => $quote['invoice_number'] ?? null,
        ],
        'items' => $items,
        'statuses' => array_map(static fn(string $key): array => ['key' => $key, 'label' => QuoteStatus::label($key)], QuoteStatus::keys()),
    ]);
}

$status = (string)($_GET['status'] ?? 'todos');
if ($status !== 'todos' && !QuoteStatus::isValid($status)) $status = 'todos';
$search = trim((string)($_GET['search'] ?? ''));
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId > 0 && !in_array($projectId, $visibleIds, true)) $projectId = 0;

$where = ['q.agency_id = ?'];
$params = [$agencyId];
if ($status !== 'todos') { $where[] = 'q.status = ?'; $params[] = $status; }
if ($projectId > 0) { $where[] = 'q.project_id = ?'; $params[] = $projectId; }
if ($search !== '') {
    $where[] = '(q.quote_number LIKE ? OR q.title LIKE ? OR q.client_name LIKE ? OR q.client_company LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

$stmt = getDB()->prepare("
    SELECT q.*, p.name AS project_name, p.color AS project_color,
           d.title AS deal_title, d.company_id AS deal_company_id, c.name AS company_name,
           (SELECT i.invoice_number FROM invoices i WHERE i.id = q.invoice_id) AS invoice_number,
           (SELECT COUNT(*) FROM quote_items qi WHERE qi.quote_id = q.id) AS item_count
    FROM quotes q
    LEFT JOIN projects p ON p.id = q.project_id
    LEFT JOIN crm_deals d ON d.id = q.deal_id
    LEFT JOIN crm_companies c ON c.id = d.company_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY q.issue_date DESC, q.id DESC
    LIMIT 200
");
$stmt->execute($params);
$quotes = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $quotes[] = [
        'id' => (int)$row['id'],
        'number' => $row['quote_number'],
        'title' => $row['title'],
        'client' => $row['client_name'],
        'company' => $row['client_company'],
        'clientEmail' => $row['client_email'],
        'issueDate' => $row['issue_date'],
        'validUntil' => $row['valid_until'],
        'total' => (float)$row['total'],
        'currency' => strtoupper((string)$row['currency']),
        'status' => $row['status'],
        'statusLabel' => QuoteStatus::label($row['status']),
        'projectName' => $row['project_name'],
        'projectId' => $row['project_id'] !== null ? (int)$row['project_id'] : null,
        'projectColor' => $row['project_color'] ?: '#0E66FF',
        'itemCount' => (int)$row['item_count'],
        'dealTitle' => $row['deal_title'],
        'dealCompany' => $row['company_name'],
        'dealCompanyId' => $row['deal_company_id'] !== null ? (int)$row['deal_company_id'] : null,
        'sentAt' => $row['sent_at'] ?? null,
        'acceptedAt' => $row['accepted_at'] ?? null,
        'rejectedAt' => $row['rejected_at'] ?? null,
        'lastEmailedAt' => $row['last_emailed_at'] ?? null,
        'emailCount' => (int)($row['email_count'] ?? 0),
        'version' => (int)($row['version'] ?? 1),
        'parentQuoteId' => $row['parent_quote_id'] !== null ? (int)$row['parent_quote_id'] : null,
        'invoiceId' => $row['invoice_id'] !== null ? (int)$row['invoice_id'] : null,
        'invoiceNumber' => $row['invoice_number'] ?? null,
    ];
}

$totals = [];
$acceptedTotals = [];
$pendingTotals = [];
$countAccepted = 0;
$countPending = 0;
$stmtTotals = getDB()->prepare("
    SELECT currency, COUNT(*) count,
           COALESCE(SUM(total),0) total,
           COALESCE(SUM(CASE WHEN status = 'aceptada' THEN total ELSE 0 END),0) accepted,
           COALESCE(SUM(CASE WHEN status IN ('borrador','enviada') THEN total ELSE 0 END),0) pending,
           SUM(CASE WHEN status = 'aceptada' THEN 1 ELSE 0 END) count_accepted,
           SUM(CASE WHEN status IN ('borrador','enviada') THEN 1 ELSE 0 END) count_pending
    FROM quotes q WHERE " . implode(' AND ', $where) . " GROUP BY currency
");
$stmtTotals->execute($params);
foreach ($stmtTotals->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $currency = strtoupper((string)$row['currency']);
    $totals[$currency] = ['count' => (int)$row['count'], 'total' => (float)$row['total']];
    $acceptedTotals[$currency] = (float)$row['accepted'];
    $pendingTotals[$currency] = (float)$row['pending'];
    $countAccepted += (int)$row['count_accepted'];
    $countPending += (int)$row['count_pending'];
}
$totalAll = array_sum(array_column($totals, 'total'));
$acceptedAll = array_sum($acceptedTotals);

$projects = getDB()->query("SELECT id, name FROM projects WHERE id {$in} AND agency_id = " . (int)$agencyId . " AND archived_at IS NULL ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$clientStmt = getDB()->prepare("SELECT DISTINCT client_name FROM quotes WHERE agency_id = ? AND client_name IS NOT NULL AND client_name <> '' ORDER BY client_name ASC LIMIT 50");
$clientStmt->execute([$agencyId]);
$clients = $clientStmt->fetchAll(PDO::FETCH_COLUMN);
$companyStmt = getDB()->prepare("
    SELECT name FROM crm_companies WHERE agency_id = ?
    UNION
    SELECT DISTINCT client_company AS name FROM quotes WHERE agency_id = ? AND client_company IS NOT NULL AND client_company <> ''
    ORDER BY name ASC LIMIT 50
");
$companyStmt->execute([$agencyId, $agencyId]);
$companies = $companyStmt->fetchAll(PDO::FETCH_COLUMN);
$contactsStmt = getDB()->prepare("
    SELECT ct.id, ct.name, ct.email, ct.phone, ct.role AS position, c.name AS company_name
    FROM crm_contacts ct
    LEFT JOIN crm_companies c ON c.id = ct.company_id
    WHERE ct.agency_id = ?
    ORDER BY ct.name ASC
    LIMIT 300
");
$contactsStmt->execute([$agencyId]);
$contacts = $contactsStmt->fetchAll(PDO::FETCH_ASSOC);

appJson([
    'quotes' => $quotes,
    'totals' => $totals,
    'acceptedTotals' => $acceptedTotals,
    'pendingTotals' => $pendingTotals,
    'stats' => [
        'count' => array_sum(array_column($totals, 'count')),
        'countAccepted' => $countAccepted,
        'countPending' => $countPending,
        'acceptanceRate' => $totalAll > 0 ? (int)round($acceptedAll / $totalAll * 100) : 0,
    ],
    'projects' => array_map(static fn(array $project): array => ['id' => (int)$project['id'], 'name' => $project['name']], $projects),
    'clients' => $clients,
    'companies' => $companies,
    'contacts' => array_map(static fn(array $contact): array => [
        'id' => (int)$contact['id'],
        'name' => $contact['name'],
        'email' => $contact['email'],
        'phone' => $contact['phone'],
        'position' => $contact['position'],
        'company' => $contact['company_name'],
    ], $contacts),
    'statuses' => array_map(static fn(string $key): array => ['key' => $key, 'label' => QuoteStatus::label($key)], QuoteStatus::keys()),
]);

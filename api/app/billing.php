<?php
// api/app/billing.php - Facturación global para React.

require_once __DIR__ . '/_bootstrap.php';

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('invoices')) {
    appJson(['error' => 'Módulo de facturación no disponible'], 403);
}

$visibleIds = array_values(array_map('intval', projectRepository()->visibleProjectIds($userId, $role)));
$in = $visibleIds === [] ? 'IN (0)' : 'IN (' . implode(',', $visibleIds) . ')';

$ownStmt = getDB()->prepare("SELECT id FROM projects WHERE agency_id = ? AND id {$in}");
$ownStmt->execute([$agencyId]);
$ownProjectIds = array_map('intval', $ownStmt->fetchAll(PDO::FETCH_COLUMN));

$projectBelongs = static function (int $projectId) use ($ownProjectIds): bool {
    return $projectId > 0 && in_array($projectId, $ownProjectIds, true);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'update_status');
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);

    if ($action === 'create') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        if ($projectId > 0 && !$projectBelongs($projectId)) {
            appJson(['error' => 'Proyecto no disponible'], 403);
        }
        if ($projectId <= 0) $projectId = 0;

        $concept = trim((string)($_POST['concept'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        if ($concept === '') appJson(['error' => 'El concepto de la factura es obligatorio'], 422);
        if ($amount <= 0) appJson(['error' => 'El importe debe ser mayor a 0'], 422);

        $invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
        $clientName = trim((string)($_POST['client_name'] ?? ''));
        if (mb_strlen($clientName) > 150) $clientName = mb_substr($clientName, 0, 150);
        $notes = trim((string)($_POST['notes'] ?? ''));

        $currency = strtoupper(trim((string)($_POST['currency'] ?? '')));
        if (!isValidCurrency($currency) && $projectId > 0) {
            $stmtCurrency = getDB()->prepare('SELECT currency FROM projects WHERE id = ?');
            $stmtCurrency->execute([$projectId]);
            $projectCurrency = (string)$stmtCurrency->fetchColumn();
            $currency = isValidCurrency($projectCurrency) ? strtoupper($projectCurrency) : 'USD';
        }
        if (!isValidCurrency($currency)) $currency = 'USD';

        $dueDate = !empty($_POST['due_date']) ? (string)$_POST['due_date'] : null;
        if ($dueDate !== null && !isIsoDate($dueDate)) {
            appJson(['error' => 'La fecha de vencimiento no es válida.'], 422);
        }
        if ($invoiceNumber !== '') {
            if (mb_strlen($invoiceNumber) > 50) appJson(['error' => 'El número de factura admite como máximo 50 caracteres.'], 422);
            // El índice único (project_id, invoice_number) no cubre las facturas sin proyecto (NULL)
            $duplicate = getDB()->prepare('SELECT 1 FROM invoices WHERE agency_id = ? AND invoice_number = ? AND project_id <=> ?');
            $duplicate->execute([$agencyId, $invoiceNumber, $projectId > 0 ? $projectId : null]);
            if ($duplicate->fetchColumn()) {
                appJson(['error' => $projectId > 0 ? 'Ya existe una factura con ese número en este proyecto' : 'Ya existe una factura general con ese número'], 409);
            }
        }
        $status = in_array($_POST['status'] ?? '', ['pendiente', 'pagada', 'anulada'], true) ? (string)$_POST['status'] : 'pendiente';
        $paidAt = $status === 'pagada' ? date('Y-m-d') : null;
        $paidAmount = $status === 'pagada' ? round((float)$amount, 2) : 0.0;

        $db = getDB();
        $db->beginTransaction();
        try {
            if ($projectId > 0) {
                $db->prepare('SELECT id FROM projects WHERE id = ? FOR UPDATE')->execute([$projectId]);
            }

            if ($invoiceNumber === '') {
                if ($projectId > 0) {
                    $stmtCount = $db->prepare('SELECT COUNT(*) FROM invoices WHERE project_id = ?');
                    $stmtCount->execute([$projectId]);
                    $next = (int)$stmtCount->fetchColumn() + 1;
                    $stmtExists = $db->prepare('SELECT 1 FROM invoices WHERE project_id = ? AND invoice_number = ?');
                } else {
                    $stmtCount = $db->prepare('SELECT COUNT(*) FROM invoices WHERE agency_id = ? AND project_id IS NULL');
                    $stmtCount->execute([$agencyId]);
                    $next = (int)$stmtCount->fetchColumn() + 1;
                    $stmtExists = $db->prepare('SELECT 1 FROM invoices WHERE agency_id = ? AND project_id IS NULL AND invoice_number = ?');
                }
                do {
                    $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad((string)$next++, 3, '0', STR_PAD_LEFT);
                    $stmtExists->execute($projectId > 0 ? [$projectId, $invoiceNumber] : [$agencyId, $invoiceNumber]);
                } while ($stmtExists->fetchColumn());
            }

            $db->prepare('
                INSERT INTO invoices (agency_id, project_id, invoice_number, client_name, concept, amount, paid_amount, currency, status, due_date, paid_at, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $agencyId,
                $projectId > 0 ? $projectId : null,
                $invoiceNumber,
                $clientName !== '' ? $clientName : null,
                $concept,
                $amount,
                $paidAmount,
                $currency,
                $status,
                $dueDate,
                $paidAt,
                $notes,
            ]);
            $newId = (int)$db->lastInsertId();

            if ($paidAmount > 0) {
                $db->prepare("
                    INSERT INTO invoice_payments (invoice_id, agency_id, amount, currency, paid_at, method, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, NULL, 'Cobro inicial al registrar la factura', ?)
                ")->execute([$newId, $agencyId, $paidAmount, $currency, $paidAt, $userId]);
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            if (($e->errorInfo[1] ?? null) === 1062) {
                appJson(['error' => 'Ya existe una factura con ese número en este proyecto'], 409);
            }
            throw $e;
        }

        try {
            activityRepository()->logByUser($userId, $projectId > 0 ? $projectId : null, 'invoice.created', 'Emitió la factura "' . $invoiceNumber . '" por ' . $amount . ' ' . $currency);
        } catch (\Throwable $e) {
            error_log('Actividad factura: ' . $e->getMessage());
        }
        if ($status === 'pagada') {
            foreach (financeRepository()->invoicePaymentIds($newId, $agencyId) as $paymentId) {
                try { cashAutomationService()->syncInvoicePayment($paymentId, $agencyId, $userId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
            }
        }
        try {
            notificationService()->notifyAgencyAdmins(
                $agencyId,
                'invoice.created',
                'Nueva factura emitida',
                $user['name'] . ' emitió la factura ' . $invoiceNumber . ' por ' . number_format($amount, 2) . ' ' . $currency . '.',
                'invoices',
                true,
                $userId
            );
        } catch (\Throwable $e) {
            error_log('Notificación factura: ' . $e->getMessage());
        }

        appJson(['success' => true, 'id' => $newId, 'number' => $invoiceNumber, 'message' => 'Factura registrada con éxito']);
    }

    if ($action === 'update') {
        $stmt = getDB()->prepare('SELECT id, project_id, paid_amount, currency FROM invoices WHERE id = ? AND agency_id = ?');
        $stmt->execute([$invoiceId, $agencyId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing === false) appJson(['error' => 'Factura no encontrada'], 404);
        if ($existing['project_id'] !== null && !$projectBelongs((int)$existing['project_id'])) {
            appJson(['error' => 'Factura no encontrada'], 404);
        }

        $paid = (float)$existing['paid_amount'];
        $postedProjectId = (int)($_POST['project_id'] ?? 0);
        $projectId = $postedProjectId > 0 ? $postedProjectId : null;
        if ($projectId !== null && !$projectBelongs($projectId)) {
            appJson(['error' => 'Proyecto no disponible'], 403);
        }

        $concept = trim((string)($_POST['concept'] ?? ''));
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
        $clientName = trim((string)($_POST['client_name'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $dueDate = trim((string)($_POST['due_date'] ?? ''));

        if ($concept === '' || mb_strlen($concept) > 255) appJson(['error' => 'Escribe el concepto (máximo 255 caracteres).'], 422);
        if ($amount <= 0) appJson(['error' => 'El importe debe ser mayor a 0'], 422);
        if ($invoiceNumber === '' || mb_strlen($invoiceNumber) > 50) appJson(['error' => 'El número de factura es obligatorio (máximo 50 caracteres).'], 422);
        if (mb_strlen($clientName) > 150) $clientName = mb_substr($clientName, 0, 150);
        if ($dueDate !== '' && !isIsoDate($dueDate)) $dueDate = '';

        $currency = $paid > 0
            ? strtoupper((string)$existing['currency'])
            : strtoupper(trim((string)($_POST['currency'] ?? '')));
        if (!isValidCurrency($currency)) {
            if ($projectId !== null) {
                $stmtCurrency = getDB()->prepare('SELECT currency FROM projects WHERE id = ?');
                $stmtCurrency->execute([$projectId]);
                $projectCurrency = (string)$stmtCurrency->fetchColumn();
                $currency = isValidCurrency($projectCurrency) ? strtoupper($projectCurrency) : 'USD';
            } else {
                $currency = 'USD';
            }
        }

        $duplicate = getDB()->prepare('SELECT 1 FROM invoices WHERE agency_id = ? AND invoice_number = ? AND id <> ? AND project_id <=> ?');
        $duplicate->execute([$agencyId, $invoiceNumber, $invoiceId, $projectId]);
        if ($duplicate->fetchColumn()) {
            appJson(['error' => 'Ya existe una factura con ese número en el proyecto seleccionado'], 409);
        }

        try {
            financeRepository()->updateInvoice($invoiceId, $agencyId, [
                'project_id' => $projectId,
                'invoice_number' => $invoiceNumber,
                'client_name' => $clientName !== '' ? $clientName : null,
                'concept' => $concept,
                'amount' => $amount,
                'currency' => $currency,
                'due_date' => $dueDate !== '' ? $dueDate : null,
                'notes' => $notes !== '' ? mb_substr($notes, 0, 2000) : null,
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                appJson(['error' => 'Ya existe una factura con ese número en el proyecto seleccionado'], 409);
            }
            throw $e;
        }

        try {
            activityRepository()->logByUser($userId, $projectId, 'invoice.updated', 'Editó la factura "' . $invoiceNumber . '"');
        } catch (\Throwable $e) {
            error_log('Actividad factura: ' . $e->getMessage());
        }

        appJson(['success' => true, 'id' => $invoiceId, 'message' => 'Factura actualizada']);
    }

    if ($action === 'delete') {
        $stmt = getDB()->prepare('SELECT id, project_id, invoice_number FROM invoices WHERE id = ? AND agency_id = ?');
        $stmt->execute([$invoiceId, $agencyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false) appJson(['error' => 'Factura no encontrada'], 404);
        if ($invoice['project_id'] !== null && !$projectBelongs((int)$invoice['project_id'])) {
            appJson(['error' => 'Factura no encontrada'], 404);
        }

        $stmtDocs = getDB()->prepare('SELECT stored_name FROM project_files WHERE invoice_id = ?');
        $stmtDocs->execute([$invoiceId]);
        $storedFiles = $stmtDocs->fetchAll(PDO::FETCH_COLUMN);

        $paymentIds = financeRepository()->invoicePaymentIds($invoiceId, $agencyId);
        getDB()->prepare('DELETE FROM invoices WHERE id = ? AND agency_id = ?')->execute([$invoiceId, $agencyId]);
        foreach ($paymentIds as $paymentId) {
            try { cashAutomationService()->unsyncInvoicePayment($paymentId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
        }
        try { cashAutomationService()->unsyncInvoice($invoiceId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }

        foreach ($storedFiles as $storedName) {
            $filePath = dirname(__DIR__, 2) . '/uploads/' . $storedName;
            if (is_string($storedName) && is_file($filePath)) {
                @unlink($filePath);
            }
        }

        try {
            activityRepository()->logByUser($userId, $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null, 'invoice.deleted', 'Eliminó la factura #' . $invoiceId);
        } catch (\Throwable $e) {
            error_log('Actividad factura: ' . $e->getMessage());
        }

        appJson(['success' => true, 'message' => 'Factura eliminada']);
    }

    if ($action === 'add_invoice_payment') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $stmt = getDB()->prepare('SELECT id, project_id, invoice_number FROM invoices WHERE id = ? AND agency_id = ?');
        $stmt->execute([$invoiceId, $agencyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false) appJson(['error' => 'Factura no encontrada'], 404);
        if ($invoice['project_id'] !== null && !$projectBelongs((int)$invoice['project_id'])) {
            appJson(['error' => 'Factura no encontrada'], 404);
        }

        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $paidAt = trim((string)($_POST['paid_at'] ?? ''));
        $method = trim((string)($_POST['method'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        if (!isIsoDate($paidAt)) $paidAt = date('Y-m-d');

        $paymentId = financeRepository()->addInvoicePayment(
            $invoiceId,
            $agencyId,
            $userId,
            $amount,
            $paidAt,
            $method !== '' ? mb_substr($method, 0, 40) : null,
            $notes !== '' ? mb_substr($notes, 0, 255) : null
        );
        try { cashAutomationService()->syncInvoicePayment($paymentId, $agencyId, $userId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
        try {
            activityRepository()->logByUser($userId, $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null, 'finance.receivable_payment_added', 'Registró un cobro en la factura ' . $invoice['invoice_number']);
        } catch (\Throwable $e) {
            error_log('Actividad cobro: ' . $e->getMessage());
        }
        appJson(['success' => true, 'paymentId' => $paymentId, 'message' => 'Cobro registrado']);
    }

    if ($action === 'delete_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $stmt = getDB()->prepare("
            SELECT pay.id, pay.invoice_id, i.project_id, i.invoice_number
            FROM invoice_payments pay
            INNER JOIN invoices i ON i.id = pay.invoice_id
            WHERE pay.id = ? AND i.agency_id = ?
        ");
        $stmt->execute([$paymentId, $agencyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($payment === false) appJson(['error' => 'Cobro no encontrado'], 404);
        if ($payment['project_id'] !== null && !$projectBelongs((int)$payment['project_id'])) {
            appJson(['error' => 'Cobro no encontrado'], 404);
        }

        try {
            financeRepository()->deleteInvoicePayment($paymentId, $agencyId);
            try { cashAutomationService()->unsyncInvoicePayment($paymentId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
            activityRepository()->logByUser($userId, $payment['project_id'] !== null ? (int)$payment['project_id'] : null, 'invoice.payment_deleted', 'Eliminó un cobro de la factura ' . $payment['invoice_number']);
            appJson(['success' => true, 'message' => 'Cobro eliminado']);
        } catch (\Throwable $e) {
            error_log('delete payment: ' . $e->getMessage());
            appJson(['error' => 'No se pudo eliminar el cobro.'], 500);
        }
    }

    $status = (string)($_POST['status'] ?? '');
    if ($status === 'reactivate') {
        $stmt = getDB()->prepare('SELECT id, project_id, invoice_number, amount, paid_amount FROM invoices WHERE id = ? AND agency_id = ?');
        $stmt->execute([$invoiceId, $agencyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false) appJson(['error' => 'Factura no encontrada'], 404);
        if ($invoice['project_id'] !== null && !$projectBelongs((int)$invoice['project_id'])) {
            appJson(['error' => 'Factura no encontrada'], 404);
        }

        $isFullyPaid = (float)$invoice['paid_amount'] + 0.005 >= (float)$invoice['amount'];
        getDB()->prepare("UPDATE invoices SET status = ?, paid_at = CASE WHEN ? = 'pagada' THEN (SELECT MAX(paid_at) FROM invoice_payments WHERE invoice_id = ?) ELSE NULL END WHERE id = ? AND agency_id = ?")
            ->execute([$isFullyPaid ? 'pagada' : 'pendiente', $isFullyPaid ? 'pagada' : 'pendiente', $invoiceId, $invoiceId, $agencyId]);

        foreach (financeRepository()->invoicePaymentIds($invoiceId, $agencyId) as $paymentId) {
            try { cashAutomationService()->syncInvoicePayment($paymentId, $agencyId, $userId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
        }
        activityRepository()->logByUser($userId, $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null, 'invoice.status_changed', 'Reactivó la factura ' . $invoice['invoice_number']);

        appJson(['success' => true, 'status' => $isFullyPaid ? 'pagada' : 'pendiente']);
    }

    if (!in_array($status, ['pendiente', 'pagada', 'anulada'], true)) {
        appJson(['error' => 'Estado no válido'], 422);
    }
    $stmt = getDB()->prepare('SELECT id, project_id, invoice_number FROM invoices WHERE id = ? AND agency_id = ?');
    $stmt->execute([$invoiceId, $agencyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($invoice === false) appJson(['error' => 'Factura no encontrada'], 404);
    if ($invoice['project_id'] !== null && !$projectBelongs((int)$invoice['project_id'])) {
        appJson(['error' => 'Factura no encontrada'], 404);
    }

    if ($status === 'pagada') {
        $paymentId = financeRepository()->markInvoicePaid($invoiceId, $agencyId, $userId);
        if ($paymentId > 0) {
            try { cashAutomationService()->syncInvoicePayment($paymentId, $agencyId, $userId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
        }
    } elseif ($status === 'pendiente') {
        try { cashAutomationService()->unsyncInvoicePayments($invoiceId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
        try { cashAutomationService()->unsyncInvoice($invoiceId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
        financeRepository()->reopenInvoice($invoiceId, $agencyId);
        getDB()->prepare("UPDATE invoices SET status = 'pendiente', paid_at = NULL WHERE id = ? AND agency_id = ?")
            ->execute([$invoiceId, $agencyId]);
    } else {
        try { cashAutomationService()->unsyncInvoicePayments($invoiceId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
        try { cashAutomationService()->unsyncInvoice($invoiceId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
        getDB()->prepare("UPDATE invoices SET status = 'anulada', paid_at = NULL WHERE id = ? AND agency_id = ?")
            ->execute([$invoiceId, $agencyId]);
    }
    activityRepository()->logByUser($userId, $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null, 'invoice.status_changed', 'Cambió la factura ' . $invoice['invoice_number'] . ' a ' . $status);
    appJson(['success' => true, 'status' => $status]);
}

appMethod('GET');

$status = (string)($_GET['status'] ?? 'todas');
$allowed = ['todas', 'pendiente', 'parcial', 'pagada', 'anulada', 'vencida'];
if (!in_array($status, $allowed, true)) $status = 'todas';
$search = trim((string)($_GET['search'] ?? ''));
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId > 0 && !$projectBelongs($projectId)) $projectId = 0;

$where = ['i.agency_id = ?', "(i.project_id IS NULL OR i.project_id {$in})"];
$params = [$agencyId];
if ($status === 'parcial') {
    $where[] = "i.status = 'pendiente' AND i.paid_amount > 0";
} elseif ($status === 'vencida') {
    $where[] = "i.status = 'pendiente' AND i.due_date IS NOT NULL AND i.due_date < CURDATE()";
} elseif ($status !== 'todas') {
    $where[] = 'i.status = ?';
    $params[] = $status;
}
if ($projectId > 0) { $where[] = 'i.project_id = ?'; $params[] = $projectId; }
if ($search !== '') {
    $where[] = '(i.invoice_number LIKE ? OR i.concept LIKE ? OR i.client_name LIKE ? OR p.name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}

$stats = ['pending' => [], 'paid' => [], 'overdue' => [], 'total' => [], 'cancelled' => [], 'countPending' => 0, 'countPaid' => 0, 'countTotal' => 0, 'countOverdue' => 0];
$stmtStats = getDB()->prepare("
    SELECT i.currency,
           COUNT(i.id) AS count_total,
           COALESCE(SUM(i.amount), 0) AS total,
           COALESCE(SUM(CASE WHEN i.status = 'pendiente' THEN GREATEST(i.amount - i.paid_amount, 0) ELSE 0 END), 0) AS pending,
           COALESCE(SUM(CASE WHEN i.status <> 'anulada' THEN i.paid_amount ELSE 0 END), 0) AS paid,
           COALESCE(SUM(CASE WHEN i.status = 'anulada' THEN i.amount ELSE 0 END), 0) AS cancelled,
           COALESCE(SUM(CASE WHEN i.status = 'pendiente' AND i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN GREATEST(i.amount - i.paid_amount, 0) ELSE 0 END), 0) AS overdue,
           COALESCE(SUM(CASE WHEN i.status = 'pendiente' THEN 1 ELSE 0 END), 0) AS count_pending,
           COALESCE(SUM(CASE WHEN i.status = 'pagada' THEN 1 ELSE 0 END), 0) AS count_paid,
           COALESCE(SUM(CASE WHEN i.status = 'pendiente' AND i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS count_overdue
    FROM invoices i
    LEFT JOIN projects p ON p.id = i.project_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY i.currency
");
$stmtStats->execute($params);
foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $currency = strtoupper((string)($row['currency'] ?: 'USD'));
    $stats['pending'][$currency] = ($stats['pending'][$currency] ?? 0) + (float)$row['pending'];
    $stats['paid'][$currency] = ($stats['paid'][$currency] ?? 0) + (float)$row['paid'];
    $stats['overdue'][$currency] = ($stats['overdue'][$currency] ?? 0) + (float)$row['overdue'];
    $stats['total'][$currency] = ($stats['total'][$currency] ?? 0) + (float)$row['total'];
    $stats['cancelled'][$currency] = ($stats['cancelled'][$currency] ?? 0) + (float)$row['cancelled'];
    $stats['countPending'] += (int)$row['count_pending'];
    $stats['countPaid'] += (int)$row['count_paid'];
    $stats['countTotal'] += (int)$row['count_total'];
    $stats['countOverdue'] += (int)$row['count_overdue'];
}
$stats['effectiveness'] = array_sum($stats['total']) > 0
    ? (int)round(array_sum($stats['paid']) / array_sum($stats['total']) * 100)
    : 0;

$perPage = 50;
$pages = max(1, (int)ceil($stats['countTotal'] / $perPage));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$stmt = getDB()->prepare("
    SELECT i.*, p.name AS project_name, p.color AS project_color, p.currency AS project_currency
    FROM invoices i
    LEFT JOIN projects p ON p.id = i.project_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY (i.status = 'pendiente') DESC, i.due_date ASC, i.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);

$invoiceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$invoicePaymentsById = [];
if ($invoiceRows !== []) {
    $invoiceIds = array_map(static fn(array $row): int => (int)$row['id'], $invoiceRows);
    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $stmtPayments = getDB()->prepare("
        SELECT pay.*, u.name AS user_name
        FROM invoice_payments pay
        LEFT JOIN users u ON u.id = pay.created_by
        WHERE pay.invoice_id IN ($placeholders)
        ORDER BY pay.paid_at DESC, pay.id DESC
    ");
    $stmtPayments->execute($invoiceIds);
    foreach ($stmtPayments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $invoicePaymentsById[(int)$payment['invoice_id']][] = [
            'id' => (int)$payment['id'],
            'amount' => (float)$payment['amount'],
            'currency' => $payment['currency'] ?: 'USD',
            'paidAt' => $payment['paid_at'],
            'method' => $payment['method'],
            'notes' => $payment['notes'],
            'user' => $payment['user_name'],
        ];
    }
}

$invoices = [];
foreach ($invoiceRows as $invoice) {
    $paid = (float)($invoice['paid_amount'] ?? 0);
    $balance = max(0.0, round((float)$invoice['amount'] - $paid, 2));
    $displayStatus = $invoice['status'] === 'pagada' ? 'pagada' : ($invoice['status'] === 'anulada' ? 'anulada' : ($paid > 0 ? 'parcial' : 'pendiente'));
    $overdue = $invoice['status'] === 'pendiente' && !empty($invoice['due_date']) && $invoice['due_date'] < date('Y-m-d');
    $daysOverdue = $overdue ? (int)max(0, floor((strtotime(date('Y-m-d')) - strtotime((string)$invoice['due_date'])) / 86400)) : 0;
    $invoices[] = [
        'id' => (int)$invoice['id'],
        'number' => $invoice['invoice_number'],
        'client' => $invoice['client_name'],
        'concept' => $invoice['concept'],
        'notes' => $invoice['notes'],
        'amount' => (float)$invoice['amount'],
        'paid' => $paid,
        'balance' => $balance,
        'currency' => strtoupper((string)$invoice['currency']),
        'projectCurrency' => $invoice['project_currency'] !== null ? strtoupper((string)$invoice['project_currency']) : null,
        'status' => $invoice['status'],
        'displayStatus' => $displayStatus,
        'dueDate' => $invoice['due_date'],
        'paidAt' => $invoice['paid_at'],
        'createdAt' => $invoice['created_at'],
        'overdue' => $overdue,
        'daysOverdue' => $daysOverdue,
        'projectId' => $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null,
        'projectName' => $invoice['project_name'],
        'projectColor' => $invoice['project_color'] ?: '#0E66FF',
        'payments' => $invoicePaymentsById[(int)$invoice['id']] ?? [],
    ];
}

$projects = getDB()->prepare("SELECT id, name, currency, client_name, budget FROM projects WHERE id {$in} AND agency_id = ? AND archived_at IS NULL ORDER BY name ASC");
$projects->execute([$agencyId]);

appJson([
    'invoices' => $invoices,
    'stats' => $stats,
    'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $stats['countTotal'], 'pages' => $pages],
    'projects' => array_map(static fn(array $project): array => [
        'id' => (int)$project['id'],
        'name' => $project['name'],
        'currency' => strtoupper((string)($project['currency'] ?: 'USD')),
        'client' => $project['client_name'],
        'budget' => (float)$project['budget'],
    ], $projects->fetchAll(PDO::FETCH_ASSOC)),
    'clients' => financeContactSuggestions($agencyId, null, false),
    'currencies' => \App\Support\Format::CURRENCIES,
]);

<?php
// api/app/finance.php - Cobros y pagos (cuentas por cobrar y por pagar) para React.
require_once __DIR__ . '/_bootstrap.php';

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('finance')) {
    appJson(['error' => 'Módulo financiero no disponible'], 403);
}

$visibleIds = array_values(array_map('intval', projectRepository()->visibleProjectIds($userId, $role)));
$in = $visibleIds === [] ? 'IN (0)' : 'IN (' . implode(',', $visibleIds) . ')';

$ownStmt = getDB()->prepare("SELECT id FROM projects WHERE agency_id = ? AND id {$in}");
$ownStmt->execute([$agencyId]);
$ownProjectIds = array_map('intval', $ownStmt->fetchAll(PDO::FETCH_COLUMN));

$validProject = static function (int $projectId) use ($ownProjectIds): bool {
    return $projectId > 0 && in_array($projectId, $ownProjectIds, true);
};

$projectIdFromPost = static function () use ($validProject): ?int {
    $projectId = (int)($_POST['project_id'] ?? 0);
    if ($projectId <= 0) {
        return null;
    }
    if (!$validProject($projectId)) {
        throw new DomainException('El proyecto seleccionado no es válido.');
    }
    return $projectId;
};

$payableData = static function () use ($projectIdFromPost): array {
    $supplier = trim((string)($_POST['supplier'] ?? ''));
    $concept = trim((string)($_POST['concept'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $kind = (string)($_POST['kind'] ?? 'direct');
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
    $dueDate = trim((string)($_POST['due_date'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!in_array($kind, ['direct', 'collaborator'], true)) {
        $kind = 'direct';
    }
    if ($supplier === '' || mb_strlen($supplier) > 150) {
        throw new DomainException(
            $kind === 'collaborator'
                ? 'Escribe el nombre del colaborador (máximo 150 caracteres).'
                : 'Escribe el proveedor (máximo 150 caracteres).'
        );
    }
    if ($concept === '' || mb_strlen($concept) > 255) {
        throw new DomainException('Escribe el concepto (máximo 255 caracteres).');
    }
    if ($amount <= 0) {
        throw new DomainException('El importe debe ser mayor que cero.');
    }
    if (!isValidCurrency($currency)) {
        $currency = 'USD';
    }
    if ($dueDate !== '' && !isIsoDate($dueDate)) {
        $dueDate = '';
    }

    return [
        'supplier' => $supplier,
        'concept' => $concept,
        'category' => $category !== '' ? mb_substr($category, 0, 60) : null,
        'kind' => $kind,
        'project_id' => $projectIdFromPost(),
        'amount' => $amount,
        'currency' => $currency,
        'due_date' => $dueDate !== '' ? $dueDate : null,
        'notes' => $notes !== '' ? mb_substr($notes, 0, 2000) : null,
    ];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'add_payment');

    try {
        switch ($action) {
            case 'create_payable':
            case 'update_payable':
                $data = $payableData();
                if ($action === 'create_payable') {
                    $payableId = financeRepository()->createPayable($agencyId, $userId, $data);
                    activityRepository()->logByUser($userId, $data['project_id'], 'finance.payable_created', 'Registró la cuenta por pagar "' . $data['concept'] . '" de ' . $data['supplier']);
                    appJson(['success' => true, 'id' => $payableId, 'message' => 'Cuenta por pagar registrada.']);
                }

                $payableId = (int)($_POST['payable_id'] ?? 0);
                if (financeRepository()->find($payableId, $agencyId) === null) {
                    appJson(['error' => 'Cuenta por pagar no encontrada.'], 404);
                }
                financeRepository()->updatePayable($payableId, $agencyId, $data);
                activityRepository()->logByUser($userId, $data['project_id'], 'finance.payable_updated', 'Actualizó la cuenta por pagar "' . $data['concept'] . '"');
                appJson(['success' => true, 'id' => $payableId, 'message' => 'Cuenta por pagar actualizada.']);

            case 'delete_payable':
                $payableId = (int)($_POST['payable_id'] ?? 0);
                $payable = financeRepository()->find($payableId, $agencyId);
                if ($payable === null) {
                    appJson(['error' => 'Cuenta por pagar no encontrada.'], 404);
                }
                try { cashAutomationService()->unsyncPayable($payableId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
                financeRepository()->deletePayable($payableId, $agencyId);
                activityRepository()->logByUser($userId, $payable['project_id'] !== null ? (int)$payable['project_id'] : null, 'finance.payable_deleted', 'Eliminó la cuenta por pagar "' . $payable['concept'] . '"');
                appJson(['success' => true, 'message' => 'Cuenta por pagar eliminada.']);

            case 'add_payment':
                $payableId = (int)($_POST['payable_id'] ?? 0);
                $amount = round((float)($_POST['amount'] ?? 0), 2);
                $paidAt = trim((string)($_POST['paid_at'] ?? ''));
                $method = trim((string)($_POST['method'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));

                if (!isIsoDate($paidAt)) {
                    $paidAt = date('Y-m-d');
                }
                $paymentId = financeRepository()->addPayment(
                    $payableId,
                    $agencyId,
                    $userId,
                    $amount,
                    $paidAt,
                    $method !== '' ? mb_substr($method, 0, 40) : null,
                    $notes !== '' ? mb_substr($notes, 0, 255) : null
                );
                if ($paymentId > 0) {
                    try { cashAutomationService()->syncPayablePayment($paymentId, $agencyId, $userId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
                }
                $payable = financeRepository()->find($payableId, $agencyId);
                activityRepository()->logByUser(
                    $userId,
                    $payable !== null && $payable['project_id'] !== null ? (int)$payable['project_id'] : null,
                    'finance.payment_added',
                    'Registró un pago de ' . formatMoney($amount, $payable['currency'] ?? 'USD') . ' en "' . ($payable['concept'] ?? 'cuenta por pagar') . '"'
                );
                appJson(['success' => true, 'paymentId' => $paymentId, 'message' => 'Pago registrado.']);

            case 'delete_payment':
                $paymentId = (int)($_POST['payment_id'] ?? 0);
                financeRepository()->deletePayment($paymentId, $agencyId);
                try { cashAutomationService()->unsyncPayablePayment($paymentId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
                activityRepository()->logByUser($userId, null, 'finance.payment_deleted', 'Eliminó un pago de una cuenta por pagar');
                appJson(['success' => true, 'message' => 'Pago eliminado.']);

            case 'add_invoice_payment':
                $invoiceId = (int)($_POST['invoice_id'] ?? 0);
                $amount = round((float)($_POST['amount'] ?? 0), 2);
                $paidAt = trim((string)($_POST['paid_at'] ?? ''));
                $method = trim((string)($_POST['method'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));

                if (!isIsoDate($paidAt)) {
                    $paidAt = date('Y-m-d');
                }

                $invoice = financeRepository()->findInvoice($invoiceId, $agencyId);
                if ($invoice === null) {
                    appJson(['error' => 'Factura no encontrada.'], 404);
                }
                if (!empty($invoice['project_id']) && !$validProject((int)$invoice['project_id'])) {
                    appJson(['error' => 'No tienes acceso a la factura indicada.'], 403);
                }

                $paymentId = financeRepository()->addInvoicePayment(
                    $invoiceId,
                    $agencyId,
                    $userId,
                    $amount,
                    $paidAt,
                    $method !== '' ? mb_substr($method, 0, 40) : null,
                    $notes !== '' ? mb_substr($notes, 0, 255) : null
                );
                if ($paymentId > 0) {
                    try { cashAutomationService()->syncInvoicePayment($paymentId, $agencyId, $userId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
                }

                $invoice = financeRepository()->findInvoice($invoiceId, $agencyId);
                activityRepository()->logByUser(
                    $userId,
                    $invoice !== null && $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null,
                    'finance.receivable_payment_added',
                    'Registró un cobro de ' . formatMoney($amount, $invoice['currency'] ?? 'USD') . ' en la factura "' . ($invoice['invoice_number'] ?? '#' . $invoiceId) . '"'
                );
                appJson(['success' => true, 'paymentId' => $paymentId, 'message' => 'Cobro registrado.']);

            case 'delete_invoice_payment':
                $paymentId = (int)($_POST['payment_id'] ?? 0);
                financeRepository()->deleteInvoicePayment($paymentId, $agencyId);
                try { cashAutomationService()->unsyncInvoicePayment($paymentId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
                activityRepository()->logByUser($userId, null, 'finance.receivable_payment_deleted', 'Eliminó un cobro de una factura');
                appJson(['success' => true, 'message' => 'Cobro eliminado.']);

            case 'create_receivable':
                $projectId = $projectIdFromPost();

                $clientName = trim((string)($_POST['client_name'] ?? ''));
                if (mb_strlen($clientName) > 150) {
                    $clientName = mb_substr($clientName, 0, 150);
                }
                $invoiceNumber = trim((string)($_POST['invoice_number'] ?? ''));
                if (mb_strlen($invoiceNumber) > 50) {
                    appJson(['error' => 'El número de factura no puede superar los 50 caracteres.'], 422);
                }
                $concept = trim((string)($_POST['concept'] ?? ''));
                if ($concept === '' || mb_strlen($concept) > 255) {
                    appJson(['error' => 'Escribe el concepto (máximo 255 caracteres).'], 422);
                }
                $amount = round((float)($_POST['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    appJson(['error' => 'El importe debe ser mayor que cero.'], 422);
                }
                $currency = strtoupper(trim((string)($_POST['currency'] ?? '')));
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
                $dueDate = trim((string)($_POST['due_date'] ?? ''));
                if ($dueDate !== '' && !isIsoDate($dueDate)) {
                    appJson(['error' => 'La fecha de vencimiento no es válida.'], 422);
                }
                $status = ($_POST['status'] ?? '') === 'pagada' ? 'pagada' : 'pendiente';
                $notes = trim((string)($_POST['notes'] ?? ''));

                $receivableId = financeRepository()->createReceivable($agencyId, $userId, [
                    'project_id' => $projectId,
                    'invoice_number' => $invoiceNumber,
                    'client_name' => $clientName !== '' ? $clientName : null,
                    'concept' => $concept,
                    'amount' => $amount,
                    'currency' => $currency,
                    'due_date' => $dueDate !== '' ? $dueDate : null,
                    'status' => $status,
                    'notes' => $notes !== '' ? mb_substr($notes, 0, 2000) : null,
                ]);

                $stmtInv = getDB()->prepare('SELECT invoice_number FROM invoices WHERE id = ?');
                $stmtInv->execute([$receivableId]);
                $savedNumber = (string)$stmtInv->fetchColumn();

                if ($status === 'pagada') {
                    foreach (financeRepository()->invoicePaymentIds($receivableId, $agencyId) as $paymentId) {
                        try { cashAutomationService()->syncInvoicePayment($paymentId, $agencyId, $userId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
                    }
                }

                activityRepository()->logByUser($userId, $projectId, 'invoice.created', 'Emitió la factura "' . $savedNumber . '" por ' . $amount . ' ' . $currency);
                try {
                    notificationService()->notifyAgencyAdmins(
                        $agencyId,
                        'invoice.created',
                        'Nueva factura emitida',
                        $user['name'] . ' emitió la factura ' . $savedNumber . ' por ' . number_format($amount, 2) . ' ' . $currency . '.',
                        'finance?tab=cobrar',
                        true,
                        $userId
                    );
                } catch (\Throwable $e) {
                    error_log('Notificación factura: ' . $e->getMessage());
                }
                appJson(['success' => true, 'id' => $receivableId, 'number' => $savedNumber, 'message' => 'Cuenta por cobrar registrada como factura ' . $savedNumber . '.']);

            case 'invoice_paid':
            case 'invoice_pending':
                $invoiceId = (int)($_POST['invoice_id'] ?? 0);
                $stmt = getDB()->prepare('SELECT i.id, i.invoice_number, i.project_id FROM invoices i WHERE i.id = ? AND i.agency_id = ?');
                $stmt->execute([$invoiceId, $agencyId]);
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($invoice === false) {
                    appJson(['error' => 'Factura no encontrada.'], 404);
                }
                if (!empty($invoice['project_id']) && !$validProject((int)$invoice['project_id'])) {
                    appJson(['error' => 'No tienes acceso a la factura indicada.'], 403);
                }

                $isPaid = $action === 'invoice_paid';
                if ($isPaid) {
                    $paymentId = financeRepository()->markInvoicePaid($invoiceId, $agencyId, $userId);
                    if ($paymentId > 0) {
                        try { cashAutomationService()->syncInvoicePayment($paymentId, $agencyId, $userId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
                    }
                } else {
                    try { cashAutomationService()->unsyncInvoicePayments($invoiceId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
                    try { cashAutomationService()->unsyncInvoice($invoiceId, $agencyId); } catch (\Throwable $e) { error_log('Caja: ' . $e->getMessage()); }
                    financeRepository()->reopenInvoice($invoiceId, $agencyId);
                    getDB()->prepare("UPDATE invoices SET status = 'pendiente', paid_at = NULL WHERE id = ? AND agency_id = ?")
                        ->execute([$invoiceId, $agencyId]);
                }
                activityRepository()->logByUser(
                    $userId,
                    $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null,
                    $isPaid ? 'finance.receivable_paid' : 'finance.receivable_pending',
                    ($isPaid ? 'Marcó como cobrada' : 'Marcó como pendiente') . ' la factura ' . $invoice['invoice_number']
                );
                appJson(['success' => true, 'message' => $isPaid ? 'Factura marcada como cobrada.' : 'Factura marcada como pendiente.']);

            default:
                appJson(['error' => 'Acción no reconocida.'], 400);
        }
    } catch (\DomainException $e) {
        appJson(['error' => $e->getMessage()], 422);
    }
}

appMethod('GET');

$tab = ($_GET['tab'] ?? '') === 'pagar' ? 'pagar' : 'cobrar';
$allowedStatuses = ['todas', 'pendientes', 'parciales', 'vencidas', 'pagadas', 'anuladas'];
$status = (string)($_GET['status'] ?? 'todas');
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'todas';
}
$search = trim((string)($_GET['search'] ?? ''));
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId > 0 && !$validProject($projectId)) {
    $projectId = 0;
}

$today = date('Y-m-d');
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));

$pendingByCurrency = [];
$overdueByCurrency = [];
$paidByCurrency = [];
$countPending = 0;
$countOverdue = 0;

$recvWhere = ['i.agency_id = ?'];
$recvParams = [$agencyId];
if ($projectId > 0) {
    $recvWhere[] = 'i.project_id = ?';
    $recvParams[] = $projectId;
} else {
    $recvWhere[] = "(i.project_id IS NULL OR i.project_id {$in})";
}

$stmtRecvSummary = getDB()->prepare("
    SELECT i.currency,
           SUM(CASE WHEN i.status = 'pendiente' THEN GREATEST(i.amount - i.paid_amount, 0) ELSE 0 END) AS pending,
           SUM(CASE WHEN i.status = 'pendiente' AND i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN GREATEST(i.amount - i.paid_amount, 0) ELSE 0 END) AS overdue,
           SUM(CASE WHEN i.status <> 'anulada' THEN i.paid_amount ELSE 0 END) AS paid,
           SUM(CASE WHEN i.status = 'pendiente' THEN 1 ELSE 0 END) AS count_pending,
           SUM(CASE WHEN i.status = 'pendiente' AND i.due_date IS NOT NULL AND i.due_date < CURDATE() THEN 1 ELSE 0 END) AS count_overdue
    FROM invoices i
    WHERE " . implode(' AND ', $recvWhere) . "
    GROUP BY i.currency
");
$stmtRecvSummary->execute($recvParams);
foreach ($stmtRecvSummary->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $currency = strtoupper((string)($row['currency'] ?: 'USD'));
    $pendingByCurrency[$currency] = ($pendingByCurrency[$currency] ?? 0) + (float)$row['pending'];
    $overdueByCurrency[$currency] = ($overdueByCurrency[$currency] ?? 0) + (float)$row['overdue'];
    $paidByCurrency[$currency] = ($paidByCurrency[$currency] ?? 0) + (float)$row['paid'];
    $countPending += (int)$row['count_pending'];
    $countOverdue += (int)$row['count_overdue'];
}

$paySummary = financeRepository()->summary($agencyId, $visibleIds, $projectId > 0 ? $projectId : null);

$receivables = [];
$payables = [];
$recevTotal = 0;
$payTotal = 0;

if ($tab === 'cobrar') {
    $where = ['i.agency_id = ?'];
    $params = [$agencyId];
    if ($projectId > 0) {
        $where[] = 'i.project_id = ?';
        $params[] = $projectId;
    } else {
        $where[] = "(i.project_id IS NULL OR i.project_id {$in})";
    }

    if ($status === 'pendientes') {
        $where[] = "i.status = 'pendiente'";
    } elseif ($status === 'parciales') {
        $where[] = "i.status = 'pendiente' AND i.paid_amount > 0";
    } elseif ($status === 'vencidas') {
        $where[] = "i.status = 'pendiente' AND i.due_date IS NOT NULL AND i.due_date < CURDATE()";
    } elseif ($status === 'pagadas') {
        $where[] = "i.status = 'pagada'";
    } elseif ($status === 'anuladas') {
        $where[] = "i.status = 'anulada'";
    }

    if ($search !== '') {
        $where[] = '(i.invoice_number LIKE ? OR i.concept LIKE ? OR p.name LIKE ? OR i.client_name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmtCount = getDB()->prepare("SELECT COUNT(*) FROM invoices i LEFT JOIN projects p ON p.id = i.project_id {$whereSql}");
    $stmtCount->execute($params);
    $filteredTotal = (int)$stmtCount->fetchColumn();
    $pages = max(1, (int)ceil($filteredTotal / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmtList = getDB()->prepare("
        SELECT i.*, p.name AS project_name, p.color AS project_color
        FROM invoices i
        LEFT JOIN projects p ON p.id = i.project_id
        {$whereSql}
        ORDER BY (i.status = 'pendiente') DESC, (i.due_date IS NULL), i.due_date ASC, i.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmtList->execute($params);
    $invoiceRows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    $invoicePaymentsById = [];
    if ($invoiceRows !== []) {
        $invoiceIds = array_map(static fn(array $row): int => (int)$row['id'], $invoiceRows);
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmtInvoicePayments = getDB()->prepare("
            SELECT pay.*, u.name AS user_name
            FROM invoice_payments pay
            LEFT JOIN users u ON u.id = pay.created_by
            WHERE pay.invoice_id IN ($placeholders)
            ORDER BY pay.paid_at DESC, pay.id DESC
        ");
        $stmtInvoicePayments->execute($invoiceIds);
        foreach ($stmtInvoicePayments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
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

    foreach ($invoiceRows as $row) {
        $isOverdue = $row['status'] === 'pendiente' && !empty($row['due_date']) && $row['due_date'] < $today;
        $paid = (float)($row['paid_amount'] ?? 0);
        $balance = max(0.0, round((float)$row['amount'] - $paid, 2));
        $displayStatus = $row['status'] === 'pagada' ? 'pagada' : ($row['status'] === 'anulada' ? 'anulada' : ($paid > 0 ? 'parcial' : 'pendiente'));
        $receivables[] = [
            'id' => (int)$row['id'],
            'number' => $row['invoice_number'],
            'client' => $row['client_name'],
            'concept' => $row['concept'],
            'amount' => (float)$row['amount'],
            'paid' => $paid,
            'balance' => $balance,
            'currency' => $row['currency'] ?: 'USD',
            'dueDate' => $row['due_date'],
            'status' => $row['status'],
            'displayStatus' => $displayStatus,
            'paidAt' => $row['paid_at'],
            'overdue' => $isOverdue,
            'daysOverdue' => $isOverdue ? (int)floor((strtotime($today) - strtotime((string)$row['due_date'])) / 86400) : 0,
            'projectId' => $row['project_id'] !== null ? (int)$row['project_id'] : null,
            'projectName' => $row['project_name'],
            'projectColor' => $row['project_color'] ?: '#0E66FF',
            'payments' => $invoicePaymentsById[(int)$row['id']] ?? [],
        ];
    }

    $stmtRecvTotal = getDB()->prepare('SELECT COUNT(*) FROM invoices i WHERE ' . 'i.agency_id = ?' . ($projectId > 0 ? ' AND i.project_id = ?' : " AND (i.project_id IS NULL OR i.project_id {$in})"));
    $stmtRecvTotal->execute($projectId > 0 ? [$agencyId, $projectId] : [$agencyId]);
    $recevTotal = (int)$stmtRecvTotal->fetchColumn();
} else {
    $where = ['pp.agency_id = ?'];
    $params = [$agencyId];
    if ($projectId > 0) {
        $where[] = 'pp.project_id = ?';
        $params[] = $projectId;
    } elseif ($visibleIds !== []) {
        $placeholders = implode(',', array_fill(0, count($visibleIds), '?'));
        $where[] = "(pp.project_id IS NULL OR pp.project_id IN ($placeholders))";
        $params = array_merge($params, $visibleIds);
    } else {
        $where[] = 'pp.project_id IS NULL';
    }

    if ($status === 'pendientes') {
        $where[] = "pp.status = 'pendiente'";
    } elseif ($status === 'parciales') {
        $where[] = "pp.status = 'pendiente' AND pp.paid_amount > 0";
    } elseif ($status === 'vencidas') {
        $where[] = "pp.status = 'pendiente' AND pp.due_date IS NOT NULL AND pp.due_date < CURDATE()";
    } elseif ($status === 'pagadas') {
        $where[] = "pp.status = 'pagada'";
    } elseif ($status === 'anuladas') {
        $where[] = "pp.status = 'anulada'";
    }

    if ($search !== '') {
        $where[] = '(pp.supplier LIKE ? OR pp.concept LIKE ? OR p.name LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmtCount = getDB()->prepare("SELECT COUNT(*) FROM payables pp LEFT JOIN projects p ON p.id = pp.project_id {$whereSql}");
    $stmtCount->execute($params);
    $filteredTotal = (int)$stmtCount->fetchColumn();
    $pages = max(1, (int)ceil($filteredTotal / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmtList = getDB()->prepare("
        SELECT pp.*, (pp.amount - pp.paid_amount) AS balance,
               p.name AS project_name, p.color AS project_color
        FROM payables pp
        LEFT JOIN projects p ON p.id = pp.project_id
        {$whereSql}
        ORDER BY (pp.status = 'pendiente') DESC, (pp.due_date IS NULL), pp.due_date ASC, pp.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmtList->execute($params);
    $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    $paymentsByPayable = [];
    if ($rows !== []) {
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtPayments = getDB()->prepare("
            SELECT pay.*, u.name AS user_name
            FROM payable_payments pay
            LEFT JOIN users u ON u.id = pay.created_by
            WHERE pay.payable_id IN ($placeholders)
            ORDER BY pay.paid_at DESC, pay.id DESC
        ");
        $stmtPayments->execute($ids);
        foreach ($stmtPayments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $paymentsByPayable[(int)$payment['payable_id']][] = [
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

    foreach ($rows as $row) {
        $isPaid = $row['status'] === 'pagada';
        $isOverdue = !$isPaid && !empty($row['due_date']) && $row['due_date'] < $today;
        $hasPayments = (float)$row['paid_amount'] > 0;
        $statusKey = $isPaid ? 'pagada' : ($hasPayments ? 'parcial' : 'pendiente');
        $payables[] = [
            'id' => (int)$row['id'],
            'supplier' => $row['supplier'],
            'concept' => $row['concept'],
            'category' => $row['category'],
            'kind' => $row['kind'] ?? 'direct',
            'amount' => (float)$row['amount'],
            'paid' => (float)$row['paid_amount'],
            'balance' => (float)$row['balance'],
            'currency' => $row['currency'] ?: 'USD',
            'dueDate' => $row['due_date'],
            'notes' => $row['notes'],
            'status' => $isPaid ? 'pagada' : $statusKey,
            'dbStatus' => $row['status'],
            'overdue' => $isOverdue,
            'daysOverdue' => $isOverdue ? (int)floor((strtotime($today) - strtotime((string)$row['due_date'])) / 86400) : 0,
            'projectId' => $row['project_id'] !== null ? (int)$row['project_id'] : null,
            'projectName' => $row['project_name'],
            'projectColor' => $row['project_color'] ?: '#0E66FF',
            'payments' => $paymentsByPayable[(int)$row['id']] ?? [],
        ];
    }

    $stmtPayTotal = getDB()->prepare('SELECT COUNT(*) FROM payables pp WHERE pp.agency_id = ?' . ($projectId > 0 ? ' AND pp.project_id = ?' : ''));
    $stmtPayTotal->execute($projectId > 0 ? [$agencyId, $projectId] : [$agencyId]);
    $payTotal = (int)$stmtPayTotal->fetchColumn();
}

$projects = getDB()->prepare("SELECT id, name, currency FROM projects WHERE id {$in} AND agency_id = ? ORDER BY name ASC");
$projects->execute([$agencyId]);

appJson([
    'tab' => $tab,
    'receivables' => $receivables,
    'payables' => $payables,
    'stats' => [
        'receivables' => [
            'pending' => $pendingByCurrency,
            'overdue' => $overdueByCurrency,
            'paid' => $paidByCurrency,
            'countPending' => $countPending,
            'countOverdue' => $countOverdue,
        ],
        'payables' => [
            'outstanding' => $paySummary['outstanding'],
            'overdue' => $paySummary['overdue'],
            'paid' => $paySummary['paid'],
            'countOpen' => $paySummary['count_open'],
            'countOverdue' => $paySummary['count_overdue'],
        ],
    ],
    'counts' => [
        'receivables' => $recevTotal,
        'payables' => $payTotal,
        'filtered' => $filteredTotal ?? 0,
    ],
    'pagination' => [
        'page' => $page ?? 1,
        'perPage' => $perPage,
        'total' => $filteredTotal ?? 0,
        'pages' => $pages ?? 1,
    ],
    'projects' => array_map(static fn(array $project): array => [
        'id' => (int)$project['id'],
        'name' => $project['name'],
        'currency' => strtoupper((string)($project['currency'] ?: 'USD')),
    ], $projects->fetchAll(PDO::FETCH_ASSOC)),
    'clients' => financeContactSuggestions($agencyId, null, false),
    'contacts' => financeContactSuggestions($agencyId),
    'currencies' => \App\Support\Format::CURRENCIES,
    'today' => $today,
]);

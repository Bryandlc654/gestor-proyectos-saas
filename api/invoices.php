<?php
// api/invoices.php - Gestión asíncrona de facturas, cobros y presupuestos de proyectos
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}
if (!moduleAllowed('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'El módulo de facturación está desactivado para tu rol']);
    exit;
}

$user = currentUser();
$db = getDB();
$csrf = $_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? '');
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$projectId = (int)($_POST['project_id'] ?? ($_GET['project_id'] ?? 0));

if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

// Verificar pertenencia o acceso al proyecto
if (!canAccessProject($projectId, $user['id'], $user['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso denegado al proyecto']);
    exit;
}

$isClient = ($user['role'] === 'cliente');
$canManageFinances = canManageProject($projectId, $user['id'], $user['role']);

$runCashAutomation = static function (callable $callback): void {
    try {
        $callback();
    } catch (\Throwable $e) {
        error_log('Caja automática: ' . $e->getMessage());
    }
};

// Factura de este proyecto o 404 (nunca se cruzan proyectos entre sí)
$requireInvoice = static function (int $invoiceId) use ($db, $projectId): array {
    $stmt = $db->prepare("SELECT id, invoice_number FROM invoices WHERE id = ? AND project_id = ?");
    $stmt->execute([$invoiceId, $projectId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Factura no encontrada']);
        exit;
    }
    return $invoice;
};

switch ($action) {
    case 'list':
        // Las finanzas nunca se comparten con la agencia invitada.
        if (!$canManageFinances) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Las finanzas solo las ve la agencia dueña del proyecto']);
            exit;
        }

        activityRepository()->logByUser((int)$user['id'], $projectId, 'finance.viewed', 'Consultó las finanzas del proyecto');

        $stmt = $db->prepare("
            SELECT * FROM invoices 
            WHERE project_id = ? 
            ORDER BY (status = 'pendiente') DESC, due_date ASC, created_at DESC
        ");
        $stmt->execute([$projectId]);
        $invoices = $stmt->fetchAll();

        // Obtener presupuesto y resumen del proyecto
        $stmtP = $db->prepare("SELECT budget, currency FROM projects WHERE id = ?");
        $stmtP->execute([$projectId]);
        $projectData = $stmtP->fetch() ?: ['budget' => 0, 'currency' => 'USD'];

        $totalInvoiced = 0;
        $totalPaid = 0;
        $totalPending = 0;

        $list = array_map(function($inv) use (&$totalInvoiced, &$totalPaid, &$totalPending) {
            $amt = floatval($inv['amount']);
            $paid = min($amt, max(0.0, floatval($inv['paid_amount'] ?? 0)));
            $balance = max(0.0, $amt - $paid);
            if ($inv['status'] !== 'anulada') {
                $totalInvoiced += $amt;
                $totalPaid += $paid;
                $totalPending += $balance;
            }

            $displayStatus = $inv['status'] === 'pagada' ? 'pagada' : ($inv['status'] === 'anulada' ? 'anulada' : ($paid > 0 ? 'parcial' : 'pendiente'));

            return [
                'id' => (int)$inv['id'],
                'project_id' => (int)$inv['project_id'],
                'invoice_number' => $inv['invoice_number'],
                'client_name' => $inv['client_name'] ?? null,
                'concept' => $inv['concept'],
                'amount' => $amt,
                'amount_formatted' => number_format($amt, 2),
                'paid' => $paid,
                'paid_formatted' => number_format($paid, 2),
                'balance' => $balance,
                'balance_formatted' => number_format($balance, 2),
                'currency' => $inv['currency'] ?: 'USD',
                'status' => $inv['status'],
                'display_status' => $displayStatus,
                'due_date' => $inv['due_date'],
                'due_date_formatted' => $inv['due_date'] ? formatDate($inv['due_date']) : '-',
                'paid_at' => $inv['paid_at'],
                'paid_at_formatted' => $inv['paid_at'] ? formatDate($inv['paid_at']) : null,
                'notes' => $inv['notes'],
                'created_at' => formatDate($inv['created_at'])
            ];
        }, $invoices);

        $budget = floatval($projectData['budget']);
        $balance = $budget - $totalPaid;

        echo json_encode([
            'success' => true,
            'budget' => $budget,
            'budget_formatted' => number_format($budget, 2),
            'currency' => $projectData['currency'] ?: 'USD',
            'summary' => [
                'total_invoiced' => $totalInvoiced,
                'total_invoiced_formatted' => number_format($totalInvoiced, 2),
                'total_paid' => $totalPaid,
                'total_paid_formatted' => number_format($totalPaid, 2),
                'total_pending' => $totalPending,
                'total_pending_formatted' => number_format($totalPending, 2),
                'balance' => $balance,
                'balance_formatted' => number_format($balance, 2)
            ],
            'invoices' => $list
        ]);
        break;

    case 'create':
        if (!$canManageFinances) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permisos para emitir facturas en este proyecto']);
            exit;
        }

        $invoiceNumber = trim($_POST['invoice_number'] ?? '');
        $clientName = trim((string)($_POST['client_name'] ?? ''));
        if (mb_strlen($clientName) > 150) {
            $clientName = mb_substr($clientName, 0, 150);
        }
        $concept = trim($_POST['concept'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $currency = strtoupper(trim((string)($_POST['currency'] ?? '')));
        if (!isValidCurrency($currency)) {
            // Sin moneda válida se usa la del proyecto (evita facturar en USD por defecto)
            $stmtCurrency = $db->prepare("SELECT currency FROM projects WHERE id = ?");
            $stmtCurrency->execute([$projectId]);
            $projectCurrency = (string)$stmtCurrency->fetchColumn();
            $currency = isValidCurrency($projectCurrency) ? strtoupper($projectCurrency) : 'USD';
        }
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $status = in_array($_POST['status'] ?? '', ['pendiente', 'pagada', 'anulada']) ? $_POST['status'] : 'pendiente';
        $paidAt = ($status === 'pagada') ? (!empty($_POST['paid_at']) ? $_POST['paid_at'] : date('Y-m-d')) : null;
        $notes = trim($_POST['notes'] ?? '');

        if (empty($concept)) {
            echo json_encode(['success' => false, 'message' => 'El concepto de la factura es obligatorio']);
            exit;
        }

        if ($amount <= 0) {
            echo json_encode(['success' => false, 'message' => 'El importe debe ser mayor a 0']);
            exit;
        }

        if (($dueDate !== null && !isIsoDate((string)$dueDate)) || ($paidAt !== null && !isIsoDate((string)$paidAt))) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'La fecha indicada no es válida']);
            exit;
        }

        $db->beginTransaction();
        try {
            // Bloquear la fila del proyecto para serializar la numeración entre peticiones simultáneas
            $db->prepare("SELECT id FROM projects WHERE id = ? FOR UPDATE")->execute([$projectId]);

            // Si no se proporcionó número, auto-generar el siguiente correlativo libre
            if (empty($invoiceNumber)) {
                $stmtCount = $db->prepare("SELECT COUNT(*) FROM invoices WHERE project_id = ?");
                $stmtCount->execute([$projectId]);
                $next = (int)$stmtCount->fetchColumn() + 1;

                $stmtExists = $db->prepare("SELECT 1 FROM invoices WHERE project_id = ? AND invoice_number = ?");
                do {
                    $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($next++, 3, '0', STR_PAD_LEFT);
                    $stmtExists->execute([$projectId, $invoiceNumber]);
                } while ($stmtExists->fetchColumn());
            }

            $paidAmount = $status === 'pagada' ? round((float)$amount, 2) : 0.0;
            $stmt = $db->prepare("
                INSERT INTO invoices (agency_id, project_id, invoice_number, client_name, concept, amount, paid_amount, currency, status, due_date, paid_at, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([currentAgencyId(), $projectId, $invoiceNumber, $clientName !== '' ? $clientName : null, $concept, $amount, $paidAmount, $currency, $status, $dueDate, $paidAt, $notes]);
            $invId = (int)$db->lastInsertId();

            if ($paidAmount > 0) {
                $db->prepare("
                    INSERT INTO invoice_payments (invoice_id, agency_id, amount, currency, paid_at, method, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, NULL, 'Cobro inicial al registrar la factura', ?)
                ")->execute([$invId, currentAgencyId(), $paidAmount, $currency, $paidAt ?: date('Y-m-d'), (int)$user['id']]);
            }
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            // 1062: entrada duplicada en el índice único (project_id, invoice_number)
            if (($e->errorInfo[1] ?? null) === 1062) {
                echo json_encode(['success' => false, 'message' => 'Ya existe una factura con ese número en este proyecto']);
                exit;
            }
            throw $e;
        }

        activityRepository()->logByUser((int)$user['id'], $projectId, 'invoice.created', 'Emitió la factura "' . $invoiceNumber . '" por ' . $amount . ' ' . $currency);
        if ($status === 'pagada') {
            foreach (financeRepository()->invoicePaymentIds($invId, currentAgencyId()) as $paymentId) {
                $runCashAutomation(static fn() => cashAutomationService()->syncInvoicePayment($paymentId, currentAgencyId(), (int)$user['id']));
            }
        }
        notificationService()->notifyAgencyAdmins(
            currentAgencyId(),
            'invoice.created',
            'Nueva factura emitida',
            $user['name'] . ' emitió la factura ' . $invoiceNumber . ' por ' . number_format($amount, 2) . ' ' . $currency . '.',
            'invoices',
            true,
            (int)$user['id']
        );
        echo json_encode([
            'success' => true,
            'message' => 'Factura / cobro registrado con éxito',
            'invoice_id' => $invId
        ]);
        break;

    case 'update_status':
        if (!$canManageFinances) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permisos para modificar el estado de facturas']);
            exit;
        }

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';

        if (!in_array($newStatus, ['pendiente', 'pagada', 'anulada'])) {
            echo json_encode(['success' => false, 'message' => 'Estado no válido']);
            exit;
        }

        $paidAt = ($newStatus === 'pagada') ? date('Y-m-d') : null;

        $stmtInv = $db->prepare("SELECT invoice_number, status, amount, paid_amount FROM invoices WHERE id = ? AND project_id = ?");
        $stmtInv->execute([$invoiceId, $projectId]);
        $invoiceRow = $stmtInv->fetch(PDO::FETCH_ASSOC);
        $invoiceNumber = $invoiceRow ? (string)$invoiceRow['invoice_number'] : ('#' . $invoiceId);
        $previousStatus = $invoiceRow ? (string)$invoiceRow['status'] : '';

        if ($newStatus === 'pagada') {
            $paymentId = financeRepository()->markInvoicePaid($invoiceId, currentAgencyId(), (int)$user['id']);
            if ($paymentId > 0) {
                $runCashAutomation(static fn() => cashAutomationService()->syncInvoicePayment($paymentId, currentAgencyId(), (int)$user['id']));
            }
        } elseif ($newStatus === 'pendiente' && $previousStatus === 'anulada') {
            $isFullyPaid = (float)$invoiceRow['paid_amount'] + 0.005 >= (float)$invoiceRow['amount'];
            $db->prepare("UPDATE invoices SET status = ?, paid_at = CASE WHEN ? = 'pagada' THEN (SELECT MAX(paid_at) FROM invoice_payments WHERE invoice_id = ?) ELSE NULL END WHERE id = ? AND project_id = ?")
                ->execute([$isFullyPaid ? 'pagada' : 'pendiente', $isFullyPaid ? 'pagada' : 'pendiente', $invoiceId, $invoiceId, $projectId]);
            foreach (financeRepository()->invoicePaymentIds($invoiceId, currentAgencyId()) as $paymentId) {
                $runCashAutomation(static fn() => cashAutomationService()->syncInvoicePayment($paymentId, currentAgencyId(), (int)$user['id']));
            }
        } elseif ($newStatus === 'pendiente') {
            $runCashAutomation(static fn() => cashAutomationService()->unsyncInvoicePayments($invoiceId, currentAgencyId()));
            $runCashAutomation(static fn() => cashAutomationService()->unsyncInvoice($invoiceId, currentAgencyId()));
            financeRepository()->reopenInvoice($invoiceId, currentAgencyId());
            $db->prepare("UPDATE invoices SET status = 'pendiente', paid_at = NULL WHERE id = ? AND project_id = ?")->execute([$invoiceId, $projectId]);
        } else {
            $runCashAutomation(static fn() => cashAutomationService()->unsyncInvoicePayments($invoiceId, currentAgencyId()));
            $runCashAutomation(static fn() => cashAutomationService()->unsyncInvoice($invoiceId, currentAgencyId()));
            $db->prepare("UPDATE invoices SET status = 'anulada', paid_at = NULL WHERE id = ? AND project_id = ?")->execute([$invoiceId, $projectId]);
        }
        activityRepository()->logByUser((int)$user['id'], $projectId, 'invoice.status_changed', 'Cambió la factura #' . $invoiceId . ' a ' . $newStatus);

        if ($newStatus === 'pagada') {
            notificationService()->notifyAgencyAdmins(
                currentAgencyId(),
                'invoice.paid',
                'Factura pagada',
                'La factura ' . $invoiceNumber . ' se marcó como pagada.',
                'invoices',
                true,
                (int)$user['id']
            );
        }

        echo json_encode([
            'success' => true,
            'message' => 'Estado de la factura actualizado',
            'status' => $newStatus,
            'paid_at' => $paidAt
        ]);
        break;

    case 'update_budget':
        if (!$canManageFinances) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permisos para modificar el costo de este proyecto']);
            exit;
        }

        $budget = floatval($_POST['budget'] ?? 0);
        $currency = strtoupper(trim((string)($_POST['currency'] ?? '')));

        if ($budget < 0) {
            echo json_encode(['success' => false, 'message' => 'El costo/presupuesto no puede ser negativo']);
            exit;
        }

        if (!isValidCurrency($currency)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Moneda no válida']);
            exit;
        }

        $stmt = $db->prepare("UPDATE projects SET budget = ?, currency = ? WHERE id = ?");
        $stmt->execute([$budget, $currency, $projectId]);
        activityRepository()->logByUser((int)$user['id'], $projectId, 'budget.updated', 'Actualizó el presupuesto a ' . $budget . ' ' . $currency);

        echo json_encode([
            'success' => true,
            'message' => 'Costo total del proyecto actualizado',
            'budget' => $budget,
            'budget_formatted' => number_format($budget, 2),
            'currency' => $currency
        ]);
        break;

    case 'documents_list':
        if (!$canManageFinances) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Las finanzas solo las ve la agencia dueña del proyecto']);
            exit;
        }

        $invoice = $requireInvoice((int)($_POST['invoice_id'] ?? 0));

        $stmtDocs = $db->prepare("
            SELECT id, original_name, file_size, file_type, created_at
            FROM project_files
            WHERE invoice_id = ? AND project_id = ?
            ORDER BY created_at DESC
        ");
        $stmtDocs->execute([(int)$invoice['id'], $projectId]);

        $documents = array_map(static function (array $doc): array {
            return [
                'id' => (int)$doc['id'],
                'name' => $doc['original_name'],
                'size' => (int)$doc['file_size'],
                'size_formatted' => formatBytes((int)$doc['file_size']),
                'type' => $doc['file_type'],
                'created_at' => formatDate($doc['created_at'], true),
                'download_url' => 'api/files.php?action=download&id=' . (int)$doc['id'],
            ];
        }, $stmtDocs->fetchAll(PDO::FETCH_ASSOC));

        echo json_encode([
            'success' => true,
            'invoice_id' => (int)$invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'documents' => $documents,
        ]);
        break;

    case 'document_upload':
        if (!$canManageFinances) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permisos para adjuntar documentos a facturas']);
            exit;
        }

        $invoice = $requireInvoice((int)($_POST['invoice_id'] ?? 0));

        if (!isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'message' => 'Selecciona un archivo para subir']);
            exit;
        }

        $stored = storeUploadedAttachment($_FILES['file']);
        if (!$stored['ok']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $stored['error']]);
            exit;
        }

        $db->prepare("
            INSERT INTO project_files (project_id, user_id, invoice_id, original_name, stored_name, file_size, file_type)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $projectId,
            (int)$user['id'],
            (int)$invoice['id'],
            $stored['original_name'],
            $stored['stored_name'],
            $stored['size'],
            $stored['mime'],
        ]);
        $documentId = (int)$db->lastInsertId();

        activityRepository()->logByUser((int)$user['id'], $projectId, 'invoice.document_uploaded', 'Adjuntó "' . $stored['original_name'] . '" a la factura ' . $invoice['invoice_number']);

        echo json_encode([
            'success' => true,
            'message' => 'Documento subido',
            'document' => [
                'id' => $documentId,
                'name' => $stored['original_name'],
                'size_formatted' => formatBytes($stored['size']),
                'download_url' => 'api/files.php?action=download&id=' . $documentId,
            ],
        ]);
        break;

    case 'document_delete':
        if (!$canManageFinances) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permisos para eliminar documentos de facturas']);
            exit;
        }

        $documentId = (int)($_POST['document_id'] ?? 0);
        $stmtDoc = $db->prepare("
            SELECT id, stored_name, original_name
            FROM project_files
            WHERE id = ? AND project_id = ? AND invoice_id IS NOT NULL
        ");
        $stmtDoc->execute([$documentId, $projectId]);
        $document = $stmtDoc->fetch(PDO::FETCH_ASSOC);

        if (!$document) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Documento no encontrado']);
            exit;
        }

        $db->prepare("DELETE FROM project_files WHERE id = ?")->execute([$documentId]);
        $filePath = dirname(__DIR__) . '/uploads/' . $document['stored_name'];
        if (is_file($filePath)) {
            @unlink($filePath);
        }

        activityRepository()->logByUser((int)$user['id'], $projectId, 'invoice.document_deleted', 'Eliminó el documento "' . $document['original_name'] . '"');

        echo json_encode(['success' => true, 'message' => 'Documento eliminado']);
        break;

    case 'delete':
        if (!$canManageFinances) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permisos para eliminar facturas']);
            exit;
        }

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);

        // Recuperar los documentos antes del borrado en cascada para eliminar también los archivos
        $stmtDocs = $db->prepare("SELECT stored_name FROM project_files WHERE invoice_id = ? AND project_id = ?");
        $stmtDocs->execute([$invoiceId, $projectId]);
        $storedFiles = $stmtDocs->fetchAll(PDO::FETCH_COLUMN);

        $paymentIds = financeRepository()->invoicePaymentIds($invoiceId, currentAgencyId());
        $stmt = $db->prepare("DELETE FROM invoices WHERE id = ? AND project_id = ?");
        $stmt->execute([$invoiceId, $projectId]);
        foreach ($paymentIds as $paymentId) {
            $runCashAutomation(static fn() => cashAutomationService()->unsyncInvoicePayment($paymentId, currentAgencyId()));
        }
        $runCashAutomation(static fn() => cashAutomationService()->unsyncInvoice($invoiceId, currentAgencyId()));

        foreach ($storedFiles as $storedName) {
            $filePath = dirname(__DIR__) . '/uploads/' . $storedName;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }

        activityRepository()->logByUser((int)$user['id'], $projectId, 'invoice.deleted', 'Eliminó la factura #' . $invoiceId);

        echo json_encode(['success' => true, 'message' => 'Factura eliminada']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
        break;
}

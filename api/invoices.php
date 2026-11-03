<?php
// api/invoices.php - Gestión asíncrona de facturas, cobros y presupuestos de proyectos
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
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

switch ($action) {
    case 'list':
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
            if ($inv['status'] !== 'anulada') {
                $totalInvoiced += $amt;
                if ($inv['status'] === 'pagada') {
                    $totalPaid += $amt;
                } elseif ($inv['status'] === 'pendiente') {
                    $totalPending += $amt;
                }
            }

            return [
                'id' => (int)$inv['id'],
                'project_id' => (int)$inv['project_id'],
                'invoice_number' => $inv['invoice_number'],
                'concept' => $inv['concept'],
                'amount' => $amt,
                'amount_formatted' => number_format($amt, 2),
                'currency' => $inv['currency'] ?: 'USD',
                'status' => $inv['status'],
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
        $concept = trim($_POST['concept'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? 'USD');
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

        // Si no se proporcionó número, auto-generar uno correlativo
        if (empty($invoiceNumber)) {
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM invoices WHERE project_id = ?");
            $stmtCount->execute([$projectId]);
            $count = (int)$stmtCount->fetchColumn() + 1;
            $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
        }

        $stmt = $db->prepare("
            INSERT INTO invoices (project_id, invoice_number, concept, amount, currency, status, due_date, paid_at, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$projectId, $invoiceNumber, $concept, $amount, $currency, $status, $dueDate, $paidAt, $notes]);
        $invId = (int)$db->lastInsertId();

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

        $stmt = $db->prepare("UPDATE invoices SET status = ?, paid_at = ? WHERE id = ? AND project_id = ?");
        $stmt->execute([$newStatus, $paidAt, $invoiceId, $projectId]);

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
        $currency = trim($_POST['currency'] ?? 'USD');

        if ($budget < 0) {
            echo json_encode(['success' => false, 'message' => 'El costo/presupuesto no puede ser negativo']);
            exit;
        }

        $stmt = $db->prepare("UPDATE projects SET budget = ?, currency = ? WHERE id = ?");
        $stmt->execute([$budget, $currency, $projectId]);

        echo json_encode([
            'success' => true,
            'message' => 'Costo total del proyecto actualizado',
            'budget' => $budget,
            'budget_formatted' => number_format($budget, 2),
            'currency' => $currency
        ]);
        break;

    case 'delete':
        if (!$canManageFinances) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permisos para eliminar facturas']);
            exit;
        }

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM invoices WHERE id = ? AND project_id = ?");
        $stmt->execute([$invoiceId, $projectId]);

        echo json_encode(['success' => true, 'message' => 'Factura eliminada']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
        break;
}

<?php

namespace App\Repository;

use DomainException;
use App\Support\Format;
use PDO;
use Throwable;

/**
 * Cuentas por pagar (proveedores/gastos) y sus pagos parciales.
 * Las cuentas por cobrar se gestionan con `invoices`.
 */
final class FinanceRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{supplier:string,concept:string,category:?string,kind?:string,project_id:?int,amount:float,currency:string,due_date:?string,notes:?string} $data
     */
    public function createPayable(int $agencyId, int $userId, array $data): int
    {
        $kind = (string)($data['kind'] ?? 'direct');
        if (!in_array($kind, ['direct', 'collaborator'], true)) {
            $kind = 'direct';
        }

        $stmt = $this->db->prepare("
            INSERT INTO payables (agency_id, project_id, supplier, concept, category, kind, amount, paid_amount, currency, due_date, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $agencyId,
            $data['project_id'],
            $data['supplier'],
            $data['concept'],
            $data['category'],
            $kind,
            $data['amount'],
            $data['currency'],
            $data['due_date'],
            $data['notes'],
            $userId,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * @param array{supplier:string,concept:string,category:?string,kind?:string,project_id:?int,amount:float,currency:string,due_date:?string,notes:?string} $data
     */
    public function updatePayable(int $payableId, int $agencyId, array $data): void
    {
        $payable = $this->find($payableId, $agencyId);
        if ($payable === null) {
            throw new DomainException('Cuenta por pagar no encontrada.');
        }

        $paid = (float)$payable['paid_amount'];
        if ((float)$data['amount'] + 0.005 < $paid) {
            throw new DomainException('El importe no puede ser menor que lo ya pagado.');
        }
        if ($paid > 0 && $data['currency'] !== $payable['currency']) {
            throw new DomainException('No puedes cambiar la moneda de una cuenta que ya tiene pagos.');
        }

        $kind = (string)($data['kind'] ?? 'direct');
        if (!in_array($kind, ['direct', 'collaborator'], true)) {
            $kind = 'direct';
        }

        $this->db->prepare("
            UPDATE payables
            SET project_id = ?, supplier = ?, concept = ?, category = ?, kind = ?, amount = ?, currency = ?, due_date = ?, notes = ?
            WHERE id = ? AND agency_id = ?
        ")->execute([
            $data['project_id'],
            $data['supplier'],
            $data['concept'],
            $data['category'],
            $kind,
            $data['amount'],
            $data['currency'],
            $data['due_date'],
            $data['notes'],
            $payableId,
            $agencyId,
        ]);

        $this->refresh($payableId, $agencyId);
    }

    public function deletePayable(int $payableId, int $agencyId): void
    {
        $this->db->prepare('DELETE FROM payables WHERE id = ? AND agency_id = ?')
            ->execute([$payableId, $agencyId]);
    }

    /**
     * Crea una cuenta por cobrar manual, asociada o no a un proyecto.
     * Si no se indica número, se genera el siguiente correlativo libre.
     *
     * @param array{project_id:?int,invoice_number:string,client_name:?string,concept:string,amount:float,currency:string,due_date:?string,status:string,notes:?string} $data
     */
    public function createReceivable(int $agencyId, int $userId, array $data): int
    {
        $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;

        $this->db->beginTransaction();
        try {
            if ($projectId !== null) {
                $stmtProject = $this->db->prepare("SELECT id FROM projects WHERE id = ? AND agency_id = ? FOR UPDATE");
                $stmtProject->execute([$projectId, $agencyId]);
                if ($stmtProject->fetchColumn() === false) {
                    throw new DomainException('El proyecto seleccionado no es válido.');
                }
            }

            $invoiceNumber = trim($data['invoice_number']);
            if ($invoiceNumber !== '') {
                // El índice único (project_id, invoice_number) no protege las cuentas sin proyecto (NULL)
                $stmtDuplicate = $this->db->prepare("SELECT 1 FROM invoices WHERE agency_id = ? AND invoice_number = ? AND project_id <=> ?");
                $stmtDuplicate->execute([$agencyId, $invoiceNumber, $projectId]);
                if ($stmtDuplicate->fetchColumn()) {
                    throw new DomainException('Ya existe una cuenta por cobrar con ese número.');
                }
            }
            if ($invoiceNumber === '') {
                if ($projectId !== null) {
                    $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM invoices WHERE project_id = ?");
                    $stmtCount->execute([$projectId]);
                    $next = (int)$stmtCount->fetchColumn() + 1;
                    $stmtExists = $this->db->prepare("SELECT 1 FROM invoices WHERE project_id = ? AND invoice_number = ?");
                } else {
                    $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM invoices WHERE agency_id = ? AND project_id IS NULL");
                    $stmtCount->execute([$agencyId]);
                    $next = (int)$stmtCount->fetchColumn() + 1;
                    $stmtExists = $this->db->prepare("SELECT 1 FROM invoices WHERE agency_id = ? AND project_id IS NULL AND invoice_number = ?");
                }

                do {
                    $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad((string)$next++, 3, '0', STR_PAD_LEFT);
                    $stmtExists->execute($projectId !== null ? [$projectId, $invoiceNumber] : [$agencyId, $invoiceNumber]);
                } while ($stmtExists->fetchColumn());
            }

            $status = $data['status'] === 'pagada' ? 'pagada' : 'pendiente';
            $paidAt = $status === 'pagada' ? date('Y-m-d') : null;
            $paidAmount = $status === 'pagada' ? round((float)$data['amount'], 2) : 0.0;

            try {
                $this->db->prepare("
                    INSERT INTO invoices (agency_id, project_id, invoice_number, client_name, concept, amount, paid_amount, currency, status, due_date, paid_at, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $agencyId,
                    $projectId,
                    $invoiceNumber,
                    $data['client_name'],
                    $data['concept'],
                    $data['amount'],
                    $paidAmount,
                    $data['currency'],
                    $status,
                    $data['due_date'],
                    $paidAt,
                    $data['notes'],
                ]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new DomainException('Ya existe una cuenta por cobrar con ese número.');
                }
                throw $e;
            }

            $invoiceId = (int)$this->db->lastInsertId();

            // Si nace cobrada, deja también su registro en el historial de cobros.
            if ($paidAmount > 0) {
                $this->db->prepare("
                    INSERT INTO invoice_payments (invoice_id, agency_id, amount, currency, paid_at, method, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, NULL, 'Cobro inicial al registrar la factura', ?)
                ")->execute([
                    $invoiceId,
                    $agencyId,
                    $paidAmount,
                    $data['currency'],
                    $paidAt,
                    $userId,
                ]);
            }

            $this->db->commit();

            return $invoiceId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function find(int $payableId, int $agencyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT pp.*, (pp.amount - pp.paid_amount) AS balance
            FROM payables pp
            WHERE pp.id = ? AND pp.agency_id = ?
        ");
        $stmt->execute([$payableId, $agencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function payments(int $payableId, int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.*, u.name AS user_name
            FROM payable_payments p
            INNER JOIN payables pa ON pa.id = p.payable_id
            LEFT JOIN users u ON u.id = p.created_by
            WHERE p.payable_id = ? AND pa.agency_id = ?
            ORDER BY p.paid_at DESC, p.id DESC
        ");
        $stmt->execute([$payableId, $agencyId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addPayment(
        int $payableId,
        int $agencyId,
        int $userId,
        float $amount,
        string $paidAt,
        ?string $method,
        ?string $notes
    ): int {
        $payable = $this->find($payableId, $agencyId);
        if ($payable === null) {
            throw new DomainException('Cuenta por pagar no encontrada.');
        }
        if ($payable['status'] === 'anulada') {
            throw new DomainException('Esta cuenta está anulada y no admite pagos.');
        }

        $balance = round((float)$payable['amount'] - (float)$payable['paid_amount'], 2);
        if ($amount <= 0) {
            throw new DomainException('El importe del pago debe ser mayor que cero.');
        }
        if ($amount > $balance + 0.005) {
            throw new DomainException('El pago supera el saldo pendiente (' . Format::money($balance, $payable['currency']) . ').');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                INSERT INTO payable_payments (payable_id, agency_id, amount, currency, paid_at, method, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $payableId,
                $agencyId,
                $amount,
                $payable['currency'],
                $paidAt,
                $method,
                $notes,
                $userId,
            ]);
            $paymentId = (int)$this->db->lastInsertId();

            $this->refresh($payableId, $agencyId);
            $this->db->commit();

            return $paymentId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function deletePayment(int $paymentId, int $agencyId): void
    {
        $stmt = $this->db->prepare("
            SELECT p.id, p.payable_id
            FROM payable_payments p
            INNER JOIN payables pa ON pa.id = p.payable_id
            WHERE p.id = ? AND pa.agency_id = ?
        ");
        $stmt->execute([$paymentId, $agencyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($payment === false) {
            throw new DomainException('Pago no encontrado.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM payable_payments WHERE id = ?')->execute([$paymentId]);
            $this->refresh((int)$payment['payable_id'], $agencyId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Actualiza los datos editables de una factura sin tocar sus cobros.
     *
     * @param array{project_id:?int,invoice_number:string,client_name:?string,concept:string,amount:float,currency:string,due_date:?string,notes:?string} $data
     */
    public function updateInvoice(int $invoiceId, int $agencyId, array $data): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM invoices WHERE id = ? AND agency_id = ? FOR UPDATE');
            $stmt->execute([$invoiceId, $agencyId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($invoice === false) {
                throw new DomainException('Factura no encontrada.');
            }

            $paid = (float)$invoice['paid_amount'];
            $amount = round((float)$data['amount'], 2);
            $currency = strtoupper((string)$data['currency']);
            $projectId = $data['project_id'] !== null ? (int)$data['project_id'] : null;
            $previousProjectId = $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null;

            if ($amount + 0.005 < $paid) {
                throw new DomainException('El importe no puede ser menor que lo ya cobrado (' . Format::money($paid, (string)$invoice['currency']) . ').');
            }
            if ($paid > 0 && $currency !== strtoupper((string)$invoice['currency'])) {
                throw new DomainException('No puedes cambiar la moneda de una factura que ya tiene cobros.');
            }
            if ($paid > 0 && $projectId !== $previousProjectId) {
                throw new DomainException('No puedes cambiar el proyecto de una factura que ya tiene cobros.');
            }

            $this->db->prepare("
                UPDATE invoices
                SET project_id = ?, invoice_number = ?, client_name = ?, concept = ?, amount = ?, currency = ?, due_date = ?, notes = ?
                WHERE id = ? AND agency_id = ?
            ")->execute([
                $projectId,
                $data['invoice_number'],
                $data['client_name'],
                $data['concept'],
                $amount,
                $currency,
                $data['due_date'],
                $data['notes'],
                $invoiceId,
                $agencyId,
            ]);

            $this->refreshInvoice($invoiceId, $agencyId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public function findInvoice(int $invoiceId, int $agencyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT i.*, GREATEST(i.amount - i.paid_amount, 0) AS balance
            FROM invoices i
            WHERE i.id = ? AND i.agency_id = ?
        ");
        $stmt->execute([$invoiceId, $agencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public function invoicePayments(int $invoiceId, int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT pay.*, u.name AS user_name
            FROM invoice_payments pay
            INNER JOIN invoices i ON i.id = pay.invoice_id
            LEFT JOIN users u ON u.id = pay.created_by
            WHERE pay.invoice_id = ? AND i.agency_id = ?
            ORDER BY pay.paid_at DESC, pay.id DESC
        ");
        $stmt->execute([$invoiceId, $agencyId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return int[] */
    public function invoicePaymentIds(int $invoiceId, int $agencyId): array
    {
        $stmt = $this->db->prepare('SELECT id FROM invoice_payments WHERE invoice_id = ? AND agency_id = ? ORDER BY id ASC');
        $stmt->execute([$invoiceId, $agencyId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function addInvoicePayment(
        int $invoiceId,
        int $agencyId,
        int $userId,
        float $amount,
        string $paidAt,
        ?string $method,
        ?string $notes
    ): int {
        $invoice = $this->findInvoice($invoiceId, $agencyId);
        if ($invoice === null) {
            throw new DomainException('Factura no encontrada.');
        }
        if ($invoice['status'] === 'anulada') {
            throw new DomainException('Esta factura está anulada y no admite cobros.');
        }

        $balance = round((float)$invoice['amount'] - (float)$invoice['paid_amount'], 2);
        if ($amount <= 0) {
            throw new DomainException('El importe del cobro debe ser mayor que cero.');
        }
        if ($amount > $balance + 0.005) {
            throw new DomainException('El cobro supera el saldo pendiente (' . Format::money($balance, $invoice['currency']) . ').');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                INSERT INTO invoice_payments (invoice_id, agency_id, amount, currency, paid_at, method, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $invoiceId,
                $agencyId,
                $amount,
                $invoice['currency'],
                $paidAt,
                $method,
                $notes,
                $userId,
            ]);
            $paymentId = (int)$this->db->lastInsertId();

            $this->refreshInvoice($invoiceId, $agencyId);
            $this->db->commit();

            return $paymentId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Cobra el saldo pendiente completo de una factura. */
    public function markInvoicePaid(int $invoiceId, int $agencyId, int $userId, ?string $paidAt = null): int
    {
        $invoice = $this->findInvoice($invoiceId, $agencyId);
        if ($invoice === null) {
            throw new DomainException('Factura no encontrada.');
        }
        if ($invoice['status'] === 'anulada') {
            throw new DomainException('Esta factura está anulada y no admite cobros.');
        }

        $balance = round((float)$invoice['amount'] - (float)$invoice['paid_amount'], 2);
        if ($balance <= 0.005) {
            return 0;
        }

        return $this->addInvoicePayment(
            $invoiceId,
            $agencyId,
            $userId,
            $balance,
            $paidAt ?? date('Y-m-d'),
            null,
            'Cobro del saldo pendiente'
        );
    }

    /** Elimina todos los cobros de una factura y la devuelve a pendiente. */
    public function reopenInvoice(int $invoiceId, int $agencyId): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM invoice_payments WHERE invoice_id = ? AND agency_id = ?')
                ->execute([$invoiceId, $agencyId]);
            $this->db->prepare("
                UPDATE invoices
                SET paid_amount = 0, paid_at = NULL,
                    status = CASE WHEN status = 'anulada' THEN 'anulada' ELSE 'pendiente' END
                WHERE id = ? AND agency_id = ?
            ")->execute([$invoiceId, $agencyId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteInvoicePayment(int $paymentId, int $agencyId): void
    {
        $stmt = $this->db->prepare("
            SELECT pay.id, pay.invoice_id
            FROM invoice_payments pay
            INNER JOIN invoices i ON i.id = pay.invoice_id
            WHERE pay.id = ? AND i.agency_id = ?
        ");
        $stmt->execute([$paymentId, $agencyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($payment === false) {
            throw new DomainException('Cobro no encontrado.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM invoice_payments WHERE id = ?')->execute([$paymentId]);
            $this->refreshInvoice((int)$payment['invoice_id'], $agencyId);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Recalcula el total cobrado y el estado de la factura desde su historial. */
    private function refreshInvoice(int $invoiceId, int $agencyId): void
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) AS paid, MAX(paid_at) AS last_paid_at
            FROM invoice_payments
            WHERE invoice_id = ?
        ");
        $stmt->execute([$invoiceId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['paid' => 0, 'last_paid_at' => null];

        $invoice = $this->findInvoice($invoiceId, $agencyId);
        if ($invoice === null) {
            return;
        }

        $amount = (float)$invoice['amount'];
        $paid = min(round((float)$totals['paid'], 2), $amount);
        $isPaid = $paid + 0.005 >= $amount;

        $this->db->prepare("
            UPDATE invoices
            SET paid_amount = ?, status = ?, paid_at = ?
            WHERE id = ? AND agency_id = ?
        ")->execute([
            $paid,
            $invoice['status'] === 'anulada' ? 'anulada' : ($isPaid ? 'pagada' : 'pendiente'),
            $isPaid && $invoice['status'] !== 'anulada' ? $totals['last_paid_at'] : null,
            $invoiceId,
            $agencyId,
        ]);
    }

    /** Recalcula el total pagado y el estado a partir del historial de pagos. */
    private function refresh(int $payableId, int $agencyId): void
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) AS paid, MAX(paid_at) AS last_paid_at
            FROM payable_payments
            WHERE payable_id = ?
        ");
        $stmt->execute([$payableId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['paid' => 0, 'last_paid_at' => null];

        $payable = $this->find($payableId, $agencyId);
        if ($payable === null) {
            return;
        }

        $amount = (float)$payable['amount'];
        $paid = min(round((float)$totals['paid'], 2), $amount);
        $isPaid = $paid + 0.005 >= $amount;

        $this->db->prepare("
            UPDATE payables
            SET paid_amount = ?, status = ?, paid_at = ?
            WHERE id = ? AND agency_id = ?
        ")->execute([
            $paid,
            $isPaid ? 'pagada' : 'pendiente',
            $isPaid ? $totals['last_paid_at'] : null,
            $payableId,
            $agencyId,
        ]);
    }

    /**
     * Resumen de cuentas por pagar por moneda.
     *
     * @param int[] $projectIds Proyectos visibles para el usuario
     * @param int|null $projectId Si se indica, limita el resumen a ese proyecto
     * @return array{outstanding:array<string,float>,overdue:array<string,float>,paid:array<string,float>,count_open:int,count_overdue:int}
     */
    public function summary(int $agencyId, array $projectIds, ?int $projectId = null): array
    {
        $conditions = ['agency_id = ?'];
        $params = [$agencyId];

        $projectIds = array_values(array_unique(array_map('intval', $projectIds)));
        if ($projectId !== null && $projectId > 0) {
            $conditions[] = 'project_id = ?';
            $params[] = $projectId;
        } elseif ($projectIds !== []) {
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            $conditions[] = "(project_id IS NULL OR project_id IN ($placeholders))";
            $params = array_merge($params, $projectIds);
        } else {
            $conditions[] = 'project_id IS NULL';
        }

        $stmt = $this->db->prepare("
            SELECT currency,
                   SUM(CASE WHEN status = 'pendiente' THEN GREATEST(amount - paid_amount, 0) ELSE 0 END) AS outstanding,
                   SUM(CASE WHEN status = 'pendiente' AND due_date IS NOT NULL AND due_date < CURDATE() THEN GREATEST(amount - paid_amount, 0) ELSE 0 END) AS overdue,
                   SUM(CASE WHEN status <> 'anulada' THEN paid_amount ELSE 0 END) AS paid,
                   SUM(CASE WHEN status = 'pendiente' THEN 1 ELSE 0 END) AS count_open,
                   SUM(CASE WHEN status = 'pendiente' AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS count_overdue
            FROM payables
            WHERE " . implode(' AND ', $conditions) . "
            GROUP BY currency
        ");
        $stmt->execute($params);

        $summary = [
            'outstanding' => [],
            'overdue' => [],
            'paid' => [],
            'count_open' => 0,
            'count_overdue' => 0,
        ];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $currency = strtoupper((string)($row['currency'] ?: 'USD'));
            $summary['outstanding'][$currency] = ($summary['outstanding'][$currency] ?? 0) + (float)$row['outstanding'];
            $summary['overdue'][$currency] = ($summary['overdue'][$currency] ?? 0) + (float)$row['overdue'];
            $summary['paid'][$currency] = ($summary['paid'][$currency] ?? 0) + (float)$row['paid'];
            $summary['count_open'] += (int)$row['count_open'];
            $summary['count_overdue'] += (int)$row['count_overdue'];
        }

        return $summary;
    }
}

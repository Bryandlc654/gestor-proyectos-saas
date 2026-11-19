<?php

namespace App\Service;

use App\Repository\CashRepository;
use DomainException;
use PDO;
use Throwable;

/**
 * Sincronización controlada entre facturas/pagos y la caja.
 *
 * Cada movimiento generado guarda `source_type` + `source_id`, lo que permite:
 * - no duplicarlo si se repite el evento,
 * - eliminarlo al deshacer el cobro/pago,
 * - revisarlo en una pantalla aparte.
 */
final class CashAutomationService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CashRepository $cash
    ) {
    }

    /**
     * Crea reglas iniciales para las monedas que ya tienen una cuenta de caja.
     * No reactiva reglas que el administrador haya desactivado.
     */
    public function ensureDefaults(int $agencyId): void
    {
        $this->cash->ensureDefaults($agencyId);

        $accounts = $this->cash->accounts($agencyId, false);
        if ($accounts === []) {
            return;
        }

        $categories = $this->cash->categories($agencyId, null, false);
        $invoiceCategory = $this->pickCategory($categories, 'ingreso', ['Cobro de facturas', 'Ventas', 'Otros ingresos']);
        $payableCategory = $this->pickCategory($categories, 'egreso', ['Proveedores', 'Otros egresos', 'Servicios']);
        if ($invoiceCategory === null || $payableCategory === null) {
            return;
        }

        $stmt = $this->db->prepare('SELECT kind, currency FROM cash_automation_rules WHERE agency_id = ?');
        $stmt->execute([$agencyId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[$row['kind'] . ':' . strtoupper((string)$row['currency'])] = true;
        }

        $accountsByCurrency = [];
        foreach ($accounts as $account) {
            if ((int)$account['is_active'] !== 1) {
                continue;
            }
            $currency = strtoupper((string)$account['currency']);
            $accountsByCurrency[$currency] ??= $account;
        }

        $insert = $this->db->prepare("
            INSERT IGNORE INTO cash_automation_rules
                (agency_id, kind, currency, account_id, category_id, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");

        foreach (array_keys($accountsByCurrency) as $currency) {
            foreach (['invoice', 'payable'] as $kind) {
                if (isset($existing[$kind . ':' . $currency])) {
                    continue;
                }
                $account = $accountsByCurrency[$currency];
                $category = $kind === 'invoice' ? $invoiceCategory : $payableCategory;
                $insert->execute([
                    $agencyId,
                    $kind,
                    $currency,
                    (int)$account['id'],
                    (int)$category['id'],
                ]);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function rules(int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, a.name AS account_name, a.currency AS account_currency, a.is_active AS account_active,
                   c.name AS category_name, c.type AS category_type, c.is_active AS category_active
            FROM cash_automation_rules r
            INNER JOIN cash_accounts a ON a.id = r.account_id
            INNER JOIN cash_categories c ON c.id = r.category_id
            WHERE r.agency_id = ?
            ORDER BY r.kind ASC, r.currency ASC
        ");
        $stmt->execute([$agencyId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Guarda las reglas existentes. El formulario envía una fila por regla.
     *
     * @param array<int|string, array{account_id?:mixed,category_id?:mixed,is_active?:mixed}> $posted
     */
    public function saveRules(int $agencyId, array $posted, int $userId): void
    {
        $this->db->beginTransaction();
        try {
            foreach ($posted as $ruleId => $values) {
                $ruleId = (int)$ruleId;
                if ($ruleId <= 0 || !is_array($values)) {
                    continue;
                }

                $stmt = $this->db->prepare('SELECT * FROM cash_automation_rules WHERE id = ? AND agency_id = ?');
                $stmt->execute([$ruleId, $agencyId]);
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($rule === false) {
                    continue;
                }

                $accountId = (int)($values['account_id'] ?? 0);
                $categoryId = (int)($values['category_id'] ?? 0);
                $isActive = !empty($values['is_active']);

                if (!$isActive) {
                    $this->db->prepare("
                        UPDATE cash_automation_rules
                        SET is_active = 0, updated_by = ?
                        WHERE id = ? AND agency_id = ?
                    ")->execute([$userId, $ruleId, $agencyId]);
                    continue;
                }

                $account = $this->cash->findAccount($accountId, $agencyId);
                $category = $this->cash->findCategory($categoryId, $agencyId);
                if ($account === null || $category === null) {
                    throw new DomainException('La cuenta o la categoría de la regla no es válida.');
                }
                if (strtoupper((string)$account['currency']) !== strtoupper((string)$rule['currency'])) {
                    throw new DomainException('La cuenta debe usar la misma moneda que la regla (' . $rule['currency'] . ').');
                }
                $expectedType = $rule['kind'] === 'invoice' ? 'ingreso' : 'egreso';
                if ($category['type'] !== $expectedType) {
                    throw new DomainException('La categoría no corresponde al tipo de movimiento de la regla.');
                }

                $this->db->prepare("
                    UPDATE cash_automation_rules
                    SET account_id = ?, category_id = ?, is_active = 1, updated_by = ?
                    WHERE id = ? AND agency_id = ?
                ")->execute([
                    $accountId,
                    $categoryId,
                    $userId,
                    $ruleId,
                    $agencyId,
                ]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function syncInvoice(int $invoiceId, int $agencyId, ?int $userId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT id, project_id, invoice_number, concept, client_name, amount, currency, status, paid_at
            FROM invoices
            WHERE id = ? AND agency_id = ?
        ");
        $stmt->execute([$invoiceId, $agencyId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false || $invoice['status'] !== 'pagada') {
            return null;
        }

        $this->ensureDefaults($agencyId);

        $currency = strtoupper((string)$invoice['currency']);
        $rule = $this->ruleFor($agencyId, 'invoice', $currency);
        if ($rule === null) {
            return null;
        }

        $existing = $this->cash->findBySource($agencyId, 'invoice', $invoiceId);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        $description = 'Cobro factura ' . $invoice['invoice_number'];
        if (trim((string)$invoice['concept']) !== '') {
            $description .= ' · ' . $invoice['concept'];
        }

        return $this->cash->createTransaction($agencyId, $userId, [
            'account_id' => (int)$rule['account_id'],
            'category_id' => (int)$rule['category_id'],
            'project_id' => $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null,
            'type' => 'ingreso',
            'amount' => (float)$invoice['amount'],
            'occurred_on' => $invoice['paid_at'] ?: date('Y-m-d'),
            'description' => mb_substr($description, 0, 255),
            'reference' => $invoice['invoice_number'],
            'notes' => null,
        ], 'invoice', $invoiceId);
    }

    public function unsyncInvoice(int $invoiceId, int $agencyId): int
    {
        return $this->cash->deleteBySource($agencyId, 'invoice', $invoiceId);
    }

    public function syncInvoicePayment(int $paymentId, int $agencyId, ?int $userId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT pay.id, pay.amount, pay.currency, pay.paid_at, pay.invoice_id,
                   i.project_id, i.invoice_number, i.concept
            FROM invoice_payments pay
            INNER JOIN invoices i ON i.id = pay.invoice_id
            WHERE pay.id = ? AND pay.agency_id = ?
        ");
        $stmt->execute([$paymentId, $agencyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($payment === false) {
            return null;
        }

        $this->ensureDefaults($agencyId);

        $currency = strtoupper((string)$payment['currency']);
        $rule = $this->ruleFor($agencyId, 'invoice', $currency);
        if ($rule === null) {
            return null;
        }

        $existing = $this->cash->findBySource($agencyId, 'invoice_payment', $paymentId);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        $description = 'Cobro factura ' . $payment['invoice_number'];
        if (trim((string)$payment['concept']) !== '') {
            $description .= ' · ' . $payment['concept'];
        }

        return $this->cash->createTransaction($agencyId, $userId, [
            'account_id' => (int)$rule['account_id'],
            'category_id' => (int)$rule['category_id'],
            'project_id' => $payment['project_id'] !== null ? (int)$payment['project_id'] : null,
            'type' => 'ingreso',
            'amount' => (float)$payment['amount'],
            'occurred_on' => $payment['paid_at'] ?: date('Y-m-d'),
            'description' => mb_substr($description, 0, 255),
            'reference' => 'COBRO-' . $paymentId,
            'notes' => null,
        ], 'invoice_payment', $paymentId);
    }

    public function unsyncInvoicePayment(int $paymentId, int $agencyId): int
    {
        return $this->cash->deleteBySource($agencyId, 'invoice_payment', $paymentId);
    }

    /** Elimina los movimientos de todos los cobros de una factura. */
    public function unsyncInvoicePayments(int $invoiceId, int $agencyId): int
    {
        $stmt = $this->db->prepare('SELECT id FROM invoice_payments WHERE invoice_id = ? AND agency_id = ?');
        $stmt->execute([$invoiceId, $agencyId]);

        $deleted = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $paymentId) {
            $deleted += $this->cash->deleteBySource($agencyId, 'invoice_payment', (int)$paymentId);
        }

        return $deleted;
    }

    public function syncPayablePayment(int $paymentId, int $agencyId, ?int $userId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT pay.id, pay.amount, pay.currency, pay.paid_at, pay.payable_id,
                   p.project_id, p.supplier, p.concept
            FROM payable_payments pay
            INNER JOIN payables p ON p.id = pay.payable_id
            WHERE pay.id = ? AND pay.agency_id = ?
        ");
        $stmt->execute([$paymentId, $agencyId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($payment === false) {
            return null;
        }

        $this->ensureDefaults($agencyId);

        $currency = strtoupper((string)$payment['currency']);
        $rule = $this->ruleFor($agencyId, 'payable', $currency);
        if ($rule === null) {
            return null;
        }

        $existing = $this->cash->findBySource($agencyId, 'payable_payment', $paymentId);
        if ($existing !== null) {
            return (int)$existing['id'];
        }

        return $this->cash->createTransaction($agencyId, $userId, [
            'account_id' => (int)$rule['account_id'],
            'category_id' => (int)$rule['category_id'],
            'project_id' => $payment['project_id'] !== null ? (int)$payment['project_id'] : null,
            'type' => 'egreso',
            'amount' => (float)$payment['amount'],
            'occurred_on' => $payment['paid_at'] ?: date('Y-m-d'),
            'description' => mb_substr('Pago a ' . $payment['supplier'] . ': ' . $payment['concept'], 0, 255),
            'reference' => 'PAGO-' . $paymentId,
            'notes' => null,
        ], 'payable_payment', $paymentId);
    }

    public function unsyncPayablePayment(int $paymentId, int $agencyId): int
    {
        return $this->cash->deleteBySource($agencyId, 'payable_payment', $paymentId);
    }

    /** Elimina los movimientos de todos los pagos de una cuenta por pagar. */
    public function unsyncPayable(int $payableId, int $agencyId): int
    {
        $stmt = $this->db->prepare('SELECT id FROM payable_payments WHERE payable_id = ? AND agency_id = ?');
        $stmt->execute([$payableId, $agencyId]);

        $deleted = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $paymentId) {
            $deleted += $this->cash->deleteBySource($agencyId, 'payable_payment', (int)$paymentId);
        }

        return $deleted;
    }

    /**
     * @return array{rows:array<int, array<string, mixed>>, total:int}
     */
    public function generatedMovements(int $agencyId, int $limit = 25, int $offset = 0): array
    {
        return $this->cash->generatedTransactions($agencyId, $limit, $offset);
    }

    public function deleteGeneratedMovement(int $movementId, int $agencyId): void
    {
        $this->cash->deleteGeneratedTransaction($movementId, $agencyId);
    }

    /**
     * Monedas con facturas o pagos que no tienen una cuenta activa.
     *
     * @return array<int, array{kind:string,currency:string}>
     */
    public function currenciesWithoutAccount(int $agencyId): array
    {
        $missing = [];
        foreach (['invoice' => 'invoices', 'payable' => 'payables'] as $kind => $table) {
            $stmt = $this->db->prepare("
                SELECT DISTINCT currency
                FROM {$table}
                WHERE agency_id = ?
                  AND currency NOT IN (
                      SELECT currency FROM cash_accounts WHERE agency_id = ? AND is_active = 1
                  )
                ORDER BY currency ASC
            ");
            $stmt->execute([$agencyId, $agencyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $currency) {
                $missing[] = ['kind' => $kind, 'currency' => strtoupper((string)$currency)];
            }
        }

        return $missing;
    }

    /** @return array<string, mixed>|null */
    private function ruleFor(int $agencyId, string $kind, string $currency): ?array
    {
        $expectedType = $kind === 'invoice' ? 'ingreso' : 'egreso';

        $stmt = $this->db->prepare("
            SELECT r.*
            FROM cash_automation_rules r
            INNER JOIN cash_accounts a ON a.id = r.account_id
            INNER JOIN cash_categories c ON c.id = r.category_id
            WHERE r.agency_id = ? AND r.kind = ? AND r.currency = ?
              AND r.is_active = 1 AND a.is_active = 1 AND c.is_active = 1
              AND a.currency = r.currency AND c.type = ?
            LIMIT 1
        ");
        $stmt->execute([$agencyId, $kind, strtoupper($currency), $expectedType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array<int, array<string, mixed>> $categories */
    private function pickCategory(array $categories, string $type, array $preferredNames): ?array
    {
        foreach ($preferredNames as $name) {
            foreach ($categories as $category) {
                if ($category['type'] === $type && mb_strtolower((string)$category['name']) === mb_strtolower($name)) {
                    return $category;
                }
            }
        }

        foreach ($categories as $category) {
            if ($category['type'] === $type) {
                return $category;
            }
        }

        return null;
    }
}

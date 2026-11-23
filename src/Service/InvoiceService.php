<?php

namespace App\Service;

use App\Repository\ActivityRepository;
use App\Repository\FinanceRepository;
use App\Repository\ProjectContentRepository;
use App\Support\Format;
use App\Support\Validator;
use DomainException;
use PDO;
use PDOException;
use Throwable;

/**
 * Ciclo de vida de una factura (cuenta por cobrar): alta con numeración, cambios de estado,
 * cobros y borrado. Antes esta lógica estaba copiada en billing.php, finance.php e invoices.php;
 * ahora todos la usan desde aquí, junto con la sincronización con Caja y el registro de actividad.
 *
 * Los permisos (quién puede tocar qué proyecto) los comprueba el endpoint; el servicio siempre
 * acota por agencia.
 */
final class InvoiceService
{
    public const STATUSES = ['pendiente', 'pagada', 'anulada'];

    public function __construct(
        private readonly PDO $db,
        private readonly FinanceRepository $finance,
        private readonly ProjectContentRepository $content,
        private readonly CashAutomationService $cash,
        private readonly ActivityRepository $activity,
        private readonly ?NotificationService $notifications = null,
        private readonly string $uploadDir = '',
    ) {
    }

    /**
     * Valida y registra una factura. Si no trae número, genera el siguiente correlativo libre.
     *
     * @param array<string, mixed> $input Datos del formulario (concept, amount, invoice_number, client_name,
     *                                    currency, due_date, status, paid_at, notes)
     * @return array{id: int, number: string, amount: float, currency: string, status: string}
     */
    public function create(int $agencyId, int $userId, ?int $projectId, array $input, string $actorName, string $notifyUrl = 'invoices'): array
    {
        $data = Validator::validate($input, [
            'concept' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0|max:9999999999.99',
            'invoice_number' => 'nullable|string|max:50',
            'client_name' => 'nullable|string',
            'currency' => 'nullable|string',
            'due_date' => 'nullable|date',
            'status' => 'in:pendiente,pagada,anulada|default:pendiente',
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ], [
            'concept' => 'el concepto de la factura',
            'amount' => 'el importe',
            'invoice_number' => 'el número de factura',
            'due_date' => 'la fecha de vencimiento',
            'paid_at' => 'la fecha de cobro',
            'notes' => 'las notas',
        ]);

        $currency = strtoupper((string)($data['currency'] ?? ''));
        if (!Format::isValidCurrency($currency)) {
            $currency = $projectId !== null ? $this->projectCurrency($projectId) : 'USD';
        }
        $status = (string)$data['status'];
        $amount = (float)$data['amount'];
        $paidAt = $status === 'pagada' ? ($data['paid_at'] ?? date('Y-m-d')) : null;
        $paidAmount = $status === 'pagada' ? $amount : 0.0;
        $clientName = $data['client_name'] !== null ? mb_substr((string)$data['client_name'], 0, 150) : null;
        $number = (string)($data['invoice_number'] ?? '');

        $this->db->beginTransaction();
        try {
            if ($projectId !== null) {
                // Bloquea el proyecto: serializa la numeración entre peticiones simultáneas
                $lock = $this->db->prepare('SELECT id FROM projects WHERE id = ? AND agency_id = ? FOR UPDATE');
                $lock->execute([$projectId, $agencyId]);
                if ($lock->fetchColumn() === false) {
                    throw new DomainException('El proyecto seleccionado no es válido.');
                }
            }

            if ($number === '') {
                $number = $this->nextNumber($agencyId, $projectId);
            } elseif ($this->numberExists($agencyId, $projectId, $number)) {
                // El índice único (project_id, invoice_number) no cubre las facturas sin proyecto (NULL)
                throw new DomainException($projectId !== null
                    ? 'Ya existe una factura con ese número en este proyecto.'
                    : 'Ya existe una factura general con ese número.');
            }

            $this->db->prepare('
                INSERT INTO invoices (agency_id, project_id, invoice_number, client_name, concept, amount, paid_amount, currency, status, due_date, paid_at, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $agencyId, $projectId, $number, $clientName, $data['concept'], $amount, $paidAmount,
                $currency, $status, $data['due_date'], $paidAt, $data['notes'],
            ]);
            $invoiceId = (int)$this->db->lastInsertId();

            if ($paidAmount > 0) {
                $this->db->prepare("
                    INSERT INTO invoice_payments (invoice_id, agency_id, amount, currency, paid_at, method, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, NULL, 'Cobro inicial al registrar la factura', ?)
                ")->execute([$invoiceId, $agencyId, $paidAmount, $currency, $paidAt, $userId]);
            }
            $this->db->commit();
        } catch (PDOException $e) {
            $this->rollBack();
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new DomainException('Ya existe una factura con ese número en este proyecto.');
            }
            throw $e;
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }

        $this->activity->logByUser($userId, $projectId, 'invoice.created', 'Emitió la factura "' . $number . '" por ' . $amount . ' ' . $currency);
        if ($status === 'pagada') {
            $this->syncPayments($invoiceId, $agencyId, $userId);
        }
        $this->notify($agencyId, 'invoice.created', 'Nueva factura emitida', $actorName . ' emitió la factura ' . $number . ' por ' . number_format($amount, 2) . ' ' . $currency . '.', $notifyUrl, $userId);

        return ['id' => $invoiceId, 'number' => $number, 'amount' => $amount, 'currency' => $currency, 'status' => $status];
    }

    /**
     * Cambia el estado de una factura.
     *  - pagada: cobra el saldo pendiente.
     *  - pendiente: si estaba anulada, la reactiva conservando sus cobros; si no, borra sus cobros y la reabre.
     *  - reactivate: reactiva una anulada (pagada si ya estaba cobrada del todo, pendiente si no).
     *  - anulada: la anula y retira sus movimientos de Caja.
     *
     * @return string Estado final
     */
    public function changeStatus(int $invoiceId, int $agencyId, int $userId, string $status, string $actorName = ''): string
    {
        $invoice = $this->requireInvoice($invoiceId, $agencyId);
        if (!in_array($status, [...self::STATUSES, 'reactivate'], true)) {
            throw new DomainException('Estado no válido.');
        }
        if ($status === 'pendiente' && $invoice['status'] === 'anulada') {
            $status = 'reactivate';
        }

        $final = $status;
        if ($status === 'pagada') {
            $paymentId = $this->finance->markInvoicePaid($invoiceId, $agencyId, $userId);
            if ($paymentId > 0) {
                $this->safely(fn() => $this->cash->syncInvoicePayment($paymentId, $agencyId, $userId));
            }
        } elseif ($status === 'reactivate') {
            $isFullyPaid = (float)$invoice['paid_amount'] + 0.005 >= (float)$invoice['amount'];
            $final = $isFullyPaid ? 'pagada' : 'pendiente';
            $this->db->prepare("
                UPDATE invoices
                SET status = ?, paid_at = CASE WHEN ? = 'pagada' THEN (SELECT MAX(paid_at) FROM invoice_payments WHERE invoice_id = ?) ELSE NULL END
                WHERE id = ? AND agency_id = ?
            ")->execute([$final, $final, $invoiceId, $invoiceId, $agencyId]);
            $this->syncPayments($invoiceId, $agencyId, $userId);
        } elseif ($status === 'pendiente') {
            $this->unsyncCash($invoiceId, $agencyId);
            $this->finance->reopenInvoice($invoiceId, $agencyId);
        } else {
            $this->unsyncCash($invoiceId, $agencyId);
            $this->db->prepare("UPDATE invoices SET status = 'anulada', paid_at = NULL WHERE id = ? AND agency_id = ?")
                ->execute([$invoiceId, $agencyId]);
        }

        $projectId = $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null;
        $this->activity->logByUser(
            $userId,
            $projectId,
            'invoice.status_changed',
            ($status === 'reactivate' ? 'Reactivó la factura ' : 'Cambió la factura ' . $invoice['invoice_number'] . ' a ') . ($status === 'reactivate' ? $invoice['invoice_number'] : $final)
        );
        if ($final === 'pagada' && $status === 'pagada') {
            $this->notify($agencyId, 'invoice.paid', 'Factura pagada', 'La factura ' . $invoice['invoice_number'] . ' se marcó como pagada.', 'invoices', $userId);
        }

        return $final;
    }

    public function addPayment(int $invoiceId, int $agencyId, int $userId, mixed $amount, mixed $paidAt, mixed $method, mixed $notes): int
    {
        $invoice = $this->requireInvoice($invoiceId, $agencyId);
        $paidAt = Format::isIsoDate($paidAt) ? (string)$paidAt : date('Y-m-d');
        $method = trim((string)$method);
        $notes = trim((string)$notes);
        $amount = round((float)$amount, 2);

        $paymentId = $this->finance->addInvoicePayment(
            $invoiceId,
            $agencyId,
            $userId,
            $amount,
            $paidAt,
            $method !== '' ? mb_substr($method, 0, 40) : null,
            $notes !== '' ? mb_substr($notes, 0, 255) : null
        );
        $this->safely(fn() => $this->cash->syncInvoicePayment($paymentId, $agencyId, $userId));
        $this->activity->logByUser(
            $userId,
            $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null,
            'finance.receivable_payment_added',
            'Registró un cobro de ' . Format::money($amount, (string)$invoice['currency']) . ' en la factura "' . $invoice['invoice_number'] . '"'
        );
        return $paymentId;
    }

    /** @return array{invoice_id: int, project_id: ?int, invoice_number: string} Factura del cobro, para comprobar permisos */
    public function paymentInvoice(int $paymentId, int $agencyId): array
    {
        $stmt = $this->db->prepare('
            SELECT i.id, i.project_id, i.invoice_number
            FROM invoice_payments pay
            INNER JOIN invoices i ON i.id = pay.invoice_id
            WHERE pay.id = ? AND i.agency_id = ?
        ');
        $stmt->execute([$paymentId, $agencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new DomainException('Cobro no encontrado.');
        }
        return ['invoice_id' => (int)$row['id'], 'project_id' => $row['project_id'] !== null ? (int)$row['project_id'] : null, 'invoice_number' => (string)$row['invoice_number']];
    }

    public function deletePayment(int $paymentId, int $agencyId, int $userId): void
    {
        $invoice = $this->paymentInvoice($paymentId, $agencyId);
        $this->finance->deleteInvoicePayment($paymentId, $agencyId);
        $this->safely(fn() => $this->cash->unsyncInvoicePayment($paymentId, $agencyId));
        $this->activity->logByUser($userId, $invoice['project_id'], 'invoice.payment_deleted', 'Eliminó un cobro de la factura ' . $invoice['invoice_number']);
    }

    /** Borra la factura, sus cobros (en cascada), sus movimientos de Caja y sus documentos del disco. */
    public function delete(int $invoiceId, int $agencyId, int $userId): void
    {
        $invoice = $this->requireInvoice($invoiceId, $agencyId);
        $storedFiles = $this->content->invoiceDocumentFiles($invoiceId);
        $paymentIds = $this->finance->invoicePaymentIds($invoiceId, $agencyId);

        $this->db->prepare('DELETE FROM invoices WHERE id = ? AND agency_id = ?')->execute([$invoiceId, $agencyId]);
        foreach ($paymentIds as $paymentId) {
            $this->safely(fn() => $this->cash->unsyncInvoicePayment($paymentId, $agencyId));
        }
        $this->safely(fn() => $this->cash->unsyncInvoice($invoiceId, $agencyId));
        foreach ($storedFiles as $name) {
            $path = rtrim($this->uploadDir, '/') . '/' . basename($name);
            if ($this->uploadDir !== '' && is_file($path)) {
                @unlink($path);
            }
        }

        $this->activity->logByUser($userId, $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null, 'invoice.deleted', 'Eliminó la factura ' . $invoice['invoice_number']);
    }

    /** @return array<string, mixed> Factura de la agencia o DomainException */
    public function requireInvoice(int $invoiceId, int $agencyId): array
    {
        $invoice = $this->finance->findInvoice($invoiceId, $agencyId);
        if ($invoice === null) {
            throw new DomainException('Factura no encontrada.');
        }
        return $invoice;
    }

    private function nextNumber(int $agencyId, ?int $projectId): string
    {
        if ($projectId !== null) {
            $count = $this->db->prepare('SELECT COUNT(*) FROM invoices WHERE project_id = ?');
            $count->execute([$projectId]);
        } else {
            $count = $this->db->prepare('SELECT COUNT(*) FROM invoices WHERE agency_id = ? AND project_id IS NULL');
            $count->execute([$agencyId]);
        }
        $next = (int)$count->fetchColumn() + 1;
        do {
            $number = 'INV-' . date('Y') . '-' . str_pad((string)$next++, 3, '0', STR_PAD_LEFT);
        } while ($this->numberExists($agencyId, $projectId, $number));
        return $number;
    }

    private function numberExists(int $agencyId, ?int $projectId, string $number): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM invoices WHERE agency_id = ? AND invoice_number = ? AND project_id <=> ?');
        $stmt->execute([$agencyId, $number, $projectId]);
        return (bool)$stmt->fetchColumn();
    }

    private function projectCurrency(int $projectId): string
    {
        $stmt = $this->db->prepare('SELECT currency FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $currency = strtoupper((string)$stmt->fetchColumn());
        return Format::isValidCurrency($currency) ? $currency : 'USD';
    }

    private function syncPayments(int $invoiceId, int $agencyId, int $userId): void
    {
        foreach ($this->finance->invoicePaymentIds($invoiceId, $agencyId) as $paymentId) {
            $this->safely(fn() => $this->cash->syncInvoicePayment($paymentId, $agencyId, $userId));
        }
    }

    private function unsyncCash(int $invoiceId, int $agencyId): void
    {
        $this->safely(fn() => $this->cash->unsyncInvoicePayments($invoiceId, $agencyId));
        $this->safely(fn() => $this->cash->unsyncInvoice($invoiceId, $agencyId));
    }

    /** La Caja automática nunca debe impedir la operación principal. */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            error_log('Caja automática: ' . $e->getMessage());
        }
    }

    private function notify(int $agencyId, string $type, string $title, string $message, string $url, int $userId): void
    {
        if ($this->notifications === null) {
            return;
        }
        try {
            $this->notifications->notifyAgencyAdmins($agencyId, $type, $title, $message, $url, true, $userId);
        } catch (Throwable $e) {
            error_log('Notificación factura: ' . $e->getMessage());
        }
    }

    private function rollBack(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}

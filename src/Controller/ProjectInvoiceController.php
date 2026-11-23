<?php

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ActivityRepository;
use App\Repository\FinanceRepository;
use App\Repository\ProjectContentRepository;
use App\Repository\ProjectRepository;
use App\Service\InvoiceService;
use App\Support\Format;
use App\Upload\AttachmentStorage;
use PDO;

/**
 * Finanzas de un proyecto (pestaña Finanzas): facturas, presupuesto y documentos de factura.
 * Solo para quien gestiona el proyecto en la agencia dueña; nunca para agencias invitadas.
 * Toda acción sobre una factura exige que pertenezca al proyecto indicado.
 */
final class ProjectInvoiceController extends ProjectScopedController
{
    public function __construct(
        array $user,
        ProjectRepository $projects,
        private readonly PDO $db,
        private readonly InvoiceService $invoices,
        private readonly FinanceRepository $finance,
        private readonly ProjectContentRepository $content,
        private readonly AttachmentStorage $storage,
        private readonly ActivityRepository $activity,
        private readonly string $uploadDir,
        private readonly int $agencyId,
    ) {
        parent::__construct($user, $projects);
    }

    public function register(Router $router): void
    {
        $router
            ->on('list', $this->list(...))
            ->on('create', $this->create(...))
            ->on('update_status', $this->updateStatus(...))
            ->on('update_budget', $this->updateBudget(...))
            ->on('documents_list', $this->documentsList(...))
            ->on('document_upload', $this->documentUpload(...))
            ->on('document_delete', $this->documentDelete(...))
            ->on('delete', $this->delete(...));
    }

    public function list(Request $request): array
    {
        $projectId = $this->authorizeFinances($request, 'Las finanzas solo las ve la agencia dueña del proyecto');
        $this->activity->logByUser($this->userId(), $projectId, 'finance.viewed', 'Consultó las finanzas del proyecto');

        $stmt = $this->db->prepare("
            SELECT * FROM invoices
            WHERE project_id = ?
            ORDER BY (status = 'pendiente') DESC, due_date ASC, created_at DESC
        ");
        $stmt->execute([$projectId]);

        $stmtProject = $this->db->prepare('SELECT budget, currency FROM projects WHERE id = ?');
        $stmtProject->execute([$projectId]);
        $project = $stmtProject->fetch(PDO::FETCH_ASSOC) ?: ['budget' => 0, 'currency' => 'USD'];

        $totals = ['invoiced' => 0.0, 'paid' => 0.0, 'pending' => 0.0];
        $list = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
            $amount = (float)$invoice['amount'];
            $paid = min($amount, max(0.0, (float)($invoice['paid_amount'] ?? 0)));
            $balance = max(0.0, $amount - $paid);
            if ($invoice['status'] !== 'anulada') {
                $totals['invoiced'] += $amount;
                $totals['paid'] += $paid;
                $totals['pending'] += $balance;
            }
            $list[] = [
                'id' => (int)$invoice['id'],
                'project_id' => (int)$invoice['project_id'],
                'invoice_number' => $invoice['invoice_number'],
                'client_name' => $invoice['client_name'] ?? null,
                'concept' => $invoice['concept'],
                'amount' => $amount,
                'amount_formatted' => number_format($amount, 2),
                'paid' => $paid,
                'paid_formatted' => number_format($paid, 2),
                'balance' => $balance,
                'balance_formatted' => number_format($balance, 2),
                'currency' => $invoice['currency'] ?: 'USD',
                'status' => $invoice['status'],
                'display_status' => $invoice['status'] === 'pagada' ? 'pagada' : ($invoice['status'] === 'anulada' ? 'anulada' : ($paid > 0 ? 'parcial' : 'pendiente')),
                'due_date' => $invoice['due_date'],
                'due_date_formatted' => $invoice['due_date'] ? Format::date($invoice['due_date']) : '-',
                'paid_at' => $invoice['paid_at'],
                'paid_at_formatted' => $invoice['paid_at'] ? Format::date($invoice['paid_at']) : null,
                'notes' => $invoice['notes'],
                'created_at' => Format::date($invoice['created_at']),
            ];
        }

        $budget = (float)$project['budget'];
        $balance = $budget - $totals['paid'];

        return [
            'success' => true,
            'budget' => $budget,
            'budget_formatted' => number_format($budget, 2),
            'currency' => $project['currency'] ?: 'USD',
            'summary' => [
                'total_invoiced' => $totals['invoiced'],
                'total_invoiced_formatted' => number_format($totals['invoiced'], 2),
                'total_paid' => $totals['paid'],
                'total_paid_formatted' => number_format($totals['paid'], 2),
                'total_pending' => $totals['pending'],
                'total_pending_formatted' => number_format($totals['pending'], 2),
                'balance' => $balance,
                'balance_formatted' => number_format($balance, 2),
            ],
            'invoices' => $list,
        ];
    }

    public function create(Request $request): array
    {
        $projectId = $this->authorizeFinances($request, 'No tienes permisos para emitir facturas en este proyecto');
        $created = $this->invoices->create($this->agencyId, $this->userId(), $projectId, [
            'invoice_number' => $request->input('invoice_number'),
            'client_name' => $request->input('client_name'),
            'concept' => $request->input('concept'),
            'amount' => $request->input('amount'),
            'currency' => $request->input('currency'),
            'due_date' => $request->input('due_date'),
            'status' => $request->input('status'),
            'paid_at' => $request->input('paid_at'),
            'notes' => $request->input('notes'),
        ], $this->userName());

        return ['success' => true, 'message' => 'Factura / cobro registrado con éxito', 'invoice_id' => $created['id']];
    }

    public function updateStatus(Request $request): array
    {
        $projectId = $this->authorizeFinances($request, 'No tienes permisos para modificar el estado de facturas');
        $invoice = $this->requireProjectInvoice($request, $projectId);
        $status = $request->string('status');
        if (!in_array($status, InvoiceService::STATUSES, true)) {
            throw HttpException::invalid('Estado no válido');
        }

        $final = $this->invoices->changeStatus((int)$invoice['id'], $this->agencyId, $this->userId(), $status, $this->userName());
        return [
            'success' => true,
            'message' => 'Estado de la factura actualizado',
            'status' => $final,
            'paid_at' => $final === 'pagada' ? date('Y-m-d') : null,
        ];
    }

    public function updateBudget(Request $request): array
    {
        $projectId = $this->authorizeFinances($request, 'No tienes permisos para modificar el costo de este proyecto');
        $budget = round((float)$request->input('budget', 0), 2);
        $currency = strtoupper($request->string('currency'));
        if ($budget < 0) {
            throw HttpException::invalid('El costo/presupuesto no puede ser negativo');
        }
        if (!Format::isValidCurrency($currency)) {
            throw HttpException::invalid('Moneda no válida');
        }

        $this->db->prepare('UPDATE projects SET budget = ?, currency = ? WHERE id = ?')->execute([$budget, $currency, $projectId]);
        $this->activity->logByUser($this->userId(), $projectId, 'budget.updated', 'Actualizó el presupuesto a ' . $budget . ' ' . $currency);

        return [
            'success' => true,
            'message' => 'Costo total del proyecto actualizado',
            'budget' => $budget,
            'budget_formatted' => number_format($budget, 2),
            'currency' => $currency,
        ];
    }

    public function documentsList(Request $request): array
    {
        $projectId = $this->authorizeFinances($request, 'Las finanzas solo las ve la agencia dueña del proyecto');
        $invoice = $this->requireProjectInvoice($request, $projectId);

        $documents = array_map(static fn(array $doc): array => [
            'id' => (int)$doc['id'],
            'name' => $doc['original_name'],
            'size' => (int)$doc['file_size'],
            'size_formatted' => Format::bytes((int)$doc['file_size']),
            'type' => $doc['file_type'],
            'created_at' => Format::date($doc['created_at'], true),
            'download_url' => AttachmentController::downloadUrl((int)$doc['id']),
        ], $this->content->invoiceDocuments((int)$invoice['id'], $projectId));

        return [
            'success' => true,
            'invoice_id' => (int)$invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'documents' => $documents,
        ];
    }

    public function documentUpload(Request $request): array
    {
        $projectId = $this->authorizeFinances($request, 'No tienes permisos para adjuntar documentos a facturas');
        $invoice = $this->requireProjectInvoice($request, $projectId);
        $file = $request->file('file');
        if ($file === null) {
            throw HttpException::invalid('Selecciona un archivo para subir');
        }

        $stored = $this->storage->store($file);
        if (!$stored['ok']) {
            throw HttpException::invalid($stored['error']);
        }
        $documentId = $this->content->createFile($projectId, $this->userId(), null, null, (int)$invoice['id'], $stored);
        $this->activity->logByUser($this->userId(), $projectId, 'invoice.document_uploaded', 'Adjuntó "' . $stored['original_name'] . '" a la factura ' . $invoice['invoice_number']);

        return [
            'success' => true,
            'message' => 'Documento subido',
            'document' => [
                'id' => $documentId,
                'name' => $stored['original_name'],
                'size_formatted' => Format::bytes($stored['size']),
                'download_url' => AttachmentController::downloadUrl($documentId),
            ],
        ];
    }

    public function documentDelete(Request $request): array
    {
        $projectId = $this->authorizeFinances($request, 'No tienes permisos para eliminar documentos de facturas');
        $document = $this->content->findInvoiceDocument($request->int('document_id'), $projectId);
        if ($document === null) {
            throw HttpException::notFound('Documento no encontrado');
        }

        $this->content->deleteFile((int)$document['id'], $projectId);
        $path = $this->uploadDir . basename((string)$document['stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
        $this->activity->logByUser($this->userId(), $projectId, 'invoice.document_deleted', 'Eliminó el documento "' . $document['original_name'] . '"');

        return ['success' => true, 'message' => 'Documento eliminado'];
    }

    public function delete(Request $request): array
    {
        $projectId = $this->authorizeFinances($request, 'No tienes permisos para eliminar facturas');
        $invoice = $this->requireProjectInvoice($request, $projectId);
        $this->invoices->delete((int)$invoice['id'], $this->agencyId, $this->userId());
        return ['success' => true, 'message' => 'Factura eliminada'];
    }

    private function authorizeFinances(Request $request, string $forbidden): int
    {
        $projectId = $this->authorizeProject($request);
        $this->require($this->projects->canManage($projectId, $this->userId(), $this->role()), $forbidden);
        return $projectId;
    }

    /** Factura del proyecto (antes: cualquier factura de la agencia podía cambiarse de estado). */
    private function requireProjectInvoice(Request $request, int $projectId): array
    {
        $invoice = $this->finance->findInvoice($request->int('invoice_id'), $this->agencyId);
        if ($invoice === null || (int)($invoice['project_id'] ?? 0) !== $projectId) {
            throw HttpException::notFound('Factura no encontrada');
        }
        return $invoice;
    }
}

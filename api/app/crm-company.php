<?php
// api/app/crm-company.php - Historial comercial de una empresa del CRM (front React).
// GET ?id=  -> empresa, contactos, oportunidades con sus propuestas y actividades.
require_once __DIR__ . '/_bootstrap.php';

use App\Repository\CrmRepository;
use App\Support\QuoteStatus;

appMethod('GET');

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('crm')) {
    appJson(['error' => 'El historial del cliente solo está disponible para administradores y project managers.'], 403);
}

$companyId = (int)($_GET['id'] ?? 0);
$crm = crmRepository();
$company = $companyId > 0 ? $crm->company($companyId, $agencyId) : null;
if ($company === null) {
    appJson(['error' => 'La empresa no existe o no pertenece a tu agencia.'], 404);
}

$quotesByDeal = [];
$quotes = [];
foreach ($crm->companyQuotes($companyId, $agencyId) as $row) {
    $quote = [
        'id' => (int)$row['id'],
        'dealId' => $row['deal_id'] !== null ? (int)$row['deal_id'] : null,
        'number' => $row['quote_number'],
        'title' => $row['title'],
        'total' => (float)$row['total'],
        'currency' => strtoupper((string)($row['currency'] ?: 'USD')),
        'status' => $row['status'],
        'statusLabel' => QuoteStatus::label($row['status']),
        'issueDate' => $row['issue_date'],
    ];
    $quotes[] = $quote;
    if ($quote['dealId'] !== null) {
        $quotesByDeal[$quote['dealId']][] = $quote;
    }
}

$deals = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'title' => $row['title'],
    'stage' => $row['stage'],
    'stageLabel' => CrmRepository::STAGE_LABELS[$row['stage']] ?? ucfirst((string)$row['stage']),
    'value' => (float)$row['value'],
    'currency' => strtoupper((string)($row['currency'] ?: 'USD')),
    'contact' => $row['contact_name'] ?? null,
    'owner' => $row['owner_name'] ?? null,
    'expectedClose' => $row['expected_close'] ?? null,
    'convertedProjectId' => !empty($row['converted_project_id']) ? (int)$row['converted_project_id'] : null,
    'convertedProjectName' => $row['converted_project_name'] ?? null,
    'quotes' => $quotesByDeal[(int)$row['id']] ?? [],
], $crm->companyDeals($companyId, $agencyId));

$contacts = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'name' => $row['name'],
    'role' => $row['role'] ?? null,
    'email' => $row['email'] ?? null,
    'phone' => $row['phone'] ?? null,
], $crm->contacts($agencyId, $companyId));

$activities = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'type' => $row['type'],
    'typeLabel' => CrmRepository::ACTIVITY_LABELS[$row['type']] ?? $row['type'],
    'title' => $row['title'] ?? ($row['subject'] ?? ''),
    'notes' => $row['notes'] ?? null,
    'occurredAt' => $row['occurred_at'],
    'user' => $row['user_name'] ?? null,
    'dealTitle' => $row['deal_title'] ?? null,
], $crm->companyActivities($companyId, $agencyId, 100));

appJson([
    'company' => [
        'id' => (int)$company['id'],
        'name' => $company['name'],
        'industry' => $company['industry'] ?? null,
        'website' => $company['website'] ?? null,
        'email' => $company['email'] ?? null,
        'phone' => $company['phone'] ?? null,
        'address' => $company['address'] ?? null,
        'notes' => $company['notes'] ?? null,
    ],
    'contacts' => $contacts,
    'deals' => $deals,
    'quotes' => $quotes,
    'activities' => $activities,
]);

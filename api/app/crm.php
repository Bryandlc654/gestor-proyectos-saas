<?php
// api/app/crm.php - Datos del CRM para React.
require_once __DIR__ . '/_bootstrap.php';

use App\Repository\CrmRepository;

appMethod('GET');

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('crm')) {
    appJson(['error' => 'Módulo CRM no disponible'], 403);
}

$crm = crmRepository();
$search = trim((string)($_GET['search'] ?? ''));

$deals = [];
foreach ($crm->deals($agencyId, $search, 300) as $deal) {
    $deals[] = [
        'id' => (int)$deal['id'],
        'title' => $deal['title'],
        'company' => $deal['company_name'],
        'companyId' => $deal['company_id'] !== null ? (int)$deal['company_id'] : null,
        'contact' => $deal['contact_name'],
        'contactId' => $deal['contact_id'] !== null ? (int)$deal['contact_id'] : null,
        'contactEmail' => $deal['contact_email'] ?? null,
        'contactPhone' => $deal['contact_phone'] ?? null,
        'companyPhone' => $deal['company_phone'] ?? null,
        'description' => $deal['description'],
        'stage' => $deal['stage'],
        'value' => (float)$deal['value'],
        'currency' => $deal['currency'] ?: 'USD',
        'expectedClose' => $deal['expected_close'],
        'owner' => $deal['owner_name'],
        'ownerId' => $deal['owner_id'] !== null ? (int)$deal['owner_id'] : null,
        'source' => $deal['source'],
        'lostReason' => $deal['lost_reason'] ?? null,
        'createdAt' => $deal['created_at'] ?? null,
        'updatedAt' => $deal['updated_at'] ?? null,
        'wonAt' => $deal['won_at'] ?? null,
        'lostAt' => $deal['lost_at'] ?? null,
        'convertedAt' => $deal['converted_at'] ?? null,
        'lastActivityAt' => $deal['last_activity_at'] ?? null,
        'nextActivityAt' => $deal['next_activity_at'] ?? null,
        'nextActivityTitle' => $deal['next_activity_title'] ?? null,
        'convertedProjectId' => $deal['converted_project_id'] !== null ? (int)$deal['converted_project_id'] : null,
        'convertedProject' => $deal['converted_project_name'],
        'quotesCount' => (int)$deal['quotes_count'],
        'quotesValue' => (float)($deal['quotes_value'] ?? 0),
        'latestQuoteStatus' => $deal['latest_quote_status'],
    ];
}

$companies = [];
foreach ($crm->companies($agencyId) as $company) {
    $companies[] = [
        'id' => (int)$company['id'],
        'name' => $company['name'],
        'industry' => $company['industry'] ?? null,
        'website' => $company['website'] ?? null,
        'email' => $company['email'] ?? null,
        'phone' => $company['phone'] ?? null,
        'address' => $company['address'] ?? null,
        'notes' => $company['notes'] ?? null,
        'contacts' => (int)$company['contacts_count'],
        'deals' => (int)$company['deals_count'],
        'projects' => (int)$company['projects_count'],
        'quotes' => (int)$company['quotes_count'],
        'openValue' => (float)$company['open_value'],
    ];
}

$contacts = [];
foreach ($crm->contacts($agencyId) as $contact) {
    $contacts[] = [
        'id' => (int)$contact['id'],
        'name' => $contact['name'],
        'email' => $contact['email'],
        'phone' => $contact['phone'],
        'position' => $contact['role'] ?? null,
        'role' => $contact['role'] ?? null,
        'notes' => $contact['notes'] ?? null,
        'companyId' => $contact['company_id'] !== null ? (int)$contact['company_id'] : null,
        'company' => $contact['company_name'],
    ];
}

$activities = [];
foreach ($crm->activities($agencyId, 100) as $activity) {
    $activities[] = [
        'id' => (int)$activity['id'],
        'type' => $activity['type'],
        'subject' => $activity['title'],
        'notes' => $activity['notes'],
        'dueAt' => $activity['occurred_at'] ?? null,
        'user' => $activity['user_name'] ?? null,
        'dealId' => $activity['deal_id'] !== null ? (int)$activity['deal_id'] : null,
        'dealTitle' => $activity['deal_title'] ?? null,
        'companyId' => $activity['company_id'] !== null ? (int)$activity['company_id'] : null,
        'companyName' => $activity['company_name'] ?? null,
        'contactId' => $activity['contact_id'] !== null ? (int)$activity['contact_id'] : null,
        'contactName' => $activity['contact_name'] ?? null,
    ];
}

appJson([
    'stats' => $crm->stats($agencyId),
    'stageCounts' => $crm->stageCounts($agencyId, $search),
    'stages' => array_map(
        static fn(string $stage): array => ['key' => $stage, 'label' => CrmRepository::STAGE_LABELS[$stage] ?? $stage],
        CrmRepository::STAGES
    ),
    'deals' => $deals,
    'companies' => $companies,
    'contacts' => $contacts,
    'activities' => $activities,
    'owners' => $crm->owners($agencyId),
    'sources' => CrmRepository::SOURCES,
    'activityTypes' => array_map(
        static fn(string $type): array => ['key' => $type, 'label' => CrmRepository::ACTIVITY_LABELS[$type] ?? $type],
        CrmRepository::ACTIVITY_TYPES
    ),
    'currencies' => CrmRepository::CURRENCIES,
]);

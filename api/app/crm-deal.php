<?php
// api/app/crm-deal.php - Resumen de una oportunidad del CRM para vincularla a una cotización (front React).
// GET ?id=  -> oportunidad, empresa, contacto y datos sugeridos para precargar la propuesta.
require_once __DIR__ . '/_bootstrap.php';

use App\Repository\CrmRepository;

appMethod('GET');

if (!in_array($role, ['admin', 'project_manager'], true)) {
    appJson(['error' => 'Sin acceso a oportunidades'], 403);
}

$crm = crmRepository();
$dealId = (int)($_GET['id'] ?? 0);
$deal = $dealId > 0 ? $crm->deal($dealId, $agencyId) : null;
if ($deal === null) {
    appJson(['error' => 'La oportunidad no existe o no pertenece a tu agencia.'], 404);
}

$company = !empty($deal['company_id']) ? $crm->company((int)$deal['company_id'], $agencyId) : null;
$contact = !empty($deal['contact_id']) ? $crm->contact((int)$deal['contact_id'], $agencyId) : null;
$currency = strtoupper((string)($deal['currency'] ?? ''));
if (!isValidCurrency($currency)) {
    $currency = 'USD';
}

appJson([
    'deal' => [
        'id' => (int)$deal['id'],
        'title' => $deal['title'],
        'stage' => $deal['stage'],
        'stageLabel' => CrmRepository::STAGE_LABELS[$deal['stage']] ?? ucfirst((string)$deal['stage']),
        'value' => (float)$deal['value'],
        'currency' => $currency,
        'company' => $company !== null ? ['id' => (int)$company['id'], 'name' => $company['name']] : null,
        'convertedProjectId' => !empty($deal['converted_project_id']) ? (int)$deal['converted_project_id'] : null,
    ],
    // Datos para una propuesta nueva creada desde la oportunidad.
    'prefill' => [
        'title' => mb_substr('Propuesta – ' . (string)$deal['title'], 0, 180),
        'clientName' => (string)($contact['name'] ?? $company['name'] ?? 'Cliente'),
        'clientCompany' => (string)($company['name'] ?? ''),
        'clientEmail' => (string)($contact['email'] ?? $company['email'] ?? ''),
        'currency' => $currency,
        'projectId' => !empty($deal['converted_project_id']) ? (string)(int)$deal['converted_project_id'] : '',
        'item' => ['description' => (string)$deal['title'], 'quantity' => '1', 'unitPrice' => (string)max(0, (float)$deal['value'])],
    ],
]);

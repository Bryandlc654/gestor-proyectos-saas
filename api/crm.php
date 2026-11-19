<?php
// api/crm.php - Altas, edición y borrado del módulo CRM
use App\Repository\CrmRepository;

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// El CRM lo gestionan administradores y project managers
if (!isProjectManager()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No tienes permisos para gestionar el CRM']);
    exit;
}
if (!moduleAllowed('crm')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'El módulo CRM está desactivado para tu rol']);
    exit;
}

$user = currentUser();
$userId = (int)$user['id'];
$agencyId = currentAgencyId();
$db = getDB();
$crm = crmRepository();
$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));
$csrf = (string)($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? ''));

if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

function crmRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function crmText(string $key, int $max = 255): string
{
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}

function crmNullable(string $key, int $max = 255): ?string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return $value === '' ? null : mb_substr($value, 0, $max);
}

function crmPositiveInt(string $key): ?int
{
    $value = (int)($_POST[$key] ?? 0);
    return $value > 0 ? $value : null;
}

function crmEmail(?string $value): ?string
{
    $value = $value !== null ? mb_strtolower(trim($value)) : null;
    if ($value === null || $value === '') {
        return null;
    }
    return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
}

/** Empresa de la agencia o null. */
$companyRef = static function (?int $id) use ($crm, $agencyId): ?int {
    return ($id !== null && $crm->company($id, $agencyId) !== null) ? $id : null;
};
/** Contacto de la agencia o null. */
$contactRef = static function (?int $id) use ($crm, $agencyId): ?int {
    return ($id !== null && $crm->contact($id, $agencyId) !== null) ? $id : null;
};
/** Oportunidad de la agencia o null. */
$dealRef = static function (?int $id) use ($crm, $agencyId): ?int {
    return ($id !== null && $crm->deal($id, $agencyId) !== null) ? $id : null;
};
/** Usuario interno de la agencia o null. */
$ownerRef = static function (?int $id) use ($agencyId): ?int {
    if ($id === null) {
        return null;
    }
    $stmt = getDB()->prepare("SELECT 1 FROM users WHERE id = ? AND agency_id = ? AND role IN ('admin', 'project_manager', 'colaborador')");
    $stmt->execute([$id, $agencyId]);
    return $stmt->fetchColumn() ? $id : null;
};

$stageOf = static function (string $raw): string {
    return in_array($raw, CrmRepository::STAGES, true) ? $raw : 'nuevo';
};

$dealPayload = static function () use ($companyRef, $contactRef, $ownerRef, $stageOf): array {
    $value = (float)str_replace(',', '.', (string)($_POST['value'] ?? '0'));
    $currency = strtoupper(crmText('currency', 10));
    $close = crmNullable('expected_close', 10);

    return [
        'company_id' => $companyRef(crmPositiveInt('company_id')),
        'contact_id' => $contactRef(crmPositiveInt('contact_id')),
        'title' => crmText('title', 150),
        'description' => crmNullable('description', 2000),
        'stage' => $stageOf(crmText('stage', 20)),
        'value' => max(0, min($value, 999999999.99)),
        'currency' => in_array($currency, CrmRepository::CURRENCIES, true) ? $currency : 'USD',
        'expected_close' => ($close !== null && isIsoDate($close)) ? $close : null,
        'owner_id' => $ownerRef(crmPositiveInt('owner_id')),
        'source' => crmNullable('source', 60),
        'lost_reason' => crmNullable('lost_reason', 255),
    ];
};

switch ($action) {
    // --- Empresas ---
    case 'company_create':
    case 'company_update':
        $data = [
            'name' => crmText('name', 150),
            'industry' => crmNullable('industry', 80),
            'website' => crmNullable('website', 255),
            'phone' => crmNullable('phone', 40),
            'email' => crmEmail(crmNullable('email', 150)),
            'address' => crmNullable('address', 255),
            'notes' => crmNullable('notes', 4000),
        ];
        if ($data['name'] === '') {
            crmRespond(['success' => false, 'message' => 'El nombre de la empresa es obligatorio'], 422);
        }

        if ($action === 'company_create') {
            $id = $crm->createCompany($agencyId, $data, $userId);
            activityRepository()->logByUser($userId, null, 'crm.company_created', 'Creó la empresa "' . $data['name'] . '"');
            crmRespond(['success' => true, 'id' => $id, 'message' => 'Empresa creada']);
        }

        $id = crmPositiveInt('id') ?? 0;
        if ($crm->company($id, $agencyId) === null) {
            crmRespond(['success' => false, 'message' => 'Empresa no encontrada'], 404);
        }
        $crm->updateCompany($id, $agencyId, $data);
        activityRepository()->logByUser($userId, null, 'crm.company_updated', 'Actualizó la empresa "' . $data['name'] . '"');
        crmRespond(['success' => true, 'message' => 'Empresa actualizada']);

    case 'company_delete':
        $id = crmPositiveInt('id') ?? 0;
        $company = $crm->company($id, $agencyId);
        if ($company === null || !$crm->deleteCompany($id, $agencyId)) {
            crmRespond(['success' => false, 'message' => 'Empresa no encontrada'], 404);
        }
        activityRepository()->logByUser($userId, null, 'crm.company_deleted', 'Eliminó la empresa "' . $company['name'] . '"');
        crmRespond(['success' => true, 'message' => 'Empresa eliminada']);

    // --- Contactos ---
    case 'contact_create':
    case 'contact_update':
        $data = [
            'company_id' => $companyRef(crmPositiveInt('company_id')),
            'name' => crmText('name', 100),
            'role' => crmNullable('role', 80),
            'email' => crmEmail(crmNullable('email', 150)),
            'phone' => crmNullable('phone', 40),
            'notes' => crmNullable('notes', 4000),
        ];
        if ($data['name'] === '') {
            crmRespond(['success' => false, 'message' => 'El nombre del contacto es obligatorio'], 422);
        }

        if ($action === 'contact_create') {
            $id = $crm->createContact($agencyId, $data);
            activityRepository()->logByUser($userId, null, 'crm.contact_created', 'Creó el contacto "' . $data['name'] . '"');
            crmRespond(['success' => true, 'id' => $id, 'message' => 'Contacto creado']);
        }

        $id = crmPositiveInt('id') ?? 0;
        if ($crm->contact($id, $agencyId) === null) {
            crmRespond(['success' => false, 'message' => 'Contacto no encontrado'], 404);
        }
        $crm->updateContact($id, $agencyId, $data);
        activityRepository()->logByUser($userId, null, 'crm.contact_updated', 'Actualizó el contacto "' . $data['name'] . '"');
        crmRespond(['success' => true, 'message' => 'Contacto actualizado']);

    case 'contact_delete':
        $id = crmPositiveInt('id') ?? 0;
        $contact = $crm->contact($id, $agencyId);
        if ($contact === null || !$crm->deleteContact($id, $agencyId)) {
            crmRespond(['success' => false, 'message' => 'Contacto no encontrado'], 404);
        }
        activityRepository()->logByUser($userId, null, 'crm.contact_deleted', 'Eliminó el contacto "' . $contact['name'] . '"');
        crmRespond(['success' => true, 'message' => 'Contacto eliminado']);

    // --- Oportunidades ---
    case 'deal_create':
    case 'deal_update':
        $data = $dealPayload();
        if ($data['title'] === '') {
            crmRespond(['success' => false, 'message' => 'El título de la oportunidad es obligatorio'], 422);
        }

        if ($action === 'deal_create') {
            $id = $crm->createDeal($agencyId, $data, $userId);
            activityRepository()->logByUser($userId, null, 'crm.deal_created', 'Creó la oportunidad "' . $data['title'] . '"');
            crmRespond(['success' => true, 'id' => $id, 'message' => 'Oportunidad creada']);
        }

        $id = crmPositiveInt('id') ?? 0;
        if ($crm->deal($id, $agencyId) === null) {
            crmRespond(['success' => false, 'message' => 'Oportunidad no encontrada'], 404);
        }
        $crm->updateDeal($id, $agencyId, $data);
        activityRepository()->logByUser($userId, null, 'crm.deal_updated', 'Actualizó la oportunidad "' . $data['title'] . '"');
        crmRespond(['success' => true, 'message' => 'Oportunidad actualizada']);

    case 'deal_convert':
        $id = crmPositiveInt('id') ?? 0;
        $deal = $crm->deal($id, $agencyId);
        if ($deal === null) {
            crmRespond(['success' => false, 'message' => 'Oportunidad no encontrada'], 404);
        }
        if (!canCreateProjects($user['role'])) {
            crmRespond(['success' => false, 'message' => 'Tu nivel de acceso no permite crear proyectos'], 403);
        }

        try {
            $result = $crm->convertDealToProject($id, $agencyId, $userId);
        } catch (DomainException $e) {
            crmRespond(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('crm deal_convert: ' . $e->getMessage());
            crmRespond(['success' => false, 'message' => 'No se pudo convertir la oportunidad en proyecto'], 500);
        }

        if (!$result['already']) {
            activityRepository()->logByUser(
                $userId,
                $result['project_id'],
                'crm.deal_converted',
                'Convirtió la oportunidad "' . $deal['title'] . '" en el proyecto #' . $result['project_id']
            );
            notificationService()->notifyAgencyAdmins(
                $agencyId,
                'crm.deal_converted',
                'Oportunidad convertida en proyecto',
                $user['name'] . ' convirtió la oportunidad "' . $deal['title'] . '" en un proyecto.',
                '/project/' . $result['project_id'],
                true,
                $userId
            );
        }

        crmRespond([
            'success' => true,
            'project_id' => $result['project_id'],
            'already' => $result['already'],
            'redirect' => '/project/' . $result['project_id'],
            'message' => $result['already'] ? 'La oportunidad ya tenía un proyecto asociado' : 'Oportunidad convertida en proyecto',
        ]);

    case 'deal_move':
        $id = crmPositiveInt('id') ?? 0;
        $stage = crmText('stage', 20);
        if (!in_array($stage, CrmRepository::STAGES, true)) {
            crmRespond(['success' => false, 'message' => 'Etapa no válida'], 422);
        }
        $deal = $crm->deal($id, $agencyId);
        if ($deal === null) {
            crmRespond(['success' => false, 'message' => 'Oportunidad no encontrada'], 404);
        }
        $crm->moveDeal($id, $agencyId, $stage, crmNullable('lost_reason', 255));
        activityRepository()->logByUser(
            $userId,
            null,
            'crm.deal_stage_changed',
            'Movió "' . $deal['title'] . '" a ' . CrmRepository::STAGE_LABELS[$stage]
        );

        if ($stage === 'ganado' || $stage === 'perdido') {
            $value = (float)($deal['value'] ?? 0);
            $currency = $deal['currency'] ?: 'USD';
            $detail = $stage === 'ganado'
                ? 'La oportunidad "' . $deal['title'] . '" se marcó como ganada' . ($value > 0 ? ' (' . number_format($value, 2) . ' ' . $currency . ')' : '') . '.'
                : 'La oportunidad "' . $deal['title'] . '" se marcó como perdida.';

            notificationService()->notifyAgencyAdmins(
                $agencyId,
                $stage === 'ganado' ? 'crm.deal_won' : 'crm.deal_lost',
                $stage === 'ganado' ? 'Oportunidad ganada' : 'Oportunidad perdida',
                $user['name'] . ': ' . $detail,
                'crm#pipeline',
                true,
                $userId
            );
        }

        crmRespond(['success' => true, 'message' => 'Etapa actualizada', 'stage' => $stage]);

    case 'deal_delete':
        $id = crmPositiveInt('id') ?? 0;
        $deal = $crm->deal($id, $agencyId);
        if ($deal === null || !$crm->deleteDeal($id, $agencyId)) {
            crmRespond(['success' => false, 'message' => 'Oportunidad no encontrada'], 404);
        }
        activityRepository()->logByUser($userId, null, 'crm.deal_deleted', 'Eliminó la oportunidad "' . $deal['title'] . '"');
        crmRespond(['success' => true, 'message' => 'Oportunidad eliminada']);

    // --- Actividades ---
    case 'activity_create':
        $type = crmText('type', 20);
        if (!in_array($type, CrmRepository::ACTIVITY_TYPES, true)) {
            $type = 'nota';
        }
        $title = crmText('title', 150);
        if ($title === '') {
            crmRespond(['success' => false, 'message' => 'Describe brevemente la actividad'], 422);
        }

        $occurred = crmText('occurred_at', 25);
        $timestamp = $occurred !== '' ? date_create($occurred) : false;
        $occurredAt = $timestamp !== false ? $timestamp->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');

        $data = [
            'deal_id' => $dealRef(crmPositiveInt('deal_id')),
            'company_id' => $companyRef(crmPositiveInt('company_id')),
            'contact_id' => $contactRef(crmPositiveInt('contact_id')),
            'type' => $type,
            'title' => $title,
            'notes' => crmNullable('notes', 4000),
            'occurred_at' => $occurredAt,
        ];

        $id = $crm->createActivity($agencyId, $data, $userId);
        activityRepository()->logByUser($userId, null, 'crm.activity_logged', 'Registró: ' . $title);
        crmRespond(['success' => true, 'id' => $id, 'message' => 'Actividad registrada']);

    case 'activity_delete':
        $id = crmPositiveInt('id') ?? 0;
        if (!$crm->deleteActivity($id, $agencyId)) {
            crmRespond(['success' => false, 'message' => 'Actividad no encontrada'], 404);
        }
        crmRespond(['success' => true, 'message' => 'Actividad eliminada']);

    default:
        crmRespond(['success' => false, 'message' => 'Acción no válida'], 400);
}

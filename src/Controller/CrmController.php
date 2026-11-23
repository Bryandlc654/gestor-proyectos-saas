<?php

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ActivityRepository;
use App\Repository\CrmRepository;
use App\Security\Permissions;
use App\Service\NotificationService;
use App\Support\Format;
use App\Support\Validator;

/**
 * Escrituras del CRM: empresas, contactos, oportunidades y actividades.
 * Toda referencia (empresa, contacto, oportunidad, responsable) se resuelve dentro de la agencia:
 * un id de otra agencia se descarta en lugar de vincularse.
 */
final class CrmController
{
    /** @param array{id: int|string, role: string, name?: string} $user */
    public function __construct(
        private readonly array $user,
        private readonly int $agencyId,
        private readonly CrmRepository $crm,
        private readonly ActivityRepository $activity,
        private readonly ?NotificationService $notifications = null,
    ) {
    }

    public function register(Router $router): void
    {
        $router
            ->on('company_create', fn(Request $r) => $this->saveCompany($r, true))
            ->on('company_update', fn(Request $r) => $this->saveCompany($r, false))
            ->on('company_delete', $this->deleteCompany(...))
            ->on('contact_create', fn(Request $r) => $this->saveContact($r, true))
            ->on('contact_update', fn(Request $r) => $this->saveContact($r, false))
            ->on('contact_delete', $this->deleteContact(...))
            ->on('deal_create', fn(Request $r) => $this->saveDeal($r, true))
            ->on('deal_update', fn(Request $r) => $this->saveDeal($r, false))
            ->on('deal_convert', $this->convertDeal(...))
            ->on('deal_move', $this->moveDeal(...))
            ->on('deal_delete', $this->deleteDeal(...))
            ->on('activity_create', $this->createActivity(...))
            ->on('activity_delete', $this->deleteActivity(...));
    }

    // --- Empresas ---

    public function saveCompany(Request $request, bool $isNew): array
    {
        $data = $this->input($request, [
            'name' => 'required|string|max:150',
            'industry' => 'nullable|string|max:80',
            'website' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|string|max:150',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:4000',
        ], ['name' => 'el nombre de la empresa']);
        $data['email'] = self::email($data['email']);

        if ($isNew) {
            $id = $this->crm->createCompany($this->agencyId, $data, $this->userId());
            $this->log('crm.company_created', 'Creó la empresa "' . $data['name'] . '"');
            return ['success' => true, 'id' => $id, 'message' => 'Empresa creada'];
        }

        $id = $request->int('id');
        if ($this->crm->company($id, $this->agencyId) === null) {
            throw HttpException::notFound('Empresa no encontrada');
        }
        $this->crm->updateCompany($id, $this->agencyId, $data);
        $this->log('crm.company_updated', 'Actualizó la empresa "' . $data['name'] . '"');
        return ['success' => true, 'message' => 'Empresa actualizada'];
    }

    public function deleteCompany(Request $request): array
    {
        $id = $request->int('id');
        $company = $this->crm->company($id, $this->agencyId);
        if ($company === null || !$this->crm->deleteCompany($id, $this->agencyId)) {
            throw HttpException::notFound('Empresa no encontrada');
        }
        $this->log('crm.company_deleted', 'Eliminó la empresa "' . $company['name'] . '"');
        return ['success' => true, 'message' => 'Empresa eliminada'];
    }

    // --- Contactos ---

    public function saveContact(Request $request, bool $isNew): array
    {
        $data = $this->input($request, [
            'name' => 'required|string|max:100',
            'role' => 'nullable|string|max:80',
            'email' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:40',
            'notes' => 'nullable|string|max:4000',
        ], ['name' => 'el nombre del contacto']);
        $data = ['company_id' => $this->companyRef($request->int('company_id'))] + $data;
        $data['email'] = self::email($data['email']);

        if ($isNew) {
            $id = $this->crm->createContact($this->agencyId, $data);
            $this->log('crm.contact_created', 'Creó el contacto "' . $data['name'] . '"');
            return ['success' => true, 'id' => $id, 'message' => 'Contacto creado'];
        }

        $id = $request->int('id');
        if ($this->crm->contact($id, $this->agencyId) === null) {
            throw HttpException::notFound('Contacto no encontrado');
        }
        $this->crm->updateContact($id, $this->agencyId, $data);
        $this->log('crm.contact_updated', 'Actualizó el contacto "' . $data['name'] . '"');
        return ['success' => true, 'message' => 'Contacto actualizado'];
    }

    public function deleteContact(Request $request): array
    {
        $id = $request->int('id');
        $contact = $this->crm->contact($id, $this->agencyId);
        if ($contact === null || !$this->crm->deleteContact($id, $this->agencyId)) {
            throw HttpException::notFound('Contacto no encontrado');
        }
        $this->log('crm.contact_deleted', 'Eliminó el contacto "' . $contact['name'] . '"');
        return ['success' => true, 'message' => 'Contacto eliminado'];
    }

    // --- Oportunidades ---

    public function saveDeal(Request $request, bool $isNew): array
    {
        $data = $this->input($request, [
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'stage' => 'in:' . implode(',', CrmRepository::STAGES) . '|default:nuevo',
            'currency' => 'in:' . implode(',', CrmRepository::CURRENCIES) . '|default:USD',
            'source' => 'nullable|string|max:60',
            'lost_reason' => 'nullable|string|max:255',
        ], ['title' => 'el título de la oportunidad']);
        $value = (float)str_replace(',', '.', (string)$request->input('value', '0'));
        $close = $request->string('expected_close');

        $data = [
            'company_id' => $this->companyRef($request->int('company_id')),
            'contact_id' => $this->contactRef($request->int('contact_id')),
            'title' => $data['title'],
            'description' => $data['description'],
            'stage' => $data['stage'],
            'value' => max(0, min($value, 999999999.99)),
            'currency' => $data['currency'],
            // Una fecha imposible se guarda como "sin fecha" (no bloquea el resto del formulario)
            'expected_close' => Format::isIsoDate($close) ? $close : null,
            'owner_id' => $this->ownerRef($request->int('owner_id')),
            'source' => $data['source'],
            'lost_reason' => $data['lost_reason'],
        ];

        if ($isNew) {
            $id = $this->crm->createDeal($this->agencyId, $data, $this->userId());
            $this->log('crm.deal_created', 'Creó la oportunidad "' . $data['title'] . '"');
            return ['success' => true, 'id' => $id, 'message' => 'Oportunidad creada'];
        }

        $id = $request->int('id');
        if ($this->crm->deal($id, $this->agencyId) === null) {
            throw HttpException::notFound('Oportunidad no encontrada');
        }
        $this->crm->updateDeal($id, $this->agencyId, $data);
        $this->log('crm.deal_updated', 'Actualizó la oportunidad "' . $data['title'] . '"');
        return ['success' => true, 'message' => 'Oportunidad actualizada'];
    }

    public function convertDeal(Request $request): array
    {
        $deal = $this->requireDeal($request->int('id'));
        if (!Permissions::canCreateProjects((string)$this->user['role'])) {
            throw HttpException::forbidden('Tu nivel de acceso no permite crear proyectos');
        }

        $result = $this->crm->convertDealToProject((int)$deal['id'], $this->agencyId, $this->userId());
        if (!$result['already']) {
            $this->activity->logByUser($this->userId(), $result['project_id'], 'crm.deal_converted', 'Convirtió la oportunidad "' . $deal['title'] . '" en el proyecto #' . $result['project_id']);
            $this->notify('crm.deal_converted', 'Oportunidad convertida en proyecto', $this->userName() . ' convirtió la oportunidad "' . $deal['title'] . '" en un proyecto.', '/project/' . $result['project_id']);
        }

        return [
            'success' => true,
            'project_id' => $result['project_id'],
            'already' => $result['already'],
            'redirect' => '/app/projects/' . $result['project_id'],
            'message' => $result['already'] ? 'La oportunidad ya tenía un proyecto asociado' : 'Oportunidad convertida en proyecto',
        ];
    }

    public function moveDeal(Request $request): array
    {
        $stage = $request->string('stage');
        if (!in_array($stage, CrmRepository::STAGES, true)) {
            throw HttpException::invalid('Etapa no válida');
        }
        $deal = $this->requireDeal($request->int('id'));
        $lostReason = mb_substr($request->string('lost_reason'), 0, 255);
        $this->crm->moveDeal((int)$deal['id'], $this->agencyId, $stage, $lostReason !== '' ? $lostReason : null);
        $this->log('crm.deal_stage_changed', 'Movió "' . $deal['title'] . '" a ' . CrmRepository::STAGE_LABELS[$stage]);

        if ($stage === 'ganado' || $stage === 'perdido') {
            $value = (float)($deal['value'] ?? 0);
            $currency = $deal['currency'] ?: 'USD';
            $detail = $stage === 'ganado'
                ? 'La oportunidad "' . $deal['title'] . '" se marcó como ganada' . ($value > 0 ? ' (' . number_format($value, 2) . ' ' . $currency . ')' : '') . '.'
                : 'La oportunidad "' . $deal['title'] . '" se marcó como perdida.';
            $this->notify(
                $stage === 'ganado' ? 'crm.deal_won' : 'crm.deal_lost',
                $stage === 'ganado' ? 'Oportunidad ganada' : 'Oportunidad perdida',
                $this->userName() . ': ' . $detail,
                'crm#pipeline'
            );
        }

        return ['success' => true, 'message' => 'Etapa actualizada', 'stage' => $stage];
    }

    public function deleteDeal(Request $request): array
    {
        $id = $request->int('id');
        $deal = $this->crm->deal($id, $this->agencyId);
        if ($deal === null || !$this->crm->deleteDeal($id, $this->agencyId)) {
            throw HttpException::notFound('Oportunidad no encontrada');
        }
        $this->log('crm.deal_deleted', 'Eliminó la oportunidad "' . $deal['title'] . '"');
        return ['success' => true, 'message' => 'Oportunidad eliminada'];
    }

    // --- Actividades ---

    public function createActivity(Request $request): array
    {
        $data = $this->input($request, [
            'title' => 'required|string|max:150',
            'type' => 'in:' . implode(',', CrmRepository::ACTIVITY_TYPES) . '|default:nota',
            'notes' => 'nullable|string|max:4000',
        ], ['title' => 'la descripción de la actividad']);

        $occurred = $request->string('occurred_at');
        $timestamp = $occurred !== '' ? date_create(mb_substr($occurred, 0, 25)) : false;

        $id = $this->crm->createActivity($this->agencyId, [
            'deal_id' => $this->dealRef($request->int('deal_id')),
            'company_id' => $this->companyRef($request->int('company_id')),
            'contact_id' => $this->contactRef($request->int('contact_id')),
            'type' => $data['type'],
            'title' => $data['title'],
            'notes' => $data['notes'],
            'occurred_at' => $timestamp !== false ? $timestamp->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'),
        ], $this->userId());
        $this->log('crm.activity_logged', 'Registró: ' . $data['title']);
        return ['success' => true, 'id' => $id, 'message' => 'Actividad registrada'];
    }

    public function deleteActivity(Request $request): array
    {
        if (!$this->crm->deleteActivity($request->int('id'), $this->agencyId)) {
            throw HttpException::notFound('Actividad no encontrada');
        }
        return ['success' => true, 'message' => 'Actividad eliminada'];
    }

    // --- Utilidades ---

    /** @param array<string, string> $rules @param array<string, string> $labels */
    private function input(Request $request, array $rules, array $labels): array
    {
        $input = [];
        foreach (array_keys($rules) as $field) {
            $input[$field] = $request->input($field);
        }
        return Validator::validate($input, $rules, $labels);
    }

    private function requireDeal(int $id): array
    {
        $deal = $this->crm->deal($id, $this->agencyId);
        if ($deal === null) {
            throw HttpException::notFound('Oportunidad no encontrada');
        }
        return $deal;
    }

    private function companyRef(int $id): ?int
    {
        return $id > 0 && $this->crm->company($id, $this->agencyId) !== null ? $id : null;
    }

    private function contactRef(int $id): ?int
    {
        return $id > 0 && $this->crm->contact($id, $this->agencyId) !== null ? $id : null;
    }

    private function dealRef(int $id): ?int
    {
        return $id > 0 && $this->crm->deal($id, $this->agencyId) !== null ? $id : null;
    }

    private function ownerRef(int $id): ?int
    {
        return $id > 0 && $this->crm->isAssignableOwner($id, $this->agencyId) ? $id : null;
    }

    private static function email(?string $value): ?string
    {
        $value = $value !== null ? mb_strtolower(trim($value)) : null;
        return $value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function userId(): int
    {
        return (int)$this->user['id'];
    }

    private function userName(): string
    {
        return (string)($this->user['name'] ?? 'Usuario');
    }

    private function log(string $action, string $description): void
    {
        $this->activity->logByUser($this->userId(), null, $action, $description);
    }

    private function notify(string $type, string $title, string $message, string $url): void
    {
        try {
            $this->notifications?->notifyAgencyAdmins($this->agencyId, $type, $title, $message, $url, true, $this->userId());
        } catch (\Throwable $e) {
            error_log('Notificación CRM: ' . $e->getMessage());
        }
    }
}

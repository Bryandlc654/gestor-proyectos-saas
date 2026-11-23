<?php

namespace App\Repository;

use PDO;
use Throwable;

/**
 * Historial de actividad y auditoría. Los registros se limitan por agencia;
 * el proyecto y el usuario pueden desaparecer sin borrar el evento.
 */
final class ActivityRepository
{
    private const LABELS = [
        'project.created' => 'Proyecto creado',
        'project.updated' => 'Proyecto actualizado',
        'project.archived' => 'Proyecto archivado',
        'project.restored' => 'Proyecto restaurado',
        'project.deleted' => 'Proyecto eliminado definitivamente',
        'project.duplicated' => 'Proyecto duplicado',
        'project.baseline_created' => 'Línea base creada',
        'project.baseline_deleted' => 'Línea base eliminada',
        'task.rescheduled' => 'Tarea reprogramada',
        'meeting.created' => 'Acta de reunión registrada',
        'meeting.deleted' => 'Acta de reunión eliminada',
        'template.created' => 'Plantilla creada',
        'template.deleted' => 'Plantilla eliminada',
        'share.created' => 'Proyecto compartido',
        'share.updated' => 'Acceso al proyecto modificado',
        'share.removed' => 'Proyecto dejó de compartirse',
        'share.members_updated' => 'Equipo invitado actualizado',
        'task.created' => 'Tarea creada',
        'task.subtask_created' => 'Subtarea creada',
        'task.dependency_added' => 'Dependencia añadida',
        'task.dependency_removed' => 'Dependencia eliminada',
        'task.updated' => 'Tarea editada',
        'task.status_changed' => 'Estado de tarea actualizado',
        'task.reordered' => 'Orden de tarea actualizado',
        'task.deleted' => 'Tarea eliminada',
        'task.reminder' => 'Recordatorio de tarea enviado',
        'invoice.reminder' => 'Recordatorio de factura enviado',
        'finance.viewed' => 'Finanzas consultadas',
        'invoice.created' => 'Factura emitida',
        'invoice.status_changed' => 'Estado de factura actualizado',
        'invoice.deleted' => 'Factura eliminada',
        'invoice.document_uploaded' => 'Documento de factura subido',
        'invoice.document_deleted' => 'Documento de factura eliminado',
        'finance.payable_created' => 'Cuenta por pagar registrada',
        'finance.payable_updated' => 'Cuenta por pagar actualizada',
        'finance.payable_deleted' => 'Cuenta por pagar eliminada',
        'finance.payment_added' => 'Pago registrado',
        'finance.payment_deleted' => 'Pago eliminado',
        'finance.receivable_paid' => 'Factura marcada como cobrada',
        'finance.receivable_pending' => 'Factura marcada como pendiente',
        'cash.movement_created' => 'Movimiento de caja registrado',
        'cash.movement_updated' => 'Movimiento de caja actualizado',
        'cash.movement_deleted' => 'Movimiento de caja eliminado',
        'cash.category_created' => 'Categoría de caja creada',
        'cash.category_updated' => 'Categoría de caja actualizada',
        'cash.category_deleted' => 'Categoría de caja eliminada',
        'cash.category_toggled' => 'Estado de categoría de caja actualizado',
        'cash.account_created' => 'Cuenta de caja creada',
        'cash.account_updated' => 'Cuenta de caja actualizada',
        'cash.account_deleted' => 'Cuenta de caja eliminada',
        'cash.account_toggled' => 'Estado de cuenta de caja actualizado',
        'cash.automation_saved' => 'Reglas de automatización de caja actualizadas',
        'cash.automation_removed' => 'Movimiento automático de caja eliminado',
        'budget.updated' => 'Presupuesto actualizado',
        'invitation.created' => 'Invitación creada',
        'profile.updated' => 'Perfil actualizado',
        'profile.avatar_updated' => 'Foto de perfil actualizada',
        'profile.avatar_removed' => 'Foto de perfil eliminada',
        'password.changed' => 'Contraseña cambiada',
        'password.reset_requested' => 'Recuperación de contraseña solicitada',
        'password.reset' => 'Contraseña restablecida',
        '2fa.enabled' => 'Doble factor activado',
        '2fa.disabled' => 'Doble factor desactivado',
        '2fa.failed' => 'Código de doble factor incorrecto',
        'auth.2fa' => 'Inicio de sesión con doble factor',
    'auth.google' => 'Inicio de sesión con Google',
    'api.token_created' => 'Token de API creado',
    'api.token_revoked' => 'Token de API revocado',
    'api.ticket_created' => 'Ticket creado vía API',
    'api.ticket_updated' => 'Ticket actualizado vía API',
    'api.webhook_updated' => 'Webhook de API actualizado',
    'settings.role_modules' => 'Permisos por rol actualizados',
        'account.verification_sent' => 'Correo de verificación enviado',
        'account.email_verified' => 'Correo electrónico verificado',
        'crm.company_created' => 'Empresa creada en el CRM',
        'crm.company_updated' => 'Empresa actualizada en el CRM',
        'crm.company_deleted' => 'Empresa eliminada del CRM',
        'crm.contact_created' => 'Contacto creado en el CRM',
        'crm.contact_updated' => 'Contacto actualizado en el CRM',
        'crm.contact_deleted' => 'Contacto eliminado del CRM',
        'crm.deal_created' => 'Oportunidad creada',
        'crm.deal_updated' => 'Oportunidad actualizada',
        'crm.deal_stage_changed' => 'Etapa de oportunidad actualizada',
        'crm.deal_deleted' => 'Oportunidad eliminada',
        'crm.activity_logged' => 'Actividad registrada en el CRM',
        'support.request_created' => 'Solicitud de soporte enviada',
        'support.request_resolved' => 'Solicitud de soporte resuelta',
        'support.request_reopened' => 'Solicitud de soporte reabierta',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Registra un evento usando la agencia del propio usuario. Si la auditoría falla,
     * no interrumpe la acción principal.
     */
    public function logByUser(?int $userId, ?int $projectId, string $action, ?string $description = null, array $metadata = []): void
    {
        try {
            $stmt = $this->db->prepare("SELECT agency_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $agencyId = $stmt->fetchColumn();
            if ($agencyId === false) {
                return;
            }
            $this->insert((int)$agencyId, $userId, $projectId, $action, $description, $metadata);
        } catch (Throwable $e) {
            error_log('activity_log: ' . $e->getMessage());
        }
    }

    /** Registra un evento con una agencia explícita (acciones sin usuario o de sistema). */
    public function log(int $agencyId, ?int $userId, ?int $projectId, string $action, ?string $description = null, array $metadata = []): void
    {
        try {
            $this->insert($agencyId, $userId, $projectId, $action, $description, $metadata);
        } catch (Throwable $e) {
            error_log('activity_log: ' . $e->getMessage());
        }
    }

    /** @return array<int, array> */
    public function forAgency(int $agencyId, int $limit = 100, ?string $action = null, ?int $projectId = null, int $offset = 0): array
    {
        [$where, $params] = $this->agencyFilters($agencyId, $action, $projectId);

        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);

        $stmt = $this->db->prepare("
            SELECT a.*, u.name AS user_name, u.role AS user_role, p.name AS project_name
            FROM activity_log a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN projects p ON p.id = a.project_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        return $this->decorate($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Total de eventos con los mismos filtros, para paginar el historial. */
    public function countForAgency(int $agencyId, ?string $action = null, ?int $projectId = null): int
    {
        [$where, $params] = $this->agencyFilters($agencyId, $action, $projectId);

        // COUNT sobre el índice (agency_id, created_at): no necesita leer la tabla completa.
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM activity_log a WHERE " . implode(' AND ', $where) . "
        ");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Página del historial y total en una sola consulta (COUNT(*) OVER ()), para no duplicar
     * el recorrido del índice ni la ida y vuelta a la base de datos.
     *
     * @return array{0: array<int, array>, 1: int} [eventos, total]
     */
    public function pageForAgency(int $agencyId, int $limit = 50, int $offset = 0, ?string $action = null, ?int $projectId = null): array
    {
        [$where, $params] = $this->agencyFilters($agencyId, $action, $projectId);

        $limit = max(1, min($limit, 200));
        $offset = max(0, $offset);

        $stmt = $this->db->prepare("
            SELECT a.*, u.name AS user_name, u.role AS user_role, p.name AS project_name,
                   COUNT(*) OVER () AS total_count
            FROM activity_log a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN projects p ON p.id = a.project_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = $rows ? (int)$rows[0]['total_count'] : 0;
        foreach ($rows as &$row) {
            unset($row['total_count']);
        }
        unset($row);

        return [$this->decorate($rows), $total];
    }

    /**
     * Condiciones comunes del historial de una agencia.
     *
     * @return array{0: array<int, string>, 1: array<int, int|string>}
     */
    private function agencyFilters(int $agencyId, ?string $action, ?int $projectId): array
    {
        $where = ['a.agency_id = ?'];
        $params = [$agencyId];

        if ($action !== null && $action !== '') {
            $where[] = 'a.action = ?';
            $params[] = $action;
        }
        if ($projectId !== null && $projectId > 0) {
            $where[] = 'a.project_id = ?';
            $params[] = $projectId;
        }

        return [$where, $params];
    }

    /** @return array<int, array> */
    public function forProject(int $projectId, int $limit = 60): array
    {
        $limit = max(1, min($limit, 200));
        $stmt = $this->db->prepare("
            SELECT a.*, u.name AS user_name, u.role AS user_role, ag.name AS agency_name
            FROM activity_log a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN agencies ag ON ag.id = a.agency_id
            WHERE a.project_id = ?
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$projectId]);
        return $this->decorate($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    /** @return array<int, string> */
    public static function labels(): array
    {
        return self::LABELS;
    }

    private function insert(int $agencyId, ?int $userId, ?int $projectId, string $action, ?string $description, array $metadata): void
    {
        $this->db->prepare("
            INSERT INTO activity_log (agency_id, user_id, project_id, action, description, metadata, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $agencyId,
            $userId,
            $projectId,
            $action,
            $description !== null ? mb_substr($description, 0, 255) : null,
            empty($metadata) ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    private function decorate(array $rows): array
    {
        return array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['agency_id'] = (int)$row['agency_id'];
            $row['user_id'] = $row['user_id'] !== null ? (int)$row['user_id'] : null;
            $row['project_id'] = $row['project_id'] !== null ? (int)$row['project_id'] : null;
            $row['label'] = self::label($row['action']);
            $row['metadata'] = !empty($row['metadata']) ? (json_decode($row['metadata'], true) ?: []) : [];
            return $row;
        }, $rows);
    }
}

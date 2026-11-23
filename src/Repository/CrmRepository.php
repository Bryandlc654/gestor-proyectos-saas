<?php

namespace App\Repository;

use PDO;
use DomainException;
use Throwable;

/**
 * Datos del módulo CRM: empresas, contactos, oportunidades (pipeline) y actividades.
 * Todas las consultas están acotadas a la agencia indicada.
 */
final class CrmRepository
{
    public const STAGES = ['nuevo', 'contactado', 'propuesta', 'negociacion', 'ganado', 'perdido'];

    public const STAGE_LABELS = [
        'nuevo' => 'Nuevo',
        'contactado' => 'Contactado',
        'propuesta' => 'Propuesta',
        'negociacion' => 'Negociación',
        'ganado' => 'Ganado',
        'perdido' => 'Perdido',
    ];

    public const SOURCES = ['Web', 'Referido', 'Redes sociales', 'Evento', 'Llamada', 'Email', 'Otro'];

    public const ACTIVITY_TYPES = ['llamada', 'correo', 'reunion', 'nota', 'tarea'];

    public const ACTIVITY_LABELS = [
        'llamada' => 'Llamada',
        'correo' => 'Correo',
        'reunion' => 'Reunión',
        'nota' => 'Nota',
        'tarea' => 'Tarea',
    ];

    public const CURRENCIES = ['USD', 'EUR', 'MXN', 'PEN', 'COP', 'CLP', 'ARS'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<int, array<string, mixed>> Empresas con sus contadores y valor abierto. */
    public function companies(int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM crm_contacts ct WHERE ct.company_id = c.id) AS contacts_count,
                   (SELECT COUNT(*) FROM crm_deals d WHERE d.company_id = c.id) AS deals_count,
                   (SELECT COUNT(*) FROM crm_deals d WHERE d.company_id = c.id AND d.converted_project_id IS NOT NULL) AS projects_count,
                   (SELECT COUNT(*) FROM quotes q INNER JOIN crm_deals d ON d.id = q.deal_id WHERE d.company_id = c.id) AS quotes_count,
                   (SELECT COALESCE(SUM(d.value), 0) FROM crm_deals d
                     WHERE d.company_id = c.id AND d.stage NOT IN ('ganado', 'perdido')) AS open_value
            FROM crm_companies c
            WHERE c.agency_id = ?
            ORDER BY c.name ASC
        ");
        $stmt->execute([$agencyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function company(int $id, int $agencyId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM crm_companies WHERE id = ? AND agency_id = ?");
        $stmt->execute([$id, $agencyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createCompany(int $agencyId, array $data, int $userId): int
    {
        $this->db->prepare("
            INSERT INTO crm_companies (agency_id, name, industry, website, phone, email, address, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $agencyId,
            $data['name'],
            $data['industry'],
            $data['website'],
            $data['phone'],
            $data['email'],
            $data['address'],
            $data['notes'],
            $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateCompany(int $id, int $agencyId, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE crm_companies
            SET name = ?, industry = ?, website = ?, phone = ?, email = ?, address = ?, notes = ?
            WHERE id = ? AND agency_id = ?
        ");
        $stmt->execute([
            $data['name'],
            $data['industry'],
            $data['website'],
            $data['phone'],
            $data['email'],
            $data['address'],
            $data['notes'],
            $id,
            $agencyId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function deleteCompany(int $id, int $agencyId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM crm_companies WHERE id = ? AND agency_id = ?");
        $stmt->execute([$id, $agencyId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int, array<string, mixed>> */
    public function contacts(int $agencyId, ?int $companyId = null): array
    {
        $sql = "
            SELECT ct.*, c.name AS company_name
            FROM crm_contacts ct
            LEFT JOIN crm_companies c ON c.id = ct.company_id
            WHERE ct.agency_id = ?
        ";
        $params = [$agencyId];
        if ($companyId !== null && $companyId > 0) {
            $sql .= " AND ct.company_id = ?";
            $params[] = $companyId;
        }
        $sql .= " ORDER BY ct.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contact(int $id, int $agencyId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM crm_contacts WHERE id = ? AND agency_id = ?");
        $stmt->execute([$id, $agencyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createContact(int $agencyId, array $data): int
    {
        $this->db->prepare("
            INSERT INTO crm_contacts (agency_id, company_id, name, role, email, phone, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $agencyId,
            $data['company_id'],
            $data['name'],
            $data['role'],
            $data['email'],
            $data['phone'],
            $data['notes'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateContact(int $id, int $agencyId, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE crm_contacts
            SET company_id = ?, name = ?, role = ?, email = ?, phone = ?, notes = ?
            WHERE id = ? AND agency_id = ?
        ");
        $stmt->execute([
            $data['company_id'],
            $data['name'],
            $data['role'],
            $data['email'],
            $data['phone'],
            $data['notes'],
            $id,
            $agencyId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function deleteContact(int $id, int $agencyId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM crm_contacts WHERE id = ? AND agency_id = ?");
        $stmt->execute([$id, $agencyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Oportunidades de la agencia, con empresa, contacto y responsable.
     *
     * @return array<int, array<string, mixed>>
     */
    public function deals(int $agencyId, string $search = '', int $limit = 300): array
    {
        $limit = max(1, min($limit, 500));

        $sql = "
            SELECT d.*, c.name AS company_name, ct.name AS contact_name, u.name AS owner_name,
                   ct.email AS contact_email, ct.phone AS contact_phone, c.phone AS company_phone,
                   p.name AS converted_project_name,
                   (SELECT COUNT(*) FROM quotes q WHERE q.deal_id = d.id) AS quotes_count,
                   (SELECT q.status FROM quotes q WHERE q.deal_id = d.id ORDER BY q.id DESC LIMIT 1) AS latest_quote_status,
                   (SELECT COALESCE(SUM(q.total), 0) FROM quotes q WHERE q.deal_id = d.id AND q.status <> 'rechazada') AS quotes_value,
                   (SELECT MAX(a.occurred_at) FROM crm_activities a WHERE a.deal_id = d.id) AS last_activity_at,
                   (SELECT a.occurred_at FROM crm_activities a
                     WHERE a.deal_id = d.id AND a.type = 'tarea' AND a.occurred_at >= NOW()
                     ORDER BY a.occurred_at ASC LIMIT 1) AS next_activity_at,
                   (SELECT a.title FROM crm_activities a
                     WHERE a.deal_id = d.id AND a.type = 'tarea' AND a.occurred_at >= NOW()
                     ORDER BY a.occurred_at ASC LIMIT 1) AS next_activity_title
            FROM crm_deals d
            LEFT JOIN crm_companies c ON c.id = d.company_id
            LEFT JOIN crm_contacts ct ON ct.id = d.contact_id
            LEFT JOIN users u ON u.id = d.owner_id
            LEFT JOIN projects p ON p.id = d.converted_project_id
            WHERE d.agency_id = ?
        ";
        $params = [$agencyId];

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= " AND (d.title LIKE ? OR c.name LIKE ? OR ct.name LIKE ?)";
            array_push($params, $like, $like, $like);
        }

        $sql .= " ORDER BY (d.stage IN ('ganado', 'perdido')) ASC, d.updated_at DESC, d.id DESC LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deal(int $id, int $agencyId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM crm_deals WHERE id = ? AND agency_id = ?");
        $stmt->execute([$id, $agencyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createDeal(int $agencyId, array $data, int $userId): int
    {
        $this->db->prepare("
            INSERT INTO crm_deals
                (agency_id, company_id, contact_id, title, description, stage, value, currency,
                 expected_close, owner_id, source, won_at, lost_at, lost_reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 'ganado', NOW(), NULL), IF(? = 'perdido', NOW(), NULL), ?, ?)
        ")->execute([
            $agencyId,
            $data['company_id'],
            $data['contact_id'],
            $data['title'],
            $data['description'],
            $data['stage'],
            $data['value'],
            $data['currency'],
            $data['expected_close'],
            $data['owner_id'],
            $data['source'],
            $data['stage'],
            $data['stage'],
            $data['lost_reason'],
            $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateDeal(int $id, int $agencyId, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE crm_deals
            SET company_id = ?, contact_id = ?, title = ?, description = ?, stage = ?, value = ?,
                currency = ?, expected_close = ?, owner_id = ?, source = ?,
                won_at = IF(? = 'ganado', COALESCE(won_at, NOW()), NULL),
                lost_at = IF(? = 'perdido', COALESCE(lost_at, NOW()), NULL),
                lost_reason = ?
            WHERE id = ? AND agency_id = ?
        ");
        $stmt->execute([
            $data['company_id'],
            $data['contact_id'],
            $data['title'],
            $data['description'],
            $data['stage'],
            $data['value'],
            $data['currency'],
            $data['expected_close'],
            $data['owner_id'],
            $data['source'],
            $data['stage'],
            $data['stage'],
            $data['lost_reason'],
            $id,
            $agencyId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function moveDeal(int $id, int $agencyId, string $stage, ?string $lostReason = null): bool
    {
        if (!in_array($stage, self::STAGES, true)) {
            return false;
        }
        $stmt = $this->db->prepare("
            UPDATE crm_deals
            SET stage = ?,
                won_at = IF(? = 'ganado', COALESCE(won_at, NOW()), NULL),
                lost_at = IF(? = 'perdido', NOW(), NULL),
                lost_reason = ?
            WHERE id = ? AND agency_id = ?
        ");
        $stmt->execute([$stage, $stage, $stage, $lostReason, $id, $agencyId]);
        return $stmt->rowCount() > 0;
    }

    public function deleteDeal(int $id, int $agencyId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM crm_deals WHERE id = ? AND agency_id = ?");
        $stmt->execute([$id, $agencyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Convierte una oportunidad ganada en proyecto. Es idempotente: si ya tiene
     * un proyecto asociado devuelve el mismo id sin crear duplicados.
     *
     * @return array{project_id:int,already:bool}
     */
    public function convertDealToProject(int $dealId, int $agencyId, int $userId): array
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM crm_deals WHERE id = ? AND agency_id = ? FOR UPDATE");
            $stmt->execute([$dealId, $agencyId]);
            $deal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$deal) {
                throw new DomainException('La oportunidad no existe o no pertenece a tu agencia.');
            }

            if (!empty($deal['converted_project_id'])) {
                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return ['project_id' => (int)$deal['converted_project_id'], 'already' => true];
            }

            if ((string)$deal['stage'] !== 'ganado') {
                throw new DomainException('Marca la oportunidad como ganada antes de convertirla en proyecto.');
            }

            $ownerId = (int)($deal['owner_id'] ?? 0);
            $ownerProjectRole = 'colaborador';
            if ($ownerId > 0) {
                $ownerStmt = $this->db->prepare("SELECT id, role FROM users WHERE id = ? AND agency_id = ?");
                $ownerStmt->execute([$ownerId, $agencyId]);
                $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
                if (!$owner) {
                    $ownerId = 0;
                } else {
                    $ownerProjectRole = in_array($owner['role'], ['admin', 'project_manager'], true) ? 'project_manager' : 'colaborador';
                }
            }
            if ($ownerId <= 0) {
                $ownerId = $userId;
            }

            $currency = strtoupper((string)($deal['currency'] ?? 'USD'));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = 'USD';
            }

            $title = trim((string)($deal['title'] ?? ''));
            if ($title === '') {
                $title = 'Proyecto sin título';
            }
            $title = mb_substr($title, 0, 150);

            $description = trim((string)($deal['description'] ?? ''));
            if ($description === '') {
                $description = 'Proyecto creado a partir de la oportunidad "' . $title . '".';
            }

            $stmt = $this->db->prepare("
                INSERT INTO projects (agency_id, user_id, name, description, category, status, color, deadline, budget, currency)
                VALUES (?, ?, ?, ?, 'General', 'activo', '#0E66FF', ?, ?, ?)
            ");
            $stmt->execute([
                $agencyId,
                $ownerId,
                $title,
                $description,
                $deal['expected_close'] ?: null,
                max(0, round((float)($deal['value'] ?? 0), 2)),
                $currency,
            ]);
            $projectId = (int)$this->db->lastInsertId();

            $memberStmt = $this->db->prepare("INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)");
            $memberStmt->execute([$projectId, $userId, 'project_manager']);
            if ($ownerId !== $userId) {
                $memberStmt->execute([$projectId, $ownerId, $ownerProjectRole]);
            }

            $this->db->prepare("UPDATE crm_deals SET converted_project_id = ?, converted_at = NOW() WHERE id = ? AND agency_id = ?")
                ->execute([$projectId, $dealId, $agencyId]);

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return ['project_id' => $projectId, 'already' => false];
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Propuestas vinculadas a oportunidades, para el seguimiento comercial del CRM.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dealQuotes(int $agencyId, string $search = '', int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        $sql = "
            SELECT q.id, q.project_id, q.deal_id, q.quote_number, q.title, q.client_name, q.client_company,
                   q.total, q.currency, q.status, q.issue_date, q.valid_until,
                   d.title AS deal_title, d.stage AS deal_stage,
                   c.id AS company_id, c.name AS company_name
            FROM quotes q
            INNER JOIN crm_deals d ON d.id = q.deal_id
            LEFT JOIN crm_companies c ON c.id = d.company_id
            WHERE q.agency_id = ?
        ";
        $params = [$agencyId];

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $sql .= " AND (q.quote_number LIKE ? OR q.title LIKE ? OR d.title LIKE ? OR c.name LIKE ?)";
            array_push($params, $like, $like, $like, $like);
        }

        $sql .= " ORDER BY q.issue_date DESC, q.id DESC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Historial de oportunidades de una empresa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function companyDeals(int $companyId, int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, ct.name AS contact_name, u.name AS owner_name,
                   p.name AS converted_project_name,
                   (SELECT COUNT(*) FROM quotes q WHERE q.deal_id = d.id) AS quotes_count,
                   (SELECT COALESCE(SUM(q.total), 0) FROM quotes q WHERE q.deal_id = d.id AND q.status <> 'rechazada') AS quotes_value
            FROM crm_deals d
            LEFT JOIN crm_contacts ct ON ct.id = d.contact_id
            LEFT JOIN users u ON u.id = d.owner_id
            LEFT JOIN projects p ON p.id = d.converted_project_id
            WHERE d.company_id = ? AND d.agency_id = ?
            ORDER BY d.updated_at DESC, d.id DESC
        ");
        $stmt->execute([$companyId, $agencyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Propuestas comerciales de una empresa (a través de sus oportunidades).
     *
     * @return array<int, array<string, mixed>>
     */
    public function companyQuotes(int $companyId, int $agencyId, int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->db->prepare("
            SELECT q.id, q.project_id, q.deal_id, q.quote_number, q.title, q.total, q.currency, q.status,
                   q.issue_date, q.valid_until, d.title AS deal_title, d.stage AS deal_stage
            FROM quotes q
            INNER JOIN crm_deals d ON d.id = q.deal_id
            WHERE d.company_id = ? AND q.agency_id = ?
            ORDER BY q.issue_date DESC, q.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$companyId, $agencyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Actividades de una empresa, incluidas las vinculadas a sus oportunidades.
     *
     * @return array<int, array<string, mixed>>
     */
    public function companyActivities(int $companyId, int $agencyId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));
        $stmt = $this->db->prepare("
            SELECT a.*, u.name AS user_name, d.title AS deal_title, ct.name AS contact_name
            FROM crm_activities a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN crm_deals d ON d.id = a.deal_id
            LEFT JOIN crm_contacts ct ON ct.id = a.contact_id
            WHERE a.agency_id = ?
              AND (a.company_id = ? OR a.deal_id IN (
                  SELECT d2.id FROM crm_deals d2 WHERE d2.company_id = ? AND d2.agency_id = ?
              ))
            ORDER BY a.occurred_at DESC, a.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$agencyId, $companyId, $companyId, $agencyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    public function activities(int $agencyId, int $limit = 50, ?int $dealId = null): array
    {
        $limit = max(1, min($limit, 200));

        $sql = "
            SELECT a.*, u.name AS user_name, d.title AS deal_title, c.name AS company_name, ct.name AS contact_name
            FROM crm_activities a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN crm_deals d ON d.id = a.deal_id
            LEFT JOIN crm_companies c ON c.id = a.company_id
            LEFT JOIN crm_contacts ct ON ct.id = a.contact_id
            WHERE a.agency_id = ?
        ";
        $params = [$agencyId];
        if ($dealId !== null && $dealId > 0) {
            $sql .= " AND a.deal_id = ?";
            $params[] = $dealId;
        }
        $sql .= " ORDER BY a.occurred_at DESC, a.id DESC LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createActivity(int $agencyId, array $data, ?int $userId): int
    {
        $this->db->prepare("
            INSERT INTO crm_activities (agency_id, deal_id, company_id, contact_id, user_id, type, title, notes, occurred_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $agencyId,
            $data['deal_id'],
            $data['company_id'],
            $data['contact_id'],
            $userId,
            $data['type'],
            $data['title'],
            $data['notes'],
            $data['occurred_at'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** Total de actividades registradas (para el contador de la pestaña). */
    public function activityCount(int $agencyId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM crm_activities WHERE agency_id = ?");
        $stmt->execute([$agencyId]);
        return (int)$stmt->fetchColumn();
    }

    public function deleteActivity(int $id, int $agencyId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM crm_activities WHERE id = ? AND agency_id = ?");
        $stmt->execute([$id, $agencyId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array{open_deals: int, open_value: float, won_deals: int, won_value: float, lost_deals: int, won_month: int, won_value_month: float, close_rate: int, companies: int, contacts: int} */
    public function stats(int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN stage NOT IN ('ganado', 'perdido') THEN 1 ELSE 0 END) AS open_deals,
                COALESCE(SUM(CASE WHEN stage NOT IN ('ganado', 'perdido') THEN value ELSE 0 END), 0) AS open_value,
                SUM(CASE WHEN stage = 'ganado' THEN 1 ELSE 0 END) AS won_deals,
                COALESCE(SUM(CASE WHEN stage = 'ganado' THEN value ELSE 0 END), 0) AS won_value,
                SUM(CASE WHEN stage = 'perdido' THEN 1 ELSE 0 END) AS lost_deals,
                SUM(CASE WHEN stage = 'ganado' AND won_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN 1 ELSE 0 END) AS won_month,
                COALESCE(SUM(CASE WHEN stage = 'ganado' AND won_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN value ELSE 0 END), 0) AS won_value_month,
                (SELECT COUNT(*) FROM crm_companies WHERE agency_id = ?) AS companies,
                (SELECT COUNT(*) FROM crm_contacts WHERE agency_id = ?) AS contacts
            FROM crm_deals
            WHERE agency_id = ?
        ");
        $stmt->execute([$agencyId, $agencyId, $agencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $won = (int)($row['won_deals'] ?? 0);
        $lost = (int)($row['lost_deals'] ?? 0);

        return [
            'open_deals' => (int)($row['open_deals'] ?? 0),
            'open_value' => (float)($row['open_value'] ?? 0),
            'won_deals' => $won,
            'won_value' => (float)($row['won_value'] ?? 0),
            'lost_deals' => $lost,
            'won_month' => (int)($row['won_month'] ?? 0),
            'won_value_month' => (float)($row['won_value_month'] ?? 0),
            'close_rate' => ($won + $lost) > 0 ? (int)round($won / ($won + $lost) * 100) : 0,
            'companies' => (int)($row['companies'] ?? 0),
            'contacts' => (int)($row['contacts'] ?? 0),
        ];
    }

    /** @return array<int, array{id: int, name: string}> Responsables posibles (equipo interno). */
    public function owners(int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name FROM users
            WHERE agency_id = ? AND role IN ('admin', 'project_manager', 'colaborador')
            ORDER BY name ASC
        ");
        $stmt->execute([$agencyId]);
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => $row['name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Número de oportunidades por etapa (para los contadores del Kanban). */
    public function stageCounts(int $agencyId, string $search = ''): array
    {
        $counts = array_fill_keys(self::STAGES, 0);
        foreach ($this->deals($agencyId, $search, 500) as $deal) {
            $stage = (string)$deal['stage'];
            if (isset($counts[$stage])) {
                $counts[$stage]++;
            }
        }
        return $counts;
    }
    /** Usuario interno de la agencia que puede ser responsable de una oportunidad. */
    public function isAssignableOwner(int $userId, int $agencyId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE id = ? AND agency_id = ? AND role IN ('admin', 'project_manager', 'colaborador')");
        $stmt->execute([$userId, $agencyId]);
        return (bool)$stmt->fetchColumn();
    }
}

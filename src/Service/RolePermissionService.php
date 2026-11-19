<?php

namespace App\Service;

use DomainException;
use PDO;

/**
 * Permisos de módulos por rol y agencia.
 *
 * Modelo de seguridad:
 * - El administrador siempre tiene todos los módulos.
 * - Cada rol tiene un techo máximo de módulos que no puede superarse desde la UI.
 * - Los administradores solo pueden restringir módulos dentro de ese techo.
 * - Dashboard y Configuración son módulos núcleo y permanecen siempre activos.
 */
final class RolePermissionService
{
    public const MODULES = [
        'dashboard' => 'Dashboard',
        'tasks' => 'Tareas',
        'calendar' => 'Calendario',
        'crm' => 'CRM',
        'invoices' => 'Facturación',
        'quotes' => 'Cotizaciones',
        'finance' => 'Cobros y pagos',
        'cash' => 'Caja',
        'reports' => 'Reportes',
        'profitability' => 'Rentabilidad',
        'templates' => 'Plantillas',
        'trash' => 'Papelera',
        'support' => 'Soporte',
        'settings' => 'Configuración',
        'users' => 'Usuarios y roles',
        'connections' => 'Agencias conectadas',
        'api-tokens' => 'Integraciones API',
    ];

    public const GROUPS = [
        'Operación diaria' => ['dashboard', 'tasks', 'calendar'],
        'Comercial' => ['crm', 'quotes'],
        'Finanzas' => ['invoices', 'finance', 'cash', 'reports', 'profitability'],
        'Administración' => ['templates', 'trash', 'support', 'settings', 'users', 'connections', 'api-tokens'],
    ];

    public const ROLE_LABELS = [
        'admin' => 'Administrador',
        'project_manager' => 'Project Manager',
        'colaborador' => 'Colaborador',
        'cliente' => 'Cliente',
    ];

    /** Techo máximo de módulos que puede tener cada rol. */
    public const CEILINGS = [
        'project_manager' => [
            'dashboard', 'tasks', 'calendar', 'crm', 'invoices', 'quotes', 'finance', 'cash',
            'reports', 'profitability', 'templates', 'trash', 'support', 'settings',
        ],
        'colaborador' => ['dashboard', 'tasks', 'calendar', 'settings'],
        'cliente' => ['dashboard', 'tasks', 'calendar', 'settings'],
    ];

    /** Valor inicial: cada rol arranca con todos los módulos de su techo. */
    public const DEFAULTS = [
        'project_manager' => [
            'dashboard', 'tasks', 'calendar', 'crm', 'invoices', 'quotes', 'finance', 'cash',
            'reports', 'profitability', 'templates', 'trash', 'support', 'settings',
        ],
        'colaborador' => ['dashboard', 'tasks', 'calendar', 'settings'],
        'cliente' => ['dashboard', 'tasks', 'calendar', 'settings'],
    ];

    /** Módulos que no se pueden desactivar. */
    public const CORE = ['dashboard', 'settings'];

    /** @var array<int, array<string, array<string, bool>>> */
    private array $cache = [];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function forAgency(int $agencyId): array
    {
        if (isset($this->cache[$agencyId])) {
            return $this->cache[$agencyId];
        }

        $matrix = ['admin' => array_fill_keys(array_keys(self::MODULES), true)];
        foreach (self::CEILINGS as $role => $ceiling) {
            foreach (array_keys(self::MODULES) as $module) {
                $matrix[$role][$module] = in_array($module, $ceiling, true)
                    && in_array($module, self::DEFAULTS[$role], true);
            }
        }

        $stmt = $this->db->prepare("SELECT role, module, allowed FROM agency_role_modules WHERE agency_id = ?");
        $stmt->execute([$agencyId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $role = (string)$row['role'];
            $module = (string)$row['module'];
            if (!isset(self::CEILINGS[$role]) || !in_array($module, self::CEILINGS[$role], true)) {
                continue;
            }
            $matrix[$role][$module] = (int)$row['allowed'] === 1;
        }

        foreach (self::CEILINGS as $role => $ceiling) {
            foreach (self::CORE as $coreModule) {
                if (in_array($coreModule, $ceiling, true)) {
                    $matrix[$role][$coreModule] = true;
                }
            }
        }

        return $this->cache[$agencyId] = $matrix;
    }

    public function allows(int $agencyId, string $role, string $module): bool
    {
        if ($role === 'admin') {
            return true;
        }
        if (!in_array($module, self::CEILINGS[$role] ?? [], true)) {
            return false;
        }
        if (in_array($module, self::CORE, true)) {
            return true;
        }
        return (bool)($this->forAgency($agencyId)[$role][$module] ?? false);
    }

    /** @param string[] $allowedModules */
    public function saveRole(int $agencyId, string $role, array $allowedModules, int $actorUserId): void
    {
        if (!isset(self::CEILINGS[$role])) {
            throw new DomainException('Rol no válido o no configurable.');
        }

        $ceiling = self::CEILINGS[$role];
        $allowedModules = array_values(array_unique(array_intersect($allowedModules, $ceiling)));
        foreach (self::CORE as $coreModule) {
            if (in_array($coreModule, $ceiling, true) && !in_array($coreModule, $allowedModules, true)) {
                $allowedModules[] = $coreModule;
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM agency_role_modules WHERE agency_id = ? AND role = ?")
                ->execute([$agencyId, $role]);

            $stmt = $this->db->prepare("
                INSERT INTO agency_role_modules (agency_id, role, module, allowed)
                VALUES (?, ?, ?, ?)
            ");
            foreach ($ceiling as $module) {
                $stmt->execute([$agencyId, $role, $module, in_array($module, $allowedModules, true) ? 1 : 0]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        unset($this->cache[$agencyId]);
    }

    public static function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? ucfirst($role);
    }

    public static function moduleLabel(string $module): string
    {
        return self::MODULES[$module] ?? ucfirst($module);
    }

    public static function inCeiling(string $role, string $module): bool
    {
        return in_array($module, self::CEILINGS[$role] ?? [], true);
    }

    public static function isCore(string $module): bool
    {
        return in_array($module, self::CORE, true);
    }
}

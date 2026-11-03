<?php

namespace App\Security;

/**
 * Reglas de permisos por rol global del usuario.
 * Roles: admin, project_manager, colaborador, cliente.
 * Las comprobaciones que dependen de un proyecto concreto están en ProjectRepository.
 */
final class Permissions
{
    public const ADMIN = 'admin';
    public const PROJECT_MANAGER = 'project_manager';
    public const COLABORADOR = 'colaborador';
    public const CLIENTE = 'cliente';

    public const ROLES = [self::ADMIN, self::PROJECT_MANAGER, self::COLABORADOR, self::CLIENTE];

    private const STAFF = [self::ADMIN, self::PROJECT_MANAGER, self::COLABORADOR];
    private const MANAGERS = [self::ADMIN, self::PROJECT_MANAGER];

    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    // Crear o editar tareas
    public static function canManageTasks(string $role): bool
    {
        return in_array($role, self::STAFF, true);
    }

    public static function canDeleteTasks(string $role): bool
    {
        return in_array($role, self::MANAGERS, true);
    }

    // Los clientes tienen acceso de solo lectura a las tareas
    public static function canChangeTaskStatus(string $role): bool
    {
        return in_array($role, self::STAFF, true);
    }

    public static function canManageChecklists(string $role): bool
    {
        return in_array($role, self::STAFF, true);
    }

    public static function canManageAttachments(string $role): bool
    {
        return in_array($role, self::STAFF, true);
    }

    // Todos los roles pueden abrir tickets
    public static function canCreateTickets(string $role): bool
    {
        return self::isValidRole($role);
    }

    public static function canChangeTicketStatus(string $role): bool
    {
        return in_array($role, self::STAFF, true);
    }

    public static function canDeleteTickets(string $role): bool
    {
        return in_array($role, self::MANAGERS, true);
    }

    public static function canCreateProjects(string $role): bool
    {
        return in_array($role, self::MANAGERS, true);
    }

    public static function canDeleteProjects(string $role): bool
    {
        return in_array($role, self::MANAGERS, true);
    }

    /**
     * Rol que corresponde en project_members a partir del rol global del usuario.
     */
    public static function memberRoleFor(string $role): string
    {
        return match ($role) {
            self::CLIENTE => self::CLIENTE,
            self::PROJECT_MANAGER => self::PROJECT_MANAGER,
            default => self::COLABORADOR,
        };
    }
}

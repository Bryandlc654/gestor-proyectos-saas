<?php

namespace Tests\Unit;

use App\Security\Permissions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase
{
    /**
     * Matriz de permisos esperada por rol: [admin, project_manager, colaborador, cliente].
     */
    public static function matrix(): array
    {
        return [
            'gestionar tareas'        => ['canManageTasks',        [true, true, true, false]],
            'eliminar tareas'         => ['canDeleteTasks',        [true, true, false, false]],
            'cambiar estado tarea'    => ['canChangeTaskStatus',   [true, true, true, false]],
            'gestionar checklists'    => ['canManageChecklists',   [true, true, true, false]],
            'gestionar adjuntos'      => ['canManageAttachments',  [true, true, true, false]],
            'crear tickets'           => ['canCreateTickets',      [true, true, true, true]],
            'cambiar estado ticket'   => ['canChangeTicketStatus', [true, true, true, false]],
            'eliminar tickets'        => ['canDeleteTickets',      [true, true, false, false]],
            'crear proyectos'         => ['canCreateProjects',     [true, true, false, false]],
            'eliminar proyectos'      => ['canDeleteProjects',     [true, true, false, false]],
        ];
    }

    #[DataProvider('matrix')]
    public function testPermissionMatrix(string $method, array $expected): void
    {
        foreach (Permissions::ROLES as $i => $role) {
            $this->assertSame($expected[$i], Permissions::$method($role), "{$method}({$role})");
        }
    }

    #[DataProvider('matrix')]
    public function testUnknownRoleHasNoPermissions(string $method): void
    {
        $this->assertFalse(Permissions::$method('superusuario'));
        $this->assertFalse(Permissions::$method(''));
    }

    public function testMemberRoleMapping(): void
    {
        $this->assertSame('cliente', Permissions::memberRoleFor('cliente'));
        $this->assertSame('project_manager', Permissions::memberRoleFor('project_manager'));
        $this->assertSame('colaborador', Permissions::memberRoleFor('colaborador'));
        $this->assertSame('colaborador', Permissions::memberRoleFor('admin'));
    }
}

<?php

namespace Tests\Integration;

use App\Controller\TaskController;
use App\Http\Request;
use App\Http\Router;
use App\Repository\AgencyRepository;
use App\Repository\ConnectionRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectShareRepository;
use App\Repository\TaskRepository;
use DomainException;

/**
 * Fase 3: proyectos compartidos entre agencias conectadas.
 * Datos demo: agencia 1 (usuarios 1-4, proyectos 1-2) y agencia 2 (usuarios 5-6, proyecto 3).
 */
final class ProjectSharingTest extends DatabaseTestCase
{
    private function connections(): ConnectionRepository
    {
        return new ConnectionRepository(static::$db, new AgencyRepository(static::$db));
    }

    private function shares(): ProjectShareRepository
    {
        return new ProjectShareRepository(static::$db, $this->connections());
    }

    private function projects(): ProjectRepository
    {
        return new ProjectRepository(static::$db);
    }

    /** Conecta las agencias demo y devuelve el id de la conexión aceptada. */
    private function connectAgencies(): int
    {
        $connections = $this->connections();
        $connections->request(1, 'NRTE-6789', self::ADMIN['id']);
        $id = (int)$connections->forAgency(2)['incoming'][0]['id'];
        $connections->accept($id, 2);
        return $id;
    }

    protected function tearDown(): void
    {
        static::$db?->exec("DELETE FROM project_shares");
        static::$db?->exec("DELETE FROM agency_connections");
        static::$db?->exec("DELETE FROM tasks WHERE project_id = 1 AND title LIKE 'Compartida%'");
    }

    public function testShareRequiresAnAcceptedConnection(): void
    {
        $this->expectException(DomainException::class);
        $this->shares()->share(1, 1, 2, 'view', self::ADMIN['id']);
    }

    public function testOnlyTheOwnerAgencyCanShare(): void
    {
        $this->connectAgencies();

        $this->expectException(DomainException::class);
        $this->shares()->share(1, 2, 1, 'view', self::ADMIN_B['id']);
    }

    public function testViewShareIsReadOnlyAndNeverExposesFinances(): void
    {
        $this->connectAgencies();
        $this->shares()->share(1, 1, 2, 'view', self::ADMIN['id']);

        $projects = $this->projects();
        $userId = self::ADMIN_B['id'];

        $this->assertTrue($projects->canAccess(1, $userId, 'admin'), 'El admin invitado ve el proyecto compartido');
        $this->assertSame('view', $projects->accessContext(1, $userId, 'admin')['level']);
        $this->assertFalse($projects->canEditTasks(1, $userId, 'admin'));
        $this->assertFalse($projects->canChangeTaskStatus(1, $userId, 'admin'));
        $this->assertFalse($projects->canDeleteTasks(1, $userId, 'admin'));
        $this->assertFalse($projects->canComment(1, $userId, 'admin'));
        $this->assertFalse($projects->canUploadAttachments(1, $userId, 'admin'));
        $this->assertFalse($projects->canManageChecklists(1, $userId, 'admin'), 'Ver = checklists en solo lectura');
        $this->assertFalse($projects->canCreateTickets(1, $userId, 'admin'), 'Ver = tickets en solo lectura');
        $this->assertFalse($projects->canChangeTicketStatus(1, $userId, 'admin'));
        $this->assertFalse($projects->canViewFinances(1, $userId, 'admin'), 'Las finanzas nunca se comparten');
        $this->assertFalse($projects->canManageShare(1, $userId, 'admin'));
        $this->assertTrue($projects->canManageShareMembers(1, $userId, 'admin'), 'La invitada elige a su equipo');
    }

    public function testGuestTeamIsChosenByTheGuestAgency(): void
    {
        $this->connectAgencies();
        $shareId = $this->shares()->share(1, 1, 2, 'view', self::ADMIN['id']);

        $projects = $this->projects();
        $this->assertFalse($projects->canAccess(1, self::COLABORADOR_B['id'], 'colaborador'), 'Aún no fue elegido');

        $this->shares()->setMembers($shareId, 2, [self::COLABORADOR_B['id']]);

        $this->assertTrue($projects->canAccess(1, self::COLABORADOR_B['id'], 'colaborador'));
        $this->assertSame('view', $projects->accessContext(1, self::COLABORADOR_B['id'], 'colaborador')['level']);
        $this->assertSame([self::COLABORADOR_B['id']], $this->shares()->memberIds($shareId));
    }

    public function testCollaborateAllowsTasksCommentsAndFilesWithoutFinances(): void
    {
        $this->connectAgencies();
        $shareId = $this->shares()->share(1, 1, 2, 'collaborate', self::ADMIN['id']);
        $this->shares()->setMembers($shareId, 2, [self::COLABORADOR_B['id']]);

        $projects = $this->projects();
        $userId = self::COLABORADOR_B['id'];

        $this->assertTrue($projects->canEditTasks(1, $userId, 'colaborador'));
        $this->assertTrue($projects->canChangeTaskStatus(1, $userId, 'colaborador'));
        $this->assertTrue($projects->canComment(1, $userId, 'colaborador'));
        $this->assertTrue($projects->canUploadAttachments(1, $userId, 'colaborador'));
        $this->assertTrue($projects->canManageTaskAttachments(1, $userId, 'colaborador'));
        $this->assertTrue($projects->canManageChecklists(1, $userId, 'colaborador'));
        $this->assertTrue($projects->canCreateTickets(1, $userId, 'colaborador'));
        $this->assertTrue($projects->canChangeTicketStatus(1, $userId, 'colaborador'));
        $this->assertFalse($projects->canDeleteTickets(1, $userId, 'colaborador'), 'Eliminar tickets sigue siendo de la dueña');
        $this->assertFalse($projects->canManageAttachments(1, $userId, 'colaborador'), 'Carpetas y enlaces son de la dueña');
        $this->assertFalse($projects->canDeleteTasks(1, $userId, 'colaborador'));
        $this->assertFalse($projects->canViewFinances(1, $userId, 'colaborador'));
        $this->assertFalse($projects->canManageShare(1, $userId, 'colaborador'));
    }

    public function testTaskApiHonoursTheShareAccessLevel(): void
    {
        $this->connectAgencies();
        $shareId = $this->shares()->share(1, 1, 2, 'collaborate', self::ADMIN['id']);
        $this->shares()->setMembers($shareId, 2, [self::COLABORADOR_B['id']]);

        $router = new Router();
        (new TaskController(self::COLABORADOR_B, $this->projects(), new TaskRepository(static::$db)))->register($router);

        [$status] = $router->handle(new Request('POST', [], [
            'action' => 'create', 'project_id' => 1, 'title' => 'Compartida por invitado', 'assigned_user_id' => self::COLABORADOR_B['id'],
        ]));
        $this->assertSame(200, $status);

        $taskId = (int)static::$db->query("SELECT id FROM tasks WHERE title = 'Compartida por invitado'")->fetchColumn();
        $this->assertGreaterThan(0, $taskId);

        [$status] = $router->handle(new Request('POST', [], [
            'action' => 'update_status', 'project_id' => 1, 'task_id' => $taskId, 'status' => 'completada',
        ]));
        $this->assertSame(200, $status);
        $this->assertSame('completada', static::$db->query("SELECT status FROM tasks WHERE id = {$taskId}")->fetchColumn());

        [$status] = $router->handle(new Request('POST', [], [
            'action' => 'delete', 'project_id' => 1, 'task_id' => $taskId,
        ]));
        $this->assertSame(403, $status, 'El invitado no puede eliminar tareas');
    }

    public function testViewShareBlocksTaskWrites(): void
    {
        $this->connectAgencies();
        $this->shares()->share(1, 1, 2, 'view', self::ADMIN['id']);

        $router = new Router();
        (new TaskController(self::ADMIN_B, $this->projects(), new TaskRepository(static::$db)))->register($router);

        [$status] = $router->handle(new Request('POST', [], [
            'action' => 'create', 'project_id' => 1, 'title' => 'Compartida no permitida',
        ]));
        $this->assertSame(403, $status);

        [$status] = $router->handle(new Request('POST', [], [
            'action' => 'update_status', 'project_id' => 1, 'task_id' => 1, 'status' => 'completada',
        ]));
        $this->assertSame(403, $status);
    }

    public function testSelectedGuestCanBeAssigneeOnlyWhenCollaborating(): void
    {
        $this->connectAgencies();
        $shareId = $this->shares()->share(1, 1, 2, 'view', self::ADMIN['id']);
        $this->shares()->setMembers($shareId, 2, [self::COLABORADOR_B['id']]);

        $this->assertNull($this->projects()->assigneeName(self::COLABORADOR_B['id'], 1), 'Solo lectura no asigna');

        $this->shares()->share(1, 1, 2, 'collaborate', self::ADMIN['id']);
        $this->assertSame('Diego Norte', $this->projects()->assigneeName(self::COLABORADOR_B['id'], 1));
    }

    public function testAccessCanChangeAndStopBeingShared(): void
    {
        $this->connectAgencies();
        $shareId = $this->shares()->share(1, 1, 2, 'view', self::ADMIN['id']);
        $this->assertSame('view', $this->shares()->find($shareId)['access']);

        $this->assertSame($shareId, $this->shares()->share(1, 1, 2, 'collaborate', self::ADMIN['id']), 'Cambiar acceso no duplica el share');
        $this->assertSame('collaborate', $this->shares()->find($shareId)['access']);

        $this->assertTrue($this->shares()->remove($shareId, 1));
        $this->assertFalse($this->projects()->canAccess(1, self::ADMIN_B['id'], 'admin'));
    }

    public function testDisconnectingAgenciesStopsSharingProjects(): void
    {
        $connectionId = $this->connectAgencies();
        $shareId = $this->shares()->share(1, 1, 2, 'collaborate', self::ADMIN['id']);
        $this->shares()->setMembers($shareId, 2, [self::COLABORADOR_B['id']]);
        $this->assertTrue($this->projects()->canAccess(1, self::COLABORADOR_B['id'], 'colaborador'));

        $this->assertTrue($this->connections()->remove($connectionId, 2));

        $this->assertSame(0, (int)static::$db->query("SELECT COUNT(*) FROM project_shares")->fetchColumn());
        $this->assertFalse($this->projects()->canAccess(1, self::ADMIN_B['id'], 'admin'));
        $this->assertFalse($this->projects()->canAccess(1, self::COLABORADOR_B['id'], 'colaborador'));
    }

    public function testOwnerFinancesStayPrivateAndOwnerCanShare(): void
    {
        $projects = $this->projects();

        $this->assertTrue($projects->canViewFinances(1, self::ADMIN['id'], 'admin'));
        $this->assertFalse($projects->canViewFinances(1, self::PM['id'], 'project_manager'), 'Las finanzas son del admin dueño');
        $this->assertTrue($projects->canManageShare(1, self::ADMIN['id'], 'admin'));
        $this->assertFalse($projects->canManageShare(1, self::PM['id'], 'project_manager'));
    }
}

<?php

namespace Tests\Integration;

use App\Controller\TaskController;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;

final class TaskControllerTest extends DatabaseTestCase
{
    /** @return array{0: int, 1: array} */
    private function call(array $user, array $body): array
    {
        $router = new Router();
        (new TaskController($user, new ProjectRepository(static::$db), new TaskRepository(static::$db)))->register($router);
        return $router->handle(new Request('POST', [], $body));
    }

    public function testCollaboratorCreatesTaskAssignedToMember(): void
    {
        [$status, $res] = $this->call(self::COLABORADOR, [
            'action' => 'create', 'project_id' => 1, 'title' => '<b>Nueva</b>', 'priority' => 'alta', 'assigned_user_id' => 3,
        ]);

        $this->assertSame(200, $status);
        $this->assertTrue($res['success']);
        $this->assertSame('&lt;b&gt;Nueva&lt;/b&gt;', $res['task']['title'], 'El título se devuelve escapado');
        $this->assertSame('Ana Colaboradora', $res['task']['assigned_to']);

        $row = static::$db->query("SELECT assigned_user_id, priority FROM tasks WHERE id = {$res['task']['id']}")->fetch();
        $this->assertSame(['assigned_user_id' => 3, 'priority' => 'alta'], $row);
    }

    public function testRejectsAssigneeOutsideProject(): void
    {
        [, $res] = $this->call(self::ADMIN, ['action' => 'create', 'project_id' => 2, 'title' => 'X', 'assigned_user_id' => 4]);

        $this->assertFalse($res['success']);
        $this->assertSame('El responsable debe ser miembro del proyecto', $res['message']);
    }

    public function testClientCannotCreateOrChangeStatus(): void
    {
        [$status] = $this->call(self::CLIENTE, ['action' => 'create', 'project_id' => 1, 'title' => 'X']);
        $this->assertSame(403, $status);

        [$status] = $this->call(self::CLIENTE, ['action' => 'update_status', 'project_id' => 1, 'task_id' => 1, 'status' => 'completada']);
        $this->assertSame(403, $status);
    }

    public function testCollaboratorCannotDelete(): void
    {
        [$status, $res] = $this->call(self::COLABORADOR, ['action' => 'delete', 'project_id' => 1, 'task_id' => 1]);

        $this->assertSame(403, $status);
        $this->assertNotFalse(static::$db->query("SELECT id FROM tasks WHERE id = 1")->fetchColumn());
    }

    public function testNoAccessToForeignProject(): void
    {
        [$status, $res] = $this->call(self::CLIENTE, ['action' => 'get_detail', 'project_id' => 2, 'task_id' => 1]);

        $this->assertSame(403, $status);
        $this->assertSame('Acceso denegado al proyecto', $res['message']);
    }

    public function testCannotModifyTaskOfAnotherProjectThroughOwnProject(): void
    {
        $foreignTask = static::$db->query("SELECT id FROM tasks WHERE project_id = 1 LIMIT 1")->fetchColumn();
        static::$db->exec("UPDATE tasks SET status = 'pendiente' WHERE id = {$foreignTask}");

        // El PM tiene acceso al proyecto 2, pero la tarea es del proyecto 1
        $this->call(self::PM, ['action' => 'update_status', 'project_id' => 2, 'task_id' => $foreignTask, 'status' => 'completada']);

        $this->assertSame('pendiente', static::$db->query("SELECT status FROM tasks WHERE id = {$foreignTask}")->fetchColumn());
    }

    public function testProjectIsResolvedFromTaskWhenMissing(): void
    {
        [$status, $res] = $this->call(self::COLABORADOR, ['action' => 'update_status', 'task_id' => 1, 'status' => 'en_progreso']);

        $this->assertSame(200, $status);
        $this->assertSame('en_progreso', static::$db->query("SELECT status FROM tasks WHERE id = 1")->fetchColumn());
    }

    public function testDetailHidesActivityFromClients(): void
    {
        static::$db->exec("INSERT INTO comments (project_id, task_id, user_id, content) VALUES (1, 1, 3, 'Nota interna')");

        [, $staff] = $this->call(self::COLABORADOR, ['action' => 'get_detail', 'project_id' => 1, 'task_id' => 1]);
        [, $client] = $this->call(self::CLIENTE, ['action' => 'get_detail', 'project_id' => 1, 'task_id' => 1]);

        $this->assertNotEmpty($staff['comments']);
        $this->assertSame([], $client['comments']);
        $this->assertTrue($client['permissions']['is_cliente']);
        $this->assertFalse($client['permissions']['can_edit']);
    }

    public function testUpdateClearsLegacyTextAssignee(): void
    {
        static::$db->exec("UPDATE tasks SET assigned_user_id = NULL, assigned_to = 'Equipo QA' WHERE id = 2");

        [, $before] = $this->call(self::ADMIN, ['action' => 'get_detail', 'project_id' => 1, 'task_id' => 2]);
        $this->assertSame('Equipo QA', $before['task']['assigned_to'], 'El texto heredado se muestra mientras no hay usuario');

        $this->call(self::ADMIN, ['action' => 'update', 'project_id' => 1, 'task_id' => 2, 'title' => 'Stripe', 'assigned_user_id' => 2]);

        $row = static::$db->query("SELECT assigned_user_id, assigned_to FROM tasks WHERE id = 2")->fetch();
        $this->assertSame(['assigned_user_id' => 2, 'assigned_to' => null], $row);
    }
}

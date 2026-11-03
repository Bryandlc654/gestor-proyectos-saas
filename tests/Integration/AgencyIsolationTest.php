<?php

namespace Tests\Integration;

use App\Controller\TaskController;
use App\Http\Request;
use App\Http\Router;
use App\Repository\AgencyRepository;
use App\Repository\InvitationRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Service\AccountService;
use DomainException;

/**
 * Cada agencia solo ve su propio contenido. Datos demo: agencia 1 (usuarios 1-4, proyectos 1-2)
 * y agencia 2 (usuarios 5-6, proyecto 3).
 */
final class AgencyIsolationTest extends DatabaseTestCase
{
    private function visibleIds(array $user): array
    {
        [$sql, $params] = (new ProjectRepository(static::$db))->visibleProjectsSql($user['id'], $user['role']);
        $stmt = static::$db->prepare("SELECT id FROM projects WHERE id IN ({$sql}) ORDER BY id");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function accounts(): AccountService
    {
        return new AccountService(static::$db, new AgencyRepository(static::$db), new InvitationRepository(static::$db));
    }

    public function testAdminsOnlySeeTheirAgencyProjects(): void
    {
        $this->assertSame([1, 2], $this->visibleIds(self::ADMIN));
        $this->assertSame([3], $this->visibleIds(self::ADMIN_B));
        $this->assertSame([3], $this->visibleIds(self::COLABORADOR_B));
        $this->assertSame([1], $this->visibleIds(self::CLIENTE));
    }

    public function testCannotAccessOrManageAnotherAgencyProject(): void
    {
        $repo = new ProjectRepository(static::$db);

        $this->assertFalse($repo->canAccess(3, self::ADMIN['id'], 'admin'));
        $this->assertFalse($repo->canManage(3, self::ADMIN['id'], 'admin'));
        $this->assertFalse($repo->canAccess(1, self::ADMIN_B['id'], 'admin'));
        $this->assertFalse($repo->canManage(1, self::ADMIN_B['id'], 'admin'));
        $this->assertTrue($repo->canManage(3, self::ADMIN_B['id'], 'admin'));
    }

    public function testCannotAssignUserFromAnotherAgency(): void
    {
        $repo = new ProjectRepository(static::$db);

        $this->assertSame('Diego Norte', $repo->assigneeName(6, 3));
        $this->assertNull($repo->assigneeName(6, 1));
        $this->assertNull($repo->assigneeName(3, 3));
    }

    public function testTaskApiRejectsAnotherAgencyProject(): void
    {
        $router = new Router();
        (new TaskController(self::ADMIN_B, new ProjectRepository(static::$db), new TaskRepository(static::$db)))->register($router);
        [$status] = $router->handle(new Request('POST', [], ['action' => 'create', 'project_id' => 1, 'title' => 'Intrusa']));

        $this->assertSame(403, $status);
        $this->assertFalse(static::$db->query("SELECT 1 FROM tasks WHERE title = 'Intrusa'")->fetchColumn());
    }

    public function testBelongsChecks(): void
    {
        $agencies = new AgencyRepository(static::$db);

        $this->assertTrue($agencies->userBelongs(5, 2));
        $this->assertFalse($agencies->userBelongs(1, 2));
        $this->assertTrue($agencies->projectBelongs(3, 2));
        $this->assertFalse($agencies->projectBelongs(3, 1));
    }

    public function testRegisterCreatesAgencyWithAdmin(): void
    {
        $user = $this->accounts()->registerAgency('Agencia Nueva', 'Dueña Nueva', 'Duena@Nueva.com', 'secreto123');

        $this->assertSame('admin', $user['role']);
        $this->assertSame('duena@nueva.com', $user['email']);
        $this->assertSame('Agencia Nueva', (new AgencyRepository(static::$db))->find($user['agency_id'])['name']);
        $this->assertSame([], $this->visibleIds(['id' => $user['id'], 'role' => 'admin']), 'Una agencia nueva empieza vacía');
    }

    public function testRegisterRejectsDuplicateEmailWithoutCreatingAgency(): void
    {
        $before = (int)static::$db->query("SELECT COUNT(*) FROM agencies")->fetchColumn();
        try {
            $this->accounts()->registerAgency('Copia', 'Alguien', 'admin@demo.com', 'secreto123');
            $this->fail('Debía rechazar el correo repetido');
        } catch (DomainException $e) {
            $this->assertStringContainsString('ya está registrado', $e->getMessage());
        }
        $this->assertSame($before, (int)static::$db->query("SELECT COUNT(*) FROM agencies")->fetchColumn());
    }

    public function testInvitationJoinsInvitingAgencyOnce(): void
    {
        $invitations = new InvitationRepository(static::$db);
        $token = $invitations->create(2, 'cliente@norte.com', 'cliente', self::ADMIN_B['id']);

        $hash = static::$db->query("SELECT token_hash FROM agency_invitations WHERE email = 'cliente@norte.com'")->fetchColumn();
        $this->assertSame(hash('sha256', $token), $hash, 'Solo se guarda el hash del token');
        $this->assertSame('Estudio Norte', $invitations->findValid($token)['agency_name']);

        $user = $this->accounts()->acceptInvitation($token, 'Cliente Norte', 'secreto123');

        $this->assertSame(['agency_id' => 2, 'role' => 'cliente'], ['agency_id' => $user['agency_id'], 'role' => $user['role']]);
        $this->assertNull($invitations->findValid($token), 'El enlace sirve una sola vez');
        $this->assertSame([], $this->visibleIds(['id' => $user['id'], 'role' => 'cliente']), 'El cliente solo verá los proyectos que le asignen');

        $this->expectException(DomainException::class);
        $this->accounts()->acceptInvitation($token, 'Otra vez', 'secreto123');
    }

    public function testExpiredOrRevokedInvitationIsInvalid(): void
    {
        $invitations = new InvitationRepository(static::$db);

        $expired = $invitations->create(1, 'caducada@demo.com', 'colaborador', 1);
        static::$db->exec("UPDATE agency_invitations SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE email = 'caducada@demo.com'");
        $this->assertNull($invitations->findValid($expired));

        $revoked = $invitations->create(1, 'revocada@demo.com', 'colaborador', 1);
        $id = (int)static::$db->query("SELECT id FROM agency_invitations WHERE email = 'revocada@demo.com'")->fetchColumn();
        $this->assertFalse($invitations->revoke($id, 2), 'Otra agencia no puede revocarla');
        $this->assertTrue($invitations->revoke($id, 1));
        $this->assertNull($invitations->findValid($revoked));

        $this->assertNull($invitations->findValid('no-es-un-token'));
    }
}

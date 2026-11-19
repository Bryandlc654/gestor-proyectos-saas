<?php

namespace Tests\Integration;

use App\Controller\TaskController;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ActivityRepository;
use App\Repository\AgencyRepository;
use App\Repository\ConnectionRepository;
use App\Repository\NotificationRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectShareRepository;
use App\Repository\ProjectTemplateRepository;
use App\Repository\TaskRepository;
use App\Service\ExportService;
use App\Service\NotificationService;
use App\Service\PasswordResetService;
use App\Service\ProfileService;
use App\Service\ReminderService;
use App\Service\TwoFactorService;
use App\Support\Mailer;
use App\Support\Totp;

final class SecurityAndArchiveTest extends DatabaseTestCase
{
    private function user(int $agencyId, string $role = 'colaborador', string $email = 'temporal@example.test'): int
    {
        $stmt = static::$db->prepare("
            INSERT INTO users (agency_id, name, email, role, password)
            VALUES (?, 'Usuario Temporal', ?, ?, ?)
        ");
        $stmt->execute([$agencyId, $email, $role, password_hash('secreto123', PASSWORD_DEFAULT)]);
        return (int)static::$db->lastInsertId();
    }

    protected function tearDown(): void
    {
        static::$db?->exec("DELETE FROM users WHERE email LIKE 'temporal%' OR email = 'cliente.invitado@example.test'");
        static::$db?->exec("DELETE FROM projects WHERE name LIKE 'Duplicado%' OR name LIKE 'Desde plantilla%'");
        static::$db?->exec("DELETE FROM project_templates");
        static::$db?->exec("DELETE FROM tasks WHERE title LIKE 'Recordatorio%'");
        static::$db?->exec("DELETE FROM invoices WHERE invoice_number LIKE 'REM-%'");
        static::$db?->exec("UPDATE projects SET archived_at = NULL, archived_by = NULL");
        static::$db?->exec("DELETE FROM project_shares");
        static::$db?->exec("DELETE FROM agency_connections");
    }

    public function testAgencyPublicSlugsAreUniqueAndResolvable(): void
    {
        $agencies = new AgencyRepository(static::$db);
        $first = $agencies->create('Agencia Enlace');
        $second = $agencies->create('Agencia Enlace');

        $this->assertSame('agencia-enlace', $agencies->find($first)['slug']);
        $this->assertSame($first, $agencies->findBySlug('agencia-enlace')['id']);
        $this->assertSame('agencia-enlace-2', $agencies->find($second)['slug']);

        $this->expectException(\DomainException::class);
        $agencies->setSlug($first, 'agencia-enlace-2');
    }

    public function testGuestClientAlwaysHasReadOnlyAccess(): void
    {
        $connections = new ConnectionRepository(static::$db, new AgencyRepository(static::$db));
        $shares = new ProjectShareRepository(static::$db, $connections);
        $connections->request(1, 'NRTE-6789', self::ADMIN['id']);
        $connections->accept((int)$connections->forAgency(2)['incoming'][0]['id'], 2);
        $shareId = $shares->share(1, 1, 2, 'collaborate', self::ADMIN['id']);

        $clientId = $this->user(2, 'cliente', 'cliente.invitado@example.test');
        $shares->setMembers($shareId, 2, [$clientId]);

        $repo = new ProjectRepository(static::$db);
        $this->assertTrue($repo->canAccess(1, $clientId, 'cliente'));
        $this->assertSame('collaborate', $repo->accessContext(1, $clientId, 'cliente')['level']);
        $this->assertFalse($repo->canEditTasks(1, $clientId, 'cliente'));
        $this->assertFalse($repo->canChangeTaskStatus(1, $clientId, 'cliente'));
        $this->assertFalse($repo->canComment(1, $clientId, 'cliente'));
        $this->assertFalse($repo->canUploadAttachments(1, $clientId, 'cliente'));
        $this->assertFalse($repo->canManageChecklists(1, $clientId, 'cliente'));
        $this->assertFalse($repo->canCreateTickets(1, $clientId, 'cliente'));
    }

    public function testProjectsCanBeArchivedAndRestored(): void
    {
        $repo = new ProjectRepository(static::$db);

        $this->assertTrue($repo->archive(1, self::ADMIN['id']));
        $this->assertFalse($repo->canAccess(1, self::ADMIN['id'], 'admin'));
        $this->assertNotEmpty($repo->archivedForAgency(1));
        $this->assertNotNull($repo->archivedProject(1, 1));

        $this->assertTrue($repo->restore(1, self::ADMIN['id']));
        $this->assertTrue($repo->canAccess(1, self::ADMIN['id'], 'admin'));
        $this->assertNull($repo->archivedProject(1, 1));
    }

    public function testTaskActivityAndAssignmentNotification(): void
    {
        $router = new Router();
        $notifications = new NotificationService(
            new NotificationRepository(static::$db),
            new Mailer(false, 'no-reply@test.local', sys_get_temp_dir() . '/vertice-mail-test.log')
        );
        (new TaskController(
            self::ADMIN,
            new ProjectRepository(static::$db),
            new TaskRepository(static::$db),
            new ActivityRepository(static::$db),
            $notifications
        ))->register($router);

        [$status, $response] = $router->handle(new Request('POST', [], [
            'action' => 'create',
            'project_id' => 1,
            'title' => 'Tarea auditada',
            'assigned_user_id' => self::COLABORADOR['id'],
        ]));

        $this->assertSame(200, $status);
        $activity = static::$db->query("SELECT action FROM activity_log WHERE project_id = 1 AND action = 'task.created'")->fetchColumn();
        $this->assertSame('task.created', $activity);

        $notification = static::$db->query("SELECT title FROM notifications WHERE user_id = " . self::COLABORADOR['id'] . " ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertSame('Nueva tarea asignada', $notification);

        static::$db->exec("DELETE FROM tasks WHERE title = 'Tarea auditada'");
    }

    public function testPasswordResetTokenChangesPasswordOnce(): void
    {
        $userId = $this->user(1, 'colaborador', 'temporal.reset@example.test');
        $token = bin2hex(random_bytes(32));
        static::$db->prepare("
            INSERT INTO password_resets (user_id, token_hash, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
        ")->execute([$userId, hash('sha256', $token)]);

        $service = new PasswordResetService(
            static::$db,
            new Mailer(false, 'no-reply@test.local', sys_get_temp_dir() . '/vertice-mail-test.log'),
            new ActivityRepository(static::$db)
        );

        $this->assertNotNull($service->findValid($token));
        $service->reset($token, 'nuevaClave123', 'nuevaClave123');

        $hash = static::$db->query("SELECT password FROM users WHERE id = {$userId}")->fetchColumn();
        $this->assertTrue(password_verify('nuevaClave123', $hash));
        $this->assertGreaterThan(0, (int)static::$db->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn());
        $this->assertNull($service->findValid($token), 'El enlace no se puede reutilizar');
    }

    public function testTwoFactorSetupLoginAndRecoveryCode(): void
    {
        $userId = $this->user(1, 'colaborador', 'temporal.2fa@example.test');
        $service = new TwoFactorService(static::$db);

        $setup = $service->startSetup($userId);
        $codes = $service->enable($userId, $setup['secret'], Totp::code($setup['secret']));
        $this->assertCount(8, $codes);
        $this->assertTrue($service->isEnabled($userId));

        $this->assertSame('totp', $service->verifyLogin($userId, Totp::code($setup['secret'])));
        $this->assertSame('recovery', $service->verifyLogin($userId, $codes[0]));
        $this->assertNull($service->verifyLogin($userId, $codes[0]), 'El código de recuperación es de un solo uso');

        $newVersion = $service->disable($userId, 'secreto123');
        $this->assertFalse($service->isEnabled($userId));
        $this->assertGreaterThan(0, $newVersion, 'Desactivar 2FA invalida las sesiones');
    }

    public function testTaskManualOrderCanBePersisted(): void
    {
        $tasks = new TaskRepository(static::$db);
        $first = (int)static::$db->query("SELECT id FROM tasks WHERE project_id = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $second = (int)static::$db->query("SELECT id FROM tasks WHERE project_id = 1 ORDER BY id LIMIT 1 OFFSET 1")->fetchColumn();

        $tasks->reorder(1, $first, 'en_progreso', [$first, $second]);
        $rows = static::$db->query("SELECT id, status, sort_order FROM tasks WHERE id IN ({$first}, {$second}) ORDER BY sort_order")->fetchAll();
        $this->assertSame($first, (int)$rows[0]['id']);
        $this->assertSame('en_progreso', $rows[0]['status']);
    }

    public function testProjectTemplatesAndDuplicationCopyTasksAndChecklists(): void
    {
        $repo = new ProjectTemplateRepository(static::$db);
        $templateId = $repo->saveFromProject(1, 1, self::ADMIN['id'], 'Plantilla QA');
        $this->assertGreaterThan(0, $templateId);
        $this->assertNotEmpty($repo->forAgency(1));

        $newId = $repo->createFromTemplate($templateId, 1, self::ADMIN['id'], 'Desde plantilla QA', null);
        $this->assertGreaterThan(0, (int)static::$db->query("SELECT COUNT(*) FROM tasks WHERE project_id = {$newId}")->fetchColumn());
        $this->assertGreaterThan(0, (int)static::$db->query("SELECT COUNT(*) FROM checklists WHERE project_id = {$newId}")->fetchColumn());
        $this->assertSame('pendiente', static::$db->query("SELECT status FROM tasks WHERE project_id = {$newId} LIMIT 1")->fetchColumn());

        $duplicateId = $repo->duplicateProject(1, 1, self::ADMIN['id'], 'Duplicado QA');
        $this->assertGreaterThan(0, (int)static::$db->query("SELECT COUNT(*) FROM tasks WHERE project_id = {$duplicateId}")->fetchColumn());
    }

    public function testProfileCanBeEditedAndPasswordChanged(): void
    {
        $userId = $this->user(1, 'colaborador', 'temporal.profile@example.test');
        $service = new ProfileService(static::$db, new ActivityRepository(static::$db));

        $updated = $service->update($userId, 'Perfil Editado', 'temporal.profile2@example.test');
        $this->assertSame(['name' => 'Perfil Editado', 'email' => 'temporal.profile2@example.test'], $updated);

        $version = $service->changePassword($userId, 'secreto123', 'nuevaClave123', 'nuevaClave123');
        $this->assertGreaterThan(0, $version);
        $this->assertTrue(password_verify('nuevaClave123', static::$db->query("SELECT password FROM users WHERE id = {$userId}")->fetchColumn()));

        $service->updateAvatar($userId, 'uploads/avatars/test.png');
        $this->assertSame('uploads/avatars/test.png', static::$db->query("SELECT avatar FROM users WHERE id = {$userId}")->fetchColumn());
    }

    public function testExportDatasetsProduceCsvAndPdf(): void
    {
        $service = new ExportService(
            static::$db,
            new ProjectRepository(static::$db),
            new ActivityRepository(static::$db)
        );

        $tasks = $service->tasks(self::ADMIN['id'], 'admin', []);
        $this->assertNotEmpty($tasks['rows']);
        $this->assertStringContainsString('Tarea', $service->csv($tasks['headers'], $tasks['rows']));
        $this->assertStringStartsWith('%PDF-1.4', $service->pdf($tasks['title'], $tasks['headers'], $tasks['rows'], $tasks['widths']));

        $invoices = $service->invoices(1, self::ADMIN['id'], 'admin', []);
        $this->assertNotEmpty($invoices['rows']);

        $audit = $service->audit(1, []);
        $this->assertSame('Auditoría', $audit['title']);
    }

    public function testDueRemindersAreSentOnlyOnce(): void
    {
        static::$db->exec("
            INSERT INTO tasks (project_id, title, priority, status, assigned_user_id, due_date)
            VALUES (1, 'Recordatorio vencido', 'media', 'pendiente', 3, CURDATE())
        ");
        static::$db->exec("
            INSERT INTO invoices (project_id, invoice_number, concept, amount, currency, status, due_date)
            VALUES (1, 'REM-TEST-001', 'Recordatorio', 100, 'USD', 'pendiente', CURDATE())
        ");

        $service = new ReminderService(
            static::$db,
            new NotificationService(
                new NotificationRepository(static::$db),
                new Mailer(false, 'no-reply@test.local', sys_get_temp_dir() . '/vertice-mail-test.log')
            ),
            new ActivityRepository(static::$db)
        );

        $first = $service->run();
        $this->assertGreaterThanOrEqual(1, $first['tasks']);
        $this->assertGreaterThanOrEqual(1, $first['invoices']);
        $this->assertNotNull(static::$db->query("SELECT reminder_sent_at FROM tasks WHERE title = 'Recordatorio vencido'")->fetchColumn());
        $this->assertNotNull(static::$db->query("SELECT reminder_sent_at FROM invoices WHERE invoice_number = 'REM-TEST-001'")->fetchColumn());

        $second = $service->run();
        $this->assertSame(0, $second['tasks']);
        $this->assertSame(0, $second['invoices']);
    }
}

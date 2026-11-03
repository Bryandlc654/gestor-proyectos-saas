<?php

namespace Tests\Integration;

use App\Database\MigrationRunner;
use App\Repository\ProjectRepository;

final class MigrationsAndAccessTest extends DatabaseTestCase
{
    public function testAllMigrationsAppliedAndRerunIsNoop(): void
    {
        $runner = new MigrationRunner(static::$db, dirname(__DIR__, 2) . '/database/migrations');

        $this->assertNotEmpty($runner->status());
        $this->assertNotContains(false, $runner->status(), 'Todas las migraciones deben estar aplicadas');
        $this->assertSame([], $runner->migrate());
    }

    public function testOwnSchemaHasNoConflicts(): void
    {
        $runner = new MigrationRunner(static::$db, dirname(__DIR__, 2) . '/database/migrations');
        $this->assertSame([], $runner->conflictingTables());
    }

    public function testDetectsTablesFromAnotherApplication(): void
    {
        // BD compartida con otra app (p. ej. Prisma): mismos nombres, ids VARCHAR y otras columnas
        $name = getenv('TEST_DB_NAME') . '_foreign';
        static::$db->exec("DROP DATABASE IF EXISTS `{$name}`");
        static::$db->exec("CREATE DATABASE `{$name}`");
        try {
            static::$db->exec("CREATE TABLE `{$name}`.users (id VARCHAR(191) PRIMARY KEY, email VARCHAR(191), passwordHash VARCHAR(191))");
            static::$db->exec("CREATE TABLE `{$name}`.projects (id VARCHAR(191) PRIMARY KEY, name VARCHAR(191), user_id VARCHAR(191))");
            static::$db->exec("USE `{$name}`");

            $conflicts = (new MigrationRunner(static::$db, dirname(__DIR__, 2) . '/database/migrations'))->conflictingTables();

            $this->assertSame(['users', 'projects'], array_keys($conflicts));
            $this->assertStringContainsString('password', $conflicts['users']);
            $this->assertStringContainsString('varchar', $conflicts['projects']);
        } finally {
            static::$db->exec("USE `" . getenv('TEST_DB_NAME') . "`");
            static::$db->exec("DROP DATABASE IF EXISTS `{$name}`");
        }
    }

    public function testSchemaMatchesWhatTheCodeUses(): void
    {
        $columns = static::$db->query("SHOW COLUMNS FROM tasks")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('assigned_user_id', $columns);

        $columns = static::$db->query("SHOW COLUMNS FROM comments")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('parent_id', $columns);

        foreach (['ticket_replies', 'app_settings', 'login_attempts', 'schema_migrations'] as $table) {
            $this->assertNotFalse(static::$db->query("SHOW TABLES LIKE '{$table}'")->fetchColumn(), $table);
        }
    }

    public function testInvoiceNumberIsUniquePerProject(): void
    {
        $this->expectException(\PDOException::class);
        static::$db->exec("INSERT INTO invoices (project_id, invoice_number, concept, amount) VALUES (1, 'INV-2026-001', 'Duplicada', 1)");
    }

    public function testProjectAccessByRole(): void
    {
        $repo = new ProjectRepository(static::$db);

        $this->assertTrue($repo->canAccess(1, 1, 'admin'));
        $this->assertTrue($repo->canAccess(2, 1, 'admin'), 'El admin accede a todo lo de su agencia');
        $this->assertFalse($repo->canAccess(999, 1, 'admin'));
        $this->assertTrue($repo->canAccess(1, 3, 'colaborador'));
        $this->assertTrue($repo->canAccess(1, 4, 'cliente'));
        $this->assertFalse($repo->canAccess(2, 4, 'cliente'), 'El cliente solo ve proyectos vinculados');
    }

    public function testOnlyAdminOrProjectManagersCanManage(): void
    {
        $repo = new ProjectRepository(static::$db);

        $this->assertTrue($repo->canManage(1, 2, 'project_manager'));
        $this->assertFalse($repo->canManage(1, 3, 'colaborador'));
        $this->assertFalse($repo->canManage(1, 4, 'cliente'));
    }

    public function testAssigneeMustBeProjectMember(): void
    {
        $repo = new ProjectRepository(static::$db);

        $this->assertSame('Ana Colaboradora', $repo->assigneeName(3, 1));
        $this->assertNull($repo->assigneeName(4, 2), 'El cliente no es miembro del proyecto 2');
        $this->assertNull($repo->assigneeName(999, 1));
    }

    public function testBelongsToChecks(): void
    {
        $repo = new ProjectRepository(static::$db);

        $this->assertTrue($repo->taskBelongsTo(1, 1));
        $this->assertFalse($repo->taskBelongsTo(1, 2));
        $this->assertTrue($repo->folderBelongsTo(1, 1));
        $this->assertFalse($repo->folderBelongsTo(1, 2));
    }
}

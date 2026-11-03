<?php

namespace Tests\Integration;

use App\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base para tests con base de datos real. La BD indicada en TEST_DB_NAME se BORRA y se recrea
 * aplicando todas las migraciones y los datos demo. Si TEST_DB_NAME está vacío, los tests se omiten.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?PDO $db = null;

    public static function setUpBeforeClass(): void
    {
        $name = getenv('TEST_DB_NAME') ?: '';
        if ($name === '') {
            return;
        }
        if (!preg_match('/^[A-Za-z0-9_]*test[A-Za-z0-9_]*$/i', $name)) {
            throw new \RuntimeException("TEST_DB_NAME debe contener 'test' para evitar borrar una base real (recibido: {$name}).");
        }

        $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', getenv('TEST_DB_HOST'), getenv('TEST_DB_PORT'));
        $server = new PDO($dsn, getenv('TEST_DB_USER'), getenv('TEST_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
        $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        static::$db = new PDO($dsn . ";dbname={$name}", getenv('TEST_DB_USER'), getenv('TEST_DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $root = dirname(__DIR__, 2);
        $runner = new MigrationRunner(static::$db, $root . '/database/migrations');
        $runner->migrate();
        $runner->runFile($root . '/database/seeds/demo.sql');
    }

    protected function setUp(): void
    {
        if (static::$db === null) {
            $this->markTestSkipped('Define TEST_DB_NAME (una base de pruebas desechable) para ejecutar los tests de integración.');
        }
    }

    // Usuarios de los datos demo
    protected const ADMIN = ['id' => 1, 'role' => 'admin'];
    protected const PM = ['id' => 2, 'role' => 'project_manager'];
    protected const COLABORADOR = ['id' => 3, 'role' => 'colaborador'];
    protected const CLIENTE = ['id' => 4, 'role' => 'cliente'];
}

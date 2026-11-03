<?php

namespace Tests\Unit;

use App\Support\ErrorHandler;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerTest extends TestCase
{
    private function pdoError(string $sqlState, string $message): PDOException
    {
        $e = new PDOException("SQLSTATE[{$sqlState}]: {$message}");
        $e->errorInfo = [$sqlState, 1146, $message];
        return $e;
    }

    public function testMissingTableOrColumnIsSchemaError(): void
    {
        $this->assertTrue(ErrorHandler::isSchemaError($this->pdoError('42S02', "Table 'x.tickets' doesn't exist")));
        $this->assertTrue(ErrorHandler::isSchemaError($this->pdoError('42S22', "Unknown column 'parent_id'")));
        $this->assertFalse(ErrorHandler::isSchemaError($this->pdoError('23000', 'Duplicate entry')));
        $this->assertFalse(ErrorHandler::isSchemaError(new \RuntimeException('otra cosa')));
    }

    public function testSchemaErrorPointsToMigrations(): void
    {
        $info = ErrorHandler::describe($this->pdoError('42S02', "Table 'x.tickets' doesn't exist"), true);

        $this->assertSame('La base de datos no está actualizada', $info['title']);
        $this->assertStringContainsString('php database/migrate.php', $info['message']);
        $this->assertStringNotContainsString('tickets', $info['message'], 'No expone el SQL al usuario');
    }

    public function testProductionHidesDetails(): void
    {
        $e = new \RuntimeException('Fallo en C:\\ruta\\secreta.php');

        $this->assertStringNotContainsString('secreta', ErrorHandler::describe($e, true)['message']);
        $this->assertStringContainsString('secreta', ErrorHandler::describe($e, false)['message']);
    }

    public function testRenderPageEscapesContent(): void
    {
        ob_start();
        ErrorHandler::renderPage('<b>Título</b>', 'Mensaje & más', '<script>x</script>');
        $html = ob_get_clean();

        $this->assertStringContainsString('&lt;b&gt;Título&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>x', $html);
    }
}

<?php

namespace App\Support;

use PDOException;
use Throwable;

/**
 * Manejo central de excepciones no capturadas: registra el detalle en el log y muestra una
 * página (o JSON en la API) sin rutas ni SQL en producción. Si falta una tabla o columna,
 * indica que hay que aplicar las migraciones en lugar de un "Fatal error".
 */
final class ErrorHandler
{
    public static function register(bool $production): void
    {
        if (PHP_SAPI === 'cli') {
            return; // en consola (migraciones, tests) se deja el comportamiento por defecto
        }
        set_exception_handler(static fn(Throwable $e) => self::handle($e, $production));
    }

    // Tabla (42S02) o columna (42S22) inexistente: el esquema no está al día
    public static function isSchemaError(Throwable $e): bool
    {
        return $e instanceof PDOException && in_array(self::sqlState($e), ['42S02', '42S22'], true);
    }

    /** @return array{title: string, message: string} */
    public static function describe(Throwable $e, bool $production): array
    {
        if (self::isSchemaError($e)) {
            return [
                'title' => 'La base de datos no está actualizada',
                'message' => 'Faltan tablas o columnas que la aplicación necesita. Ejecuta php database/migrate.php '
                    . 'con la configuración (.env) de esta instalación y recarga la página.',
            ];
        }
        return [
            'title' => 'Algo salió mal',
            'message' => $production
                ? 'Se produjo un error inesperado. Inténtalo de nuevo en unos minutos.'
                : get_class($e) . ': ' . $e->getMessage(),
        ];
    }

    public static function handle(Throwable $e, bool $production): void
    {
        error_log(sprintf('[%s] %s en %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header_remove('Content-Encoding');
        }

        ['title' => $title, 'message' => $message] = self::describe($e, $production);

        if (self::wantsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false, 'message' => $title . '. ' . $message], JSON_UNESCAPED_UNICODE);
            return;
        }

        $detail = $production ? null : $e->getMessage() . "\n\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
        self::renderPage($title, $message, $detail);
    }

    // Página de error mínima y autónoma: no depende de sesión, base de datos ni hojas de estilo externas
    public static function renderPage(string $title, string $message, ?string $detail = null): void
    {
        $h = [Html::class, 'e'];
        $css = 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;'
            . 'background:#f8fafc;color:#1e293b;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}'
            . '.card{max-width:32rem;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 1px 2px rgba(15,23,42,.06);padding:28px}'
            . 'h1{margin:0 0 8px;font-size:18px;font-weight:600;color:#0f172a}p{margin:0;font-size:14px;line-height:1.6;color:#475569}'
            . 'pre{margin:20px 0 0;background:#0f172a;color:#e2e8f0;padding:16px;border-radius:12px;font-size:12px;overflow-x:auto;white-space:pre-wrap}'
            . 'a{display:inline-block;margin-top:20px;font-size:13px;font-weight:600;color:#0e66ff;text-decoration:none}';
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $h($title) . '</title><style>' . $css . '</style></head>'
            . '<body><div class="card">'
            . '<h1>' . $h($title) . '</h1>'
            . '<p>' . $h($message) . '</p>'
            . ($detail !== null ? '<pre>' . $h($detail) . '</pre>' : '')
            . '<a href="/app/dashboard">Volver a la aplicación</a>'
            . '</div></body></html>';
    }

    private static function sqlState(PDOException $e): string
    {
        return (string)($e->errorInfo[0] ?? $e->getCode());
    }

    private static function wantsJson(): bool
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type: application/json') === 0) {
                return true;
            }
        }
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
    }
}

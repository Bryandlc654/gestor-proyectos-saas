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

    // Página de error mínima y autónoma (no depende de sesión ni de la base de datos)
    public static function renderPage(string $title, string $message, ?string $detail = null): void
    {
        $h = [Html::class, 'e'];
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $h($title) . '</title><link rel="stylesheet" href="assets/css/app.css"></head>'
            . '<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 font-sans text-slate-800 antialiased">'
            . '<div class="max-w-lg w-full bg-white rounded-2xl border border-slate-200/70 shadow-sm p-6 sm:p-8">'
            . '<h1 class="text-lg font-semibold text-slate-900 mb-2">' . $h($title) . '</h1>'
            . '<p class="text-sm text-slate-600 leading-relaxed">' . $h($message) . '</p>'
            . ($detail !== null
                ? '<pre class="mt-5 bg-slate-900 text-slate-200 p-4 rounded-xl text-xs overflow-x-auto whitespace-pre-wrap">' . $h($detail) . '</pre>'
                : '')
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

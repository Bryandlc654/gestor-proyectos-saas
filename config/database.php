<?php
// config/database.php - Configuración y conexión segura a MySQL mediante PDO

require_once __DIR__ . '/env.php';

use App\Support\ErrorHandler;

// Excepciones no capturadas: se registran en el log y se muestra una página sin detalles internos en producción
ErrorHandler::register(isProduction());

define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'gestor_proyectos'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Misma collation que las tablas (ver migraciones). Sin esto la conexión usa la del servidor
            // (p. ej. utf8mb4_general_ci en algunos hostings) y MySQL rechaza comparar textos de ambas
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, 'No se pudo conectar a ' . DB_NAME . ' en ' . DB_HOST . ': ' . $e->getMessage() . "\n");
                exit(1);
            }

            error_log('Error de conexión a MySQL: ' . $e->getMessage());
            http_response_code(500);

            // En producción no se exponen detalles de la conexión
            ErrorHandler::renderPage(
                'No se pudo conectar a la base de datos',
                isProduction()
                    ? 'Servicio no disponible temporalmente. Inténtalo de nuevo más tarde.'
                    : 'Comprueba que MySQL esté encendido y que los datos de conexión de .env sean correctos (BD: ' . DB_NAME . ' en ' . DB_HOST . ').',
                isProduction() ? null : $e->getMessage()
            );
            exit;
        }
    }

    return $pdo;
}

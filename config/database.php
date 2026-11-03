<?php
// config/database.php - Configuración y conexión segura a MySQL mediante PDO

require_once __DIR__ . '/env.php';

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
        } catch (PDOException $e) {
            error_log('Error de conexión a MySQL: ' . $e->getMessage());
            http_response_code(500);

            // En producción no se exponen detalles de la conexión
            if (isProduction()) {
                exit('Servicio no disponible temporalmente. Inténtalo de nuevo más tarde.');
            }

            // Interfaz visual de error amigable si la BD aún no ha sido importada (solo desarrollo)
            ?>
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Error de Conexión a Base de Datos</title>
                <link rel="stylesheet" href="assets/css/app.css">
            </head>
            <body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 font-sans text-slate-800">
                <div class="max-w-lg w-full bg-white rounded-2xl shadow-xl border border-red-100 p-8 text-center">
                    <div class="w-16 h-16 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <h2 class="text-2xl font-bold text-slate-900 mb-2">No se pudo conectar a MySQL</h2>
                    <p class="text-sm text-slate-600 mb-6">Asegúrate de que tu servidor MySQL esté encendido (XAMPP / Laragon / Docker) y que hayas ejecutado <code class="bg-slate-100 px-2 py-0.5 rounded text-indigo-600 font-mono text-xs">php database/migrate.php</code>.</p>
                    
                    <div class="bg-slate-900 text-slate-200 text-left p-4 rounded-xl text-xs font-mono overflow-x-auto mb-6">
                        <p class="text-amber-400 font-semibold mb-1">Detalle del error:</p>
                        <?= htmlspecialchars($e->getMessage()) ?>
                    </div>
                    <p class="text-xs text-slate-400">Host: <?= htmlspecialchars(DB_HOST) ?> | BD: <?= htmlspecialchars(DB_NAME) ?></p>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }

    return $pdo;
}

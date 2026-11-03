<?php
// config/database.php - Configuración y conexión segura a MySQL mediante PDO

define('DB_HOST', getenv('DB_HOST') ?: '193.203.175.226');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'u560058480_gesttareas');
define('DB_USER', getenv('DB_USER') ?: 'u560058480_gesttareas');
define('DB_PASS', getenv('DB_PASS') ?: '@:RP7Z~h');

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
            // Interfaz visual de error amigable si la BD aún no ha sido importada
            http_response_code(500);
            ?>
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Error de Conexión a Base de Datos</title>
                <script src="https://cdn.tailwindcss.com"></script>
            </head>
            <body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 font-sans text-slate-800">
                <div class="max-w-lg w-full bg-white rounded-2xl shadow-xl border border-red-100 p-8 text-center">
                    <div class="w-16 h-16 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <h2 class="text-2xl font-bold text-slate-900 mb-2">No se pudo conectar a MySQL</h2>
                    <p class="text-sm text-slate-600 mb-6">Asegúrate de que tu servidor MySQL esté encendido (XAMPP / Laragon / Docker) y que hayas importado el archivo <code class="bg-slate-100 px-2 py-0.5 rounded text-indigo-600 font-mono text-xs">database/schema.sql</code>.</p>
                    
                    <div class="bg-slate-900 text-slate-200 text-left p-4 rounded-xl text-xs font-mono overflow-x-auto mb-6">
                        <p class="text-amber-400 font-semibold mb-1">Detalle del error:</p>
                        <?= htmlspecialchars($e->getMessage()) ?>
                    </div>
                    <p class="text-xs text-slate-400">Host: <?= DB_HOST ?> | BD: <?= DB_NAME ?> | Usuario: <?= DB_USER ?></p>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }

    return $pdo;
}

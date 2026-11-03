<?php
// database/migrate.php - Ejecutor de migraciones SQL versionadas (solo CLI)
//
// Uso:
//   php database/migrate.php            Aplica las migraciones pendientes de database/migrations/
//   php database/migrate.php --status   Muestra qué migraciones están aplicadas y cuáles pendientes
//   php database/migrate.php --seed     Carga los datos de demostración (database/seeds/demo.sql)
//
// La lógica vive en App\Database\MigrationRunner.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

use App\Database\MigrationRunner;

$args = array_slice($argv, 1);
$runner = new MigrationRunner(getDB(), __DIR__ . '/migrations');

if (in_array('--seed', $args, true)) {
    if (isProduction() && !in_array('--force', $args, true)) {
        fwrite(STDERR, "APP_ENV=production: los datos demo crean cuentas con contraseña conocida. Usa --force si de verdad lo necesitas.\n");
        exit(1);
    }
    $runner->runFile(__DIR__ . '/seeds/demo.sql');
    echo "Datos de demostración cargados.\n";
    exit(0);
}

if (in_array('--status', $args, true)) {
    foreach ($runner->status() as $version => $isApplied) {
        echo ($isApplied ? '[aplicada]  ' : '[pendiente] ') . $version . "\n";
    }
    exit(0);
}

if (empty($runner->pending())) {
    echo "No hay migraciones pendientes.\n";
    exit(0);
}

// Antes del primer despliegue, comprobar que la BD no tenga tablas de otra aplicación con los mismos nombres
if (!in_array(MigrationRunner::BASELINE, $runner->applied(), true) && ($conflicts = $runner->conflictingTables())) {
    fwrite(STDERR, "ERROR: la base de datos " . DB_NAME . " ya contiene tablas con nombres de FlowTasks pero con otra estructura:\n");
    foreach ($conflicts as $table => $reason) {
        fwrite(STDERR, "  - {$table}: {$reason}\n");
    }
    fwrite(STDERR, "Probablemente pertenecen a otra aplicación. Usa una base de datos dedicada para FlowTasks (DB_NAME en .env).\n");
    fwrite(STDERR, "No se ha modificado nada.\n");
    exit(1);
}

try {
    $runner->migrate(fn(string $version) => print("Aplicada {$version}\n"));
    echo "Migraciones completadas.\n";
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Se detiene la ejecución. Las migraciones anteriores ya quedaron aplicadas.\n");
    exit(1);
}

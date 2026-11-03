<?php
// database/migrate.php - Ejecutor de migraciones SQL versionadas (solo CLI)
//
// Uso:
//   php database/migrate.php            Aplica las migraciones pendientes de database/migrations/
//   php database/migrate.php --status   Muestra qué migraciones están aplicadas y cuáles pendientes
//   php database/migrate.php --seed     Carga los datos de demostración (database/seeds/demo.sql)
//
// Cada archivo NNNN_nombre.sql se aplica una sola vez y queda registrado en la tabla schema_migrations.
// Nota: en MySQL las sentencias DDL (CREATE/ALTER) confirman la transacción de forma implícita,
// así que si una migración falla a mitad hay que revisar su estado antes de reintentar.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$args = array_slice($argv, 1);
$db = getDB();
$migrationsDir = __DIR__ . '/migrations';

/**
 * Divide un script SQL en sentencias, respetando cadenas entre comillas y comentarios.
 */
function splitSqlStatements(string $sql): array {
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $quote = null;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($quote !== null) {
            $current .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {
                $current .= $sql[++$i];
            } elseif ($ch === $quote) {
                $quote = null;
            }
            continue;
        }

        // Comentario de línea: -- hasta el final de la línea
        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            $current .= "\n";
            continue;
        }

        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
        }

        if ($ch === ';') {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    if (trim($current) !== '') {
        $statements[] = trim($current);
    }
    return $statements;
}

function runSqlFile(PDO $db, string $path): void {
    foreach (splitSqlStatements(file_get_contents($path)) as $statement) {
        $db->exec($statement);
    }
}

function appliedVersions(PDO $db): array {
    $exists = $db->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
    if (!$exists) {
        return [];
    }
    return $db->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
}

$files = glob($migrationsDir . '/*.sql');
sort($files, SORT_STRING);

// --seed: datos de demostración
if (in_array('--seed', $args, true)) {
    if (isProduction() && !in_array('--force', $args, true)) {
        fwrite(STDERR, "APP_ENV=production: los datos demo crean cuentas con contraseña conocida. Usa --force si de verdad lo necesitas.\n");
        exit(1);
    }
    runSqlFile($db, __DIR__ . '/seeds/demo.sql');
    echo "Datos de demostración cargados.\n";
    exit(0);
}

$applied = appliedVersions($db);

// --status: listado
if (in_array('--status', $args, true)) {
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        echo (in_array($version, $applied, true) ? '[aplicada]  ' : '[pendiente] ') . $version . "\n";
    }
    exit(0);
}

$pending = array_filter($files, fn($f) => !in_array(basename($f, '.sql'), $applied, true));
if (empty($pending)) {
    echo "No hay migraciones pendientes.\n";
    exit(0);
}

foreach ($pending as $file) {
    $version = basename($file, '.sql');
    echo "Aplicando {$version}... ";
    try {
        runSqlFile($db, $file);
        $db->prepare("INSERT IGNORE INTO schema_migrations (version) VALUES (?)")->execute([$version]);
        echo "OK\n";
    } catch (PDOException $e) {
        echo "ERROR\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        fwrite(STDERR, "Se detiene la ejecución. Las migraciones anteriores ya quedaron aplicadas.\n");
        exit(1);
    }
}

echo "Migraciones completadas.\n";

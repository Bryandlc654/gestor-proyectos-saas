<?php

namespace App\Database;

use PDO;
use PDOException;

/**
 * Aplica migraciones SQL versionadas (NNNN_nombre.sql) una sola vez y las registra en schema_migrations.
 * Nota: en MySQL las sentencias DDL confirman la transacción de forma implícita, así que una
 * migración que falle a mitad puede quedar aplicada parcialmente.
 */
final class MigrationRunner
{
    public const BASELINE = '0001_baseline';

    /**
     * Columnas mínimas que identifican cada tabla de FlowTasks (presentes en todas sus versiones).
     * Todas deben tener además un `id` INT, porque las claves foráneas apuntan a él.
     */
    private const SIGNATURE_COLUMNS = [
        'users' => ['email', 'password'],
        'projects' => ['user_id', 'name'],
        'tasks' => ['project_id', 'title'],
        'project_folders' => ['project_id'],
        'checklists' => ['project_id'],
        'tickets' => ['project_id'],
        'comments' => ['project_id'],
        'invoices' => ['project_id'],
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly string $migrationsDir,
    ) {
    }

    /** @return string[] Rutas de los archivos de migración en orden */
    public function files(): array
    {
        $files = glob(rtrim($this->migrationsDir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return string[] Versiones ya aplicadas */
    public function applied(): array
    {
        if (!$this->db->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn()) {
            return [];
        }
        return $this->db->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string, bool> versión => aplicada */
    public function status(): array
    {
        $applied = $this->applied();
        $status = [];
        foreach ($this->files() as $file) {
            $version = basename($file, '.sql');
            $status[$version] = in_array($version, $applied, true);
        }
        return $status;
    }

    /** @return string[] Rutas de las migraciones pendientes */
    public function pending(): array
    {
        $applied = $this->applied();
        return array_values(array_filter(
            $this->files(),
            fn(string $file) => !in_array(basename($file, '.sql'), $applied, true)
        ));
    }

    /**
     * Tablas que ya existen con el nombre de una tabla de FlowTasks pero con otra estructura
     * (por ejemplo, de otra aplicación que comparte la base de datos). Aplicar el baseline sobre
     * ellas fallaría a mitad, así que conviene comprobarlo antes.
     *
     * @return array<string, string> tabla => motivo
     */
    public function conflictingTables(): array
    {
        $stmt = $this->db->query(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
        );
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[strtolower($row['TABLE_NAME'])][strtolower($row['COLUMN_NAME'])] = strtolower($row['DATA_TYPE']);
        }

        $conflicts = [];
        foreach (self::SIGNATURE_COLUMNS as $table => $required) {
            if (!isset($columns[$table])) {
                continue; // no existe: el baseline la creará
            }
            $missing = array_diff($required, array_keys($columns[$table]));
            if ($missing) {
                $conflicts[$table] = 'faltan las columnas ' . implode(', ', $missing);
            } elseif (($columns[$table]['id'] ?? null) !== 'int') {
                $conflicts[$table] = 'la columna id es ' . ($columns[$table]['id'] ?? 'inexistente') . ' en lugar de INT';
            }
        }
        return $conflicts;
    }

    /**
     * Aplica las migraciones pendientes. Llama a $onApply(version) después de cada una.
     * Lanza la PDOException de la primera migración que falle.
     *
     * @return string[] Versiones aplicadas en esta ejecución
     */
    public function migrate(?callable $onApply = null): array
    {
        $done = [];
        foreach ($this->pending() as $file) {
            $version = basename($file, '.sql');
            $this->runFile($file);
            $this->db->prepare("INSERT IGNORE INTO schema_migrations (version) VALUES (?)")->execute([$version]);
            $done[] = $version;
            if ($onApply) {
                $onApply($version);
            }
        }
        return $done;
    }

    public function runFile(string $path): void
    {
        foreach (SqlSplitter::split(file_get_contents($path)) as $statement) {
            $this->execResumable($statement);
        }
    }

    /**
     * Ejecuta una sentencia tolerando lo que ya se aplicó en una ejecución anterior que se cortó a mitad
     * (columna, índice, clave foránea o tabla existentes). Un ALTER TABLE con varias cláusulas falla
     * entero si una ya existe, así que en ese caso se reintenta cláusula a cláusula.
     */
    private function execResumable(string $statement): void
    {
        try {
            $this->db->exec($statement);
            return;
        } catch (PDOException $e) {
            if (!self::isAlreadyApplied($e)) {
                throw $e;
            }
        }

        foreach (self::alterClauses($statement) as $clause) {
            try {
                $this->db->exec($clause);
            } catch (PDOException $e) {
                if (!self::isAlreadyApplied($e)) {
                    throw $e;
                }
            }
        }
    }

    // 1050 tabla, 1060 columna, 1061 índice, 1826 clave foránea (MySQL); MariaDB indica la clave foránea
    // repetida con errno 121 dentro de un error genérico
    private static function isAlreadyApplied(PDOException $e): bool
    {
        $code = (int)($e->errorInfo[1] ?? 0);
        return in_array($code, [1050, 1060, 1061, 1826], true)
            || (in_array($code, [1005, 1022], true) && str_contains($e->getMessage(), '121'));
    }

    /** @return string[] Un ALTER TABLE por cada cláusula; vacío si la sentencia no tiene varias. */
    private static function alterClauses(string $statement): array
    {
        if (!preg_match('/^\s*ALTER\s+TABLE\s+(`[^`]+`|\w+)\s+(.+)$/is', $statement, $m)) {
            return [];
        }

        $clauses = [];
        $current = '';
        $depth = 0;
        $quote = null;
        foreach (str_split($m[2]) as $ch) {
            if ($quote !== null) {
                $quote = $ch === $quote ? null : $quote;
            } elseif ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $clauses[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $clauses[] = trim($current);

        return count($clauses) > 1 ? array_map(fn(string $c) => "ALTER TABLE {$m[1]} {$c}", $clauses) : [];
    }
}

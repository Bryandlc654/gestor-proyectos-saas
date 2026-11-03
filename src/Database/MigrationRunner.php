<?php

namespace App\Database;

use PDO;

/**
 * Aplica migraciones SQL versionadas (NNNN_nombre.sql) una sola vez y las registra en schema_migrations.
 * Nota: en MySQL las sentencias DDL confirman la transacción de forma implícita, así que una
 * migración que falle a mitad puede quedar aplicada parcialmente.
 */
final class MigrationRunner
{
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
            $this->db->exec($statement);
        }
    }
}

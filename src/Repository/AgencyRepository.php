<?php

namespace App\Repository;

use PDO;

/**
 * Agencias (organizaciones cliente del SaaS) y su marca.
 */
final class AgencyRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{id: int, name: string, logo: ?string}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT id, name, logo FROM agencies WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['id' => (int)$row['id'], 'name' => $row['name'], 'logo' => $row['logo']] : null;
    }

    public function create(string $name): int
    {
        $this->db->prepare("INSERT INTO agencies (name) VALUES (?)")->execute([$name]);
        return (int)$this->db->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $this->db->prepare("UPDATE agencies SET name = ? WHERE id = ?")->execute([$name, $id]);
    }

    public function setLogo(int $id, ?string $logo): void
    {
        $this->db->prepare("UPDATE agencies SET logo = ? WHERE id = ?")->execute([$logo, $id]);
    }

    // Código de conexión: 8 caracteres sin los que se confunden al dictarlos (0/O, 1/I), como "ABCD-2345"
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Código público para que otra agencia solicite conectarse. Se genera la primera vez. */
    public function connectCode(int $id): string
    {
        $stmt = $this->db->prepare("SELECT connect_code FROM agencies WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: $this->regenerateConnectCode($id);
    }

    /** Sustituye el código (el anterior deja de servir). Las conexiones ya hechas se mantienen. */
    public function regenerateConnectCode(int $id): string
    {
        for ($attempt = 0; ; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $code = substr($code, 0, 4) . '-' . substr($code, 4);
            try {
                $this->db->prepare("UPDATE agencies SET connect_code = ? WHERE id = ?")->execute([$code, $id]);
                return $code;
            } catch (\PDOException $e) {
                if ($attempt >= 4) {
                    throw $e; // colisión repetida: el error no es un código duplicado
                }
            }
        }
    }

    /** Acepta el código con o sin guion, en minúsculas o con espacios. */
    public function findByConnectCode(string $code): ?array
    {
        $code = preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
        if (strlen($code) !== 8) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM agencies WHERE connect_code = ?");
        $stmt->execute([substr($code, 0, 4) . '-' . substr($code, 4)]);
        $id = $stmt->fetchColumn();
        return $id ? $this->find((int)$id) : null;
    }

    // Comprobación explícita para operaciones que reciben ids de otro usuario o proyecto
    public function userBelongs(int $userId, int $agencyId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE id = ? AND agency_id = ?");
        $stmt->execute([$userId, $agencyId]);
        return (bool)$stmt->fetchColumn();
    }

    public function projectBelongs(int $projectId, int $agencyId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM projects WHERE id = ? AND agency_id = ?");
        $stmt->execute([$projectId, $agencyId]);
        return (bool)$stmt->fetchColumn();
    }
}

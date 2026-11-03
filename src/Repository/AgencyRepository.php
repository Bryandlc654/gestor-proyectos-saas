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

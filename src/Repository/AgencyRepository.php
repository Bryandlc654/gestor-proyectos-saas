<?php

namespace App\Repository;

use DomainException;
use PDO;

/**
 * Agencias (organizaciones cliente del SaaS) y su marca.
 */
final class AgencyRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{id: int, name: string, logo: ?string, slug: string}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT id, name, logo, slug FROM agencies WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'logo' => $row['logo'],
            'slug' => (string)$row['slug'],
        ] : null;
    }

    /** @return array{id: int, name: string, logo: ?string, slug: string}|null */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT id FROM agencies WHERE slug = ?");
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        return $id ? $this->find((int)$id) : null;
    }

    public function create(string $name): int
    {
        $slug = $this->uniqueSlug($this->slugify($name));
        $this->db->prepare("INSERT INTO agencies (name, slug) VALUES (?, ?)")->execute([$name, $slug]);
        return (int)$this->db->lastInsertId();
    }

    public function setSlug(int $id, string $slug): string
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,58}[a-z0-9])$/', $slug)) {
            throw new DomainException('El enlace debe tener entre 3 y 60 caracteres: letras minúsculas, números y guiones.');
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw new DomainException('Ese enlace está reservado. Elige otro.');
        }

        $stmt = $this->db->prepare("SELECT 1 FROM agencies WHERE slug = ? AND id <> ?");
        $stmt->execute([$slug, $id]);
        if ($stmt->fetchColumn()) {
            throw new DomainException('Ese enlace ya está en uso por otra agencia.');
        }

        $this->db->prepare("UPDATE agencies SET slug = ? WHERE id = ?")->execute([$slug, $id]);
        return $slug;
    }

    private const RESERVED_SLUGS = [
        'admin', 'agencia', 'api', 'api-tokens', 'assets', 'calendar', 'compare', 'comparar', 'config', 'connections', 'crm', 'crm-company', 'dashboard', 'database',
        'export', 'finance', 'forgot-password', 'google-callback', 'google-login', 'includes', 'index', 'invite', 'invoices', 'login', 'logout',
        'node_modules', 'profile', 'profitability', 'project', 'quote', 'quote-form', 'quote-pdf', 'quote-view', 'quotes',
        'register', 'reports', 'reset-password', 'security', 'settings', 'src',
        'tasks', 'templates', 'tests', 'trash', 'two-factor', 'uploads', 'users', 'vendor', 'verify-email',
    ];

    private function slugify(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $base = strtolower($ascii !== false ? $ascii : $name);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?: '';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'agencia';
        }
        if (in_array($base, self::RESERVED_SLUGS, true)) {
            $base = 'agencia-' . $base;
        }
        if (strlen($base) < 3) {
            $base .= '-agencia';
        }
        return substr($base, 0, 50);
    }

    private function uniqueSlug(string $base): string
    {
        $candidate = $base;
        $suffix = 2;
        while (true) {
            $stmt = $this->db->prepare("SELECT 1 FROM agencies WHERE slug = ?");
            $stmt->execute([$candidate]);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
            $candidate = substr($base, 0, 55) . '-' . $suffix++;
        }
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

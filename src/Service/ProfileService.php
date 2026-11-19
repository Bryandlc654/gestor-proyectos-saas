<?php

namespace App\Service;

use App\Repository\ActivityRepository;
use DomainException;
use PDO;

/** Datos básicos del perfil y cambio de contraseña del usuario autenticado. */
final class ProfileService
{
    public function __construct(
        private readonly PDO $db,
        private readonly ActivityRepository $activity,
    ) {
    }

    /** @return array{name: string, email: string} */
    public function update(int $userId, string $name, string $email): array
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('Indica tu nombre (máximo 100 caracteres).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('El correo electrónico no tiene un formato válido.');
        }

        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE email = ? AND id <> ?");
        $stmt->execute([$email, $userId]);
        if ($stmt->fetchColumn()) {
            throw new DomainException('Ese correo ya está registrado por otro usuario.');
        }

        $this->db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")
            ->execute([$name, $email, $userId]);

        $this->activity->logByUser($userId, null, 'profile.updated', 'Actualizó su perfil');
        return ['name' => $name, 'email' => $email];
    }

    /** @return int Nueva versión de sesión (invalida las demás sesiones) */
    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $confirmation): int
    {
        $stmt = $this->db->prepare("SELECT email, password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($currentPassword, (string)$user['password'])) {
            throw new DomainException('La contraseña actual no es correcta.');
        }
        if (strlen($newPassword) < 8) {
            throw new DomainException('La nueva contraseña debe tener al menos 8 caracteres.');
        }
        if (!hash_equals($newPassword, $confirmation)) {
            throw new DomainException('La confirmación no coincide con la nueva contraseña.');
        }
        if (password_verify($newPassword, (string)$user['password'])) {
            throw new DomainException('La nueva contraseña debe ser diferente a la actual.');
        }

        $this->db->prepare("
            UPDATE users
            SET password = ?, session_version = session_version + 1
            WHERE id = ?
        ")->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        $this->db->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$user['email']]);

        $stmt = $this->db->prepare("SELECT session_version FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $version = (int)$stmt->fetchColumn();

        $this->activity->logByUser($userId, null, 'password.changed', 'Cambió su contraseña');
        return $version;
    }

    public function updateAvatar(int $userId, ?string $avatar): void
    {
        $this->db->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$avatar, $userId]);
        $this->activity->logByUser(
            $userId,
            null,
            $avatar === null ? 'profile.avatar_removed' : 'profile.avatar_updated',
            $avatar === null ? 'Eliminó su foto de perfil' : 'Actualizó su foto de perfil'
        );
    }
}

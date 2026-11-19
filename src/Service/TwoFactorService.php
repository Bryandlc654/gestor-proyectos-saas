<?php

namespace App\Service;

use App\Support\Totp;
use PDO;

/** Configuración y verificación del doble factor TOTP. */
final class TwoFactorService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function isEnabled(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT two_factor_enabled FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return array{secret: string, uri: string} */
    public function startSetup(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $email = (string)$stmt->fetchColumn();
        $secret = Totp::generateSecret();
        return ['secret' => $secret, 'uri' => Totp::provisioningUri($secret, $email)];
    }

    /** @return array<int, string> Códigos de recuperación en claro (solo se muestran una vez) */
    public function enable(int $userId, string $secret, string $code): array
    {
        if (!Totp::verify($secret, $code)) {
            throw new \DomainException('El código de 6 dígitos no es válido. Comprueba la hora de tu dispositivo.');
        }

        $codes = Totp::generateRecoveryCodes();
        $this->db->prepare("
            UPDATE users
            SET two_factor_secret = ?, two_factor_enabled = 1, two_factor_recovery_codes = ?
            WHERE id = ?
        ")->execute([$secret, json_encode(Totp::hashRecoveryCodes($codes)), $userId]);

        return $codes;
    }

    /** @return int Nueva versión de sesión (invalida las demás sesiones) */
    public function disable(int $userId, string $password): int
    {
        $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = (string)$stmt->fetchColumn();
        if ($hash === '' || !password_verify($password, $hash)) {
            throw new \DomainException('La contraseña no es correcta.');
        }

        $this->db->prepare("
            UPDATE users
            SET two_factor_secret = NULL, two_factor_enabled = 0, two_factor_recovery_codes = NULL,
                session_version = session_version + 1
            WHERE id = ?
        ")->execute([$userId]);

        $stmt = $this->db->prepare("SELECT session_version FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** @return string|null 'totp' o 'recovery' si el código es válido */
    public function verifyLogin(int $userId, string $code): ?string
    {
        $stmt = $this->db->prepare("
            SELECT two_factor_secret, two_factor_enabled, two_factor_recovery_codes
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !(int)$row['two_factor_enabled'] || empty($row['two_factor_secret'])) {
            return null;
        }

        if (Totp::verify((string)$row['two_factor_secret'], $code)) {
            return 'totp';
        }

        $remaining = Totp::consumeRecoveryCode($row['two_factor_recovery_codes'] ?? null, $code);
        if ($remaining === null) {
            return null;
        }

        $this->db->prepare("UPDATE users SET two_factor_recovery_codes = ? WHERE id = ?")
            ->execute([$remaining, $userId]);
        return 'recovery';
    }
}

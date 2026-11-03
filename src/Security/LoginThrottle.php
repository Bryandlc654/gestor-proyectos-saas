<?php

namespace App\Security;

use PDO;
use PDOException;

/**
 * Limitación de intentos fallidos de inicio de sesión (tabla login_attempts).
 * Si la tabla no existe, no bloquea el login (falla en abierto) y deja constancia en el log.
 */
final class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_MINUTES = 15;
    // Por IP se permite más margen (varios usuarios tras la misma red)
    public const IP_MULTIPLIER = 4;

    public function __construct(private readonly PDO $db)
    {
    }

    public function isThrottled(string $email, string $ip): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    SUM(email = ?) AS by_email,
                    SUM(ip_address = ?) AS by_ip
                FROM login_attempts
                WHERE attempted_at > (NOW() - INTERVAL " . self::WINDOW_MINUTES . " MINUTE)
                  AND (email = ? OR ip_address = ?)
            ");
            $stmt->execute([$email, $ip, $email, $ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$row['by_email'] >= self::MAX_ATTEMPTS
                || (int)$row['by_ip'] >= self::MAX_ATTEMPTS * self::IP_MULTIPLIER;
        } catch (PDOException $e) {
            error_log('login_attempts no disponible: ' . $e->getMessage());
            return false;
        }
    }

    public function recordFailure(string $email, string $ip): void
    {
        try {
            $this->db->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)")->execute([$email, $ip]);
            // Limpieza ocasional de registros antiguos
            if (random_int(1, 50) === 1) {
                $this->db->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
            }
        } catch (PDOException $e) {
            error_log('login_attempts no disponible: ' . $e->getMessage());
        }
    }

    public function clear(string $email): void
    {
        try {
            $this->db->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$email]);
        } catch (PDOException $e) {
            error_log('login_attempts no disponible: ' . $e->getMessage());
        }
    }
}

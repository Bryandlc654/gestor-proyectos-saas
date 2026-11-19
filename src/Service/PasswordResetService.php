<?php

namespace App\Service;

use App\Repository\ActivityRepository;
use App\Support\EmailTemplate;
use App\Support\Mailer;
use DomainException;
use PDO;
use Throwable;

/** Recuperación de contraseña por enlace de un solo uso (60 minutos). */
final class PasswordResetService
{
    public const TTL_MINUTES = 60;

    public function __construct(
        private readonly PDO $db,
        private readonly Mailer $mailer,
        private readonly ActivityRepository $activity,
        private readonly string $baseUrl = '',
        private readonly string $appName = 'Vértice',
    ) {
    }

    /** Solicita el enlace. No revela si el correo existe. */
    public function request(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $stmt = $this->db->prepare("SELECT id, agency_id, name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return;
        }

        try {
            $token = bin2hex(random_bytes(32));
            $this->db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);
            $this->db->prepare("
                INSERT INTO password_resets (user_id, token_hash, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . self::TTL_MINUTES . " MINUTE))
            ")->execute([(int)$user['id'], hash('sha256', $token)]);

            $link = ($this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : '') . '/reset-password?token=' . urlencode($token);

            $text = "Hola " . $user['name'] . ",\n\n"
                . "Recibimos una solicitud para restablecer tu contraseña. El enlace caduca en "
                . self::TTL_MINUTES . " minutos y solo se puede usar una vez:\n\n"
                . $link . "\n\n"
                . "Si no fuiste tú, ignora este mensaje.";

            $html = EmailTemplate::render([
                'eyebrow' => 'Seguridad',
                'title' => 'Restablece tu contraseña',
                'preheader' => 'Solicitud para crear una nueva contraseña en ' . $this->appName . '.',
                'paragraphs' => [
                    'Hola ' . $user['name'] . ',',
                    'Recibimos una solicitud para restablecer la contraseña de tu cuenta. Pulsa el botón para elegir una nueva.',
                ],
                'cta_label' => 'Crear nueva contraseña',
                'cta_url' => $link,
                'note' => 'El enlace caduca en ' . self::TTL_MINUTES . ' minutos y solo se puede usar una vez. Si no fuiste tú, ignora este mensaje: tu contraseña no cambiará.',
                'app_name' => $this->appName,
                'app_url' => $this->baseUrl,
            ]);

            $this->mailer->send($email, 'Restablece tu contraseña de ' . $this->appName, $text, $html);
            $this->activity->logByUser((int)$user['id'], null, 'password.reset_requested', 'Solicitó recuperar su contraseña');
        } catch (Throwable $e) {
            error_log('password reset: ' . $e->getMessage());
        }
    }

    /** @return array{id: int, agency_id: int, name: string, email: string}|null */
    public function findValid(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT u.id, u.agency_id, u.name, u.email
            FROM password_resets pr
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
        ");
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? [
            'id' => (int)$row['id'],
            'agency_id' => (int)$row['agency_id'],
            'name' => $row['name'],
            'email' => $row['email'],
        ] : null;
    }

    /** @return array{id: int, agency_id: int, name: string, email: string} */
    public function reset(string $token, string $password, string $passwordConfirm): array
    {
        $user = $this->findValid($token);
        if ($user === null) {
            throw new DomainException('El enlace no es válido o ya caducó. Solicita uno nuevo.');
        }
        if (strlen($password) < 8) {
            throw new DomainException('La contraseña debe tener al menos 8 caracteres.');
        }
        if (!hash_equals($password, $passwordConfirm)) {
            throw new DomainException('Las contraseñas no coinciden.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE users SET password = ?, session_version = session_version + 1 WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            $this->db->prepare("
                UPDATE password_resets SET used_at = NOW() WHERE token_hash = ?
            ")->execute([hash('sha256', $token)]);
            $this->db->prepare("DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL")
                ->execute([$user['id']]);
            $this->db->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$user['email']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->activity->logByUser($user['id'], null, 'password.reset', 'Restableció su contraseña');
        return $user;
    }
}

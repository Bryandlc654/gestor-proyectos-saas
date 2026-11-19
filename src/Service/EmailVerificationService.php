<?php

namespace App\Service;

use App\Repository\ActivityRepository;
use App\Support\EmailTemplate;
use App\Support\Mailer;
use PDO;
use Throwable;

/**
 * Verificación del correo electrónico de una cuenta.
 * Envía un enlace de un solo uso (48 h) y marca la cuenta como verificada al abrirlo.
 * Solo se guarda el hash del token.
 */
final class EmailVerificationService
{
    public const TTL_HOURS = 48;

    public function __construct(
        private readonly PDO $db,
        private readonly Mailer $mailer,
        private readonly string $baseUrl = '',
        private readonly ?ActivityRepository $activity = null,
        private readonly string $appName = 'Vértice',
    ) {
    }

    /** Genera un token nuevo y envía el correo de verificación. Devuelve true si el envío salió bien. */
    public function send(int $userId, string $email, string $name): bool
    {
        try {
            $token = bin2hex(random_bytes(32));

            $this->db->prepare("DELETE FROM email_verifications WHERE user_id = ?")->execute([$userId]);
            $this->db->prepare("
                INSERT INTO email_verifications (user_id, token_hash, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . self::TTL_HOURS . " HOUR))
            ")->execute([$userId, hash('sha256', $token)]);

            $link = rtrim($this->baseUrl, '/') . '/verify-email?token=' . urlencode($token);
            $greeting = $name !== '' ? 'Hola ' . $name . ',' : 'Hola,';

            $text = $greeting . "\n\n"
                . "¡Bienvenido a Vértice! Solo falta confirmar que este correo es tuyo.\n\n"
                . "Abre este enlace para verificar tu cuenta (caduca en " . self::TTL_HOURS . " horas):\n\n"
                . $link . "\n\n"
                . "Si no creaste esta cuenta, ignora este mensaje y no se activará.\n\n"
                . "— El equipo de Vértice";

            $html = EmailTemplate::render([
                'eyebrow' => 'Verificación de cuenta',
                'title' => 'Confirma tu correo electrónico',
                'preheader' => 'Solo falta un paso para activar tu cuenta en ' . $this->appName . '.',
                'paragraphs' => [
                    $greeting,
                    '¡Bienvenido a ' . $this->appName . '! Solo falta confirmar que este correo es tuyo para activar tu cuenta.',
                ],
                'cta_label' => 'Verificar mi correo',
                'cta_url' => $link,
                'note' => 'El enlace caduca en ' . self::TTL_HOURS . ' horas y solo se puede usar una vez. Si no creaste esta cuenta, ignora este mensaje.',
                'app_name' => $this->appName,
                'app_url' => $this->baseUrl,
            ]);

            $sent = $this->mailer->send($email, 'Verifica tu correo para activar tu cuenta', $text, $html);

            $this->log($userId, 'account.verification_sent', 'Se envió el correo de verificación');
            return $sent;
        } catch (Throwable $e) {
            error_log('verificación de correo: ' . $e->getMessage());
            return false;
        }
    }

    /** Reenvía el enlace a partir del correo. No revela si la cuenta existe o ya estaba verificada. */
    public function resend(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT id, name, email, email_verified_at FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !empty($user['email_verified_at'])) {
            return false;
        }

        return $this->send((int)$user['id'], $user['email'], (string)$user['name']);
    }

    /**
     * Busca el usuario de un token válido (sin usar y sin caducar).
     *
     * @return array{id: int, agency_id: int, name: string, email: string}|null
     */
    public function findValid(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT u.id, u.agency_id, u.name, u.email
            FROM email_verifications ev
            INNER JOIN users u ON u.id = ev.user_id
            WHERE ev.token_hash = ? AND ev.used_at IS NULL AND ev.expires_at > NOW()
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

    /**
     * Marca el correo como verificado consumiendo el token.
     *
     * @return array{id: int, agency_id: int, name: string, email: string}|null
     */
    public function verify(string $token): ?array
    {
        $user = $this->findValid($token);
        if ($user === null) {
            return null;
        }

        try {
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?")->execute([$user['id']]);
            $this->db->prepare("UPDATE email_verifications SET used_at = NOW() WHERE token_hash = ?")
                ->execute([hash('sha256', $token)]);
            $this->db->prepare("DELETE FROM email_verifications WHERE user_id = ? AND used_at IS NULL")
                ->execute([$user['id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('verificación de correo: ' . $e->getMessage());
            return null;
        }

        $this->log($user['id'], 'account.email_verified', 'Verificó su correo electrónico');
        return $user;
    }

    public function isVerified(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT email_verified_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null && $value !== '';
    }

    private function log(int $userId, string $action, string $description): void
    {
        $this->activity?->logByUser($userId, null, $action, $description);
    }
}

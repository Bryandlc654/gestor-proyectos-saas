<?php

namespace App\Service;

use App\Repository\NotificationRepository;
use App\Support\EmailTemplate;
use App\Support\Mailer;
use Throwable;

/**
 * Notificaciones internas y por correo. Nunca interrumpe la acción principal:
 * si el correo o la notificación fallan, solo se registra en el log.
 */
final class NotificationService
{
    private const ROLE_LABELS = [
        'admin' => 'administrador',
        'project_manager' => 'project manager',
        'colaborador' => 'colaborador',
        'cliente' => 'cliente',
    ];

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly Mailer $mailer,
        private readonly string $baseUrl = '',
        private readonly string $appName = 'Vértice',
    ) {
    }

    public function notifyUser(int $userId, int $agencyId, string $type, string $title, string $message, ?string $url, bool $email = false): void
    {
        try {
            $this->notifications->create($agencyId, $userId, $type, $title, $message, $url);
            if ($email) {
                $user = $this->notifications->user($userId);
                if ($user !== null) {
                    [$text, $html] = $this->emailBody($title, $message, $url);
                    $this->mailer->send($user['email'], $title, $text, $html);
                }
            }
        } catch (Throwable $e) {
            error_log('notifications: ' . $e->getMessage());
        }
    }

    public function notifyAgencyAdmins(int $agencyId, string $type, string $title, string $message, ?string $url, bool $email = true, ?int $excludeUserId = null): void
    {
        try {
            $allAdmins = $this->notifications->adminsOf($agencyId);
            $admins = $allAdmins;

            if ($excludeUserId !== null) {
                $admins = array_values(array_filter(
                    $admins,
                    static fn(array $admin): bool => (int)$admin['id'] !== $excludeUserId
                ));

                // Si el actor es el único administrador, se le envía copia para que quede constancia
                if ($admins === [] && $allAdmins !== []) {
                    $admins = array_values(array_filter(
                        $allAdmins,
                        static fn(array $admin): bool => (int)$admin['id'] === $excludeUserId
                    ));
                }
            }

            if ($admins === []) {
                return;
            }

            // Una sola inserción para todos los administradores (antes: 1 INSERT + 1 SELECT por admin)
            $this->notifications->createMany($agencyId, array_column($admins, 'id'), $type, $title, $message, $url);

            if ($email) {
                [$text, $html] = $this->emailBody($title, $message, $url);
                foreach ($admins as $admin) {
                    $this->mailer->send($admin['email'], $title, $text, $html);
                }
            }
        } catch (Throwable $e) {
            error_log('notifications: ' . $e->getMessage());
        }
    }

    public function notifyTaskAssignment(int $assigneeId, string $taskTitle, int $projectId, string $projectName): void
    {
        $assignee = $this->notifications->user($assigneeId);
        if ($assignee === null) {
            return;
        }
        $this->notifyUser(
            $assigneeId,
            $assignee['agency_id'],
            'task.assigned',
            'Nueva tarea asignada',
            'Te asignaron la tarea "' . $taskTitle . '" en ' . $projectName . '.',
            '/project/' . $projectId,
            true
        );
    }

    public function notifyMention(int $userId, int $agencyId, string $authorName, string $projectName, int $projectId, ?string $taskTitle = null): void
    {
        $extra = $taskTitle !== null ? ' en la tarea "' . $taskTitle . '"' : '';
        $this->notifyUser(
            $userId,
            $agencyId,
            'comment.mention',
            'Te mencionaron en un comentario',
            $authorName . ' te mencionó en "' . $projectName . '"' . $extra . '.',
            '/project/' . $projectId . '#comments',
            true
        );
    }

    public function sendInvitation(string $email, string $agencyName, string $role, string $token): void
    {
        $link = $this->absoluteUrl('invite?token=' . urlencode($token));
        $roleLabel = self::ROLE_LABELS[$role] ?? $role;

        $text = "Hola,\n\n"
            . $agencyName . " te invitó a unirte como " . $roleLabel . ".\n\n"
            . "Crea tu cuenta con este enlace (válido 7 días):\n" . $link . "\n\n"
            . "Si no esperabas esta invitación, ignora este mensaje.";

        $html = EmailTemplate::render([
            'eyebrow' => 'Invitación',
            'title' => 'Te invitaron a ' . $agencyName,
            'preheader' => $agencyName . ' te invita a unirte a su espacio de trabajo en ' . $this->appName . '.',
            'paragraphs' => [
                'Hola,',
                $agencyName . ' te invitó a unirte a su espacio de trabajo en ' . $this->appName . ' con el rol de ' . $roleLabel . '.',
            ],
            'cta_label' => 'Aceptar invitación',
            'cta_url' => $link,
            'note' => 'La invitación caduca en 7 días. Si no esperabas este mensaje, puedes ignorarlo.',
            'app_name' => $this->appName,
            'app_url' => $this->baseUrl,
        ]);

        $this->mailer->send($email, 'Invitación a ' . $agencyName . ' en ' . $this->appName, $text, $html);
    }

    /** @return array{0: string, 1: string} [texto plano, html] */
    private function emailBody(string $title, string $message, ?string $url): array
    {
        $link = $this->absoluteUrl($url);
        $text = $message . ($link !== '' ? "\n\n" . $link : '');
        $html = EmailTemplate::render([
            'eyebrow' => 'Notificación',
            'title' => $title,
            'preheader' => $message,
            'paragraphs' => [$message],
            'cta_label' => $link !== '' ? 'Abrir en ' . $this->appName : '',
            'cta_url' => $link,
            'app_name' => $this->appName,
            'app_url' => $this->baseUrl,
        ]);
        return [$text, $html];
    }

    private function absoluteUrl(?string $url): string
    {
        if ($url === null || $url === '') {
            return $this->baseUrl;
        }
        if (str_starts_with($url, 'http')) {
            return $url;
        }
        return ($this->baseUrl !== '' ? rtrim($this->baseUrl, '/') : '') . '/' . ltrim($url, '/');
    }
}

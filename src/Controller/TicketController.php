<?php

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ProjectRepository;
use App\Repository\TicketRepository;
use App\Security\Permissions;
use App\Service\WebhookService;
use App\Support\Format;
use App\Support\Html;

/** Tickets de un proyecto y sus respuestas. Los cambios se notifican por webhook a la agencia dueña. */
final class TicketController extends ProjectScopedController
{
    public function __construct(
        array $user,
        ProjectRepository $projects,
        private readonly TicketRepository $tickets,
        private readonly ?WebhookService $webhooks = null,
    ) {
        parent::__construct($user, $projects);
    }

    public function register(Router $router): void
    {
        $router
            ->on('create', $this->create(...))
            ->on('update_status', $this->updateStatus(...))
            ->on('delete', $this->delete(...))
            ->on('list_replies', $this->listReplies(...))
            ->on('add_reply', $this->addReply(...))
            ->on('delete_reply', $this->deleteReply(...));
    }

    public function create(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        $this->require($this->projects->canCreateTickets($projectId, $this->userId(), $this->role()), 'Tu agencia no participa en este proyecto');

        $title = mb_substr($request->string('title'), 0, 255);
        $description = $request->string('description');
        if ($title === '' || $description === '') {
            throw HttpException::invalid('Asunto y descripción son obligatorios');
        }
        $priority = $request->enum('priority', TicketRepository::PRIORITIES, 'media');

        $ticketId = $this->tickets->create($projectId, $this->userId(), $title, $description, $priority);
        $this->dispatch($projectId, 'ticket.created', $this->tickets->find($ticketId));

        return [
            'success' => true,
            'message' => 'Ticket registrado',
            'ticket' => [
                'id' => $ticketId,
                'title' => Html::e($title),
                'description' => Html::e($description),
                'priority' => $priority,
                'status' => 'abierto',
                'author_name' => Html::e($this->userName()),
                'created_at' => Format::date(date('Y-m-d H:i:s'), true),
            ],
        ];
    }

    public function updateStatus(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        $this->require($this->projects->canChangeTicketStatus($projectId, $this->userId(), $this->role()), 'Tu nivel de acceso no permite cambiar el estado de tickets');

        $status = $request->string('status');
        if (!in_array($status, TicketRepository::STATUSES, true)) {
            throw HttpException::invalid('Estado de ticket no válido');
        }
        $before = $this->requireTicket($request, $projectId);
        $ticketId = (int)$before['id'];

        $this->tickets->updateStatus($ticketId, $projectId, $status);
        $after = $this->tickets->find($ticketId);
        $this->dispatch($projectId, 'ticket.updated', $after);
        if ($after !== null && (string)$before['status'] !== (string)$after['status']) {
            $this->dispatch($projectId, 'ticket.status_changed', $after, ['status' => (string)$before['status']]);
        }

        return ['success' => true, 'message' => 'Estado del ticket actualizado', 'ticket_id' => $ticketId, 'status' => $status];
    }

    public function delete(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        $this->require($this->projects->canDeleteTickets($projectId, $this->userId(), $this->role()), 'Solo los Administradores y Project Managers pueden eliminar tickets');

        $ticket = $this->requireTicket($request, $projectId);
        $this->tickets->delete((int)$ticket['id'], $projectId);
        $this->dispatch($projectId, 'ticket.deleted', $ticket);

        return ['success' => true, 'message' => 'Ticket eliminado', 'ticket_id' => (int)$ticket['id']];
    }

    public function listReplies(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        $ticket = $this->requireTicket($request, $projectId);
        $canModerate = $this->canModerateReplies($projectId);

        $replies = array_map(function (array $reply) use ($canModerate): array {
            $badge = Permissions::roleBadge((string)$reply['author_role']);
            return [
                'id' => (int)$reply['id'],
                'ticket_id' => (int)$reply['ticket_id'],
                'user_id' => (int)$reply['user_id'],
                'author_name' => Html::e($reply['author_name']),
                'role_label' => $badge['label'],
                'role_class' => $badge['class'],
                'initials' => strtoupper(substr((string)$reply['author_name'], 0, 2)),
                'message' => nl2br(Html::e($reply['message'])),
                'raw_message' => $reply['message'],
                'created_at' => Format::date($reply['created_at'], true),
                'can_delete' => (int)$reply['user_id'] === $this->userId() || $canModerate,
            ];
        }, $this->tickets->replies((int)$ticket['id']));

        return ['success' => true, 'replies' => $replies];
    }

    public function addReply(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        $this->require($this->projects->canCreateTickets($projectId, $this->userId(), $this->role()), 'Tu agencia no participa en este proyecto');

        $message = $request->string('message');
        if ($request->int('ticket_id') <= 0) {
            throw HttpException::invalid('Ticket no válido');
        }
        if ($message === '') {
            throw HttpException::invalid('El mensaje de respuesta no puede estar vacío');
        }
        $ticket = $this->requireTicket($request, $projectId);

        $replyId = $this->tickets->addReply((int)$ticket['id'], $this->userId(), $message);
        $badge = Permissions::roleBadge($this->role());

        return [
            'success' => true,
            'message' => 'Respuesta añadida al ticket',
            'reply' => [
                'id' => $replyId,
                'ticket_id' => (int)$ticket['id'],
                'user_id' => $this->userId(),
                'author_name' => Html::e($this->userName()),
                'role_label' => $badge['label'],
                'role_class' => $badge['class'],
                'initials' => strtoupper(substr($this->userName(), 0, 2)),
                'message' => nl2br(Html::e($message)),
                'raw_message' => $message,
                'created_at' => Format::date(date('Y-m-d H:i:s'), true),
                'can_delete' => true,
            ],
        ];
    }

    public function deleteReply(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        $reply = $this->tickets->findReply($request->int('reply_id'), $projectId);
        if ($reply === null) {
            throw HttpException::notFound('Respuesta no encontrada');
        }
        if ((int)$reply['user_id'] !== $this->userId() && !$this->canModerateReplies($projectId)) {
            throw HttpException::forbidden('No tienes permiso para eliminar esta respuesta');
        }
        $this->tickets->deleteReply((int)$reply['id']);
        return ['success' => true, 'message' => 'Respuesta eliminada'];
    }

    private function requireTicket(Request $request, int $projectId): array
    {
        $ticket = $this->tickets->findInProject($request->int('ticket_id'), $projectId);
        if ($ticket === null) {
            throw HttpException::notFound('Ticket no encontrado');
        }
        return $ticket;
    }

    /** Moderar respuestas ajenas: el admin de la agencia dueña. */
    private function canModerateReplies(int $projectId): bool
    {
        return $this->role() === Permissions::ADMIN && $this->projects->isOwnerAgency($projectId, $this->userId());
    }

    private function dispatch(int $projectId, string $event, ?array $ticket, array $previous = []): void
    {
        $agencyId = $this->projects->ownerAgencyId($projectId);
        if ($this->webhooks === null || $ticket === null || $agencyId === null) {
            return;
        }
        try {
            $this->webhooks->dispatch($agencyId, $event, $ticket, null, $previous);
        } catch (\Throwable $e) {
            error_log('Webhook ' . $event . ': ' . $e->getMessage());
        }
    }
}

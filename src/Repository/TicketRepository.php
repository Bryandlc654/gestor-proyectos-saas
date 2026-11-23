<?php

namespace App\Repository;

use PDO;

/** Tickets de un proyecto y sus respuestas. */
final class TicketRepository
{
    public const STATUSES = ['abierto', 'en_revision', 'resuelto', 'cerrado'];
    public const PRIORITIES = ['baja', 'media', 'alta', 'critica'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Ticket con el nombre de su autor (formato que usan los webhooks). */
    public function find(int $ticketId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT t.*, u.name AS author_name
            FROM tickets t
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.id = ?
        ');
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Ticket solo si pertenece al proyecto. */
    public function findInProject(int $ticketId, int $projectId): ?array
    {
        $ticket = $this->find($ticketId);
        return $ticket !== null && (int)$ticket['project_id'] === $projectId ? $ticket : null;
    }

    public function create(int $projectId, int $userId, string $title, string $description, string $priority): int
    {
        $this->db->prepare("INSERT INTO tickets (project_id, user_id, title, description, priority, status) VALUES (?, ?, ?, ?, ?, 'abierto')")
            ->execute([$projectId, $userId, $title, $description, $priority]);
        return (int)$this->db->lastInsertId();
    }

    public function updateStatus(int $ticketId, int $projectId, string $status): void
    {
        $this->db->prepare('UPDATE tickets SET status = ? WHERE id = ? AND project_id = ?')
            ->execute([$status, $ticketId, $projectId]);
    }

    public function delete(int $ticketId, int $projectId): void
    {
        $this->db->prepare('DELETE FROM tickets WHERE id = ? AND project_id = ?')->execute([$ticketId, $projectId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function replies(int $ticketId): array
    {
        $stmt = $this->db->prepare('
            SELECT r.*, u.name AS author_name, u.role AS author_role
            FROM ticket_replies r
            INNER JOIN users u ON r.user_id = u.id
            WHERE r.ticket_id = ?
            ORDER BY r.created_at ASC, r.id ASC
        ');
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addReply(int $ticketId, int $userId, string $message): int
    {
        $this->db->prepare('INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)')
            ->execute([$ticketId, $userId, $message]);
        $replyId = (int)$this->db->lastInsertId();
        $this->db->prepare('UPDATE tickets SET updated_at = NOW() WHERE id = ?')->execute([$ticketId]);
        return $replyId;
    }

    /** Respuesta solo si su ticket pertenece al proyecto. */
    public function findReply(int $replyId, int $projectId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT r.id, r.user_id, r.ticket_id
            FROM ticket_replies r
            INNER JOIN tickets t ON r.ticket_id = t.id
            WHERE r.id = ? AND t.project_id = ?
        ');
        $stmt->execute([$replyId, $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function deleteReply(int $replyId): void
    {
        $this->db->prepare('DELETE FROM ticket_replies WHERE id = ?')->execute([$replyId]);
    }
}

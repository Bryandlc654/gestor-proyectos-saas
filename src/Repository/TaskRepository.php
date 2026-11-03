<?php

namespace App\Repository;

use PDO;

/**
 * Acceso a datos de tareas. Todas las operaciones se limitan al proyecto indicado.
 */
final class TaskRepository
{
    public const PRIORITIES = ['baja', 'media', 'alta', 'urgente'];
    public const STATUSES = ['pendiente', 'en_progreso', 'completada'];

    public function __construct(private readonly PDO $db)
    {
    }

    public function projectIdOf(int $taskId): ?int
    {
        $stmt = $this->db->prepare("SELECT project_id FROM tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $projectId = $stmt->fetchColumn();
        return $projectId === false ? null : (int)$projectId;
    }

    public function create(int $projectId, string $title, string $description, string $priority, ?int $assignedUserId, ?string $dueDate): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tasks (project_id, title, description, priority, status, assigned_user_id, due_date)
            VALUES (?, ?, ?, ?, 'pendiente', ?, ?)
        ");
        $stmt->execute([$projectId, $title, $description, $priority, $assignedUserId, $dueDate]);
        return (int)$this->db->lastInsertId();
    }

    // Al guardar se limpia el responsable heredado en texto (assigned_to): manda assigned_user_id
    public function update(int $taskId, int $projectId, string $title, string $description, string $priority, string $status, ?int $assignedUserId, ?string $dueDate): void
    {
        $stmt = $this->db->prepare("
            UPDATE tasks
            SET title = ?, description = ?, priority = ?, status = ?, assigned_user_id = ?, assigned_to = NULL, due_date = ?
            WHERE id = ? AND project_id = ?
        ");
        $stmt->execute([$title, $description, $priority, $status, $assignedUserId, $dueDate, $taskId, $projectId]);
    }

    public function updateStatus(int $taskId, int $projectId, string $status): void
    {
        $this->db->prepare("UPDATE tasks SET status = ? WHERE id = ? AND project_id = ?")
            ->execute([$status, $taskId, $projectId]);
    }

    public function delete(int $taskId, int $projectId): void
    {
        $this->db->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?")
            ->execute([$taskId, $projectId]);
    }

    // Tarea con el nombre del responsable (o el texto heredado si aún no tiene usuario asignado)
    public function find(int $taskId, int $projectId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, COALESCE(au.name, t.assigned_to) AS assigned_to
            FROM tasks t
            LEFT JOIN users au ON au.id = t.assigned_user_id
            WHERE t.id = ? AND t.project_id = ?
        ");
        $stmt->execute([$taskId, $projectId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function comments(int $taskId, int $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, u.name AS author_name
            FROM comments c
            INNER JOIN users u ON c.user_id = u.id
            WHERE c.task_id = ? AND c.project_id = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$taskId, $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Checklists de la tarea con sus items agrupados (2 consultas en lugar de N+1).
     */
    public function checklistsWithItems(int $taskId, int $projectId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM checklists WHERE task_id = ? AND project_id = ? ORDER BY created_at ASC");
        $stmt->execute([$taskId, $projectId]);
        $checklists = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($checklists)) {
            return [];
        }

        $ids = array_column($checklists, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtItems = $this->db->prepare("SELECT * FROM checklist_items WHERE checklist_id IN ($placeholders) ORDER BY id ASC");
        $stmtItems->execute($ids);

        $itemsByChecklist = [];
        foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $itemsByChecklist[$item['checklist_id']][] = $item;
        }

        foreach ($checklists as &$checklist) {
            $checklist['items'] = $itemsByChecklist[$checklist['id']] ?? [];
        }
        return $checklists;
    }

    public function files(int $taskId, int $projectId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM project_files WHERE task_id = ? AND project_id = ? ORDER BY created_at DESC");
        $stmt->execute([$taskId, $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function links(int $taskId, int $projectId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM project_links WHERE task_id = ? AND project_id = ? ORDER BY created_at DESC");
        $stmt->execute([$taskId, $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

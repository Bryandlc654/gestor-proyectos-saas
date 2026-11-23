<?php

namespace App\Repository;

use PDO;

/** Checklists de un proyecto (o de una de sus tareas) y sus pasos. */
final class ChecklistRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(int $projectId, ?int $taskId, string $title): int
    {
        $this->db->prepare('INSERT INTO checklists (project_id, task_id, title) VALUES (?, ?, ?)')
            ->execute([$projectId, $taskId, $title]);
        return (int)$this->db->lastInsertId();
    }

    public function delete(int $checklistId, int $projectId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM checklists WHERE id = ? AND project_id = ?');
        $stmt->execute([$checklistId, $projectId]);
        return $stmt->rowCount() > 0;
    }

    public function belongsTo(int $checklistId, int $projectId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM checklists WHERE id = ? AND project_id = ?');
        $stmt->execute([$checklistId, $projectId]);
        return (bool)$stmt->fetchColumn();
    }

    public function addItem(int $checklistId, string $title): int
    {
        $this->db->prepare('INSERT INTO checklist_items (checklist_id, title, is_completed) VALUES (?, ?, 0)')
            ->execute([$checklistId, $title]);
        return (int)$this->db->lastInsertId();
    }

    public function setItemCompleted(int $itemId, int $projectId, bool $completed): bool
    {
        $stmt = $this->db->prepare('
            UPDATE checklist_items ci
            INNER JOIN checklists c ON ci.checklist_id = c.id
            SET ci.is_completed = ?
            WHERE ci.id = ? AND c.project_id = ?
        ');
        $stmt->execute([$completed ? 1 : 0, $itemId, $projectId]);
        return $this->itemBelongsTo($itemId, $projectId);
    }

    public function deleteItem(int $itemId, int $projectId): bool
    {
        $stmt = $this->db->prepare('
            DELETE ci FROM checklist_items ci
            INNER JOIN checklists c ON ci.checklist_id = c.id
            WHERE ci.id = ? AND c.project_id = ?
        ');
        $stmt->execute([$itemId, $projectId]);
        return $stmt->rowCount() > 0;
    }

    private function itemBelongsTo(int $itemId, int $projectId): bool
    {
        $stmt = $this->db->prepare('
            SELECT 1 FROM checklist_items ci
            INNER JOIN checklists c ON ci.checklist_id = c.id
            WHERE ci.id = ? AND c.project_id = ?
        ');
        $stmt->execute([$itemId, $projectId]);
        return (bool)$stmt->fetchColumn();
    }
}

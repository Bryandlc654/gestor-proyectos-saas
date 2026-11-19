<?php

namespace App\Repository;

use DomainException;
use PDO;

/**
 * Plantillas de proyecto y duplicado. El payload guarda tareas y checklists
 * con referencias por índice para reconstruirlos sin depender de ids originales.
 */
final class ProjectTemplateRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function saveFromProject(int $projectId, int $agencyId, int $userId, string $name): int
    {
        $project = $this->project($projectId, $agencyId);
        if ($project === null) {
            throw new DomainException('El proyecto no pertenece a tu agencia.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Escribe un nombre para la plantilla.');
        }

        $payload = $this->payloadFromProject($projectId, $project);
        $this->db->prepare("
            INSERT INTO project_templates (agency_id, name, description, category, color, payload, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $agencyId,
            mb_substr($name, 0, 150),
            $project['description'],
            $project['category'],
            $project['color'],
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** @return array<int, array> */
    public function forAgency(int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.id, t.name, t.description, t.category, t.color, t.created_at,
                   u.name AS created_by_name
            FROM project_templates t
            LEFT JOIN users u ON u.id = t.created_by
            WHERE t.agency_id = ?
            ORDER BY t.created_at DESC
        ");
        $stmt->execute([$agencyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{id: int, name: string, payload: array}|null */
    public function find(int $templateId, int $agencyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, payload FROM project_templates WHERE id = ? AND agency_id = ?
        ");
        $stmt->execute([$templateId, $agencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['payload'] = json_decode($row['payload'], true) ?: [];
        return $row;
    }

    public function delete(int $templateId, int $agencyId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM project_templates WHERE id = ? AND agency_id = ?");
        $stmt->execute([$templateId, $agencyId]);
        return $stmt->rowCount() > 0;
    }

    public function createFromTemplate(int $templateId, int $agencyId, int $userId, string $projectName, ?string $deadline): int
    {
        $template = $this->find($templateId, $agencyId);
        if ($template === null) {
            throw new DomainException('Plantilla no encontrada.');
        }
        return $this->insertFromPayload(
            $template['payload'],
            $agencyId,
            $userId,
            $projectName,
            $deadline,
            false
        );
    }

    public function duplicateProject(int $projectId, int $agencyId, int $userId, string $projectName): int
    {
        $project = $this->project($projectId, $agencyId);
        if ($project === null) {
            throw new DomainException('El proyecto no pertenece a tu agencia.');
        }
        return $this->insertFromPayload(
            $this->payloadFromProject($projectId, $project),
            $agencyId,
            $userId,
            $projectName,
            $project['deadline'],
            true
        );
    }

    private function project(int $projectId, int $agencyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, description, category, color, deadline
            FROM projects WHERE id = ? AND agency_id = ? AND archived_at IS NULL
        ");
        $stmt->execute([$projectId, $agencyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function payloadFromProject(int $projectId, array $project): array
    {
        $stmtTasks = $this->db->prepare("
            SELECT id, title, description, priority, status, parent_task_id
            FROM tasks WHERE project_id = ? ORDER BY sort_order ASC, id ASC
        ");
        $stmtTasks->execute([$projectId]);
        $tasks = $stmtTasks->fetchAll(PDO::FETCH_ASSOC);

        $taskIndex = [];
        foreach ($tasks as $index => $task) {
            $taskIndex[(int)$task['id']] = $index;
        }

        $stmtDependencies = $this->db->prepare("
            SELECT d.task_id, d.depends_on_task_id
            FROM task_dependencies d
            INNER JOIN tasks t ON t.id = d.task_id
            WHERE t.project_id = ?
        ");
        $stmtDependencies->execute([$projectId]);
        $dependencies = [];
        foreach ($stmtDependencies->fetchAll(PDO::FETCH_ASSOC) as $dependency) {
            $taskIndexPos = $taskIndex[(int)$dependency['task_id']] ?? null;
            $dependsIndexPos = $taskIndex[(int)$dependency['depends_on_task_id']] ?? null;
            if ($taskIndexPos !== null && $dependsIndexPos !== null) {
                $dependencies[] = [
                    'task_index' => $taskIndexPos,
                    'depends_on_index' => $dependsIndexPos,
                ];
            }
        }

        $stmtChecklists = $this->db->prepare("
            SELECT c.id, c.title, c.task_id, i.title AS item_title, i.is_completed
            FROM checklists c
            LEFT JOIN checklist_items i ON i.checklist_id = c.id
            WHERE c.project_id = ?
            ORDER BY c.created_at ASC, i.id ASC
        ");
        $stmtChecklists->execute([$projectId]);

        $checklists = [];
        foreach ($stmtChecklists->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($checklists[$row['id']])) {
                $checklists[$row['id']] = [
                    'title' => $row['title'],
                    'task_index' => $row['task_id'] !== null ? ($taskIndex[(int)$row['task_id']] ?? null) : null,
                    'items' => [],
                ];
            }
            if ($row['item_title'] !== null) {
                $checklists[$row['id']]['items'][] = [
                    'title' => $row['item_title'],
                    'is_completed' => (int)$row['is_completed'],
                ];
            }
        }

        return [
            'description' => $project['description'],
            'category' => $project['category'],
            'color' => $project['color'],
            'tasks' => array_map(fn(array $task) => [
                'title' => $task['title'],
                'description' => $task['description'],
                'priority' => $task['priority'],
                'status' => $task['status'],
                'parent_index' => !empty($task['parent_task_id']) ? ($taskIndex[(int)$task['parent_task_id']] ?? null) : null,
            ], $tasks),
            'dependencies' => $dependencies,
            'checklists' => array_values($checklists),
        ];
    }

    private function insertFromPayload(array $payload, int $agencyId, int $userId, string $projectName, ?string $deadline, bool $preserveState): int
    {
        $projectName = trim($projectName);
        if ($projectName === '') {
            throw new DomainException('Escribe un nombre para el proyecto.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                INSERT INTO projects (agency_id, user_id, name, description, category, color, deadline, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'activo')
            ")->execute([
                $agencyId,
                $userId,
                mb_substr($projectName, 0, 150),
                $payload['description'] ?? '',
                $payload['category'] ?? 'General',
                $payload['color'] ?? '#0E66FF',
                $deadline,
            ]);
            $newProjectId = (int)$this->db->lastInsertId();

            $this->db->prepare("INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'project_manager')")
                ->execute([$newProjectId, $userId]);

            $taskIds = [];
            $sort = 0;
            $insertTask = $this->db->prepare("
                INSERT INTO tasks (project_id, title, description, priority, status, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach (($payload['tasks'] ?? []) as $index => $task) {
                $insertTask->execute([
                    $newProjectId,
                    $task['title'],
                    $task['description'] ?? '',
                    $task['priority'] ?? 'media',
                    $preserveState ? ($task['status'] ?? 'pendiente') : 'pendiente',
                    ++$sort,
                ]);
                $taskIds[$index] = (int)$this->db->lastInsertId();
            }

            // Restaurar jerarquía de subtareas y dependencias (si el payload las incluye)
            $updateParent = $this->db->prepare('UPDATE tasks SET parent_task_id = ? WHERE id = ? AND project_id = ?');
            foreach (($payload['tasks'] ?? []) as $index => $task) {
                $parentIndex = $task['parent_index'] ?? null;
                if ($parentIndex !== null && isset($taskIds[$parentIndex], $taskIds[$index])) {
                    $updateParent->execute([$taskIds[$parentIndex], $taskIds[$index], $newProjectId]);
                }
            }
            $insertDependency = $this->db->prepare('INSERT IGNORE INTO task_dependencies (task_id, depends_on_task_id) VALUES (?, ?)');
            foreach (($payload['dependencies'] ?? []) as $dependency) {
                if (isset($taskIds[$dependency['task_index']], $taskIds[$dependency['depends_on_index']])) {
                    $insertDependency->execute([
                        $taskIds[$dependency['task_index']],
                        $taskIds[$dependency['depends_on_index']],
                    ]);
                }
            }

            $insertChecklist = $this->db->prepare("INSERT INTO checklists (project_id, task_id, title) VALUES (?, ?, ?)");
            $insertItem = $this->db->prepare("INSERT INTO checklist_items (checklist_id, title, is_completed) VALUES (?, ?, ?)");
            foreach (($payload['checklists'] ?? []) as $checklist) {
                $taskId = $checklist['task_index'] !== null ? ($taskIds[$checklist['task_index']] ?? null) : null;
                $insertChecklist->execute([$newProjectId, $taskId, $checklist['title']]);
                $checklistId = (int)$this->db->lastInsertId();
                foreach (($checklist['items'] ?? []) as $item) {
                    $insertItem->execute([
                        $checklistId,
                        $item['title'],
                        $preserveState ? (int)($item['is_completed'] ?? 0) : 0,
                    ]);
                }
            }

            $this->db->commit();
            return $newProjectId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}

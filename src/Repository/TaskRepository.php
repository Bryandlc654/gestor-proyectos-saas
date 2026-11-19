<?php

namespace App\Repository;

use PDO;
use Throwable;

/**
 * Acceso a datos de tareas. Todas las operaciones se limitan al proyecto indicado.
 */
final class TaskRepository
{
    public const PRIORITIES = ['baja', 'media', 'alta', 'urgente'];
    public const STATUSES = ['pendiente', 'en_progreso', 'completada'];
    public const STATUS_KINDS = ['open', 'in_progress', 'done'];

    /** Nombres de columna por defecto (cada agencia puede cambiarlos o añadir más) */
    public const STATUS_LABELS = [
        'pendiente' => 'Por Hacer',
        'en_progreso' => 'En Progreso',
        'completada' => 'Completada',
    ];

    /** Columnas iniciales de una agencia nueva */
    public const DEFAULT_STATUSES = [
        ['key' => 'pendiente', 'label' => 'Por Hacer', 'kind' => 'open', 'order' => 1],
        ['key' => 'en_progreso', 'label' => 'En Progreso', 'kind' => 'in_progress', 'order' => 2],
        ['key' => 'completada', 'label' => 'Completada', 'kind' => 'done', 'order' => 3],
    ];

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

    public function create(int $projectId, string $title, string $description, string $priority, ?int $assignedUserId, ?string $dueDate, ?int $parentTaskId = null, ?string $startDate = null, bool $isMilestone = false): int
    {
        $agencyId = (int)$this->db->query("SELECT agency_id FROM projects WHERE id = " . (int)$projectId)->fetchColumn();
        $defaultStatus = 'pendiente';
        foreach ($this->statuses($agencyId) as $status) {
            if ($status['kind'] === 'open') {
                $defaultStatus = $status['key'];
                break;
            }
        }

        $next = $this->nextSortOrder($projectId, $defaultStatus);
        $stmt = $this->db->prepare("
            INSERT INTO tasks (project_id, parent_task_id, title, description, priority, start_date, due_date, is_milestone, status, sort_order, assigned_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$projectId, $parentTaskId, $title, $description, $priority, $startDate, $dueDate, $isMilestone ? 1 : 0, $defaultStatus, $next, $assignedUserId]);
        return (int)$this->db->lastInsertId();
    }

    // Al guardar se limpia el responsable heredado en texto (assigned_to): manda assigned_user_id
    public function update(int $taskId, int $projectId, string $title, string $description, string $priority, string $status, ?int $assignedUserId, ?string $dueDate, ?string $startDate = null, bool $isMilestone = false): void
    {
        $stmt = $this->db->prepare("
            UPDATE tasks
            SET title = ?, description = ?, priority = ?, status = ?, assigned_user_id = ?, assigned_to = NULL, start_date = ?, due_date = ?, is_milestone = ?
            WHERE id = ? AND project_id = ?
        ");
        $stmt->execute([$title, $description, $priority, $status, $assignedUserId, $startDate, $dueDate, $isMilestone ? 1 : 0, $taskId, $projectId]);
    }

    public function reschedule(int $taskId, int $projectId, string $startDate, string $dueDate): void
    {
        $this->db->prepare('UPDATE tasks SET start_date = ?, due_date = ? WHERE id = ? AND project_id = ?')
            ->execute([$startDate, $dueDate, $taskId, $projectId]);
    }

    public function updateStatus(int $taskId, int $projectId, string $status): void
    {
        $this->db->prepare("UPDATE tasks SET status = ?, sort_order = ? WHERE id = ? AND project_id = ?")
            ->execute([$status, $this->nextSortOrder($projectId, $status), $taskId, $projectId]);
    }

    /**
     * Reordena manualmente una columna del Kanban y mueve la tarea indicada a ese estado.
     *
     * @param array<int|string> $orderedIds
     */
    public function reorder(int $projectId, int $taskId, string $status, array $orderedIds): void
    {
        $agencyId = (int)$this->db->query("SELECT agency_id FROM projects WHERE id = " . (int)$projectId)->fetchColumn();
        if (!$this->statusExists($agencyId, $status)) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $orderedIds))));
        if (!in_array($taskId, $ids, true)) {
            $ids[] = $taskId;
        }

        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare("
                UPDATE tasks SET status = ?, sort_order = ?
                WHERE id = ? AND project_id = ?
            ");
            foreach ($ids as $index => $id) {
                $update->execute([$status, $index + 1, $id, $projectId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function nextSortOrder(int $projectId, string $status): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tasks
            WHERE project_id = ? AND status = ?
        ");
        $stmt->execute([$projectId, $status]);
        return (int)$stmt->fetchColumn();
    }

    public function delete(int $taskId, int $projectId): void
    {
        // El borrado en cascada elimina subtareas, adjuntos y comentarios en la BD, pero no los archivos:
        // se recogen antes para borrarlos del disco (si no, las imágenes seguirían accesibles por URL).
        $storedFiles = $this->storedFilesForTaskTree($taskId, $projectId);

        $this->db->prepare("DELETE FROM tasks WHERE id = ? AND project_id = ?")
            ->execute([$taskId, $projectId]);

        $uploadDir = dirname(__DIR__, 2) . '/uploads/';
        foreach ($storedFiles as $name) {
            $name = basename($name);
            if ($name !== '' && is_file($uploadDir . $name)) {
                @unlink($uploadDir . $name);
            }
        }
    }

    /** @return string[] Archivos de la tarea y de todas sus subtareas (adjuntos e imágenes de comentarios). */
    private function storedFilesForTaskTree(int $taskId, int $projectId): array
    {
        $ids = [$taskId];
        $frontier = [$taskId];
        $children = $this->db->prepare("SELECT id FROM tasks WHERE parent_task_id = ? AND project_id = ?");
        while ($frontier !== [] && count($ids) < 5000) {
            $next = [];
            foreach ($frontier as $parentId) {
                $children->execute([$parentId, $projectId]);
                foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $childId) {
                    if (!in_array((int)$childId, $ids, true)) {
                        $ids[] = (int)$childId;
                        $next[] = (int)$childId;
                    }
                }
            }
            $frontier = $next;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $files = $this->db->prepare("SELECT stored_name FROM project_files WHERE project_id = ? AND task_id IN ($placeholders)");
        $files->execute([$projectId, ...$ids]);
        $images = $this->db->prepare("SELECT image_path FROM comments WHERE project_id = ? AND image_path IS NOT NULL AND task_id IN ($placeholders)");
        $images->execute([$projectId, ...$ids]);

        return array_map('strval', array_merge($files->fetchAll(PDO::FETCH_COLUMN), $images->fetchAll(PDO::FETCH_COLUMN)));
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

    /** @return array<int, array<string, mixed>> Subtareas directas de una tarea. */
    public function subtasks(int $parentId, int $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, COALESCE(au.name, t.assigned_to) AS assigned_to
            FROM tasks t
            LEFT JOIN users au ON au.id = t.assigned_user_id
            WHERE t.parent_task_id = ? AND t.project_id = ?
            ORDER BY t.sort_order ASC, t.id ASC
        ");
        $stmt->execute([$parentId, $projectId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function parentOf(int $taskId, int $projectId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT parent.*
            FROM tasks t
            INNER JOIN tasks parent ON parent.id = t.parent_task_id
            WHERE t.id = ? AND t.project_id = ?
        ");
        $stmt->execute([$taskId, $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> Tareas de las que depende la indicada. */
    public function dependenciesOf(int $taskId, int $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, COALESCE(au.name, t.assigned_to) AS assigned_to
            FROM task_dependencies d
            INNER JOIN tasks t ON t.id = d.depends_on_task_id
            LEFT JOIN users au ON au.id = t.assigned_user_id
            WHERE d.task_id = ? AND t.project_id = ?
            ORDER BY t.id ASC
        ");
        $stmt->execute([$taskId, $projectId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> Tareas que dependen de la indicada. */
    public function dependentsOf(int $taskId, int $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, COALESCE(au.name, t.assigned_to) AS assigned_to
            FROM task_dependencies d
            INNER JOIN tasks t ON t.id = d.task_id
            LEFT JOIN users au ON au.id = t.assigned_user_id
            WHERE d.depends_on_task_id = ? AND t.project_id = ?
            ORDER BY t.id ASC
        ");
        $stmt->execute([$taskId, $projectId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> Dependencias que todavía no están completadas. */
    public function unfinishedDependencies(int $taskId, int $projectId): array
    {
        $doneKeys = $this->doneStatusKeys($projectId);
        $sql = "
            SELECT t.id, t.title, t.status
            FROM task_dependencies d
            INNER JOIN tasks t ON t.id = d.depends_on_task_id
            WHERE d.task_id = ? AND t.project_id = ?
        ";
        $params = [$taskId, $projectId];
        if ($doneKeys !== []) {
            $placeholders = implode(',', array_fill(0, count($doneKeys), '?'));
            $sql .= " AND t.status NOT IN ($placeholders)";
            $params = array_merge($params, $doneKeys);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> Tareas que se pueden añadir como dependencia. */
    public function dependencyOptions(int $taskId, int $projectId): array
    {
        $doneKeys = $this->doneStatusKeys($projectId);
        $sql = "
            SELECT t.id, t.title, t.status
            FROM tasks t
            WHERE t.project_id = ? AND t.id <> ?
              AND NOT EXISTS (
                  SELECT 1 FROM task_dependencies d
                  WHERE d.task_id = ? AND d.depends_on_task_id = t.id
              )
        ";
        $params = [$projectId, $taskId, $taskId];
        if ($doneKeys !== []) {
            $placeholders = implode(',', array_fill(0, count($doneKeys), '?'));
            $sql .= " AND t.status NOT IN ($placeholders)";
            $params = array_merge($params, $doneKeys);
        }
        $sql .= ' ORDER BY t.title ASC LIMIT 200';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addDependency(int $taskId, int $dependsOnId, int $projectId): void
    {
        if ($taskId === $dependsOnId) {
            throw new \DomainException('Una tarea no puede depender de sí misma.');
        }

        $stmt = $this->db->prepare('SELECT id FROM tasks WHERE project_id = ? AND id IN (?, ?)');
        $stmt->execute([$projectId, $taskId, $dependsOnId]);
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== 2) {
            throw new \DomainException('Las tareas deben pertenecer al mismo proyecto.');
        }

        $check = $this->db->prepare('SELECT 1 FROM task_dependencies WHERE task_id = ? AND depends_on_task_id = ?');
        $check->execute([$taskId, $dependsOnId]);
        if ($check->fetchColumn()) {
            throw new \DomainException('Esa dependencia ya existe.');
        }

        // Evitar ciclos: si la tarea de la que se quiere depender ya depende (directa o
        // indirectamente) de esta tarea, se rechaza.
        $edges = $this->db->prepare('SELECT depends_on_task_id FROM task_dependencies WHERE task_id = ?');
        $stack = [$dependsOnId];
        $seen = [];
        $guard = 0;
        while ($stack !== []) {
            $current = (int)array_pop($stack);
            if ($current === $taskId) {
                throw new \DomainException('No se puede crear una dependencia circular.');
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            if (++$guard > 500) {
                break;
            }
            $edges->execute([$current]);
            foreach ($edges->fetchAll(PDO::FETCH_COLUMN) as $next) {
                $stack[] = (int)$next;
            }
        }

        try {
            $this->db->prepare('INSERT INTO task_dependencies (task_id, depends_on_task_id) VALUES (?, ?)')
                ->execute([$taskId, $dependsOnId]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new \DomainException('Esa dependencia ya existe.');
            }
            throw $e;
        }
    }
    public function removeDependency(int $taskId, int $dependsOnId, int $projectId): void
    {
        $check = $this->db->prepare("
            SELECT 1
            FROM task_dependencies d
            INNER JOIN tasks t ON t.id = d.task_id
            WHERE d.task_id = ? AND d.depends_on_task_id = ? AND t.project_id = ?
        ");
        $check->execute([$taskId, $dependsOnId, $projectId]);
        if (!$check->fetchColumn()) {
            return;
        }

        $this->db->prepare('DELETE FROM task_dependencies WHERE task_id = ? AND depends_on_task_id = ?')
            ->execute([$taskId, $dependsOnId]);
    }

    /** @return string[] */
    private function doneStatusKeys(int $projectId): array
    {
        $done = [];
        foreach ($this->statuses($this->projectAgencyId($projectId)) as $status) {
            if ($status['kind'] === 'done') {
                $done[] = $status['key'];
            }
        }

        return $done;
    }

    private function projectAgencyId(int $projectId): int
    {
        $stmt = $this->db->prepare('SELECT agency_id FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);

        return (int)$stmt->fetchColumn();
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

    /**
     * Columnas de estado de una agencia, en orden. Si aún no hay filas, devuelve las predeterminadas.
     *
     * @return array<int, array{key:string,label:string,kind:string,order:int}>
     */
    public function statuses(int $agencyId): array
    {
        if ($agencyId <= 0) {
            return self::DEFAULT_STATUSES;
        }

        $stmt = $this->db->prepare("
            SELECT status_key, label, kind, sort_order
            FROM agency_task_statuses
            WHERE agency_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$agencyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return self::DEFAULT_STATUSES;
        }

        $statuses = [];
        foreach ($rows as $row) {
            $key = trim((string)$row['status_key']);
            if ($key === '') {
                continue;
            }
            $label = trim((string)$row['label']);
            $kind = in_array($row['kind'], self::STATUS_KINDS, true) ? $row['kind'] : 'open';
            $statuses[] = [
                'key' => $key,
                'label' => $label !== '' ? $label : ucfirst(str_replace('_', ' ', $key)),
                'kind' => $kind,
                'order' => (int)$row['sort_order'],
            ];
        }

        return $statuses !== [] ? $statuses : self::DEFAULT_STATUSES;
    }

    /** @return array<string, string> */
    public function statusLabels(int $agencyId): array
    {
        $labels = [];
        foreach ($this->statuses($agencyId) as $status) {
            $labels[$status['key']] = $status['label'];
        }
        return $labels;
    }

    /** @return array<string, string> */
    public function statusKinds(int $agencyId): array
    {
        $kinds = [];
        foreach ($this->statuses($agencyId) as $status) {
            $kinds[$status['key']] = $status['kind'];
        }
        return $kinds;
    }

    public function statusExists(int $agencyId, string $statusKey): bool
    {
        foreach ($this->statuses($agencyId) as $status) {
            if ($status['key'] === $statusKey) {
                return true;
            }
        }
        return false;
    }

    /** Crea las columnas predeterminadas si la agencia todavía no tiene ninguna. */
    public function ensureStatuses(int $agencyId): void
    {
        if ($agencyId <= 0) {
            return;
        }

        $insert = $this->db->prepare("
            INSERT IGNORE INTO agency_task_statuses (agency_id, status_key, label, kind, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach (self::DEFAULT_STATUSES as $status) {
            $insert->execute([$agencyId, $status['key'], $status['label'], $status['kind'], $status['order']]);
        }
    }

    /**
     * Guarda etiquetas, tipos y orden de las columnas.
     *
     * @param array<int, array{key:string,label:string,kind:string}> $statuses
     */
    public function saveStatuses(int $agencyId, array $statuses): void
    {
        $this->ensureStatuses($agencyId);

        $current = [];
        foreach ($this->statuses($agencyId) as $status) {
            $current[$status['key']] = $status;
        }

        $normalized = [];
        $order = 0;
        foreach ($statuses as $row) {
            $key = (string)($row['key'] ?? '');
            if (!isset($current[$key])) {
                continue;
            }
            $label = trim((string)($row['label'] ?? ''));
            $label = $label === '' ? $current[$key]['label'] : mb_substr($label, 0, 40);
            $kind = in_array($row['kind'] ?? '', self::STATUS_KINDS, true) ? $row['kind'] : $current[$key]['kind'];
            $normalized[] = ['key' => $key, 'label' => $label, 'kind' => $kind, 'order' => ++$order];
        }

        // Columnas que no llegaron en el formulario se conservan al final
        foreach ($current as $key => $status) {
            if (!in_array($key, array_column($normalized, 'key'), true)) {
                $normalized[] = ['key' => $key, 'label' => $status['label'], 'kind' => $status['kind'], 'order' => ++$order];
            }
        }

        if ($normalized === []) {
            throw new \DomainException('No hay columnas para guardar.');
        }
        $this->validateStatusKinds($normalized);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE agency_task_statuses
                SET label = ?, kind = ?, sort_order = ?
                WHERE agency_id = ? AND status_key = ?
            ");
            foreach ($normalized as $status) {
                $stmt->execute([$status['label'], $status['kind'], $status['order'], $agencyId, $status['key']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Añade una columna personalizada y devuelve su clave interna. */
    public function addStatus(int $agencyId, string $label, string $kind = 'open'): string
    {
        $this->ensureStatuses($agencyId);

        $label = trim($label);
        if ($label === '') {
            throw new \DomainException('Escribe un nombre para la nueva columna.');
        }
        if (!in_array($kind, self::STATUS_KINDS, true)) {
            $kind = 'open';
        }

        $existing = array_column($this->statuses($agencyId), 'key');
        $base = $this->statusSlug($label);
        $key = $base;
        $suffix = 2;
        while (in_array($key, $existing, true)) {
            $key = $base . '_' . $suffix++;
        }

        $stmtMax = $this->db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM agency_task_statuses WHERE agency_id = ?");
        $stmtMax->execute([$agencyId]);
        $order = (int)$stmtMax->fetchColumn() + 1;

        $this->db->prepare("
            INSERT INTO agency_task_statuses (agency_id, status_key, label, kind, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$agencyId, $key, mb_substr($label, 0, 40), $kind, $order]);

        return $key;
    }

    /** Elimina una columna personalizada si no tiene tareas. */
    public function deleteStatus(int $agencyId, string $statusKey): void
    {
        if (in_array($statusKey, self::STATUSES, true)) {
            throw new \DomainException('Las columnas predeterminadas no se pueden eliminar; puedes renombrarlas.');
        }

        $statuses = $this->statuses($agencyId);
        $found = false;
        foreach ($statuses as $status) {
            if ($status['key'] === $statusKey) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new \DomainException('Columna no encontrada.');
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE p.agency_id = ? AND t.status = ?
        ");
        $stmt->execute([$agencyId, $statusKey]);
        $taskCount = (int)$stmt->fetchColumn();
        if ($taskCount > 0) {
            throw new \DomainException('No puedes eliminar esta columna: tiene ' . $taskCount . ' tarea(s). Muévelas a otra columna primero.');
        }

        $remaining = array_values(array_filter($statuses, static fn(array $status): bool => $status['key'] !== $statusKey));
        $this->validateStatusKinds($remaining);

        $this->db->prepare("DELETE FROM agency_task_statuses WHERE agency_id = ? AND status_key = ?")
            ->execute([$agencyId, $statusKey]);
    }

    public function moveStatus(int $agencyId, string $statusKey, string $direction): void
    {
        $this->ensureStatuses($agencyId);
        $statuses = $this->statuses($agencyId);

        $index = null;
        foreach ($statuses as $i => $status) {
            if ($status['key'] === $statusKey) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return;
        }

        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($statuses[$swap])) {
            return;
        }
        [$statuses[$index], $statuses[$swap]] = [$statuses[$swap], $statuses[$index]];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("UPDATE agency_task_statuses SET sort_order = ? WHERE agency_id = ? AND status_key = ?");
            foreach ($statuses as $i => $status) {
                $stmt->execute([$i + 1, $agencyId, $status['key']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Restaura los nombres/tipos predeterminados y elimina columnas personalizadas vacías. */
    public function resetStatuses(int $agencyId): void
    {
        $this->ensureStatuses($agencyId);

        foreach ($this->statuses($agencyId) as $status) {
            if (in_array($status['key'], self::STATUSES, true)) {
                continue;
            }
            $stmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM tasks t
                INNER JOIN projects p ON p.id = t.project_id
                WHERE p.agency_id = ? AND t.status = ?
            ");
            $stmt->execute([$agencyId, $status['key']]);
            if ((int)$stmt->fetchColumn() === 0) {
                $this->db->prepare("DELETE FROM agency_task_statuses WHERE agency_id = ? AND status_key = ?")
                    ->execute([$agencyId, $status['key']]);
            }
        }

        $this->ensureStatuses($agencyId);
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare("
                UPDATE agency_task_statuses SET label = ?, kind = ?, sort_order = ?
                WHERE agency_id = ? AND status_key = ?
            ");
            foreach (self::DEFAULT_STATUSES as $default) {
                $update->execute([$default['label'], $default['kind'], $default['order'], $agencyId, $default['key']]);
            }
            $next = count(self::DEFAULT_STATUSES) + 1;
            foreach ($this->statuses($agencyId) as $status) {
                if (in_array($status['key'], self::STATUSES, true)) {
                    continue;
                }
                $update->execute([$status['label'], $status['kind'], $next++, $agencyId, $status['key']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Compatibilidad con el método anterior (solo etiquetas). */
    public function saveStatusLabels(int $agencyId, array $labels): void
    {
        $rows = [];
        foreach ($this->statuses($agencyId) as $status) {
            $rows[] = [
                'key' => $status['key'],
                'label' => $labels[$status['key']] ?? $status['label'],
                'kind' => $status['kind'],
            ];
        }
        $this->saveStatuses($agencyId, $rows);
    }

    public function resetStatusLabels(int $agencyId): void
    {
        $this->resetStatuses($agencyId);
    }

    /** @param array<int, array{key:string,kind:string}> $statuses */
    private function validateStatusKinds(array $statuses): void
    {
        $done = 0;
        $pending = 0;
        foreach ($statuses as $status) {
            if (($status['kind'] ?? '') === 'done') {
                $done++;
            } else {
                $pending++;
            }
        }
        if ($done === 0 || $pending === 0) {
            throw new \DomainException('Debe existir al menos una columna de tareas completadas y otra de tareas pendientes.');
        }
    }

    private function statusSlug(string $label): string
    {
        $base = strtr($label, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
        $base = strtolower($ascii !== false ? $ascii : $base);
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: '';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'columna';
        }
        return substr($base, 0, 30);
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

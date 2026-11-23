<?php

namespace App\Repository;

use PDO;

/**
 * Contenido de un proyecto: comentarios, carpetas, enlaces, archivos y documentos de factura.
 * Todas las consultas se acotan al proyecto: un id de otro proyecto nunca se encuentra.
 */
final class ProjectContentRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function taskTitle(int $taskId): ?string
    {
        $stmt = $this->db->prepare('SELECT title FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);
        $title = $stmt->fetchColumn();
        return $title === false ? null : (string)$title;
    }

    // --- Comentarios ---

    /** @return array{id: int, user_id: int, task_id: ?int, image_path: ?string}|null */
    public function findComment(int $commentId, int $projectId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, user_id, task_id, image_path FROM comments WHERE id = ? AND project_id = ?');
        $stmt->execute([$commentId, $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'task_id' => $row['task_id'] !== null ? (int)$row['task_id'] : null,
            'image_path' => $row['image_path'] !== null ? (string)$row['image_path'] : null,
        ];
    }

    public function createComment(int $projectId, ?int $taskId, ?int $parentId, int $userId, string $content, ?string $imagePath): int
    {
        $this->db->prepare('INSERT INTO comments (project_id, task_id, parent_id, user_id, content, image_path) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$projectId, $taskId, $parentId, $userId, $content, $imagePath]);
        return (int)$this->db->lastInsertId();
    }

    public function updateComment(int $commentId, int $projectId, string $content): void
    {
        $this->db->prepare('UPDATE comments SET content = ? WHERE id = ? AND project_id = ?')
            ->execute([$content, $commentId, $projectId]);
    }

    public function deleteComment(int $commentId, int $projectId): void
    {
        $this->db->prepare('DELETE FROM comments WHERE id = ? AND project_id = ?')->execute([$commentId, $projectId]);
    }

    // --- Carpetas ---

    public function createFolder(int $projectId, string $name, string $color): int
    {
        $this->db->prepare('INSERT INTO project_folders (project_id, name, color) VALUES (?, ?, ?)')
            ->execute([$projectId, $name, $color]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteFolder(int $folderId, int $projectId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM project_folders WHERE id = ? AND project_id = ?');
        $stmt->execute([$folderId, $projectId]);
        return $stmt->rowCount() > 0;
    }

    // --- Enlaces ---

    public function createLink(int $projectId, ?int $folderId, ?int $taskId, string $title, string $url): int
    {
        $this->db->prepare('INSERT INTO project_links (project_id, folder_id, task_id, title, url) VALUES (?, ?, ?, ?, ?)')
            ->execute([$projectId, $folderId, $taskId, $title, $url]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteLink(int $linkId, int $projectId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM project_links WHERE id = ? AND project_id = ?');
        $stmt->execute([$linkId, $projectId]);
        return $stmt->rowCount() > 0;
    }

    // --- Archivos ---

    /** Archivo por id (sin acotar a proyecto: la descarga comprueba el acceso al proyecto del archivo). */
    public function file(int $fileId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM project_files WHERE id = ?');
        $stmt->execute([$fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findProjectFile(int $fileId, int $projectId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, user_id, stored_name, original_name, invoice_id FROM project_files WHERE id = ? AND project_id = ?');
        $stmt->execute([$fileId, $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array{original_name: string, stored_name: string, size: int, mime: string} $upload */
    public function createFile(int $projectId, int $userId, ?int $folderId, ?int $taskId, ?int $invoiceId, array $upload): int
    {
        $this->db->prepare('
            INSERT INTO project_files (project_id, user_id, folder_id, task_id, invoice_id, original_name, stored_name, file_size, file_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $projectId,
            $userId,
            $folderId,
            $taskId,
            $invoiceId,
            $upload['original_name'],
            $upload['stored_name'],
            $upload['size'],
            $upload['mime'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteFile(int $fileId, int $projectId): void
    {
        $this->db->prepare('DELETE FROM project_files WHERE id = ? AND project_id = ?')->execute([$fileId, $projectId]);
    }

    // --- Documentos de factura ---

    /** @return array<int, array<string, mixed>> */
    public function invoiceDocuments(int $invoiceId, int $projectId): array
    {
        $stmt = $this->db->prepare('
            SELECT id, original_name, file_size, file_type, created_at
            FROM project_files
            WHERE invoice_id = ? AND project_id = ?
            ORDER BY created_at DESC
        ');
        $stmt->execute([$invoiceId, $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findInvoiceDocument(int $documentId, int $projectId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, stored_name, original_name
            FROM project_files
            WHERE id = ? AND project_id = ? AND invoice_id IS NOT NULL
        ');
        $stmt->execute([$documentId, $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return string[] Nombres en disco de los documentos de una factura (para borrarlos con ella). */
    public function invoiceDocumentFiles(int $invoiceId): array
    {
        $stmt = $this->db->prepare('SELECT stored_name FROM project_files WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

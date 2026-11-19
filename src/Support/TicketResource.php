<?php

namespace App\Support;

/** Representación pública y estable de un ticket para API y webhooks. */
final class TicketResource
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function fromRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'project_id' => (int)$row['project_id'],
            'title' => (string)$row['title'],
            'description' => (string)$row['description'],
            'priority' => (string)$row['priority'],
            'status' => (string)$row['status'],
            'external_source' => $row['external_source'] !== null ? (string)$row['external_source'] : null,
            'external_id' => $row['external_id'] !== null ? (string)$row['external_id'] : null,
            'requester' => [
                'name' => $row['external_requester_name'] !== null ? (string)$row['external_requester_name'] : null,
                'email' => $row['external_requester_email'] !== null ? (string)$row['external_requester_email'] : null,
            ],
            'external_url' => $row['external_url'] !== null ? (string)$row['external_url'] : null,
            'author' => [
                'id' => (int)$row['user_id'],
                'name' => isset($row['author_name']) ? (string)$row['author_name'] : null,
            ],
            'created_at' => !empty($row['created_at']) ? date('c', strtotime((string)$row['created_at'])) : null,
            'updated_at' => !empty($row['updated_at']) ? date('c', strtotime((string)$row['updated_at'])) : null,
            'links' => [
                'self' => '/api/v1/tickets/' . (int)$row['id'],
                'project' => '/project/' . (int)$row['project_id'],
            ],
        ];
    }
}

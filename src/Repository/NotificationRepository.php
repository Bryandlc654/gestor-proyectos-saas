<?php

namespace App\Repository;

use PDO;

/** Notificaciones internas de la campana. */
final class NotificationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(int $agencyId, int $userId, string $type, string $title, ?string $message, ?string $url): void
    {
        $this->db->prepare("
            INSERT INTO notifications (agency_id, user_id, type, title, message, url)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $agencyId,
            $userId,
            mb_substr($type, 0, 40),
            mb_substr($title, 0, 150),
            $message !== null ? mb_substr($message, 0, 500) : null,
            $url !== null ? mb_substr($url, 0, 255) : null,
        ]);
    }

    /** @return array<int, array> */
    public function forUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $stmt = $this->db->prepare("
            SELECT id, type, title, message, url, read_at, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY (read_at IS NULL) DESC, created_at DESC, id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$userId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['is_read'] = $row['read_at'] !== null;
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Lista y total de no leídas en una sola consulta (evita el COUNT adicional al abrir la campana).
     *
     * @return array{0: array<int, array>, 1: int} [notificaciones, no leídas]
     */
    public function forUserWithUnread(int $userId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        $stmt = $this->db->prepare("
            SELECT id, type, title, message, url, read_at, created_at,
                   SUM(read_at IS NULL) OVER () AS unread_total
            FROM notifications
            WHERE user_id = ?
            ORDER BY (read_at IS NULL) DESC, created_at DESC, id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$userId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread = $rows !== [] ? (int)$rows[0]['unread_total'] : 0;

        foreach ($rows as &$row) {
            unset($row['unread_total']);
            $row['id'] = (int)$row['id'];
            $row['is_read'] = $row['read_at'] !== null;
        }
        unset($row);

        return [$rows, $unread];
    }

    /**
     * Crea la misma notificación para varios usuarios en una sola sentencia.
     *
     * @param array<int|string> $userIds
     */
    public function createMany(int $agencyId, array $userIds, string $type, string $title, ?string $message, ?string $url): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return;
        }

        $type = mb_substr($type, 0, 40);
        $title = mb_substr($title, 0, 150);
        $message = $message !== null ? mb_substr($message, 0, 500) : null;
        $url = $url !== null ? mb_substr($url, 0, 255) : null;

        $values = [];
        $params = [];
        foreach ($ids as $userId) {
            $values[] = '(?, ?, ?, ?, ?, ?)';
            array_push($params, $agencyId, $userId, $type, $title, $message, $url);
        }

        $this->db->prepare("
            INSERT INTO notifications (agency_id, user_id, type, title, message, url)
            VALUES " . implode(',', $values) . "
        ")->execute($params);
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE notifications SET read_at = NOW()
            WHERE id = ? AND user_id = ? AND read_at IS NULL
        ");
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function markAllRead(int $userId): int
    {
        $stmt = $this->db->prepare("
            UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL
        ");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /** @return array<int, array{id: int, email: string, name: string}> */
    public function adminsOf(int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, email, name FROM users
            WHERE agency_id = ? AND role = 'admin'
            ORDER BY id
        ");
        $stmt->execute([$agencyId]);
        return array_map(static fn(array $row) => [
            'id' => (int)$row['id'],
            'email' => $row['email'],
            'name' => $row['name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{id: int, email: string, name: string, agency_id: int}|null */
    public function user(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, email, name, agency_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['agency_id'] = (int)$row['agency_id'];
        return $row;
    }
}

<?php

namespace App\Controller;

use App\Http\Request;
use App\Http\Router;
use App\Repository\NotificationRepository;
use App\Support\Format;

/** Campana de notificaciones internas del usuario autenticado. */
final class NotificationController
{
    public function __construct(
        private readonly int $userId,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function register(Router $router): void
    {
        $router
            ->on('count', $this->count(...))
            ->on('list', $this->list(...))
            ->on('mark_read', $this->markRead(...))
            ->on('mark_all_read', $this->markAllRead(...));
    }

    /** Consulta mínima para el contador: no trae la lista. */
    public function count(Request $request): array
    {
        return ['success' => true, 'unread' => $this->notifications->unreadCount($this->userId)];
    }

    public function list(Request $request): array
    {
        [$items, $unread] = $this->notifications->forUserWithUnread($this->userId);
        $items = array_map(static function (array $notification): array {
            $notification['created_at'] = Format::date($notification['created_at'], true);
            return $notification;
        }, $items);
        return ['success' => true, 'unread' => $unread, 'notifications' => $items];
    }

    public function markRead(Request $request): array
    {
        $marked = $this->notifications->markRead($request->int('notification_id'), $this->userId);
        return ['success' => $marked, 'unread' => $this->notifications->unreadCount($this->userId)];
    }

    public function markAllRead(Request $request): array
    {
        $this->notifications->markAllRead($this->userId);
        return ['success' => true, 'unread' => 0];
    }
}

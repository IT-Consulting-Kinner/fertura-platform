<?php
declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Service\Notification\NotificationService;
use Cake\Http\Response;

/**
 * Externe API für Benachrichtigungen des Token-Inhabers (P09, Scope `me:read`).
 *
 *   GET  /api/v1/notifications            -> ungelesene Benachrichtigungen
 *   POST /api/v1/notifications/{id}/read  -> als gelesen markieren
 *   POST /api/v1/notifications/read-all   -> alle als gelesen markieren
 */
class NotificationsController extends ApiController
{
    public function index(): Response
    {
        if ($denied = $this->requireScope('me:read')) {
            return $denied;
        }
        $svc = new NotificationService();
        $userId = $this->userId();

        return $this->json([
            'unread' => $svc->unread($userId),
            'unread_count' => $svc->unreadCount($userId),
        ]);
    }

    public function read(string $id): Response
    {
        if ($denied = $this->requireScope('me:read')) {
            return $denied;
        }
        (new NotificationService())->markRead($this->userId(), $id);

        return $this->json(['status' => 'ok']);
    }

    public function readAll(): Response
    {
        if ($denied = $this->requireScope('me:read')) {
            return $denied;
        }
        (new NotificationService())->markAllRead($this->userId());

        return $this->json(['status' => 'ok']);
    }
}

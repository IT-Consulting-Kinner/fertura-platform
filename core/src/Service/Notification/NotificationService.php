<?php
declare(strict_types=1);

namespace App\Service\Notification;

use App\Service\Event\OutboxPublisher;
use App\Service\Mail\MailService;
use App\Service\Module\ContributionRuntime;
use App\Service\Realtime\RealtimeService;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Throwable;

/**
 * Benachrichtigungs-Framework (Programm Tier-1, P09).
 *
 * Ein Aufruf, mehrere Kanäle: **In-App** (gespeichert + per SSE/P08 live),
 * **E-Mail** (Core-MailService) und **Modul-Kanäle** (Collector-Contract
 * `core.collector.notification_channel`). Kanäle je (Benutzer, Typ) über
 * Präferenzen steuerbar. Zusätzlich wird ein Outbox-Event
 * `core.notification.created` veröffentlicht, sodass Webhook-Abos (P05) externe
 * Empfänger erreichen. Kanal-Fehler sind isoliert (eine fehlgeschlagene E-Mail
 * verliert nicht die In-App-Benachrichtigung).
 */
class NotificationService
{
    public const CHANNEL_COLLECTOR = 'core.collector.notification_channel';

    public function __construct(
        private ?RealtimeService $realtime = null,
        private ?MailService $mail = null,
        private ?ContributionRuntime $runtime = null,
    ) {
    }

    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    /**
     * Benachrichtigt einen Benutzer über die (aufgelösten) Kanäle.
     *
     * @param array<string,mixed> $data
     * @param list<string>|null $channels explizite Kanäle (sonst aus Präferenzen)
     * @return string In-App-ID (leer, wenn in_app nicht aktiv)
     */
    public function notify(string $userId, string $type, string $title, string $body = '', array $data = [], ?array $channels = null): string
    {
        $user = $this->conn()->execute(
            'SELECT email, status FROM users WHERE id = :id',
            ['id' => $userId],
        )->fetch('assoc');
        if ($user === false) {
            throw new RuntimeException('Unbekannter Benutzer für Benachrichtigung.');
        }
        $channels = $channels ?? $this->resolveChannels($userId, $type);

        $id = '';
        if (in_array('in_app', $channels, true)) {
            $row = $this->conn()->execute(
                'INSERT INTO notifications (user_id, type, title, body, data) '
                . 'VALUES (:u, :t, :ti, :b, CAST(:d AS jsonb)) RETURNING id',
                ['u' => $userId, 't' => $type, 'ti' => $title, 'b' => $body, 'd' => json_encode($data)],
            )->fetch('assoc');
            $id = (string)$row['id'];
            try {
                ($this->realtime ??= new RealtimeService())->publish($userId, 'notification', [
                    'id' => $id, 'type' => $type, 'title' => $title, 'body' => $body,
                ]);
            } catch (Throwable) {
            }
        }

        if (in_array('email', $channels, true) && !empty($user['email'])) {
            try {
                ($this->mail ??= new MailService())->notify((string)$user['email'], $title, $body !== '' ? $body : $title);
            } catch (Throwable) {
            }
        }

        $this->dispatchModuleChannels($channels, $userId, $type, $title, $body, $data, $user['email'] ?? null);

        try {
            (new OutboxPublisher())->publish('core.notification.created', [
                'user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body, 'data' => $data,
            ]);
        } catch (Throwable) {
        }

        return $id;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function dispatchModuleChannels(array $channels, string $userId, string $type, string $title, string $body, array $data, ?string $email): void
    {
        $runtime = $this->runtime ??= new ContributionRuntime();
        try {
            $contribs = $runtime->collectors(self::CHANNEL_COLLECTOR);
        } catch (Throwable) {
            return;
        }
        foreach ($contribs as $contrib) {
            try {
                $key = (string)$runtime->call($contrib, 'key', []);
                if (!in_array($key, $channels, true)) {
                    continue;
                }
                $runtime->call($contrib, 'deliver', [[
                    'user_id' => $userId, 'type' => $type, 'title' => $title,
                    'body' => $body, 'data' => $data, 'email' => $email,
                ]], ['bypass' => true]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * Standardkanäle: in_app + email, minus per Präferenz deaktivierte, plus
     * per Präferenz aktivierte (Modul-)Kanäle.
     *
     * @return list<string>
     */
    private function resolveChannels(string $userId, string $type): array
    {
        $channels = ['in_app', 'email'];
        $prefs = $this->conn()->execute(
            'SELECT channel, enabled FROM notification_prefs WHERE user_id = :u AND type = :t',
            ['u' => $userId, 't' => $type],
        )->fetchAll('assoc');
        foreach ($prefs as $p) {
            if ((bool)$p['enabled'] === false) {
                $channels = array_values(array_diff($channels, [(string)$p['channel']]));
            } elseif (!in_array($p['channel'], $channels, true)) {
                $channels[] = (string)$p['channel'];
            }
        }

        return $channels;
    }

    public function setPref(string $userId, string $type, string $channel, bool $enabled): void
    {
        $this->conn()->execute(
            'INSERT INTO notification_prefs (user_id, type, channel, enabled) VALUES (:u, :t, :c, :e) '
            . 'ON CONFLICT (user_id, type, channel) DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = now()',
            ['u' => $userId, 't' => $type, 'c' => $channel, 'e' => $enabled ? 'true' : 'false'],
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function unread(string $userId, int $limit = 50): array
    {
        $rows = $this->conn()->execute(
            'SELECT id, type, title, body, data, created_at FROM notifications '
            . 'WHERE user_id = :u AND read_at IS NULL ORDER BY created_at DESC LIMIT :l',
            ['u' => $userId, 'l' => $limit],
        )->fetchAll('assoc');
        foreach ($rows as &$r) {
            $r['data'] = json_decode((string)$r['data'], true);
        }

        return $rows;
    }

    public function unreadCount(string $userId): int
    {
        $row = $this->conn()->execute(
            'SELECT count(*) AS c FROM notifications WHERE user_id = :u AND read_at IS NULL',
            ['u' => $userId],
        )->fetch('assoc');

        return (int)$row['c'];
    }

    public function markRead(string $userId, string $id): void
    {
        $this->conn()->execute(
            'UPDATE notifications SET read_at = now() WHERE id = :id AND user_id = :u AND read_at IS NULL',
            ['id' => $id, 'u' => $userId],
        );
    }

    public function markAllRead(string $userId): void
    {
        $this->conn()->execute(
            'UPDATE notifications SET read_at = now() WHERE user_id = :u AND read_at IS NULL',
            ['u' => $userId],
        );
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Notification;

use App\Infrastructure\Uuid;
use App\Service\Event\OutboxPublisher;
use App\Service\Mail\MailService;
use App\Service\Module\ContributionRuntime;
use App\Service\Realtime\RealtimeService;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use RuntimeException;
use Throwable;

/**
 * Notification framework (Program Tier-1, P09).
 *
 * One call, multiple channels: **in-app** (persisted + delivered live via
 * SSE/P08), **email** (core MailService) and **module channels** (collector
 * contract `core.collector.notification_channel`). Channels are controllable
 * per (user, type) via preferences. Additionally an outbox event
 * `core.notification.created` is published so that webhook subscriptions (P05)
 * reach external recipients. Channel failures are isolated (a failed email does
 * not lose the in-app notification).
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
     * Notifies a user over the (resolved) channels.
     *
     * @param array<string,mixed> $data
     * @param list<string>|null $channels explicit channels (otherwise from preferences)
     * @return string in-app ID (empty if in_app is not active)
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
            // Isolate channel failures: a failed in-app insert must not abort the
            // remaining channels (email/module) or the outbox event.
            try {
                $row = $this->conn()->execute(
                    'INSERT INTO notifications (user_id, type, title, body, data) '
                    . 'VALUES (:u, :t, :ti, :b, CAST(:d AS jsonb)) RETURNING id',
                    ['u' => $userId, 't' => $type, 'ti' => $title, 'b' => $body, 'd' => json_encode($data)],
                )->fetch('assoc');
                $id = (string)$row['id'];
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
                ]]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * Default channels: in_app + email, minus those disabled by preference, plus
     * (module) channels enabled by preference.
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
        // UUID guard: the ID comes from the API URL; treat malformed values like
        // unknown ones (no-op) instead of 22P02 -> 500 (cf. \App\Infrastructure\Uuid).
        if (!Uuid::isValid($id)) {
            return;
        }
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

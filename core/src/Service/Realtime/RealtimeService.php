<?php
declare(strict_types=1);

namespace App\Service\Realtime;

use Cake\Datasource\ConnectionManager;
use PDO;
use Throwable;
use function Cake\Core\env;

/**
 * Real-time delivery to users (Program Tier-2, P08) via PostgreSQL
 * `LISTEN/NOTIFY`.
 *
 * Each user has its own channel (`rt_<hash>`). {@see publish()} sends an event
 * (`pg_notify`) that the user's SSE connection
 * ({@see \App\Controller\SseController}) delivers. No additional broker required
 * (same approach as the outbox worker).
 */
class RealtimeService
{
    /** Stable, identifier-safe channel name per user. */
    public static function channel(string $userId): string
    {
        return 'rt_' . substr((string)preg_replace('/[^a-z0-9]/i', '', $userId), 0, 50);
    }

    /**
     * Publishes an event to a user's channel.
     *
     * @param array<string,mixed> $data
     */
    public function publish(string $userId, string $event, array $data): void
    {
        $payload = (string)json_encode(['event' => $event, 'data' => $data], JSON_UNESCAPED_UNICODE);
        // pg_notify takes the channel as a parameter (no identifier-quoting issue);
        // payload limit 8000 bytes -> keep notifications small.
        ConnectionManager::get('default')->execute(
            'SELECT pg_notify(:channel, :payload)',
            ['channel' => self::channel($userId), 'payload' => mb_substr($payload, 0, 7900)],
        );
    }

    /**
     * Opens a dedicated PDO connection for `LISTEN` (separate from the ORM
     * connection, since the SSE loop stays open for a long time). The app role
     * suffices (LISTEN/NOTIFY is not permission-restricted).
     */
    public static function listenPdo(): ?PDO
    {
        $url = (string)(env('APP_DATABASE_URL') ?: env('DATABASE_URL') ?: '');
        $p = $url !== '' ? parse_url($url) : false;
        if ($p === false || !isset($p['host'])) {
            return null;
        }
        try {
            return new PDO(
                'pgsql:host=' . $p['host'] . ';port=' . ($p['port'] ?? 5432)
                    . ';dbname=' . (isset($p['path']) ? ltrim($p['path'], '/') : 'fertura'),
                $p['user'] ?? 'fertura',
                $p['pass'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (Throwable) {
            return null;
        }
    }
}

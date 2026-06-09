<?php
declare(strict_types=1);

namespace App\Service\Realtime;

use Cake\Datasource\ConnectionManager;
use PDO;
use Throwable;
use function Cake\Core\env;

/**
 * Echtzeit-Zustellung an Benutzer (Programm Tier-2, P08) über PostgreSQL
 * `LISTEN/NOTIFY`.
 *
 * Jeder Benutzer hat einen eigenen Kanal (`rt_<hash>`). {@see publish()} sendet
 * ein Ereignis (`pg_notify`), das die SSE-Verbindung des Benutzers
 * ({@see \App\Controller\SseController}) ausliefert. Kein zusätzlicher Broker
 * nötig (gleiche Strömung wie der Outbox-Worker).
 */
class RealtimeService
{
    /** Stabiler, identifier-sicherer Kanalname je Benutzer. */
    public static function channel(string $userId): string
    {
        return 'rt_' . substr((string)preg_replace('/[^a-z0-9]/i', '', $userId), 0, 50);
    }

    /**
     * Veröffentlicht ein Ereignis an den Kanal eines Benutzers.
     *
     * @param array<string,mixed> $data
     */
    public function publish(string $userId, string $event, array $data): void
    {
        $payload = (string)json_encode(['event' => $event, 'data' => $data], JSON_UNESCAPED_UNICODE);
        // pg_notify nimmt den Kanal als Parameter (keine Identifier-Quoting-Frage);
        // Payload-Grenze 8000 Bytes -> Benachrichtigungen klein halten.
        ConnectionManager::get('default')->execute(
            'SELECT pg_notify(:channel, :payload)',
            ['channel' => self::channel($userId), 'payload' => mb_substr($payload, 0, 7900)],
        );
    }

    /**
     * Öffnet eine eigene PDO-Verbindung für `LISTEN` (getrennt von der ORM-
     * Connection, da die SSE-Schleife lange offen bleibt). App-Rolle genügt
     * (LISTEN/NOTIFY ist nicht rechtebeschränkt).
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

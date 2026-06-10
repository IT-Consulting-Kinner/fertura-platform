<?php
declare(strict_types=1);

namespace App\Service\Queue;

use Predis\Client;
use function Cake\Core\env;

/**
 * Broker-Treiber des Job-Queue-Transports: **Redis Streams** mit Consumer-Group
 * (durabel, mehrere Consumer kollisionsfrei). Nutzt `predis` (reines PHP, keine
 * Erweiterung). Reserve = `XREADGROUP` neuer Einträge + `XAUTOCLAIM` verwaister
 * (Crash-Recovery); ack = `XACK`+`XDEL`. Verbindung über `QUEUE_REDIS_URL`.
 *
 * Stream-Befehle laufen über `executeRaw`, um von predis-Versionssignaturen
 * unabhängig zu sein.
 *
 * Bekannte, bewusst offene Punkte (für den späteren produktiven Consumer-Loop):
 * - **Stale Consumer:** der Consumer-Name ist `hostname:pid`; nach Neustart bleibt
 *   der alte (leere) Consumer in der Gruppe. Verwaiste *Einträge* werden per
 *   XAUTOCLAIM zurückgeholt (kein Verlust), aber leere Consumer häufen sich an —
 *   ein periodisches `XGROUP DELCONSUMER` (0 Pending) wäre die Housekeeping-Lösung.
 * - **release()-Latenz:** ein freigegebener (fehlgeschlagener) Job wird erst nach
 *   `RECLAIM_IDLE_MS` per XAUTOCLAIM erneut zugestellt (kein sofortiges Requeue);
 *   Absturz und explizites release() sind dadurch ununterscheidbar.
 */
class RedisStreamTransport implements QueueTransportInterface
{
    private const GROUP = 'fertura';
    private const RECLAIM_IDLE_MS = 60000;

    private Client $redis;
    private string $consumer;

    public function __construct(?Client $client = null)
    {
        $this->redis = $client ?? new Client((string)(env('QUEUE_REDIS_URL') ?: 'tcp://redis:6379'));
        $this->consumer = (gethostname() ?: 'host') . ':' . getmypid();
    }

    /** @param array<string,mixed> $payload */
    public function push(string $queue, array $payload): string
    {
        return (string)$this->redis->executeRaw([
            'XADD', $this->key($queue), '*', 'data', json_encode($payload === [] ? new \stdClass() : $payload),
        ]);
    }

    public function reserve(string $queue, int $max = 1): array
    {
        $key = $this->key($queue);
        $this->ensureGroup($key);
        $max = max(1, $max);

        // 1) Verwaiste (idle) Einträge anderer/abgestürzter Consumer zurückholen.
        //    XAUTOCLAIM liefert je Aufruf nur EINE Seite (COUNT) ab dem Cursor; bei
        //    großem Pending-Backlog würden Einträge jenseits der ersten Seite sonst
        //    erst über viele reserve()-Aufrufe (je vom Anfang) zurückgeholt. Daher
        //    paginieren wir mit dem zurückgegebenen Cursor, bis $max gedeckt ist oder
        //    der Cursor zu '0' zurückkehrt (Scan vollständig). Harte Schleifengrenze
        //    als Sicherheitsnetz gegen unerwartete Cursor-Zyklen.
        $out = [];
        $cursor = '0';
        for ($page = 0; $page < 100 && count($out) < $max; $page++) {
            $need = $max - count($out);
            $claim = $this->redis->executeRaw([
                'XAUTOCLAIM', $key, self::GROUP, $this->consumer,
                (string)self::RECLAIM_IDLE_MS, $cursor, 'COUNT', (string)$need,
            ]);
            // Antwort: [nextCursor, [[id,[f,v,...]],...], [deleted]] (Redis 7).
            if (!is_array($claim)) {
                break;
            }
            if (isset($claim[1]) && is_array($claim[1])) {
                $out = array_merge($out, $this->parseEntries($claim[1]));
            }
            $cursor = isset($claim[0]) ? (string)$claim[0] : '0';
            if ($cursor === '0') {
                break; // Scan der Pending-Liste vollständig.
            }
        }

        // 2) Neue Einträge lesen, falls noch Kapazität.
        $need = $max - count($out);
        if ($need > 0) {
            $read = $this->redis->executeRaw([
                'XREADGROUP', 'GROUP', self::GROUP, $this->consumer, 'COUNT', (string)$need, 'STREAMS', $key, '>',
            ]);
            // Antwort: [[streamKey, [[id,[f,v,...]],...]]] oder null.
            if (is_array($read) && isset($read[0][1]) && is_array($read[0][1])) {
                $out = array_merge($out, $this->parseEntries($read[0][1]));
            }
        }

        return $out;
    }

    public function ack(string $queue, string $id): void
    {
        $key = $this->key($queue);
        $this->redis->executeRaw(['XACK', $key, self::GROUP, $id]);
        $this->redis->executeRaw(['XDEL', $key, $id]);
    }

    public function release(string $queue, string $id): void
    {
        // Kein XACK: der Eintrag bleibt in der Pending-Liste und wird von einer
        // späteren reserve() per XAUTOCLAIM (nach Idle-Timeout) erneut zugestellt.
    }

    public function size(string $queue): int
    {
        return (int)$this->redis->executeRaw(['XLEN', $this->key($queue)]);
    }

    public function name(): string
    {
        return 'redis';
    }

    private function key(string $queue): string
    {
        return 'fertura:q:' . $queue;
    }

    private function ensureGroup(string $key): void
    {
        try {
            // Ab `0` (Stream-Anfang), damit auch VOR der (lazy) Gruppenerstellung
            // eingereihte Einträge zugestellt werden — sonst gingen sie verloren.
            $this->redis->executeRaw(['XGROUP', 'CREATE', $key, self::GROUP, '0', 'MKSTREAM']);
        } catch (\Throwable $e) {
            // BUSYGROUP = Gruppe existiert bereits -> ok.
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /**
     * @param array<int,mixed> $entries Liste [[id, [field, value, ...]], ...]
     * @return list<array{id:string, payload:array<string,mixed>}>
     */
    private function parseEntries(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry[0])) {
                continue;
            }
            $id = (string)$entry[0];
            $fields = is_array($entry[1] ?? null) ? $entry[1] : [];
            $data = '{}';
            for ($i = 0; $i + 1 < count($fields); $i += 2) {
                if ((string)$fields[$i] === 'data') {
                    $data = (string)$fields[$i + 1];
                }
            }
            /** @var array<string,mixed> $payload */
            $payload = (array)(json_decode($data, true) ?: []);
            $out[] = ['id' => $id, 'payload' => $payload];
        }

        return $out;
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Queue;

use Predis\Client;
use function Cake\Core\env;

/**
 * Broker driver of the job-queue transport: **Redis Streams** with a consumer
 * group (durable, multiple consumers without collisions). Uses `predis` (pure
 * PHP, no extension). Reserve = `XREADGROUP` for new entries + `XAUTOCLAIM` for
 * orphaned ones (crash recovery); ack = `XACK`+`XDEL`. Connection via
 * `QUEUE_REDIS_URL`.
 *
 * Stream commands run through `executeRaw` to be independent of predis version
 * signatures.
 *
 * Known, deliberately open items (for the later production consumer loop):
 * - **Stale consumers:** the consumer name is `hostname:pid`; after a restart the
 *   old (empty) consumer remains in the group. Orphaned *entries* are reclaimed via
 *   XAUTOCLAIM (no loss), but empty consumers accumulate — a periodic
 *   `XGROUP DELCONSUMER` (0 pending) would be the housekeeping solution.
 * - **release() latency:** a released (failed) job is only redelivered after
 *   `RECLAIM_IDLE_MS` via XAUTOCLAIM (no immediate requeue); a crash and an
 *   explicit release() are therefore indistinguishable.
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

        // 1) Reclaim orphaned (idle) entries from other/crashed consumers.
        //    XAUTOCLAIM returns only ONE page (COUNT) per call starting from the
        //    cursor; with a large pending backlog, entries beyond the first page
        //    would otherwise only be reclaimed across many reserve() calls (each
        //    from the start). So we paginate with the returned cursor until $max is
        //    covered or the cursor returns to '0' (scan complete). Hard loop bound
        //    as a safety net against unexpected cursor cycles.
        $out = [];
        $cursor = '0';
        for ($page = 0; $page < 100 && count($out) < $max; $page++) {
            $need = $max - count($out);
            $claim = $this->redis->executeRaw([
                'XAUTOCLAIM', $key, self::GROUP, $this->consumer,
                (string)self::RECLAIM_IDLE_MS, $cursor, 'COUNT', (string)$need,
            ]);
            // Response: [nextCursor, [[id,[f,v,...]],...], [deleted]] (Redis 7).
            if (!is_array($claim)) {
                break;
            }
            if (isset($claim[1]) && is_array($claim[1])) {
                $out = array_merge($out, $this->parseEntries($claim[1]));
            }
            $cursor = isset($claim[0]) ? (string)$claim[0] : '0';
            if ($cursor === '0') {
                break; // scan of the pending list complete.
            }
        }

        // 2) Read new entries if there is still capacity.
        $need = $max - count($out);
        if ($need > 0) {
            $read = $this->redis->executeRaw([
                'XREADGROUP', 'GROUP', self::GROUP, $this->consumer, 'COUNT', (string)$need, 'STREAMS', $key, '>',
            ]);
            // Response: [[streamKey, [[id,[f,v,...]],...]]] or null.
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
        // No XACK: the entry stays in the pending list and is redelivered by a
        // later reserve() via XAUTOCLAIM (after the idle timeout).
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
            // From `0` (stream start) so that entries enqueued BEFORE the (lazy)
            // group creation are also delivered — otherwise they would be lost.
            $this->redis->executeRaw(['XGROUP', 'CREATE', $key, self::GROUP, '0', 'MKSTREAM']);
        } catch (\Throwable $e) {
            // BUSYGROUP = group already exists -> ok.
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /**
     * @param array<int,mixed> $entries list [[id, [field, value, ...]], ...]
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

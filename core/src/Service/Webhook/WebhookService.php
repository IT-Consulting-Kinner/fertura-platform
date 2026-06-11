<?php
declare(strict_types=1);

namespace App\Service\Webhook;

use App\Audit\AuditLogger;
use App\Infrastructure\Uuid;
use App\Service\Http\EgressClient;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Outbound webhooks (program Tier-1, P05).
 *
 * Delivers platform events externally over HTTP — on the **hardened egress**
 * (P01, SSRF protection) and following the outbox pattern: one idempotently
 * enqueued delivery task per (subscription, event), which the worker delivers
 * with an **HMAC signature**, retry/backoff and dead-letter.
 *
 * Signature (as usual, replay-resistant): `X-Fertura-Signature: sha256=<hmac>`
 * over `"<timestamp>.<body>"` with the subscription secret; the recipient checks
 * the signature **and** `X-Fertura-Timestamp`.
 */
class WebhookService
{
    public function __construct(
        private ?EgressClient $egress = null,
        private ?AuditLogger $audit = null,
        private int $backoffBaseSeconds = 10,
    ) {
    }

    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    private function audit(): AuditLogger
    {
        return $this->audit ??= new AuditLogger();
    }

    /** Computes the HMAC signature over `"<timestamp>.<body>"`. */
    public function signature(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public function backoffSeconds(int $attempt): int
    {
        return (int)min($this->backoffBaseSeconds * (2 ** max(0, $attempt - 1)), 3600);
    }

    /**
     * Enqueues delivery tasks for an event for all matching, active subscriptions
     * (idempotent via UNIQUE(subscription_id, event_id)).
     *
     * @param array<string, mixed> $payload
     * @return int Number of newly enqueued deliveries
     */
    public function enqueueForEvent(string $eventName, array $payload, string $eventId): int
    {
        $stmt = $this->conn()->execute(
            <<<SQL
            INSERT INTO webhook_deliveries (subscription_id, event_id, event_name, payload)
            SELECT s.id, :eid, :name, :payload::jsonb
            FROM webhook_subscriptions s
            WHERE s.active
              AND (s.event_filter = '*' OR :name = ANY (string_to_array(s.event_filter, ',')))
            ON CONFLICT (subscription_id, event_id) DO NOTHING
            SQL,
            ['eid' => $eventId, 'name' => $eventName, 'payload' => json_encode($payload)],
        );

        return $stmt->rowCount();
    }

    /**
     * Delivers due, pending deliveries. Returns the number of deliveries processed.
     */
    public function deliverPending(?int $limit = null): int
    {
        $limit ??= 25;
        $egress = $this->egress ??= new EgressClient();
        $conn = $this->conn();

        $rows = $conn->execute(
            <<<SQL
            WITH due AS (
                SELECT id FROM webhook_deliveries
                WHERE status = 'pending' AND available_at <= now()
                ORDER BY available_at
                FOR UPDATE SKIP LOCKED
                LIMIT :limit
            )
            UPDATE webhook_deliveries d
            SET status = 'delivering', locked_at = now(), attempt_count = d.attempt_count + 1
            FROM due WHERE d.id = due.id
            RETURNING d.id, d.subscription_id, d.event_id, d.event_name, d.payload, d.attempt_count, d.max_attempts
            SQL,
            ['limit' => $limit],
        )->fetchAll('assoc');

        foreach ($rows as $d) {
            $sub = $conn->execute(
                'SELECT url, secret FROM webhook_subscriptions WHERE id = :id',
                ['id' => $d['subscription_id']],
            )->fetch('assoc');
            if ($sub === false) {
                $conn->execute(
                    "UPDATE webhook_deliveries SET status = 'dead_letter', last_error = 'subscription entfernt' WHERE id = :id",
                    ['id' => $d['id']],
                );
                continue;
            }
            $this->attempt($conn, $egress, $d, $sub);
        }

        return count($rows);
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $sub
     */
    private function attempt(ConnectionInterface $conn, EgressClient $egress, array $d, array $sub): void
    {
        $body = (string)$d['payload'];
        $timestamp = (string)time();
        $headers = [
            'Content-Type' => 'application/json',
            'X-Fertura-Event' => (string)$d['event_name'],
            'X-Fertura-Delivery' => (string)$d['id'],
            'X-Fertura-Timestamp' => $timestamp,
        ];
        if (!empty($sub['secret'])) {
            $headers['X-Fertura-Signature'] = 'sha256=' . $this->signature((string)$sub['secret'], $timestamp, $body);
        }

        $code = null;
        try {
            $resp = $egress->request('POST', (string)$sub['url'], ['headers' => $headers, 'data' => $body]);
            $code = $resp->statusCode;
            if ($resp->isSuccess()) {
                $conn->execute(
                    "UPDATE webhook_deliveries SET status = 'done', last_status_code = :c, last_error = NULL, "
                    . 'delivered_at = now() WHERE id = :id',
                    ['c' => $code, 'id' => $d['id']],
                );

                return;
            }
            $error = "HTTP $code";
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $this->reschedule($conn, $d, $code, $error);
    }

    /**
     * @param array<string, mixed> $d
     */
    private function reschedule(ConnectionInterface $conn, array $d, ?int $code, string $error): void
    {
        $attempt = (int)$d['attempt_count'];
        if ($attempt >= (int)$d['max_attempts']) {
            $conn->execute(
                "UPDATE webhook_deliveries SET status = 'dead_letter', last_status_code = :c, last_error = :e WHERE id = :id",
                ['c' => $code, 'e' => $error, 'id' => $d['id']],
            );

            return;
        }
        $delay = $this->backoffSeconds($attempt);
        $conn->execute(
            "UPDATE webhook_deliveries SET status = 'pending', "
            . "available_at = now() + (:delay || ' seconds')::interval, "
            . 'last_status_code = :c, last_error = :e WHERE id = :id',
            ['delay' => (string)$delay, 'c' => $code, 'e' => $error, 'id' => $d['id']],
        );
    }

    // ---- Administration -----------------------------------------------------

    /**
     * @return array{id:string}
     */
    public function createSubscription(string $name, string $url, string $eventFilter = '*', ?string $secret = null, bool $active = true): array
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Webhook-URL muss http/https sein.');
        }
        $filter = (string)preg_replace('/\s+/', '', $eventFilter) ?: '*';
        $row = $this->conn()->execute(
            'INSERT INTO webhook_subscriptions (name, url, event_filter, secret, active) '
            . 'VALUES (:n, :u, :f, :s, :a) RETURNING id',
            ['n' => $name, 'u' => $url, 'f' => $filter, 's' => $secret, 'a' => $active ? 'true' : 'false'],
        )->fetch('assoc');

        $this->audit()->log('webhook.create', 'webhook_subscription', (string)$row['id'], [
            'name' => $name,
            'url' => $url,
            'event_filter' => $filter,
        ]);

        return ['id' => (string)$row['id']];
    }

    public function setActive(string $id, bool $active): void
    {
        // UUID guard: the ID comes from the admin GUI URL; treat malformed values
        // like unknown ones instead of 22P02 -> 500 (cf. \App\Infrastructure\Uuid).
        if (!Uuid::isValid($id)) {
            return;
        }
        $this->conn()->execute(
            'UPDATE webhook_subscriptions SET active = :a WHERE id = :id',
            ['a' => $active ? 'true' : 'false', 'id' => $id],
        );
        $this->audit()->log($active ? 'webhook.activate' : 'webhook.deactivate', 'webhook_subscription', $id, []);
    }

    public function deleteSubscription(string $id): void
    {
        if (!Uuid::isValid($id)) {
            return;
        }
        $this->conn()->execute('DELETE FROM webhook_subscriptions WHERE id = :id', ['id' => $id]);
        $this->audit()->log('webhook.delete', 'webhook_subscription', $id, []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSubscriptions(): array
    {
        return $this->conn()->execute(
            'SELECT id, name, url, event_filter, active, (secret IS NOT NULL) AS has_secret, created_at '
            . 'FROM webhook_subscriptions ORDER BY created_at DESC',
        )->fetchAll('assoc');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDeliveries(?string $status = null, int $limit = 100): array
    {
        if ($status !== null) {
            return $this->conn()->execute(
                'SELECT id, subscription_id, event_name, status, attempt_count, last_status_code, last_error, '
                . 'available_at, delivered_at, created_at FROM webhook_deliveries '
                . 'WHERE status = :s ORDER BY created_at DESC LIMIT :l',
                ['s' => $status, 'l' => $limit],
            )->fetchAll('assoc');
        }

        return $this->conn()->execute(
            'SELECT id, subscription_id, event_name, status, attempt_count, last_status_code, last_error, '
            . 'available_at, delivered_at, created_at FROM webhook_deliveries ORDER BY created_at DESC LIMIT :l',
            ['l' => $limit],
        )->fetchAll('assoc');
    }

    /** Re-enqueues a (failed/dead-letter) delivery. */
    public function retryDelivery(string $id): void
    {
        if (!Uuid::isValid($id)) {
            return;
        }
        $this->conn()->execute(
            "UPDATE webhook_deliveries SET status = 'pending', available_at = now(), attempt_count = 0, "
            . 'last_error = NULL WHERE id = :id',
            ['id' => $id],
        );
    }
}

<?php
declare(strict_types=1);

namespace App\Service\Event;

use Cake\Datasource\ConnectionManager;

/**
 * Writes events into the transactional outbox (ch. 26.9.2, Decision 168).
 *
 * The INSERT runs over the current connection and thus within the ongoing
 * transaction of the business change. `pg_notify` is issued in the same
 * transaction and is delivered by PostgreSQL only after a successful COMMIT
 * (and not at all on rollback) — exactly the desired behavior.
 */
class OutboxPublisher
{
    public const CHANNEL = 'core_event_outbox';

    /**
     * @param array<string, mixed> $payload
     * @param array{correlationId?: ?string, maxAttempts?: int} $opts
     * @return string The event ID.
     */
    public function publish(string $contractName, array $payload = [], array $opts = []): string
    {
        $connection = ConnectionManager::get('default');

        $row = $connection->execute(
            'INSERT INTO event_outbox (contract_name, payload, correlation_id, max_attempts, tenant_id) '
            // Record the tenant of the publishing context (NULL = system-wide),
            // so the worker later processes the event in the correct tenant.
            . "VALUES (:contract, CAST(:payload AS jsonb), :corr, :max, "
            . "nullif(current_setting('app.current_tenant_id', true), '')::uuid) "
            . 'RETURNING id',
            [
                'contract' => $contractName,
                'payload' => json_encode($payload === [] ? new \stdClass() : $payload),
                'corr' => $opts['correlationId'] ?? null,
                'max' => $opts['maxAttempts'] ?? 5,
            ],
        )->fetch('assoc');

        $id = (string)$row['id'];

        // NOTIFY within the same transaction -> delivered on COMMIT.
        $connection->execute("SELECT pg_notify(:channel, :id)", ['channel' => self::CHANNEL, 'id' => $id]);

        return $id;
    }
}

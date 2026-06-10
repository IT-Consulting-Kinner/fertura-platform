<?php
declare(strict_types=1);

namespace App\Service\Event;

use Cake\Datasource\ConnectionManager;

/**
 * Schreibt Events in den transaktionalen Outbox (Kap. 26.9.2, Entscheidung 168).
 *
 * Der INSERT läuft über die aktuelle Connection und damit innerhalb der
 * laufenden Transaktion der fachlichen Änderung. `pg_notify` wird in derselben
 * Transaktion ausgelöst und von PostgreSQL erst nach erfolgreichem COMMIT
 * zugestellt (bei Rollback gar nicht) — exakt das gewünschte Verhalten.
 */
class OutboxPublisher
{
    public const CHANNEL = 'core_event_outbox';

    /**
     * @param array<string, mixed> $payload
     * @param array{correlationId?: ?string, maxAttempts?: int} $opts
     * @return string Die Event-ID.
     */
    public function publish(string $contractName, array $payload = [], array $opts = []): string
    {
        $connection = ConnectionManager::get('default');

        $row = $connection->execute(
            'INSERT INTO event_outbox (contract_name, payload, correlation_id, max_attempts, tenant_id) '
            // Mandant des publizierenden Kontextes festhalten (NULL = systemweit),
            // damit der Worker das Event später im richtigen Mandanten verarbeitet.
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

        // NOTIFY innerhalb derselben Transaktion -> wird auf COMMIT zugestellt.
        $connection->execute("SELECT pg_notify(:channel, :id)", ['channel' => self::CHANNEL, 'id' => $id]);

        return $id;
    }
}

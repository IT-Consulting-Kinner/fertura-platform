<?php
declare(strict_types=1);

namespace App\Service\Event;

use App\Audit\AuditLogger;
use Cake\Datasource\ConnectionManager;

/**
 * Dead-Letter-Verwaltung für die Admin-GUI (Kap. 26.9.2).
 *
 * Listet fehlgeschlagene Events (`dead_letter`) und erlaubt manuelles
 * Wiedereinstellen (Retry → `pending`, Zähler/Lock/Fehler zurückgesetzt) oder
 * Verwerfen (`discarded`). Beide Aktionen werden auditiert.
 */
class OutboxAdmin
{
    public function __construct(private ?AuditLogger $audit = null)
    {
        $this->audit ??= new AuditLogger();
    }

    private function conn()
    {
        return ConnectionManager::get('default');
    }

    /** @return array<string,int> Zähler je Status. */
    public function counts(): array
    {
        $rows = $this->conn()->execute(
            'SELECT status, count(*) AS n FROM event_outbox GROUP BY status',
        )->fetchAll('assoc');
        $out = ['pending' => 0, 'processing' => 0, 'done' => 0, 'dead_letter' => 0, 'discarded' => 0];
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }

        return $out;
    }

    /**
     * Dead-Letter-Events (neueste zuerst).
     *
     * @return list<array<string,mixed>>
     */
    public function deadLetters(int $limit = 200): array
    {
        return $this->conn()->execute(
            'SELECT id, created_at, contract_name, correlation_id, attempt_count, max_attempts, last_error '
            . "FROM event_outbox WHERE status = 'dead_letter' ORDER BY created_at DESC LIMIT :l",
            ['l' => $limit],
        )->fetchAll('assoc');
    }

    /** Stellt ein Dead-Letter-Event wieder ein (Retry). */
    public function retry(string $id): bool
    {
        $n = $this->conn()->execute(
            "UPDATE event_outbox SET status = 'pending', attempt_count = 0, available_at = now(), "
            . "locked_at = NULL, last_error = NULL WHERE id = :id AND status = 'dead_letter'",
            ['id' => $id],
        )->rowCount();
        if ($n > 0) {
            $this->audit->log('outbox.retry', 'event_outbox', $id, ['component' => 'core']);
        }

        return $n > 0;
    }

    /** Verwirft ein Dead-Letter-Event endgültig (discarded). */
    public function discard(string $id): bool
    {
        $n = $this->conn()->execute(
            "UPDATE event_outbox SET status = 'discarded', processed_at = now() "
            . "WHERE id = :id AND status = 'dead_letter'",
            ['id' => $id],
        )->rowCount();
        if ($n > 0) {
            $this->audit->log('outbox.discard', 'event_outbox', $id, ['component' => 'core']);
        }

        return $n > 0;
    }

    /** Stellt alle Dead-Letter-Events wieder ein. Gibt die Anzahl zurück. */
    public function retryAll(): int
    {
        $n = $this->conn()->execute(
            "UPDATE event_outbox SET status = 'pending', attempt_count = 0, available_at = now(), "
            . "locked_at = NULL, last_error = NULL WHERE status = 'dead_letter'",
        )->rowCount();
        if ($n > 0) {
            $this->audit->log('outbox.retry_all', 'event_outbox', null, ['component' => 'core', 'newValue' => ['count' => $n]]);
        }

        return $n;
    }
}

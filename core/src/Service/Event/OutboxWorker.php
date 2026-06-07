<?php
declare(strict_types=1);

namespace App\Service\Event;

use App\Event\EventListenerInterface;
use App\Service\Module\ModuleAutoloader;
use App\Service\Registry\ContractRegistry;
use Cake\Datasource\ConnectionManager;
use Closure;
use PDO;
use Throwable;
use function Cake\Core\env;

/**
 * Verarbeitet den Event-Outbox (Step 6).
 *
 * - claim: holt fällige `pending`-Events sicher mit FOR UPDATE SKIP LOCKED
 *   (mehrere Worker parallel möglich) und setzt sie auf `processing`.
 * - dispatch: ruft die in der Registry aktiven Listener isoliert auf
 *   (Fehler eines Listeners blockiert die anderen nicht, Kap. 26.16.3).
 * - Erfolg -> done; Fehler -> Retry mit exponentiellem Backoff; nach
 *   Ausschöpfung der Versuche -> dead_letter (sichtbar, nie auto-gelöscht).
 * - reclaim: hängende `processing`-Events (Worker-Absturz) zurück auf pending.
 * - run(): LISTEN/NOTIFY-Schleife mit periodischem Poll-Fallback.
 */
class OutboxWorker
{
    private ContractRegistry $registry;
    private bool $running = false;
    private ?Closure $logger;

    public function __construct(
        ?ContractRegistry $registry = null,
        private int $batchLimit = 50,
        private int $backoffBaseSeconds = 5,
        private int $reclaimAfterSeconds = 300,
        private int $pollMs = 5000,
        ?Closure $logger = null,
    ) {
        $this->registry = $registry ?? new ContractRegistry();
        $this->logger = $logger;
    }

    private function connection()
    {
        return ConnectionManager::get('default');
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        } else {
            error_log('[outbox-worker] ' . $message);
        }
    }

    public function backoffSeconds(int $attempt): int
    {
        $delay = $this->backoffBaseSeconds * (2 ** max(0, $attempt - 1));

        return (int)min($delay, 3600);
    }

    /**
     * Holt einen Batch fälliger Events und verarbeitet sie. Gibt die Anzahl
     * verarbeiteter Events zurück.
     */
    public function processBatch(?int $limit = null): int
    {
        $limit ??= $this->batchLimit;
        $rows = $this->connection()->execute(
            <<<SQL
            WITH due AS (
                SELECT id, created_at FROM event_outbox
                WHERE status = 'pending' AND available_at <= now()
                ORDER BY available_at
                FOR UPDATE SKIP LOCKED
                LIMIT :limit
            )
            UPDATE event_outbox o
            SET status = 'processing', locked_at = now(), attempt_count = o.attempt_count + 1
            FROM due
            WHERE o.id = due.id AND o.created_at = due.created_at
            RETURNING o.id, o.contract_name, o.payload, o.attempt_count, o.max_attempts, o.correlation_id
            SQL,
            ['limit' => $limit],
        )->fetchAll('assoc');

        foreach ($rows as $event) {
            $this->dispatch($event);
        }

        return count($rows);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function dispatch(array $event): void
    {
        $payload = json_decode((string)$event['payload'], true) ?: [];
        $context = [
            'event_id' => $event['id'],
            'contract_name' => $event['contract_name'],
            'attempt' => (int)$event['attempt_count'],
            'correlation_id' => $event['correlation_id'],
        ];

        $errors = [];
        foreach ($this->registry->listenerClasses((string)$event['contract_name']) as $class) {
            try {
                if (!class_exists($class)) {
                    $errors[] = "Listener-Klasse fehlt: $class";
                    continue;
                }
                $listener = new $class();
                if (!$listener instanceof EventListenerInterface) {
                    $errors[] = "$class implementiert EventListenerInterface nicht";
                    continue;
                }
                $listener->handle($payload, $context);
            } catch (Throwable $e) {
                // Isolation: Fehler eines Listeners stoppt die anderen nicht.
                $errors[] = "$class: " . $e->getMessage();
            }
        }

        if ($errors === []) {
            $this->connection()->execute(
                "UPDATE event_outbox SET status = 'done', processed_at = now(), last_error = NULL WHERE id = :id",
                ['id' => $event['id']],
            );

            return;
        }

        $errorText = implode(' | ', $errors);
        $attempt = (int)$event['attempt_count'];
        $maxAttempts = (int)$event['max_attempts'];

        if ($attempt >= $maxAttempts) {
            $this->connection()->execute(
                "UPDATE event_outbox SET status = 'dead_letter', last_error = :err WHERE id = :id",
                ['err' => $errorText, 'id' => $event['id']],
            );
            $this->log("DEAD-LETTER {$event['contract_name']} ({$event['id']}): $errorText");

            return;
        }

        $delay = $this->backoffSeconds($attempt);
        $this->connection()->execute(
            "UPDATE event_outbox SET status = 'pending', available_at = now() + (:delay || ' seconds')::interval, "
            . 'last_error = :err WHERE id = :id',
            ['delay' => (string)$delay, 'err' => $errorText, 'id' => $event['id']],
        );
    }

    /** Setzt hängende 'processing'-Events (z. B. nach Worker-Absturz) zurück. */
    public function reclaimStuck(): int
    {
        $statement = $this->connection()->execute(
            "UPDATE event_outbox SET status = 'pending', locked_at = NULL "
            . "WHERE status = 'processing' AND locked_at < now() - (:secs || ' seconds')::interval",
            ['secs' => (string)$this->reclaimAfterSeconds],
        );

        return $statement->rowCount();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Dauerschleife: verarbeitet vorhandene Events, wartet dann per LISTEN/NOTIFY
     * (latenzarm) bzw. per Poll-Timeout (Fallback) auf neue.
     */
    public function run(): void
    {
        $this->running = true;
        $listenPdo = $this->openListenPdo();
        if ($listenPdo !== null) {
            $listenPdo->exec('LISTEN ' . OutboxPublisher::CHANNEL);
            $this->log('LISTEN ' . OutboxPublisher::CHANNEL . ' aktiv.');
        } else {
            $this->log('Kein LISTEN möglich -> reiner Poll-Modus.');
        }

        \App\Log\LogContext::put('component', 'worker');

        while ($this->running) {
            try {
                // Heartbeat (Kap. 20.3): jeder Zyklus protokolliert seinen Lauf
                // inkl. Lauf-Dauer und erwartetem Intervall (für die >2×-Intervall-
                // Überfälligkeitswarnung im Health/Dashboard, Kap. 20.3).
                $cycleStart = microtime(true);
                // Neu aktivierte Modul-Listener nachladbar machen (Step 7).
                ModuleAutoloader::registerActiveModules();
                $reclaimed = $this->reclaimStuck();
                if ($reclaimed > 0) {
                    $this->log("$reclaimed haengende Events zurueckgesetzt.");
                }
                // Vorhandene Events vollständig abarbeiten.
                $processed = 0;
                while ($this->running && ($n = $this->processBatch()) > 0) {
                    $processed += $n;
                }
                \App\Service\Health\WorkerHeartbeat::beat('outbox', 'ok', [
                    'duration_ms' => (int)round((microtime(true) - $cycleStart) * 1000),
                    'interval_seconds' => (int)round($this->pollMs / 1000),
                    'processed' => $processed,
                ]);
                // Auf NOTIFY oder Timeout warten.
                if ($listenPdo !== null) {
                    $listenPdo->pgsqlGetNotify(PDO::FETCH_ASSOC, $this->pollMs);
                } else {
                    usleep($this->pollMs * 1000);
                }
            } catch (Throwable $e) {
                $this->log('Iterationsfehler: ' . $e->getMessage());
                usleep(2_000_000);
                $listenPdo = $this->openListenPdo();
                if ($listenPdo !== null) {
                    try {
                        $listenPdo->exec('LISTEN ' . OutboxPublisher::CHANNEL);
                    } catch (Throwable) {
                        $listenPdo = null;
                    }
                }
            }
        }

        $this->log('Worker gestoppt.');
    }

    private function openListenPdo(): ?PDO
    {
        $url = env('DATABASE_URL');
        if (!$url) {
            return null;
        }
        $p = parse_url((string)$url);
        if ($p === false || !isset($p['host'])) {
            return null;
        }
        $host = $p['host'];
        $port = $p['port'] ?? 5432;
        $db = isset($p['path']) ? ltrim($p['path'], '/') : 'fertura';
        $user = $p['user'] ?? 'fertura';
        $pass = $p['pass'] ?? '';

        try {
            return new PDO(
                "pgsql:host=$host;port=$port;dbname=$db",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (Throwable) {
            return null;
        }
    }
}

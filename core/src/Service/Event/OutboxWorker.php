<?php
declare(strict_types=1);

namespace App\Service\Event;

use App\Event\EventListenerInterface;
use App\Service\Module\ModuleAutoloader;
use App\Service\Registry\ContractRegistry;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Closure;
use PDO;
use Throwable;
use function Cake\Core\env;

/**
 * Processes the event outbox (step 6).
 *
 * - claim: safely fetches due `pending` events with FOR UPDATE SKIP LOCKED
 *   (multiple workers may run in parallel) and sets them to `processing`.
 * - dispatch: invokes the listeners active in the registry in isolation
 *   (a failure in one listener does not block the others, ch. 26.16.3).
 * - success -> done; failure -> retry with exponential backoff; once attempts
 *   are exhausted -> dead_letter (visible, never auto-deleted).
 * - reclaim: returns stuck `processing` events (worker crash) back to pending.
 * - run(): LISTEN/NOTIFY loop with a periodic poll fallback.
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

    private function connection(): Connection
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');

        return $conn;
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
     * Fetches a batch of due events and processes them. Returns the number of
     * processed events.
     */
    public function processBatch(?int $limit = null): int
    {
        $limit ??= $this->batchLimit;
        // Tenant fairness (pool model, against noisy neighbors): events are ranked
        // per tenant by due time and selected **round-robin** (all rn=1 first, then
        // rn=2 …), so no single tenant monopolizes the worker. `perTenant`
        // additionally caps how many events ONE tenant may contribute per batch.
        // FOR UPDATE SKIP LOCKED is preserved (multi-worker) by subsequently
        // locking the ranked candidate IDs on the base table.
        //
        // Important (multi-worker throughput): the final `LIMIT :limit` takes effect
        // ONLY AFTER the SKIP LOCKED. `picked` therefore over-selects fairly ranked
        // candidates (`:overfetch`), and `due` locks the first `:limit` of them that
        // are NOT already locked. Otherwise (LIMIT before the lock) all workers would
        // contend for the same top-N candidates, find them mutually locked, and
        // fetch almost empty batches.
        $perTenant = $this->maxPerTenantPerBatch();
        $overfetch = $limit * 10;
        $rows = $this->connection()->execute(
            <<<SQL
            WITH candidates AS (
                SELECT id, created_at, available_at,
                       row_number() OVER (
                           PARTITION BY coalesce(tenant_id::text, '~system')
                           ORDER BY available_at, created_at
                       ) AS rn
                FROM event_outbox
                WHERE status = 'pending' AND available_at <= now()
            ),
            picked AS (
                SELECT id, created_at, rn, available_at FROM candidates
                WHERE rn <= :perTenant
                ORDER BY rn, available_at
                LIMIT :overfetch
            ),
            due AS (
                SELECT o.id, o.created_at FROM event_outbox o
                JOIN picked p ON p.id = o.id AND p.created_at = o.created_at
                WHERE o.status = 'pending' AND o.available_at <= now()
                ORDER BY p.rn, p.available_at
                FOR UPDATE SKIP LOCKED
                LIMIT :limit
            )
            UPDATE event_outbox o
            SET status = 'processing', locked_at = now(), attempt_count = o.attempt_count + 1
            FROM due
            WHERE o.id = due.id AND o.created_at = due.created_at
            RETURNING o.id, o.contract_name, o.payload, o.attempt_count, o.max_attempts, o.correlation_id, o.derived_done, o.tenant_id
            SQL,
            ['limit' => $limit, 'perTenant' => $perTenant, 'overfetch' => $overfetch],
        )->fetchAll('assoc');

        foreach ($rows as $event) {
            $this->dispatch($event);
        }

        return count($rows);
    }

    /** Per-tenant upper bound on events per batch (fairness/quota). */
    private function maxPerTenantPerBatch(): int
    {
        try {
            return max(1, (int)(new \App\Service\Settings\SettingsManager())
                ->get('core', 'outbox.max_per_tenant_per_batch', 10000));
        } catch (Throwable) {
            return 10000;
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function dispatch(array $event): void
    {
        // Set the event's tenant context so derived actions and listeners operate
        // in the correct tenant (e.g. the right search/embedding index).
        // Session-wide, since processing is sequential; cleared again in the
        // finally so no tenant leaks into the next event.
        $tenantId = $event['tenant_id'] !== null ? (string)$event['tenant_id'] : '';
        $this->connection()->execute(
            "SELECT set_config('app.current_tenant_id', :t, false)",
            ['t' => $tenantId],
        );
        try {
            $this->dispatchInner($event);
        } finally {
            $this->connection()->execute("SELECT set_config('app.current_tenant_id', '', false)");
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function dispatchInner(array $event): void
    {
        $payload = json_decode((string)$event['payload'], true) ?: [];
        $context = [
            'event_id' => $event['id'],
            'contract_name' => $event['contract_name'],
            'attempt' => (int)$event['attempt_count'],
            'correlation_id' => $event['correlation_id'],
        ];

        $errors = [];
        // Outbound webhooks (P05): idempotently enqueue matching subscriptions
        // (UNIQUE(subscription_id,event_id)); a failure here lets the event be
        // retried (at-least-once, enqueuing is idempotent).
        try {
            (new \App\Service\Webhook\WebhookService())
                ->enqueueForEvent((string)$event['contract_name'], $payload, (string)$event['id']);
        } catch (Throwable $e) {
            $errors[] = 'webhook-enqueue: ' . $e->getMessage();
        }
        // Evaluate automation/workflow rules exactly once, gated by the persisted
        // `derived_done` flag. The actions AND setting the flag run in **one**
        // transaction: a crash in between rolls back both, so the reclaim runs them
        // exactly once (no duplicate notification / no duplicate follow-up event).
        // Handled engine errors stay isolated (the flag commits regardless); a
        // normal listener retry does not re-trigger the actions.
        if (!(bool)$event['derived_done']) {
            $this->connection()->transactional(function () use ($event, $payload): void {
                try {
                    (new \App\Service\Automation\AutomationEngine())
                        ->onEvent((string)$event['contract_name'], $payload);
                } catch (Throwable $e) {
                    $this->log('Automation-Fehler: ' . $e->getMessage());
                }
                try {
                    (new \App\Service\Automation\WorkflowEngine())
                        ->onEvent((string)$event['contract_name'], $payload);
                } catch (Throwable $e) {
                    $this->log('Workflow-Fehler: ' . $e->getMessage());
                }
                $this->connection()->execute(
                    'UPDATE event_outbox SET derived_done = true WHERE id = :id',
                    ['id' => $event['id']],
                );
            });
        }
        $runtime = new \App\Service\Module\ContributionRuntime($this->registry);
        foreach ($runtime->listeners((string)$event['contract_name']) as $listener) {
            try {
                // In-process locally, out_of_process via the isolated host (RPC).
                // Worker context has no active user -> bypass for the listener.
                $runtime->call($listener, 'handle', [$payload, $context], ['bypass' => true]);
            } catch (Throwable $e) {
                // Isolation: a failure in one listener does not stop the others.
                $errors[] = $listener['class'] . ': ' . $e->getMessage();
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

    /** Resets stuck 'processing' events (e.g. after a worker crash). */
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
     * Continuous loop: processes existing events, then waits for new ones via
     * LISTEN/NOTIFY (low latency) or a poll timeout (fallback).
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

        // Module-host supervision not on every poll cycle but throttled
        // (DB query + socket checks per isolated module are otherwise expensive).
        $lastSupervision = 0.0;
        $supervisionEverySec = 30.0;

        while ($this->running) {
            try {
                // Heartbeat (ch. 20.3): every cycle logs its run including run
                // duration and expected interval (for the >2× interval overdue
                // warning in the health/dashboard, ch. 20.3).
                $cycleStart = microtime(true);
                // Make newly activated module listeners loadable (step 7).
                ModuleAutoloader::registerActiveModules();
                $reclaimed = $this->reclaimStuck();
                if ($reclaimed > 0) {
                    $this->log("$reclaimed haengende Events zurueckgesetzt.");
                }
                // Tick periodic module tasks (extension point, ch. 20.4) –
                // error-isolated, so a module job does not stop the worker.
                try {
                    $ran = (new \App\Service\Schedule\ScheduledTaskRunner())->tick();
                    if ($ran !== []) {
                        $this->log('Scheduled tasks: ' . implode(', ', $ran));
                    }
                } catch (Throwable $e) {
                    $this->log('Scheduler-Fehler: ' . $e->getMessage());
                }
                // Supervise out-of-process module hosts (self-healing, ch. 23.16.2):
                // restart crashed hosts, stop orphaned ones — throttled.
                if ($cycleStart - $lastSupervision >= $supervisionEverySec) {
                    $lastSupervision = $cycleStart;
                    try {
                        $sup = new \App\Service\Module\ModuleHostSupervisor();
                        $started = $sup->ensureAll();
                        $sup->reapStale();
                        if ($started !== []) {
                            $this->log('Modulhosts gestartet: ' . implode(', ', $started));
                        }
                    } catch (Throwable $e) {
                        $this->log('Modulhost-Supervision-Fehler: ' . $e->getMessage());
                    }
                    // Observability (#12), throttled: OTLP metric push (if
                    // OTEL_EXPORTER_OTLP_ENDPOINT is set) + health alert on a
                    // status change. Error-isolated.
                    try {
                        $otlp = new \App\Service\Observability\OtlpMetricsExporter();
                        if ($otlp->available()) {
                            $otlp->export();
                        }
                        if ((new \App\Service\Observability\HealthAlertService())->check()) {
                            $this->log('Health-Alarm gesendet (Statuswechsel).');
                        }
                    } catch (Throwable $e) {
                        $this->log('Observability-Fehler: ' . $e->getMessage());
                    }
                }
                // Fully drain the existing events.
                $processed = 0;
                while ($this->running && ($n = $this->processBatch()) > 0) {
                    $processed += $n;
                }
                // Deliver due webhook deliveries (P05) — capped per cycle
                // (external HTTP calls), the rest follows in the next cycle.
                $deliveredWebhooks = 0;
                try {
                    $deliveredWebhooks = (new \App\Service\Webhook\WebhookService())->deliverPending(25);
                } catch (Throwable $e) {
                    $this->log('Webhook-Zustellung-Fehler: ' . $e->getMessage());
                }
                \App\Service\Health\WorkerHeartbeat::beat('outbox', 'ok', [
                    'duration_ms' => (int)round((microtime(true) - $cycleStart) * 1000),
                    'interval_seconds' => (int)round($this->pollMs / 1000),
                    'processed' => $processed,
                    'webhooks_delivered' => $deliveredWebhooks,
                ]);
                // Wait for NOTIFY or timeout.
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

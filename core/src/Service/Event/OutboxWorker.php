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
        // Mandanten-Fairness (Pool-Modell, gegen Noisy-Neighbor): Events werden je
        // Mandant nach Fälligkeit gerankt und **round-robin** ausgewählt (alle rn=1
        // zuerst, dann rn=2 …), sodass kein einzelner Mandant den Worker monopolisiert.
        // `perTenant` begrenzt zusätzlich, wie viele Events EIN Mandant pro Batch
        // beisteuern darf. FOR UPDATE SKIP LOCKED bleibt erhalten (Multi-Worker), indem
        // die gerankten Kandidaten-IDs anschließend an der Basistabelle gesperrt werden.
        $perTenant = $this->maxPerTenantPerBatch();
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
                SELECT id, created_at FROM candidates
                WHERE rn <= :perTenant
                ORDER BY rn, available_at
                LIMIT :limit
            ),
            due AS (
                SELECT o.id, o.created_at FROM event_outbox o
                JOIN picked p ON p.id = o.id AND p.created_at = o.created_at
                WHERE o.status = 'pending' AND o.available_at <= now()
                FOR UPDATE SKIP LOCKED
            )
            UPDATE event_outbox o
            SET status = 'processing', locked_at = now(), attempt_count = o.attempt_count + 1
            FROM due
            WHERE o.id = due.id AND o.created_at = due.created_at
            RETURNING o.id, o.contract_name, o.payload, o.attempt_count, o.max_attempts, o.correlation_id, o.derived_done, o.tenant_id
            SQL,
            ['limit' => $limit, 'perTenant' => $perTenant],
        )->fetchAll('assoc');

        foreach ($rows as $event) {
            $this->dispatch($event);
        }

        return count($rows);
    }

    /** Pro-Mandant-Obergrenze an Events je Batch (Fairness/Quota). */
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
        // Mandantenkontext des Events setzen, damit abgeleitete Aktionen und
        // Listener im richtigen Mandanten arbeiten (z. B. korrekter Such-/Embedding-
        // Index). Session-weit, da Verarbeitung sequenziell ist; im finally wieder
        // geleert, damit kein Mandant ins nächste Event leckt.
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
        // Outbound-Webhooks (P05): passende Subscriptions idempotent einreihen
        // (UNIQUE(subscription_id,event_id)); ein Fehler hier lässt das Event
        // erneut versuchen (at-least-once, Einreihen ist idempotent).
        try {
            (new \App\Service\Webhook\WebhookService())
                ->enqueueForEvent((string)$event['contract_name'], $payload, (string)$event['id']);
        } catch (Throwable $e) {
            $errors[] = 'webhook-enqueue: ' . $e->getMessage();
        }
        // Automations-/Workflow-Regeln genau einmal auswerten, gesteuert über das
        // persistierte `derived_done`-Flag. Aktionen UND das Setzen des Flags
        // laufen in **einer** Transaktion: ein Absturz dazwischen rollt beides
        // zurück, sodass der Reclaim sie genau einmal ausführt (keine doppelte
        // Benachrichtigung / kein doppeltes Folge-Event). Behandelte Engine-Fehler
        // bleiben isoliert (das Flag committet trotzdem); ein normaler Listener-
        // Retry löst die Aktionen nicht erneut aus.
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
                // In-Process lokal, out_of_process über den isolierten Host (RPC).
                // Worker-Kontext ohne laufenden Benutzer -> Bypass für den Listener.
                $runtime->call($listener, 'handle', [$payload, $context], ['bypass' => true]);
            } catch (Throwable $e) {
                // Isolation: Fehler eines Listeners stoppt die anderen nicht.
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

        // Modulhost-Supervision nicht in jedem Poll-Zyklus, sondern gedrosselt
        // (DB-Query + Socket-Checks je isoliertem Modul sind sonst teuer).
        $lastSupervision = 0.0;
        $supervisionEverySec = 30.0;

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
                // Periodische Modul-Aufgaben (Andock-Punkt, Kap. 20.4) ticken –
                // fehlerisoliert, damit ein Modul-Job den Worker nicht stoppt.
                try {
                    $ran = (new \App\Service\Schedule\ScheduledTaskRunner())->tick();
                    if ($ran !== []) {
                        $this->log('Scheduled tasks: ' . implode(', ', $ran));
                    }
                } catch (Throwable $e) {
                    $this->log('Scheduler-Fehler: ' . $e->getMessage());
                }
                // Out-of-Process-Modulhosts überwachen (Selbstheilung, Kap. 23.16.2):
                // abgestürzte Hosts neu starten, verwaiste stoppen — gedrosselt.
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
                    // Observability (#12), gedrosselt: OTLP-Metrik-Push (falls
                    // OTEL_EXPORTER_OTLP_ENDPOINT gesetzt) + Health-Alert bei
                    // Statuswechsel. Fehlerisoliert.
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
                // Vorhandene Events vollständig abarbeiten.
                $processed = 0;
                while ($this->running && ($n = $this->processBatch()) > 0) {
                    $processed += $n;
                }
                // Fällige Webhook-Zustellungen (P05) zustellen — pro Zyklus
                // begrenzt (externe HTTP-Aufrufe), der Rest folgt im nächsten Zyklus.
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

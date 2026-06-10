<?php
declare(strict_types=1);

namespace App\Service\Automation;

use App\Audit\AuditLogger;
use App\Service\Event\OutboxPublisher;
use App\Service\Notification\NotificationService;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Automations-Engine (Programm Tier-2, P12): wertet beim Event-Dispatch
 * passende, aktive Regeln aus (Event-Muster → Bedingung → Aktionen).
 *
 * Aktionen: `notify` (Benachrichtigung an einen Benutzer) und `event`
 * (weiteres Outbox-Event publizieren → löst Listener/Webhooks aus). Fehler sind
 * isoliert (eine fehlerhafte Regel stoppt die anderen nicht).
 */
class AutomationEngine
{
    private ?ActionExecutor $executor = null;

    public function __construct(
        private ?ConditionEvaluator $evaluator = null,
        private ?NotificationService $notifications = null,
        private ?OutboxPublisher $outbox = null,
        private ?AuditLogger $audit = null,
    ) {
        $this->evaluator ??= new ConditionEvaluator();
    }

    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    /**
     * Wertet alle passenden, aktiven Regeln für ein Event aus und führt deren
     * Aktionen aus. Gibt die Anzahl ausgelöster Regeln zurück.
     *
     * @param array<string,mixed> $payload
     */
    public function onEvent(string $event, array $payload): int
    {
        $rules = $this->conn()->execute(
            'SELECT id, name, event, condition, actions FROM automation_rules WHERE active',
        )->fetchAll('assoc');

        $fired = 0;
        foreach ($rules as $rule) {
            if (!$this->matches((string)$rule['event'], $event)) {
                continue;
            }
            try {
                $condition = (array)(json_decode((string)$rule['condition'], true) ?: []);
                if (!$this->evaluator->evaluate($condition, $payload)) {
                    continue;
                }
                $actions = (array)(json_decode((string)$rule['actions'], true) ?: []);
                ($this->executor ??= new ActionExecutor($this->notifications, $this->outbox))->run($actions, $payload);
                $fired++;
            } catch (Throwable) {
                // Regelfehler isolieren.
            }
        }

        return $fired;
    }

    /** Event-Muster: exakt, `*` (alle) oder `prefix.*`. */
    private function matches(string $pattern, string $event): bool
    {
        if ($pattern === '*' || $pattern === $event) {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            return str_starts_with($event, substr($pattern, 0, -1)); // "core." Prefix inkl. Punkt
        }

        return false;
    }
}

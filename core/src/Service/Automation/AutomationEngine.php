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
                $this->runActions($actions, $payload);
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

    /**
     * @param list<array<string,mixed>> $actions
     * @param array<string,mixed> $payload
     */
    private function runActions(array $actions, array $payload): void
    {
        foreach ($actions as $action) {
            $type = (string)($action['type'] ?? '');
            try {
                match ($type) {
                    'notify' => $this->actionNotify($action, $payload),
                    'event' => $this->actionEvent($action, $payload),
                    default => null,
                };
            } catch (Throwable) {
                // Aktionsfehler isolieren.
            }
        }
    }

    /**
     * @param array<string,mixed> $action
     * @param array<string,mixed> $payload
     */
    private function actionNotify(array $action, array $payload): void
    {
        $userId = (string)($action['user_id'] ?? $this->fromPayload((string)($action['user_field'] ?? 'user_id'), $payload));
        if ($userId === '') {
            return;
        }
        ($this->notifications ??= new NotificationService())->notify(
            $userId,
            (string)($action['notify_type'] ?? 'automation'),
            $this->interpolate((string)($action['title'] ?? 'Automatisierung'), $payload),
            $this->interpolate((string)($action['body'] ?? ''), $payload),
        );
    }

    /**
     * @param array<string,mixed> $action
     * @param array<string,mixed> $payload
     */
    private function actionEvent(array $action, array $payload): void
    {
        $contract = (string)($action['contract'] ?? '');
        if ($contract === '') {
            return;
        }
        ($this->outbox ??= new OutboxPublisher())->publish($contract, (array)($action['payload'] ?? $payload));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function fromPayload(string $path, array $payload): string
    {
        $value = $payload;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return '';
            }
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Ersetzt `{{pfad}}`-Platzhalter aus der Nutzlast.
     *
     * @param array<string,mixed> $payload
     */
    private function interpolate(string $text, array $payload): string
    {
        return (string)preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $m) use ($payload): string {
            return $this->fromPayload($m[1], $payload);
        }, $text);
    }
}

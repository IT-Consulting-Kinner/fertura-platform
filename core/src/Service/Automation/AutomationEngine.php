<?php
declare(strict_types=1);

namespace App\Service\Automation;

use App\Service\Event\OutboxPublisher;
use App\Service\Notification\NotificationService;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Automation engine (program tier-2, P12): on event dispatch, evaluates the
 * matching, active rules (event pattern → condition → actions).
 *
 * Actions: `notify` (notification to a user) and `event` (publish a further
 * outbox event → triggers listeners/webhooks). Failures are isolated (a faulty
 * rule does not stop the others).
 */
class AutomationEngine
{
    private ?ActionExecutor $executor = null;

    public function __construct(
        private ?ConditionEvaluator $evaluator = null,
        private ?NotificationService $notifications = null,
        private ?OutboxPublisher $outbox = null,
    ) {
        $this->evaluator ??= new ConditionEvaluator();
    }

    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    /**
     * Evaluates all matching, active rules for an event and runs their actions.
     * Returns the number of fired rules.
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
                // Isolate rule failures.
            }
        }

        return $fired;
    }

    /** Event pattern: exact, `*` (all) or `prefix.*`. */
    private function matches(string $pattern, string $event): bool
    {
        if ($pattern === '*' || $pattern === $event) {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            return str_starts_with($event, substr($pattern, 0, -1)); // "core." prefix incl. the dot
        }

        return false;
    }
}

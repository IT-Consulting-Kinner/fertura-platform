<?php
declare(strict_types=1);

namespace App\Service\Automation;

use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Workflow state-machine engine (program tier-2; deferred P12 expansion).
 *
 * On event dispatch, evaluates active definitions: per business object
 * (resolved via `entity_id_field` from the payload) an instance holds the
 * state. If a transition matches (`from` == current state or `*`, `on_event` ==
 * event, condition satisfied), the state changes and the actions run (via the
 * {@see ActionExecutor}). One transition per event and instance; failures
 * isolated.
 */
class WorkflowEngine
{
    public function __construct(
        private ?ConditionEvaluator $evaluator = null,
        private ?ActionExecutor $executor = null,
    ) {
        $this->evaluator ??= new ConditionEvaluator();
        $this->executor ??= new ActionExecutor();
    }

    private function conn(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    /**
     * @param array<string,mixed> $payload
     * @return int number of state transitions
     */
    public function onEvent(string $event, array $payload): int
    {
        $defs = $this->conn()->execute(
            'SELECT id, entity_id_field, initial_state, transitions FROM workflow_definitions WHERE active',
        )->fetchAll('assoc');

        $advanced = 0;
        foreach ($defs as $def) {
            try {
                $transitions = (array)(json_decode((string)$def['transitions'], true) ?: []);
                $forEvent = array_values(array_filter($transitions, static fn($t) => (string)($t['on_event'] ?? '') === $event));
                if ($forEvent === []) {
                    continue;
                }
                $entityId = $this->executor->fromPayload((string)$def['entity_id_field'], $payload);
                if ($entityId === '') {
                    continue;
                }
                $instance = $this->getOrCreate((string)$def['id'], $entityId, (string)$def['initial_state']);
                foreach ($forEvent as $t) {
                    $from = (string)($t['from'] ?? '*');
                    if (
                        ($from !== '*' && $from !== $instance['state'])
                        || !$this->evaluator->evaluate((array)($t['condition'] ?? []), $payload)
                    ) {
                        continue;
                    }
                    $to = (string)($t['to'] ?? $instance['state']);
                    // Atomic compare-and-swap on the observed state: with
                    // concurrent workers only ONE transitions (no double
                    // transition/double action from a race).
                    if (!$this->transition((string)$instance['id'], (string)$instance['state'], $to)) {
                        break; // another worker was faster
                    }
                    $this->executor->run((array)($t['actions'] ?? []), $payload + [
                        'workflow' => ['entity_id' => $entityId, 'from' => $instance['state'], 'to' => $to],
                    ]);
                    $advanced++;
                    break; // one transition per event/instance
                }
            } catch (Throwable) {
                // Isolate definition failures.
            }
        }

        return $advanced;
    }

    public function stateOf(string $definitionId, string $entityId): ?string
    {
        $row = $this->conn()->execute(
            'SELECT state FROM workflow_instances WHERE definition_id = :d AND entity_id = :e',
            ['d' => $definitionId, 'e' => $entityId],
        )->fetch('assoc');

        return $row === false ? null : (string)$row['state'];
    }

    /**
     * @return array{id:string,state:string}
     */
    private function getOrCreate(string $definitionId, string $entityId, string $initialState): array
    {
        $this->conn()->execute(
            'INSERT INTO workflow_instances (definition_id, entity_id, state) VALUES (:d, :e, :s) '
            . 'ON CONFLICT (definition_id, entity_id) DO NOTHING',
            ['d' => $definitionId, 'e' => $entityId, 's' => $initialState],
        );
        $row = $this->conn()->execute(
            'SELECT id, state FROM workflow_instances WHERE definition_id = :d AND entity_id = :e',
            ['d' => $definitionId, 'e' => $entityId],
        )->fetch('assoc');

        return ['id' => (string)$row['id'], 'state' => (string)$row['state']];
    }

    /** Atomic state change; true only if the expected source state held. */
    private function transition(string $instanceId, string $from, string $to): bool
    {
        $stmt = $this->conn()->execute(
            'UPDATE workflow_instances SET state = :to WHERE id = :id AND state = :from',
            ['to' => $to, 'id' => $instanceId, 'from' => $from],
        );

        return $stmt->rowCount() === 1;
    }
}

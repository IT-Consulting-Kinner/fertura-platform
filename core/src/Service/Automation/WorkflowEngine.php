<?php
declare(strict_types=1);

namespace App\Service\Automation;

use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use Throwable;

/**
 * Workflow-State-Machine-Engine (Programm Tier-2; zurückgestellter P12-Ausbau).
 *
 * Wertet beim Event-Dispatch aktive Definitionen aus: je Geschäftsobjekt
 * (aufgelöst über `entity_id_field` aus der Nutzlast) führt eine Instanz den
 * Zustand. Passt eine Transition (`from` == aktueller Zustand bzw. `*`,
 * `on_event` == Event, Bedingung erfüllt), wechselt der Zustand und die
 * Aktionen laufen (über den {@see ActionExecutor}). Eine Transition pro Event
 * und Instanz; Fehler isoliert.
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
     * @return int Anzahl Zustandsübergänge
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
                $forEvent = array_values(array_filter($transitions, static fn ($t) => (string)($t['on_event'] ?? '') === $event));
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
                    if (($from !== '*' && $from !== $instance['state'])
                        || !$this->evaluator->evaluate((array)($t['condition'] ?? []), $payload)) {
                        continue;
                    }
                    $to = (string)($t['to'] ?? $instance['state']);
                    $this->setState((string)$instance['id'], $to);
                    $this->executor->run((array)($t['actions'] ?? []), $payload + [
                        'workflow' => ['entity_id' => $entityId, 'from' => $instance['state'], 'to' => $to],
                    ]);
                    $advanced++;
                    break; // eine Transition pro Event/Instanz
                }
            } catch (Throwable) {
                // Definitionsfehler isolieren.
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

    private function setState(string $instanceId, string $state): void
    {
        $this->conn()->execute(
            'UPDATE workflow_instances SET state = :s WHERE id = :id',
            ['s' => $state, 'id' => $instanceId],
        );
    }
}

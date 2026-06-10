<?php
declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLogger;
use App\Service\Automation\WorkflowEngine;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * Verwaltung der Workflow-State-Machines (P12-Ausbau) auf der Konsole.
 *
 *   bin/cake workflow list
 *   bin/cake workflow add --name X --entity-type order --entity-field data.order_id \
 *       --initial new --transitions '[{"from":"new","on_event":"order.paid","to":"paid"}]'
 *   bin/cake workflow rm <id>
 *   bin/cake workflow status <definitionId> <entityId>
 */
class WorkflowCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Workflow-State-Machines verwalten (P12-Ausbau).');
        $parser->addArgument('action', ['help' => 'list|add|rm|status', 'required' => true]);
        $parser->addArgument('id', ['help' => 'Definition-ID (rm/status)', 'required' => false]);
        $parser->addArgument('entity', ['help' => 'Entity-ID (status)', 'required' => false]);
        $parser->addOption('name', ['help' => 'Name']);
        $parser->addOption('entity-type', ['help' => 'Entitätstyp']);
        $parser->addOption('entity-field', ['help' => 'Pfad zur Entity-ID in der Nutzlast', 'default' => 'entity_id']);
        $parser->addOption('initial', ['help' => 'Anfangszustand']);
        $parser->addOption('transitions', ['help' => 'Transitionen als JSON-Array', 'default' => '[]']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $conn = ConnectionManager::get('default');
        $action = (string)$args->getArgument('action');
        $id = (string)$args->getArgument('id');

        switch ($action) {
            case 'list':
                foreach ($conn->execute('SELECT id, name, entity_type, initial_state, active FROM workflow_definitions ORDER BY created_at')->fetchAll('assoc') as $r) {
                    $io->out(sprintf('%s  [%s]  %s (%s, start=%s)', $r['id'], $r['active'] ? 'aktiv' : 'inaktiv', $r['name'], $r['entity_type'], $r['initial_state']));
                }

                return self::CODE_SUCCESS;

            case 'add':
                $name = (string)$args->getOption('name');
                $type = (string)$args->getOption('entity-type');
                $initial = (string)$args->getOption('initial');
                $transitions = (string)$args->getOption('transitions');
                if ($name === '' || $type === '' || $initial === '') {
                    $io->error('--name, --entity-type, --initial erforderlich.');

                    return self::CODE_ERROR;
                }
                if (!is_array(json_decode($transitions, true))) {
                    $io->error('--transitions muss ein JSON-Array sein.');

                    return self::CODE_ERROR;
                }
                $row = $conn->execute(
                    'INSERT INTO workflow_definitions (name, entity_type, entity_id_field, initial_state, transitions) '
                    . 'VALUES (:n, :et, :ef, :i, CAST(:t AS jsonb)) RETURNING id',
                    ['n' => $name, 'et' => $type, 'ef' => (string)$args->getOption('entity-field'), 'i' => $initial, 't' => $transitions],
                )->fetch('assoc');
                (new AuditLogger())->log('workflow.create', 'workflow_definition', (string)$row['id'], ['name' => $name]);
                $io->success('Workflow angelegt: ' . $row['id']);

                return self::CODE_SUCCESS;

            case 'rm':
                if ($id === '') {
                    $io->error('ID erforderlich.');

                    return self::CODE_ERROR;
                }
                $conn->execute('DELETE FROM workflow_definitions WHERE id = :id', ['id' => $id]);
                $io->success('Gelöscht.');

                return self::CODE_SUCCESS;

            case 'status':
                $entity = (string)$args->getArgument('entity');
                if ($id === '' || $entity === '') {
                    $io->error('Definition-ID und Entity-ID erforderlich.');

                    return self::CODE_ERROR;
                }
                $state = (new WorkflowEngine())->stateOf($id, $entity);
                $io->out($state ?? '(keine Instanz)');

                return self::CODE_SUCCESS;

            default:
                $io->error("Unbekannte Aktion: $action");

                return self::CODE_ERROR;
        }
    }
}

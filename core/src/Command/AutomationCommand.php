<?php
declare(strict_types=1);

namespace App\Command;

use App\Audit\AuditLogger;
use App\Service\Tenant\CliTenantContext;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;
use RuntimeException;

/**
 * Console management of automation rules (Programme Tier-2, P12).
 *
 *   bin/cake automation list
 *   bin/cake automation add --name X --event "core.notification.created" \
 *       --condition '{"field":"data.priority","op":"eq","value":"high"}' \
 *       --actions '[{"type":"notify","user_field":"user_id","title":"Wichtig: {{title}}"}]'
 *   bin/cake automation on|off|rm <id>
 */
class AutomationCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Automations-Regeln (Event-Condition-Action) verwalten (P12).');
        $parser->addArgument('action', ['help' => 'list|add|on|off|rm', 'required' => true]);
        $parser->addArgument('id', ['help' => 'Regel-ID (on/off/rm)', 'required' => false]);
        $parser->addOption('name', ['help' => 'Regelname']);
        $parser->addOption('event', ['help' => 'Event-Muster (exakt, * oder prefix.*)']);
        $parser->addOption('condition', ['help' => 'Bedingung als JSON', 'default' => '{}']);
        $parser->addOption('actions', ['help' => 'Aktionen als JSON-Array', 'default' => '[]']);
        $parser->addOption('tenant', ['help' => 'Mandant (Key oder UUID; Default = Default-Mandant)']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('default');
        // Rules are tenant-scoped (RLS); establish the tenant context so list
        // reads, and add/on/off/rm writes, operate within the right tenant.
        $tenantOption = $args->getOption('tenant');
        try {
            CliTenantContext::apply($conn, is_string($tenantOption) ? $tenantOption : null);
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());

            return self::CODE_ERROR;
        }
        $action = (string)$args->getArgument('action');
        $id = (string)$args->getArgument('id');

        switch ($action) {
            case 'list':
                $rows = $conn->execute(
                    'SELECT id, name, event, active FROM automation_rules ORDER BY created_at',
                )->fetchAll('assoc');
                foreach ($rows as $r) {
                    $io->out(sprintf('%s  [%s]  %s  <- %s', $r['id'], $r['active'] ? 'aktiv' : 'inaktiv', $r['name'], $r['event']));
                }

                return self::CODE_SUCCESS;

            case 'add':
                $name = (string)$args->getOption('name');
                $event = (string)$args->getOption('event');
                if ($name === '' || $event === '') {
                    $io->error('--name und --event erforderlich.');

                    return self::CODE_ERROR;
                }
                $condition = (string)$args->getOption('condition');
                $actions = (string)$args->getOption('actions');
                if (json_decode($condition, true) === null && trim($condition) !== '{}' && trim($condition) !== 'null') {
                    $io->error('--condition ist kein gültiges JSON.');

                    return self::CODE_ERROR;
                }
                if (!is_array(json_decode($actions, true))) {
                    $io->error('--actions muss ein JSON-Array sein.');

                    return self::CODE_ERROR;
                }
                $row = $conn->execute(
                    'INSERT INTO automation_rules (name, event, condition, actions) '
                    . 'VALUES (:n, :e, CAST(:c AS jsonb), CAST(:a AS jsonb)) RETURNING id',
                    ['n' => $name, 'e' => $event, 'c' => $condition, 'a' => $actions],
                )->fetch('assoc');
                (new AuditLogger())->log('automation.create', 'automation_rule', (string)$row['id'], ['name' => $name, 'event' => $event]);
                $io->success('Regel angelegt: ' . $row['id']);

                return self::CODE_SUCCESS;

            case 'on':
            case 'off':
                if ($id === '') {
                    $io->error('ID erforderlich.');

                    return self::CODE_ERROR;
                }
                $conn->execute(
                    'UPDATE automation_rules SET active = :a WHERE id = :id',
                    ['a' => $action === 'on' ? 'true' : 'false', 'id' => $id],
                );
                $io->success('OK.');

                return self::CODE_SUCCESS;

            case 'rm':
                if ($id === '') {
                    $io->error('ID erforderlich.');

                    return self::CODE_ERROR;
                }
                $conn->execute('DELETE FROM automation_rules WHERE id = :id', ['id' => $id]);
                $io->success('Gelöscht.');

                return self::CODE_SUCCESS;

            default:
                $io->error("Unbekannte Aktion: $action");

                return self::CODE_ERROR;
        }
    }
}

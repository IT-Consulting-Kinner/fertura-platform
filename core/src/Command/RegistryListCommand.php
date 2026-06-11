<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

/**
 * Shows the registered contracts and their active registrations
 * (read-only admin/debug view until the GUI arrives in Step 10).
 */
class RegistryListCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $connection = ConnectionManager::get('default');

        $contracts = $connection->execute(
            'SELECT id, name, contract_type, version, owner_module_key, active '
            . 'FROM contracts ORDER BY name',
        )->fetchAll('assoc');

        if (!$contracts) {
            $io->out('Keine Contracts registriert.');

            return static::CODE_SUCCESS;
        }

        foreach ($contracts as $c) {
            $flag = $c['active'] ? '' : ' (inaktiv)';
            $io->out(sprintf(
                "<info>%s</info>  [%s v%s, owner=%s]%s",
                $c['name'],
                $c['contract_type'],
                $c['version'],
                $c['owner_module_key'],
                $flag,
            ));

            $regs = $connection->execute(
                'SELECT module_key, registration_type, implementation_class, priority, active '
                . 'FROM contract_registrations WHERE contract_id = :id '
                . 'ORDER BY registration_type, priority DESC',
                ['id' => $c['id']],
            )->fetchAll('assoc');

            foreach ($regs as $r) {
                $io->out(sprintf(
                    '    - %-22s %s%s%s',
                    $r['registration_type'],
                    $r['module_key'],
                    $r['implementation_class'] ? ' (' . $r['implementation_class'] . ')' : '',
                    $r['active'] ? '' : ' [inaktiv]',
                ));
            }
        }

        return static::CODE_SUCCESS;
    }
}

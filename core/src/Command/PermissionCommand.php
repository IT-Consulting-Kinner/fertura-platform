<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Permission\PermissionService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Datasource\ConnectionManager;

/**
 * BREAD-Rechteverwaltung (bis zur Admin-GUI in Step 10).
 *
 *   bin/cake permission check <userId> <module> <type> <action> [--key K]
 *   bin/cake permission grant <groupId> <module> <type> <BREAD> [--key K] [--actions a,b]
 *   bin/cake permission revoke <groupId> <module> <type> [--key K]
 *   bin/cake permission resources
 */
class PermissionCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', ['choices' => ['check', 'grant', 'revoke', 'resources'], 'required' => true])
            ->addArgument('subject', ['help' => 'userId (check) oder groupId (grant/revoke)'])
            ->addArgument('module', ['help' => 'module_key'])
            ->addArgument('type', ['help' => 'resource_type'])
            ->addArgument('action_or_bread', ['help' => 'Aktion (check) oder BREAD-Buchstaben (grant)'])
            ->addOption('key', ['help' => 'resource_key (Einzelobjekt)'])
            ->addOption('actions', ['help' => 'Zusatzaktionen (kommagetrennt, grant)']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $service = new PermissionService();
        $key = $args->getOption('key');

        switch ($args->getArgument('operation')) {
            case 'resources':
                $rows = ConnectionManager::get('default')->execute(
                    'SELECT module_key, resource_type, resource_name, is_scoped FROM resources ORDER BY module_key, resource_name',
                )->fetchAll('assoc');
                foreach ($rows as $r) {
                    $io->out(sprintf('  %-16s %-12s %-28s scoped=%s', $r['module_key'], $r['resource_type'], $r['resource_name'], $r['is_scoped'] ? 'ja' : 'nein'));
                }

                return static::CODE_SUCCESS;

            case 'check':
                $ok = $service->canPerform(
                    (string)$args->getArgument('subject'),
                    (string)$args->getArgument('module'),
                    (string)$args->getArgument('type'),
                    $key,
                    (string)$args->getArgument('action_or_bread'),
                );
                $io->out($ok ? 'ALLOW' : 'DENY');

                return static::CODE_SUCCESS;

            case 'grant':
                $letters = strtoupper((string)$args->getArgument('action_or_bread'));
                $bread = [
                    'browse' => str_contains($letters, 'B'),
                    'read' => str_contains($letters, 'R'),
                    'add' => str_contains($letters, 'A'),
                    'edit' => str_contains($letters, 'E'),
                    'delete' => str_contains($letters, 'D'),
                ];
                $extra = [];
                if ($args->getOption('actions')) {
                    foreach (explode(',', (string)$args->getOption('actions')) as $a) {
                        $a = trim($a);
                        if ($a !== '') {
                            $extra[$a] = true;
                        }
                    }
                }
                $service->grant(
                    (string)$args->getArgument('subject'),
                    (string)$args->getArgument('module'),
                    (string)$args->getArgument('type'),
                    $key,
                    $bread,
                    $extra,
                );
                $io->success('Rechte vergeben.');

                return static::CODE_SUCCESS;

            case 'revoke':
                $service->revoke(
                    (string)$args->getArgument('subject'),
                    (string)$args->getArgument('module'),
                    (string)$args->getArgument('type'),
                    $key,
                );
                $io->success('Rechte entzogen.');

                return static::CODE_SUCCESS;
        }

        return static::CODE_ERROR;
    }
}

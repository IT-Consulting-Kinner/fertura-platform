<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Update\UpdateManager;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Throwable;

/**
 * Update-Manager (Kap. 28.8/28.9).
 *
 *   bin/cake update module <key> <neues_paketverzeichnis>
 *   bin/cake update core <zielversion> [--force]
 *   bin/cake update history
 */
class UpdateCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addArgument('operation', ['choices' => ['module', 'core', 'history'], 'required' => true])
            ->addArgument('target', ['help' => 'module_key oder Zielversion'])
            ->addArgument('source', ['help' => 'Paketverzeichnis (module-Update)'])
            ->addOption('force', ['boolean' => true, 'help' => 'Inkompatibilitäten überstimmen (core)']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $manager = new UpdateManager();

        try {
            switch ($args->getArgument('operation')) {
                case 'module':
                    $mod = $manager->updateModule((string)$args->getArgument('target'), (string)$args->getArgument('source'));
                    $io->success("Modul aktualisiert: {$mod['module_key']} -> v{$mod['version']} ({$mod['status']}).");
                    break;
                case 'core':
                    $r = $manager->updateCore((string)$args->getArgument('target'), (bool)$args->getOption('force'));
                    $io->success("Core-Update: {$r['old_version']} -> {$r['new_version']} (Recovery: {$r['recovery_point']}).");
                    break;
                case 'history':
                    foreach ($manager->listHistory() as $h) {
                        $io->out(sprintf('  %-7s %-20s %s -> %s  [%s]  %s', $h['component_type'], $h['component_key'], $h['old_version'] ?? '-', $h['new_version'] ?? '-', $h['result'], $h['executed_at']));
                    }
                    break;
            }
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }
}

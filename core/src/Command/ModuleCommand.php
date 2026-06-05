<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Module\LifecycleException;
use App\Service\Module\ModuleLifecycle;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Modul-Lifecycle-Verwaltung (bis zur Admin-GUI in Step 10).
 *
 *   bin/cake module list
 *   bin/cake module install /pfad/zum/modul
 *   bin/cake module activate <key>
 *   bin/cake module deactivate <key>
 *   bin/cake module delete <key>
 */
class ModuleCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Module installieren/aktivieren/deaktivieren/löschen/auflisten.')
            ->addArgument('operation', [
                'help' => 'list|install|activate|deactivate|delete',
                'choices' => ['list', 'install', 'activate', 'deactivate', 'delete'],
                'required' => true,
            ])
            ->addArgument('target', ['help' => 'Pfad (install) oder module_key']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $lifecycle = new ModuleLifecycle();
        $op = (string)$args->getArgument('operation');
        $target = $args->getArgument('target');

        try {
            switch ($op) {
                case 'list':
                    $mods = $lifecycle->listModules();
                    if (!$mods) {
                        $io->out('Keine Module installiert.');
                        break;
                    }
                    foreach ($mods as $m) {
                        $io->out(sprintf('  %-24s %-10s v%-8s [%s]', $m['module_key'], $m['type'], $m['version'], $m['status']));
                    }
                    break;
                case 'install':
                    $this->requireTarget($target);
                    $mod = $lifecycle->install((string)$target);
                    $io->success("Installiert: {$mod['module_key']} v{$mod['version']} ({$mod['status']}).");
                    break;
                case 'activate':
                    $this->requireTarget($target);
                    $mod = $lifecycle->activate((string)$target);
                    $io->success("Aktiviert: {$mod['module_key']} ({$mod['status']}).");
                    break;
                case 'deactivate':
                    $this->requireTarget($target);
                    $lifecycle->deactivate((string)$target);
                    $io->success("Deaktiviert: $target.");
                    break;
                case 'delete':
                    $this->requireTarget($target);
                    $lifecycle->delete((string)$target);
                    $io->success("Gelöscht: $target.");
                    break;
            }
        } catch (LifecycleException $e) {
            $io->error($e->getMessage());

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }

    private function requireTarget(mixed $target): void
    {
        if ($target === null || $target === '') {
            throw new LifecycleException('Argument "target" erforderlich (Pfad oder module_key).');
        }
    }
}

<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Module\LifecycleException;
use App\Service\Module\ModuleHostSupervisor;
use App\Service\Module\ModuleLifecycle;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Module lifecycle management.
 *
 *   bin/cake module list
 *   bin/cake module install /path/to/module [--isolation out_of_process]
 *   bin/cake module activate <key>
 *   bin/cake module deactivate <key>
 *   bin/cake module delete <key>
 *   bin/cake module isolate <key> <in_process|out_of_process>
 *   bin/cake module host <status|start|stop> [<key>]
 */
class ModuleCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Module installieren/aktivieren/deaktivieren/löschen/auflisten/isolieren.')
            ->addArgument('operation', [
                'help' => 'list|install|activate|deactivate|delete|isolate|host',
                'choices' => ['list', 'install', 'activate', 'deactivate', 'delete', 'isolate', 'host'],
                'required' => true,
            ])
            ->addArgument('target', ['help' => 'Pfad (install), module_key, oder host-Aktion (status|start|stop)'])
            ->addArgument('extra', ['help' => 'isolate: in_process|out_of_process; host start/stop: module_key'])
            ->addOption('isolation', [
                'help' => 'Isolationsmodus bei install',
                'choices' => ['in_process', 'out_of_process'],
                'default' => 'in_process',
            ]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $lifecycle = new ModuleLifecycle();
        $op = (string)$args->getArgument('operation');
        $target = $args->getArgument('target');
        $extra = (string)($args->getArgument('extra') ?? '');

        try {
            switch ($op) {
                case 'list':
                    $mods = $lifecycle->listModules();
                    if (!$mods) {
                        $io->out('Keine Module installiert.');
                        break;
                    }
                    foreach ($mods as $m) {
                        $io->out(sprintf(
                            '  %-24s %-10s v%-8s [%s] {%s}',
                            $m['module_key'],
                            $m['type'],
                            $m['version'],
                            $m['status'],
                            $m['isolation'] ?? 'in_process',
                        ));
                    }
                    break;
                case 'install':
                    $this->requireTarget($target);
                    $mod = $lifecycle->install((string)$target, (string)$args->getOption('isolation'));
                    $io->success("Installiert: {$mod['module_key']} v{$mod['version']} ({$mod['status']}, {$mod['isolation']}).");
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
                case 'isolate':
                    $this->requireTarget($target);
                    if ($extra === '') {
                        throw new LifecycleException('Modus erforderlich: in_process|out_of_process.');
                    }
                    $mod = $lifecycle->setIsolation((string)$target, $extra);
                    $io->success("Isolation: {$mod['module_key']} -> {$mod['isolation']}.");
                    break;
                case 'host':
                    return $this->host((string)($target ?? 'status'), $extra, $io);
            }
        } catch (LifecycleException $e) {
            $io->error($e->getMessage());

            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }

    private function host(string $action, string $key, ConsoleIo $io): int
    {
        $sup = new ModuleHostSupervisor();
        switch ($action) {
            case 'status':
                $rows = $sup->status();
                if ($rows === []) {
                    $io->out('Keine aktiven out_of_process-Module.');
                    break;
                }
                foreach ($rows as $r) {
                    $io->out(sprintf('  %-24s %s', $r['key'], $r['running'] ? 'läuft' : 'GESTOPPT'));
                }
                break;
            case 'start':
                if ($key !== '') {
                    $sup->ensureRunning($key);
                    $io->success("Host gestartet/aktiv: $key.");
                } else {
                    $started = $sup->ensureAll();
                    $io->success('Gestartet: ' . ($started === [] ? '(alle liefen bereits)' : implode(', ', $started)));
                }
                break;
            case 'stop':
                if ($key === '') {
                    $io->error('module host stop <key> erfordert einen Modulschlüssel.');

                    return static::CODE_ERROR;
                }
                $sup->stop($key);
                $io->success("Host gestoppt: $key.");
                break;
            default:
                $io->error("Unbekannte host-Aktion: $action (status|start|stop).");

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

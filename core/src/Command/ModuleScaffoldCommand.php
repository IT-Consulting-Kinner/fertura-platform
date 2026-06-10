<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Sdk\ModuleScaffolder;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Erzeugt ein lauffähiges Modul-Gerüst (Programm Tier-3, P16).
 *
 *   bin/cake module_scaffold mein_modul --namespace "Acme\\MeinModul" --into ./modules-dev
 */
class ModuleScaffoldCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Modul-Gerüst erzeugen (P16).');
        $parser->addArgument('key', ['help' => 'Modul-Key ([a-z][a-z0-9_]*)', 'required' => true]);
        $parser->addOption('namespace', ['help' => 'PHP-Namespace', 'default' => '']);
        $parser->addOption('into', ['help' => 'Zielverzeichnis', 'default' => '.']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $key = (string)$args->getArgument('key');
        $namespace = (string)$args->getOption('namespace');
        if ($namespace === '') {
            $namespace = 'Acme\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
        }
        try {
            $files = (new ModuleScaffolder())->scaffold($key, $namespace, (string)$args->getOption('into'));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return self::CODE_ERROR;
        }
        $io->success("Modul-Gerüst '$key' erzeugt:");
        foreach ($files as $f) {
            $io->out('  ' . $key . '/' . $f);
        }
        $io->out('Nächster Schritt: bin/cake module_lint ' . rtrim((string)$args->getOption('into'), '/') . '/' . $key);

        return self::CODE_SUCCESS;
    }
}

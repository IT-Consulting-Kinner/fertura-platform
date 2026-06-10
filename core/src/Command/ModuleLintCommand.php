<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Sdk\ManifestLinter;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Prüft ein Modul-Manifest statisch (Programm Tier-3, P16).
 *
 *   bin/cake module_lint ./mein_modul            # Verzeichnis
 *   bin/cake module_lint ./mein_modul/manifest.json
 */
class ModuleLintCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Modul-Manifest statisch prüfen (P16).');
        $parser->addArgument('path', ['help' => 'Modulverzeichnis oder manifest.json', 'required' => true]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $path = (string)$args->getArgument('path');
        if (is_dir($path)) {
            $path = rtrim($path, '/') . '/manifest.json';
        }
        $result = (new ManifestLinter())->lintFile($path);

        foreach ($result['warnings'] as $w) {
            $io->warning('WARN: ' . $w);
        }
        foreach ($result['errors'] as $e) {
            $io->error('FEHLER: ' . $e);
        }
        if ($result['errors'] === []) {
            $io->success('Manifest ok' . ($result['warnings'] === [] ? '.' : ' (mit Hinweisen).'));

            return self::CODE_SUCCESS;
        }

        return self::CODE_ERROR;
    }
}

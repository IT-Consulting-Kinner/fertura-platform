<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Module\ModuleManifest;
use App\Service\Sdk\ManifestLinter;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Statically checks a module manifest (Programme Tier-3, P16).
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
        $input = (string)$args->getArgument('path');
        $dir = is_dir($input) ? rtrim($input, '/\\') : dirname($input);
        $manifest = is_dir($input) ? $dir . '/manifest.json' : $input;

        $linter = new ManifestLinter();
        $manifestResult = $linter->lintFile($manifest);
        // Also scan the module's src/ for the per-module storage convention (Inc 8c).
        $sourceResult = $linter->lintSource($dir);
        // Advisory scan of migrations/ for the per-tenant table convention (Inc 9d),
        // using the manifest's declared module-global tables as the exception list.
        $manifestData = is_file($manifest) ? (json_decode((string)file_get_contents($manifest), true) ?: []) : [];
        $globalTables = (new ModuleManifest((array)$manifestData))->globalTables();
        $migrationResult = $linter->lintMigrations($dir, $globalTables);

        $warnings = array_merge($manifestResult['warnings'], $sourceResult['warnings'], $migrationResult['warnings']);
        $errors = array_merge($manifestResult['errors'], $sourceResult['errors'], $migrationResult['errors']);

        foreach ($warnings as $w) {
            $io->warning('WARN: ' . $w);
        }
        foreach ($errors as $e) {
            $io->error('FEHLER: ' . $e);
        }
        if ($errors === []) {
            $io->success('Modul ok' . ($warnings === [] ? '.' : ' (mit Hinweisen).'));

            return self::CODE_SUCCESS;
        }

        return self::CODE_ERROR;
    }
}

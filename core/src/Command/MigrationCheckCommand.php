<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Deploy\MigrationSafetyChecker;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * Prüft Migrationen auf zero-downtime-gefährdende Muster (Programm Tier-3, P15).
 *
 *   bin/cake migration_check          # Core-Migrationen prüfen (advisory)
 */
class MigrationCheckCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Migrationen auf destruktive/abwärts-inkompatible Muster prüfen (P15).');
        $parser->addOption('dir', ['help' => 'Migrationsverzeichnis', 'default' => CONFIG . 'Migrations']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $findings = (new MigrationSafetyChecker())->check((string)$args->getOption('dir'));
        if ($findings === []) {
            $io->success('Keine zero-downtime-kritischen Muster in den up()-Pfaden gefunden.');

            return self::CODE_SUCCESS;
        }

        $io->warning(count($findings) . ' Hinweis(e) — Expand/Contract erwägen:');
        foreach ($findings as $f) {
            $io->out(sprintf('  %s:%d  [%s]  %s', $f['file'], $f['line'], $f['issue'], $f['hint']));
        }
        // Advisory: kein Fehlercode (der Betreiber entscheidet bewusst).
        return self::CODE_SUCCESS;
    }
}

<?php
declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Db;
use App\Service\Update\RecoveryPoint;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Migrations\Migrations;
use Throwable;

/**
 * Migrationen mit verpflichtendem Wiederherstellungspunkt (Kap. 28.14.2).
 *
 * Wird beim Container-Start aufgerufen (Entrypoint): **nur wenn Migrationen
 * ausstehen**, wird zuerst ein Wiederherstellungspunkt (pg_dump) erzeugt, dann
 * migriert. So ist der „vorher"-Stand garantiert vorhanden, ohne dass der
 * Betreiber daran denken muss — und ohne bei jedem Neustart ohne Schemaänderung
 * unnötig zu dumpen. Scheitert der Wiederherstellungspunkt, wird **nicht**
 * migriert (Schema bleibt konsistent zum letzten gesicherten Stand).
 */
class CoreMigrateCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->addOption('skip-recovery', [
            'boolean' => true,
            'help' => 'Notfall: ohne Wiederherstellungspunkt migrieren (nicht empfohlen).',
        ]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $migrations = new Migrations(['connection' => Db::privilegedName()]);

        $pending = 0;
        try {
            foreach ($migrations->status() as $row) {
                if (($row['status'] ?? '') === 'down') {
                    $pending++;
                }
            }
        } catch (Throwable $e) {
            // z. B. allererster Boot ohne Tracking-Tabelle -> als ausstehend behandeln.
            $io->info('Migrationsstatus nicht lesbar, nehme ausstehende an: ' . $e->getMessage());
            $pending = 1;
        }

        if ($pending === 0) {
            $io->out('Keine ausstehenden Migrationen — kein Wiederherstellungspunkt nötig.');

            return static::CODE_SUCCESS;
        }
        $io->out($pending . ' ausstehende Migration(en).');

        if (!$args->getOption('skip-recovery')) {
            try {
                $path = (new RecoveryPoint())->create('boot_migrate');
                $io->success('Wiederherstellungspunkt erstellt: ' . $path);
            } catch (Throwable $e) {
                $io->error('Wiederherstellungspunkt fehlgeschlagen — Migration NICHT ausgeführt: ' . $e->getMessage());

                return static::CODE_ERROR;
            }
        } else {
            $io->warning('Wiederherstellungspunkt übersprungen (--skip-recovery).');
        }

        try {
            $migrations->migrate();
            $io->success('Migrationen ausgeführt.');

            return static::CODE_SUCCESS;
        } catch (Throwable $e) {
            $io->error('Migration fehlgeschlagen (transaktional zurückgerollt): ' . $e->getMessage());

            return static::CODE_ERROR;
        }
    }
}

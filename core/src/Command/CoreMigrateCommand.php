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
 * Migrations with a mandatory recovery point (ch. 28.14.2).
 *
 * Invoked on container start (entrypoint): **only when migrations are
 * pending** is a recovery point (pg_dump) created first, then the migrations
 * run. This guarantees the "before" state exists without the operator having
 * to remember it — and without dumping needlessly on every restart that
 * carries no schema change. If the recovery point fails, the migrations do
 * **not** run (the schema stays consistent with the last secured state).
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
            // e.g. very first boot without a tracking table -> treat as pending.
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
            // Clear the ORM metadata cache: a cached table schema would ignore
            // new columns in the ORM layer (e.g. INSERTs without the new column)
            // until the cache expires. So invalidate it after migrations.
            foreach (['_cake_model_', '_cake_translations_'] as $cfg) {
                try {
                    \Cake\Cache\Cache::clear($cfg);
                } catch (Throwable) {
                }
            }
            $io->success('Migrationen ausgeführt.');

            return static::CODE_SUCCESS;
        } catch (Throwable $e) {
            $io->error('Migration fehlgeschlagen (transaktional zurückgerollt): ' . $e->getMessage());

            return static::CODE_ERROR;
        }
    }
}

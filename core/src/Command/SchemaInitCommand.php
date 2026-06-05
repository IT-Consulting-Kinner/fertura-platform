<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

/**
 * Stellt das Core-Schema bereit, BEVOR der Migrations-Runner läuft.
 *
 * Hintergrund: Die Reflektion/Connection nutzt schema=core (DB_CONVENTIONS.md);
 * damit der Migrations-Runner seine Tracking-Tabelle (cake_migrations) in `core`
 * anlegen kann, muss das Schema vorab existieren (Henne-Ei auf frischer DB).
 * Wird im Container-Entrypoint vor `migrations migrate` aufgerufen.
 */
class SchemaInitCommand extends Command
{
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $connection = ConnectionManager::get('default');
        $connection->execute('CREATE SCHEMA IF NOT EXISTS core');
        $io->success('Schema "core" sichergestellt.');

        return static::CODE_SUCCESS;
    }
}

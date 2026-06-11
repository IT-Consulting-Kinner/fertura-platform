<?php
declare(strict_types=1);

namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Datasource\ConnectionManager;

/**
 * Provisions the Core schema BEFORE the migrations runner runs.
 *
 * Background: reflection/connection uses schema=core (DB_CONVENTIONS.md); for
 * the migrations runner to create its tracking table (cake_migrations) inside
 * `core`, the schema must already exist (a chicken-and-egg problem on a fresh
 * DB). Invoked from the container entrypoint before `migrations migrate`.
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

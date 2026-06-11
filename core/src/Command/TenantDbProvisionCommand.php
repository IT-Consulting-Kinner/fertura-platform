<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Tenant\TenantConnectionResolver;
use App\Service\Tenant\TenantService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Migrations\Migrations;
use Throwable;

/**
 * Provisions the **dedicated database** of a DB-isolated tenant (#10/4):
 * checks the out-of-band DSN (`TENANT_DB_<KEY>`), tests connectivity and runs
 * the migrations against it. Precondition (out of scope): the target DB already
 * exists, including schema/role bootstrap (`schema_init`, analogous to the main DB).
 */
class TenantDbProvisionCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Migriert die dedizierte Datenbank eines DB-isolierten Mandanten.')
            ->addArgument('key', ['help' => 'Mandanten-Schlüssel', 'required' => true]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $key = (string)$args->getArgument('key');
        $tenant = null;
        foreach ((new TenantService())->all() as $t) {
            if ($t['key'] === $key) {
                $tenant = $t;
                break;
            }
        }
        if ($tenant === null) {
            $io->error("Unbekannter Mandant: $key");

            return self::CODE_ERROR;
        }

        try {
            $conn = (new TenantConnectionResolver())->isolatedConnection($key);
            $conn->execute('SELECT 1'); // connectivity
            $io->success("Verbindung zu Mandanten-DB '$key' ok.");

            // Use the same connection name the resolver registered
            // (no second, divergent derivation of the name).
            (new Migrations(['connection' => $conn->configName()]))->migrate();
            $io->success("Migrationen auf Mandanten-DB '$key' ausgeführt.");

            return self::CODE_SUCCESS;
        } catch (Throwable $e) {
            $io->error('Provisionierung fehlgeschlagen: ' . $e->getMessage());
            $io->info('Voraussetzung: DB existiert + Schema-/Rollen-Bootstrap (vgl. TENANCY.md).');

            return self::CODE_ERROR;
        }
    }
}

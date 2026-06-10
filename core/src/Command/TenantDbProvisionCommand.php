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
 * Bereitet die **eigene Datenbank** eines DB-isolierten Mandanten vor (#10/4):
 * prüft die Out-of-Band-DSN (`TENANT_DB_<KEY>`), testet die Verbindung und führt
 * die Migrationen auf ihr aus. Voraussetzung (out of scope): die Ziel-DB existiert
 * inkl. Schema-/Rollen-Bootstrap (`schema_init` analog zur Haupt-DB).
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
            $conn->execute('SELECT 1'); // Konnektivität
            $io->success("Verbindung zu Mandanten-DB '$key' ok.");

            (new Migrations(['connection' => 'tenant_' . $key]))->migrate();
            $io->success("Migrationen auf Mandanten-DB '$key' ausgeführt.");

            return self::CODE_SUCCESS;
        } catch (Throwable $e) {
            $io->error('Provisionierung fehlgeschlagen: ' . $e->getMessage());
            $io->info('Voraussetzung: DB existiert + Schema-/Rollen-Bootstrap (vgl. TENANCY.md).');

            return self::CODE_ERROR;
        }
    }
}

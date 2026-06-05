<?php
declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Db;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;

/**
 * Provisioniert die NOBYPASSRLS-Anwendungsrolle und ihre Rechte (Entscheidung
 * E26 / Aufgabe „App-DB-Rolle ohne Superuser").
 *
 * Idempotent; läuft über die privilegierte (Superuser-)Connection. Wird vom
 * Entrypoint nach den Migrationen aufgerufen. Die Anwendung verbindet danach
 * über diese Rolle (APP_DATABASE_URL), sodass RLS zur Laufzeit greift.
 *
 *   bin/cake db_provision_app_role
 */
class DbProvisionAppRoleCommand extends Command
{
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Legt die NOBYPASSRLS-App-Rolle an und vergibt ihre Rechte (idempotent).');

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $role = (string)(env('APP_DB_USER') ?: Configure::read('App.dbAppRole') ?: 'fertura_app');
        $password = (string)(env('APP_DB_PASSWORD') ?: '');

        if (!preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            $io->error("Ungültiger Rollenname: $role");

            return static::CODE_ERROR;
        }
        if ($password === '') {
            $io->warning('APP_DB_PASSWORD nicht gesetzt -> Provisionierung übersprungen (App läuft als Superuser).');

            return static::CODE_SUCCESS;
        }

        $conn = Db::privileged();
        $pwLiteral = "'" . str_replace("'", "''", $password) . "'";

        // 1. Rolle anlegen/aktualisieren (NOBYPASSRLS, nur Login).
        $conn->execute(
            "DO \$do\$ BEGIN "
            . "IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$role') THEN "
            . "CREATE ROLE $role LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD $pwLiteral; "
            . "END IF; END \$do\$;",
        );
        $conn->execute("ALTER ROLE $role WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD $pwLiteral");

        // 2. Rechte im core-Schema (DML, keine DDL) + Default-Privilegien.
        $this->grantSchema($conn, 'core', $role);

        // 3. Bestehende Modul-Schemata (mod_*) ebenfalls berechtigen.
        $schemas = $conn->execute(
            "SELECT nspname FROM pg_namespace WHERE nspname LIKE 'mod\\_%'",
        )->fetchAll('assoc');
        foreach ($schemas as $s) {
            $this->grantSchema($conn, (string)$s['nspname'], $role);
        }

        $io->success("App-Rolle '$role' provisioniert (NOBYPASSRLS) inkl. Rechte auf core" .
            ($schemas === [] ? '' : ' + ' . count($schemas) . ' Modul-Schema(ta)') . '.');

        return static::CODE_SUCCESS;
    }

    private function grantSchema(\Cake\Database\Connection $conn, string $schema, string $role): void
    {
        $conn->execute("GRANT USAGE ON SCHEMA $schema TO $role");
        $conn->execute("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA $schema TO $role");
        $conn->execute("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA $schema TO $role");
        $conn->execute("GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA $schema TO $role");
        // Zukünftige Objekte (von der aktuellen, privilegierten Rolle erzeugt).
        $conn->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $role");
        $conn->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT USAGE, SELECT ON SEQUENCES TO $role");
        $conn->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT EXECUTE ON FUNCTIONS TO $role");
    }
}

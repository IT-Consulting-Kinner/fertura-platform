<?php
declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Db;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Database\Connection;

/**
 * Provisions the NOBYPASSRLS application role and its privileges (Decision
 * E26 / task "app DB role without superuser").
 *
 * Idempotent; runs over the privileged (superuser) connection. Invoked by the
 * entrypoint after the migrations. The application then connects through this
 * role (APP_DATABASE_URL), so that RLS takes effect at runtime.
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
        // Single-quoted literal with doubled quotes. NOT placed inside the dollar-
        // quoted DO block below: a password containing the tag `$do$` would otherwise
        // close the block early and run the trailing bytes as top-level SQL on the
        // superuser connection (peer-review F21). The password is set only via the
        // ALTER ROLE statement, which is NOT dollar-quoted, so this escaping suffices.
        $pwLiteral = "'" . str_replace("'", "''", $password) . "'";

        // 1. Create the role WITHOUT a password (NOBYPASSRLS, login only); the
        //    password is applied by the ALTER ROLE immediately afterwards.
        $conn->execute(
            'DO $do$ BEGIN '
            . "IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$role') THEN "
            . "CREATE ROLE $role LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS; "
            . 'END IF; END $do$;',
        );
        $conn->execute("ALTER ROLE $role WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD $pwLiteral");

        // 2. Privileges on the core schema (DML, no DDL) + default privileges.
        $this->grantSchema($conn, 'core', $role);

        // 3. Grant on existing module schemas (mod_*) as well.
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

    private function grantSchema(Connection $conn, string $schema, string $role): void
    {
        $conn->execute("GRANT USAGE ON SCHEMA $schema TO $role");
        $conn->execute("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA $schema TO $role");
        $conn->execute("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA $schema TO $role");
        $conn->execute("GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA $schema TO $role");
        // Future objects (created by the current, privileged role).
        $conn->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $role");
        $conn->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT USAGE, SELECT ON SEQUENCES TO $role");
        $conn->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT EXECUTE ON FUNCTIONS TO $role");
    }
}

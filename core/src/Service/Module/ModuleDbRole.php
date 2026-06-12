<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Infrastructure\Db;
use App\Service\Settings\SecretCipher;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use RuntimeException;

/**
 * Restricted DB role per out-of-process module (ch. 23.16.2, phase 2).
 *
 * An isolated module connects exclusively through its **own role** `mod_<key>`
 * (LOGIN, NOBYPASSRLS) with privileges **only on its own schema** plus EXECUTE
 * on a handful of core helper functions (UUID/timestamp/RLS context) — and **no**
 * access to core tables. The role password is generated randomly and stored
 * **AES-256-GCM-encrypted** in `core.modules.db_role_secret`; the isolated role
 * cannot read it (no access to core tables).
 *
 * RLS guarantee even for module-owned (role-owned) tables: after the migrations
 * the core sets `FORCE ROW LEVEL SECURITY` (otherwise the table owner would
 * bypass the policy).
 */
class ModuleDbRole
{
    /** Core helper functions that module schemas need in DEFAULTs/triggers/policies. */
    private const CORE_FUNCTIONS = [
        'core.uuid_generate_v7()',
        'core.set_updated_at()',
        'core.current_user_id()',
        'core.current_group_ids()',
        'core.rls_bypass()',
    ];

    private function conn(): Connection
    {
        return Db::privileged();
    }

    public function roleName(string $key): string
    {
        $this->assertSafe($key);

        return 'mod_' . $key;
    }

    private function assertSafe(string $key): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key)) {
            throw new RuntimeException("Ungültiger Modulschlüssel für DB-Rolle: $key");
        }
    }

    /**
     * Creates the role (or resets its password), grants the core function
     * privileges and stores the encrypted password on the module. Idempotent.
     * Returns the plaintext password (for the caller only).
     */
    public function provision(string $key): string
    {
        $role = $this->roleName($key);
        $password = bin2hex(random_bytes(24)); // only [0-9a-f] -> safe as an SQL literal
        $conn = $this->conn();

        $exists = $conn->execute('SELECT 1 FROM pg_roles WHERE rolname = :r', ['r' => $role])->fetch() !== false;
        $opts = "LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD '$password'";
        if ($exists) {
            $conn->execute("ALTER ROLE $role WITH $opts");
        } else {
            $conn->execute("CREATE ROLE $role WITH $opts");
        }

        // Minimal core access: schema USAGE (does NOT permit table SELECT) +
        // EXECUTE on the few helper functions.
        $conn->execute("GRANT USAGE ON SCHEMA core TO $role");
        foreach (self::CORE_FUNCTIONS as $fn) {
            $conn->execute("GRANT EXECUTE ON FUNCTION $fn TO $role");
        }

        $secret = (new SecretCipher())->encrypt($password);
        $conn->execute(
            'UPDATE modules SET db_role = :r, db_role_secret = :s WHERE module_key = :k',
            ['r' => $role, 's' => $secret, 'k' => $key],
        );

        return $password;
    }

    /** Safely quotes an SQL identifier (doubles any embedded quotes). */
    private function q(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    /** Grants the role the right to create objects in its own schema (migrations-as-role). */
    public function grantSchemaCreate(string $key): void
    {
        $role = $this->roleName($key); // validates $key
        $schema = 'mod_' . $key;
        $this->conn()->execute("GRANT CREATE, USAGE ON SCHEMA $schema TO $role");
    }

    /**
     * Grants the role runtime CRUD on an existing (superuser-owned) schema — for
     * switching an already installed module to out_of_process (the tables stay
     * superuser-owned, RLS applies as usual).
     */
    public function grantSchemaCrud(string $key): void
    {
        $role = $this->roleName($key); // validates $key
        $schema = 'mod_' . $key;
        $c = $this->conn();
        $c->execute("GRANT USAGE ON SCHEMA $schema TO $role");
        $c->execute("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA $schema TO $role");
        $c->execute("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA $schema TO $role");
        $c->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $role");
        $c->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT USAGE, SELECT ON SEQUENCES TO $role");
    }

    /**
     * Forces RLS on all RLS-enabled tables of the module schema, so the policy
     * also applies to the table owner (the module role).
     */
    public function forceRls(string $key): void
    {
        $this->assertSafe($key);
        $schema = 'mod_' . $key;
        $rows = $this->conn()->execute(
            'SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = :s AND c.relkind = 'r' AND c.relrowsecurity",
            ['s' => $schema],
        )->fetchAll('assoc');
        foreach ($rows as $r) {
            // The table name comes from the catalog -> quote it safely (the
            // module migration may have named it arbitrarily).
            $this->conn()->execute(
                'ALTER TABLE ' . $this->q($schema) . '.' . $this->q((string)$r['relname']) . ' FORCE ROW LEVEL SECURITY',
            );
        }
    }

    /** Builds the module role's PDO DSN (for the host process). Null if not provisioned. */
    public function dsn(string $key): ?string
    {
        $row = $this->conn()->execute(
            'SELECT db_role, db_role_secret FROM modules WHERE module_key = :k',
            ['k' => $key],
        )->fetch('assoc');
        if ($row === false || empty($row['db_role']) || empty($row['db_role_secret'])) {
            return null;
        }
        $password = (new SecretCipher())->decrypt((string)$row['db_role_secret']);
        $c = ConnectionManager::get('default')->config();
        $host = (string)($c['host'] ?? 'db');
        $port = (string)($c['port'] ?? '5432');
        $db = (string)($c['database'] ?? 'fertura');

        return sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;user=%s;password=%s',
            $host,
            $port,
            $db,
            (string)$row['db_role'],
            $password,
        );
    }

    /**
     * Builds the CakePHP connection URL of the module role (for the
     * ConnectionManager configuration in the isolated host, phase 3). Null if
     * not provisioned.
     */
    public function cakeUrl(string $key): ?string
    {
        $row = $this->conn()->execute(
            'SELECT db_role, db_role_secret FROM modules WHERE module_key = :k',
            ['k' => $key],
        )->fetch('assoc');
        if ($row === false || empty($row['db_role']) || empty($row['db_role_secret'])) {
            return null;
        }
        $password = (new SecretCipher())->decrypt((string)$row['db_role_secret']);
        $c = ConnectionManager::get('default')->config();

        return sprintf(
            'postgres://%s:%s@%s:%s/%s?encoding=utf8',
            rawurlencode((string)$row['db_role']),
            rawurlencode($password),
            (string)($c['host'] ?? 'db'),
            (string)($c['port'] ?? '5432'),
            (string)($c['database'] ?? 'fertura'),
        );
    }

    /** Removes the role together with its privileges (on uninstall). */
    public function drop(string $key): void
    {
        $role = $this->roleName($key);
        $conn = $this->conn();
        if ($conn->execute('SELECT 1 FROM pg_roles WHERE rolname = :r', ['r' => $role])->fetch() === false) {
            return;
        }
        // Detach privileges/ownership, then drop the role.
        $conn->execute("DROP OWNED BY $role CASCADE");
        $conn->execute("DROP ROLE IF EXISTS $role");
    }
}

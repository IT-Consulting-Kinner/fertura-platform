<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Infrastructure\Db;
use App\Service\Settings\SecretCipher;
use Cake\Datasource\ConnectionManager;
use RuntimeException;

/**
 * Eingeschränkte DB-Rolle je Out-of-Process-Modul (Kap. 23.16.2, Phase 2).
 *
 * Ein isoliertes Modul verbindet sich ausschließlich über eine **eigene Rolle**
 * `mod_<key>` (LOGIN, NOBYPASSRLS) mit Rechten **nur auf das eigene Schema** und
 * EXECUTE auf wenige Core-Hilfsfunktionen (UUID/Timestamp/RLS-Kontext) — **kein**
 * Zugriff auf Core-Tabellen. Das Rollenpasswort wird zufällig erzeugt und
 * **AES-256-GCM-verschlüsselt** in `core.modules.db_role_secret` abgelegt; die
 * isolierte Rolle kann es nicht lesen (kein Core-Tabellenzugriff).
 *
 * RLS-Garantie auch bei modul-eigenen (rollen-besessenen) Tabellen: Der Core
 * setzt nach den Migrationen `FORCE ROW LEVEL SECURITY` (sonst umginge der
 * Tabelleneigentümer die Policy).
 */
class ModuleDbRole
{
    /** Core-Hilfsfunktionen, die Modulschemata in DEFAULTs/Triggern/Policies brauchen. */
    private const CORE_FUNCTIONS = [
        'core.uuid_generate_v7()',
        'core.set_updated_at()',
        'core.current_user_id()',
        'core.current_group_ids()',
        'core.rls_bypass()',
    ];

    private function conn()
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
     * Legt die Rolle an (oder setzt das Passwort neu), erteilt die Core-
     * Funktionsrechte und speichert das verschlüsselte Passwort am Modul.
     * Idempotent. Gibt das Klartext-Passwort zurück (nur für den Aufrufer).
     */
    public function provision(string $key): string
    {
        $role = $this->roleName($key);
        $password = bin2hex(random_bytes(24)); // nur [0-9a-f] -> SQL-literal-sicher
        $conn = $this->conn();

        $exists = $conn->execute('SELECT 1 FROM pg_roles WHERE rolname = :r', ['r' => $role])->fetch() !== false;
        $opts = "LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD '$password'";
        if ($exists) {
            $conn->execute("ALTER ROLE $role WITH $opts");
        } else {
            $conn->execute("CREATE ROLE $role WITH $opts");
        }

        // Minimaler Core-Zugriff: Schema-USAGE (erlaubt KEIN Tabellen-SELECT) +
        // EXECUTE auf die wenigen Hilfsfunktionen.
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

    /** Erteilt der Rolle das Recht, im eigenen Schema Objekte anzulegen (Migrationen-als-Rolle). */
    public function grantSchemaCreate(string $key): void
    {
        $role = $this->roleName($key);
        $schema = 'mod_' . $key;
        $this->conn()->execute("GRANT CREATE, USAGE ON SCHEMA $schema TO $role");
    }

    /**
     * Erteilt der Rolle Laufzeit-CRUD auf ein bestehendes (Superuser-besessenes)
     * Schema — für den Wechsel eines bereits installierten Moduls auf
     * out_of_process (Tabellen bleiben Superuser-Eigentum, RLS greift natürlich).
     */
    public function grantSchemaCrud(string $key): void
    {
        $role = $this->roleName($key);
        $schema = 'mod_' . $key;
        $c = $this->conn();
        $c->execute("GRANT USAGE ON SCHEMA $schema TO $role");
        $c->execute("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA $schema TO $role");
        $c->execute("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA $schema TO $role");
        $c->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $role");
        $c->execute("ALTER DEFAULT PRIVILEGES IN SCHEMA $schema GRANT USAGE, SELECT ON SEQUENCES TO $role");
    }

    /**
     * Erzwingt RLS auf allen RLS-aktivierten Tabellen des Modulschemas, damit die
     * Policy auch für den Tabelleneigentümer (die Modul-Rolle) greift.
     */
    public function forceRls(string $key): void
    {
        $schema = 'mod_' . $key;
        $rows = $this->conn()->execute(
            'SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            . "WHERE n.nspname = :s AND c.relkind = 'r' AND c.relrowsecurity",
            ['s' => $schema],
        )->fetchAll('assoc');
        foreach ($rows as $r) {
            $this->conn()->execute("ALTER TABLE $schema." . $r['relname'] . ' FORCE ROW LEVEL SECURITY');
        }
    }

    /** Baut den PDO-DSN der Modul-Rolle (für den Host-Prozess). Null, wenn nicht provisioniert. */
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

    /** Entfernt die Rolle samt ihrer Rechte (bei Deinstallation). */
    public function drop(string $key): void
    {
        $role = $this->roleName($key);
        $conn = $this->conn();
        if ($conn->execute('SELECT 1 FROM pg_roles WHERE rolname = :r', ['r' => $role])->fetch() === false) {
            return;
        }
        // Rechte/Eigentum lösen, dann Rolle entfernen.
        $conn->execute("DROP OWNED BY $role CASCADE");
        $conn->execute("DROP ROLE IF EXISTS $role");
    }
}

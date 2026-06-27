<?php
declare(strict_types=1);

namespace App\Service\Module;

use App\Infrastructure\Db;
use Cake\Database\Connection;
use RuntimeException;

/**
 * Forces ROW LEVEL SECURITY on every RLS-enabled table of a module's schema
 * (`mod_<key>`), so the policy also binds the table owner — not just NOBYPASSRLS
 * grantees. Invoked at install/update time before the Inc 9c tenant-conformance gate,
 * which requires `relforcerowsecurity` on every tenant table; running it uniformly for
 * all modules keeps the gate's FORCE requirement satisfiable. Harmless for the common
 * case (the migration/owner role bypasses RLS anyway; the runtime app role is a
 * NOBYPASSRLS grantee already subject to the policy) and essential whenever the owning
 * role is itself NOBYPASSRLS.
 *
 * Extracted (verbatim) from the former per-module DB-role helper when the
 * out-of-process module isolation was removed (Inc 10), so the FORCE-RLS
 * guarantee — which the tenant-conformance gate depends on — keeps working.
 */
class ModuleTableRls
{
    private function conn(): Connection
    {
        return Db::privileged();
    }

    private function assertSafe(string $key): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $key)) {
            throw new RuntimeException("Ungültiger Modulschlüssel für Schema-RLS: $key");
        }
    }

    /** Safely quotes an SQL identifier (doubles any embedded quotes). */
    private function q(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    /**
     * Forces RLS on all RLS-enabled tables of the module schema, so the policy
     * also applies to the table owner.
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
}

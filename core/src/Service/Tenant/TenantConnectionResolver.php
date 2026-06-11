<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use Cake\Database\Connection;
use Cake\Database\Driver\Postgres;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;
use RuntimeException;
use function Cake\Core\env;

/**
 * Resolves a tenant's **database connection** (#10/4, DB-per-tenant).
 *
 * - Tenant without `db_isolated` (or no tenant) → shared `default` connection
 *   (pool model, shared schema with `tenant_id` scoping).
 * - Tenant with `db_isolated` → its own connection `tenant_<key>`, whose DSN comes
 *   **out-of-band** from the env `TENANT_DB_<KEY>` (no DB credentials in the DB).
 *   If the DSN is missing, it throws **fail-closed** (no silent sharing of the
 *   shared DB — otherwise the declared isolation would be undermined).
 *
 * Note (foundation): services/modules that want physical tenant isolation obtain
 * their connection through this resolver instead of hard-coding `get('default')`.
 * Fully automatic re-routing of **all** accesses is the production full build-out.
 */
class TenantConnectionResolver
{
    /** Connection for the CURRENT tenant (from the RLS context). */
    public function current(): ConnectionInterface
    {
        $row = ConnectionManager::get('default')->execute(
            "SELECT nullif(current_setting('app.current_tenant_id', true), '') AS t",
        )->fetch('assoc');

        return $this->for($row !== false && $row['t'] !== null && $row['t'] !== '' ? (string)$row['t'] : null);
    }

    public function for(?string $tenantId): ConnectionInterface
    {
        if ($tenantId === null) {
            return ConnectionManager::get('default');
        }
        $row = ConnectionManager::get('default')->execute(
            'SELECT key, db_isolated FROM tenants WHERE id = :id',
            ['id' => $tenantId],
        )->fetch('assoc');
        if ($row === false || !(bool)$row['db_isolated']) {
            return ConnectionManager::get('default');
        }

        return $this->isolatedConnection((string)$row['key']);
    }

    /** Ensures and returns the connection of a DB-isolated tenant. */
    public function isolatedConnection(string $key): ConnectionInterface
    {
        // Fail-closed against env-name collision: envKey() maps '-' to '_', so two
        // keys differing only by '-' vs. '_' (e.g. 'acme-eu' and 'acme_eu') would
        // collide on the same TENANT_DB_* env → two isolated tenants would land on
        // the same DB (cross-tenant leak). By forbidding '_' in isolated keys, the
        // mapping is injective.
        if (str_contains($key, '_')) {
            throw new RuntimeException(
                "DB-isolierter Mandanten-Key '$key' darf kein '_' enthalten "
                . "(Kollision der TENANT_DB_*-Env mit '-' ausgeschlossen).",
            );
        }
        $name = 'tenant_' . $key;
        if (!in_array($name, ConnectionManager::configured(), true)) {
            $dsn = trim((string)(env('TENANT_DB_' . $this->envKey($key)) ?: ''));
            if ($dsn === '') {
                throw new RuntimeException(
                    "Mandant '$key' ist DB-isoliert, aber die Env TENANT_DB_" . $this->envKey($key) . ' fehlt.',
                );
            }
            // Same connection profile as 'default' (DB_CONVENTIONS.md): schema
            // reflection and search_path on 'core' — otherwise unqualified
            // queries/migrations on the tenant DB hit the wrong (default) schema.
            ConnectionManager::setConfig($name, [
                'className' => Connection::class,
                'driver' => Postgres::class,
                'persistent' => false,
                'timezone' => 'UTC',
                'encoding' => 'utf8',
                'flags' => [],
                'cacheMetadata' => true,
                'quoteIdentifiers' => true,
                'log' => false,
                'schema' => 'core',
                'init' => ['SET search_path TO core, public'],
                'url' => $dsn,
            ]);
        }

        return ConnectionManager::get($name);
    }

    private function envKey(string $key): string
    {
        return strtoupper(str_replace('-', '_', $key));
    }
}

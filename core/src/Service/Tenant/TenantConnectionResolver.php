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
 * Löst die **Datenbank-Verbindung** eines Mandanten auf (#10/4, DB-pro-Mandant).
 *
 * - Mandant ohne `db_isolated` (oder kein Mandant) → geteilte `default`-Connection
 *   (Pool-Modell, shared schema mit `tenant_id`-Scoping).
 * - Mandant mit `db_isolated` → eigene Connection `tenant_<key>`, deren DSN
 *   **out-of-band** aus der Env `TENANT_DB_<KEY>` kommt (keine DB-Credentials in
 *   der DB). Fehlt die DSN, wird **fail-closed** geworfen (kein stilles Teilen der
 *   geteilten DB — sonst wäre die deklarierte Isolation unterlaufen).
 *
 * Hinweis (Fundament): Dienste/Module, die physische Mandanten-Isolation wollen,
 * holen ihre Connection über diesen Resolver statt fest über `get('default')`.
 * Ein vollautomatisches Re-Routing **aller** Zugriffe ist der Produktiv-Vollausbau.
 */
class TenantConnectionResolver
{
    /** Verbindung für den AKTUELLEN Mandanten (aus dem RLS-Kontext). */
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

    /** Stellt die Connection eines DB-isolierten Mandanten sicher und liefert sie. */
    public function isolatedConnection(string $key): ConnectionInterface
    {
        // Fail-closed gegen Env-Namens-Kollision: envKey() bildet '-' auf '_' ab,
        // also kollidierten zwei Keys, die sich nur durch '-' vs. '_' unterscheiden
        // (z. B. 'acme-eu' und 'acme_eu') auf dieselbe TENANT_DB_*-Env → zwei
        // isolierte Mandanten landeten auf derselben DB (Cross-Tenant-Leak). Indem
        // wir '_' in isolierten Keys verbieten, ist die Abbildung injektiv.
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
            // Gleiches Verbindungsprofil wie 'default' (DB_CONVENTIONS.md): Schema-
            // Reflektion und search_path auf 'core' — sonst greifen unqualifizierte
            // Abfragen/Migrationen auf der Mandanten-DB ins falsche (Default-)Schema.
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

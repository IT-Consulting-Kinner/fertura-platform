<?php
declare(strict_types=1);

namespace App\Service\Tenant;

use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;

/**
 * Verbindungs-Konvention für DB-pro-Mandant (#10/4). Macht die Trennung
 * **zentral vs. mandantendaten** explizit und ergonomisch — statt ein pauschales
 * Re-Aliasing von `default` (das den Auth-/Session-Bootstrap bräche).
 *
 * - {@see central()} — **zentrale, mandanten­übergreifende** Tabellen liegen IMMER
 *   auf der geteilten DB: `users`, `tenants`, `sessions`, `settings`, `audit_log`,
 *   `groups`/`groups_users`, `user_admin_areas`, `sso_providers`, `auth_failures`,
 *   `event_outbox`, Lizenz-/Trust-/Modul-Tabellen. Grund (Henne-Ei): Session/Auth/
 *   Benutzer→Mandant müssen aufgelöst sein, BEVOR ein Mandant geroutet werden kann.
 * - {@see data()} — **Mandanten-Geschäftsdaten** laufen bei `db_isolated` auf der
 *   eigenen DB des Mandanten (sonst auf der geteilten, Pool-Modell mit RLS/
 *   `tenant_id`-Scoping). Dienste/Module für Mandantendaten holen ihre Connection
 *   hierüber statt fest über `get('default')`.
 */
final class Tenancy
{
    public static function central(): ConnectionInterface
    {
        return ConnectionManager::get('default');
    }

    public static function data(): ConnectionInterface
    {
        return (new TenantConnectionResolver())->current();
    }
}

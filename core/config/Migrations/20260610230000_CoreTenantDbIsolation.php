<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * DB-pro-Mandant-Option (#10/4): Flag `tenants.db_isolated`. Ist es gesetzt, läuft
 * der Mandant auf einer **eigenen Datenbank** (stärkste Isolation) statt im
 * shared-schema-Pool. Die Verbindungs-DSN kommt **out-of-band** über die Env
 * `TENANT_DB_<KEY>` (kein DB-Credential in der DB), aufgelöst vom
 * `TenantConnectionResolver`. Default false → unverändertes Pool-Verhalten.
 */
class CoreTenantDbIsolation extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.tenants ADD COLUMN db_isolated boolean NOT NULL DEFAULT false');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.tenants DROP COLUMN IF EXISTS db_isolated');
    }
}

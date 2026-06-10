<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Optionale Host-Zuordnung für Mandanten (Pre-Auth-Mandantenauflösung): ein
 * Mandant kann eine eigene Domain tragen; zusätzlich greift die Konvention
 * `<key>.<basis-domain>` (Subdomain = Mandanten-Schlüssel). Damit lässt sich der
 * Mandant bereits VOR dem Login aus dem Request-Host bestimmen (mandantenspezifische
 * Login-/SSO-Oberfläche), unabhängig von Session/Cookie.
 */
class CoreTenantDomain extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.tenants ADD COLUMN domain text NULL');
        $this->execute(
            'CREATE UNIQUE INDEX uq_tenants_domain ON core.tenants (lower(domain)) WHERE domain IS NOT NULL',
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.uq_tenants_domain');
        $this->execute('ALTER TABLE core.tenants DROP COLUMN IF EXISTS domain');
    }
}

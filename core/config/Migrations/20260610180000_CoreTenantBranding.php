<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Mandantenspezifisches Branding (B6-Entscheidung „beides"): optionaler Anzeige-
 * name und Logo je Mandant, die auf der (pre-auth host-aufgelösten) Login-Seite
 * angezeigt werden. NULL = kein Branding (Core-Standard).
 */
class CoreTenantBranding extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.tenants ADD COLUMN brand_name text NULL');
        $this->execute('ALTER TABLE core.tenants ADD COLUMN logo_url text NULL');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.tenants DROP COLUMN IF EXISTS logo_url');
        $this->execute('ALTER TABLE core.tenants DROP COLUMN IF EXISTS brand_name');
    }
}

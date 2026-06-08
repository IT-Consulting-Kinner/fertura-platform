<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Out-of-Process-Isolation, Phase 2 (Kap. 23.16.2): pro isoliertem Modul eine
 * eigene, eingeschränkte DB-Rolle. `db_role` = Rollenname (`mod_<key>`),
 * `db_role_secret` = AES-256-GCM-verschlüsseltes Rollenpasswort (Core-only,
 * für die isolierte Rolle unlesbar, da kein Zugriff auf core.*).
 */
class CoreModuleDbRole extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.modules ADD COLUMN IF NOT EXISTS db_role text NULL');
        $this->execute('ALTER TABLE core.modules ADD COLUMN IF NOT EXISTS db_role_secret text NULL');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.modules DROP COLUMN IF EXISTS db_role_secret');
        $this->execute('ALTER TABLE core.modules DROP COLUMN IF EXISTS db_role');
    }
}

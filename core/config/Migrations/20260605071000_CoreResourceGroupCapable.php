<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Gruppenfähigkeit von Ressourcen (Kap. 25.11): Module kennzeichnen, ob eine
 * Ressource über Gruppen-BREAD-Rechte vergeben werden darf. Nicht gruppenfähige
 * Ressourcen erscheinen nicht im Gruppen-Rechte-Editor.
 */
class CoreResourceGroupCapable extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE core.resources ADD COLUMN IF NOT EXISTS group_capable boolean NOT NULL DEFAULT true',
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.resources DROP COLUMN IF EXISTS group_capable');
    }
}

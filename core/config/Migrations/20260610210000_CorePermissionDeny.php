<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Deny-Regeln für die BREAD-Rechte (Core-C-Thema „RBAC-Feinheit"). Ergänzt die
 * rein additive Vergabe um **explizite Verbote**: ein Deny auf einer der Gruppen
 * eines Benutzers überschreibt jede Erlaubnis (deny-wins). Ein Klassen-Deny
 * (resource_key NULL) verbietet auch die Objekte des Typs.
 *
 * Spalten am bestehenden Mapping (gleiche Zeile je Gruppe/Ressource), damit Allow
 * und Deny koexistieren; Default false → kein Verhaltensänderung im Bestand.
 */
class CorePermissionDeny extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            ALTER TABLE core.group_resource_permissions
                ADD COLUMN deny_browse boolean NOT NULL DEFAULT false,
                ADD COLUMN deny_read   boolean NOT NULL DEFAULT false,
                ADD COLUMN deny_add    boolean NOT NULL DEFAULT false,
                ADD COLUMN deny_edit   boolean NOT NULL DEFAULT false,
                ADD COLUMN deny_delete boolean NOT NULL DEFAULT false,
                ADD COLUMN deny_extra  jsonb   NULL
            SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
            ALTER TABLE core.group_resource_permissions
                DROP COLUMN IF EXISTS deny_browse,
                DROP COLUMN IF EXISTS deny_read,
                DROP COLUMN IF EXISTS deny_add,
                DROP COLUMN IF EXISTS deny_edit,
                DROP COLUMN IF EXISTS deny_delete,
                DROP COLUMN IF EXISTS deny_extra
            SQL);
    }
}

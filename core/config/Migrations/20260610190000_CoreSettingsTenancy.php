<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Pro-Mandant-Settings (Core-B-Thema): ein Setting kann global (tenant_id NULL)
 * **oder** je Mandant überschrieben werden. Die Auflösung bevorzugt den mandanten-
 * spezifischen Wert und fällt sonst auf den globalen Wert bzw. den Katalog-Default
 * zurück. Single-Org nutzt weiterhin nur globale Werte (NULL) — unverändert.
 */
class CoreSettingsTenancy extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE core.settings ADD COLUMN tenant_id uuid NULL '
            . 'REFERENCES core.tenants (id) ON DELETE CASCADE',
        );
        // Eindeutigkeit je (Namespace, Key, Mandant). NULL = global; per coalesce auf
        // eine Sentinel-UUID, damit höchstens EIN globaler Wert je Key existiert
        // (Postgres behandelt NULL sonst als verschieden).
        $this->execute('ALTER TABLE core.settings DROP CONSTRAINT uq_settings_ns_key');
        $this->execute(
            'CREATE UNIQUE INDEX uq_settings_ns_key ON core.settings '
            . "(namespace, config_key, coalesce(tenant_id, '00000000-0000-0000-0000-000000000000'::uuid))",
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.uq_settings_ns_key');
        $this->execute('DELETE FROM core.settings WHERE tenant_id IS NOT NULL');
        $this->execute('ALTER TABLE core.settings DROP COLUMN IF EXISTS tenant_id');
        $this->execute(
            'ALTER TABLE core.settings ADD CONSTRAINT uq_settings_ns_key UNIQUE (namespace, config_key)',
        );
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * i18n-6 (E41): Sprachverwaltung als 7. fester Administrationsbereich.
 */
class CoreLocalizationArea extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            INSERT INTO core.admin_areas (area_key, label, sort_order)
            VALUES ('localization', 'Sprachverwaltung', 70)
            ON CONFLICT (area_key) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.user_admin_areas WHERE admin_area_key = 'localization'");
        $this->execute("DELETE FROM core.admin_areas WHERE area_key = 'localization'");
    }
}

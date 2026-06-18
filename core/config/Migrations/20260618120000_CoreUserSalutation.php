<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Self-service profile: a `salutation` (Anrede) on core.users that the user edits
 * themselves via /account — decoupled from user administration. Free text (no
 * fixed list), nullable.
 */
class CoreUserSalutation extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.users ADD COLUMN salutation text NULL');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.users DROP COLUMN IF EXISTS salutation');
    }
}

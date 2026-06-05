<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Sicherheitsupdate-Kennzeichnung (Kap. 28.10): Module deklarieren im Manifest
 * `security: true` (+ optionale `severity`); die Update-Historie hält dies fest,
 * damit Sicherheitsupdates erkennbar/hervorgehoben sind.
 */
class CoreUpdateSecurityFlag extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.update_history ADD COLUMN IF NOT EXISTS is_security boolean NOT NULL DEFAULT false');
        $this->execute('ALTER TABLE core.update_history ADD COLUMN IF NOT EXISTS severity text NULL');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.update_history DROP COLUMN IF EXISTS severity');
        $this->execute('ALTER TABLE core.update_history DROP COLUMN IF EXISTS is_security');
    }
}

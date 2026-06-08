<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Out-of-Process-Modulausführung (Kap. 23.16, Phase 1): Isolationsmodus je Modul.
 * `in_process` (Standard) oder `out_of_process` (separater Modulprozess via RPC).
 */
class CoreModuleIsolation extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            "ALTER TABLE core.modules ADD COLUMN IF NOT EXISTS isolation text NOT NULL DEFAULT 'in_process'",
        );
        $this->execute('ALTER TABLE core.modules DROP CONSTRAINT IF EXISTS ck_modules_isolation');
        $this->execute(
            "ALTER TABLE core.modules ADD CONSTRAINT ck_modules_isolation "
            . "CHECK (isolation IN ('in_process','out_of_process'))",
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.modules DROP CONSTRAINT IF EXISTS ck_modules_isolation');
        $this->execute('ALTER TABLE core.modules DROP COLUMN IF EXISTS isolation');
    }
}

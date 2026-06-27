<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Drops the out-of-process module isolation columns (Inc 10): the platform unifies on
 * in-process modules trusted at install time (signature + review + capability gate), so
 * `core.modules.isolation` / `db_role` / `db_role_secret` and the
 * `core.module_install_jobs.isolation` column have no consumer left. The historical
 * add-migrations (20260608*) stay untouched; this is the forward drop, shipped only
 * after all code stopped reading the columns. CASCADE removes the CHECK constraints
 * that referenced the columns.
 */
class CoreDropModuleIsolation extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.modules DROP COLUMN IF EXISTS isolation CASCADE');
        $this->execute('ALTER TABLE core.modules DROP COLUMN IF EXISTS db_role CASCADE');
        $this->execute('ALTER TABLE core.modules DROP COLUMN IF EXISTS db_role_secret CASCADE');
        $this->execute('ALTER TABLE core.module_install_jobs DROP COLUMN IF EXISTS isolation CASCADE');
    }

    public function down(): void
    {
        // Rollback safety only — re-adds the columns defaulted; no out-of-process
        // machinery returns with them.
        $this->execute("ALTER TABLE core.modules ADD COLUMN IF NOT EXISTS isolation text NOT NULL DEFAULT 'in_process'");
        $this->execute('ALTER TABLE core.modules ADD COLUMN IF NOT EXISTS db_role text');
        $this->execute('ALTER TABLE core.modules ADD COLUMN IF NOT EXISTS db_role_secret text');
        $this->execute(
            "ALTER TABLE core.module_install_jobs ADD COLUMN IF NOT EXISTS isolation text NOT NULL DEFAULT 'in_process'",
        );
    }
}

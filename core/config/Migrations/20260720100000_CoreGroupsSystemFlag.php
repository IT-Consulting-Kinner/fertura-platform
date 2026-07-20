<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Protected system group marker (live finding): the bootstrap administrator group
 * (created by `bin/cake group_init` / `create_admin`, {@see \App\Service\Permission\AdminGroupService})
 * must not be deactivatable, and its permissions must not be editable in the GUI —
 * doing either can lock every admin out. A rename-stable `is_system` flag marks it,
 * so the guard does not depend on the group name at runtime.
 *
 * Backfills existing installs by the same name convention AdminGroupService uses
 * (case-insensitive "Administratoren"); AdminGroupService keeps it in sync going
 * forward (sets it on create and tops it up on an existing match).
 */
class CoreGroupsSystemFlag extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core."groups" ADD COLUMN is_system boolean NOT NULL DEFAULT false');
        $this->execute(
            'UPDATE core."groups" SET is_system = true WHERE lower(name) = lower(\'Administratoren\')',
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core."groups" DROP COLUMN IF EXISTS is_system');
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Allow `type = integration` in `core.modules` (E164, completes E162).
 *
 * E162 added the integration-extension / connector module type to manifest
 * validation and the linter, but the `modules` table still carried
 * `CHECK (type IN ('main','extension'))` — so a real connector install would
 * fail at the INSERT despite passing validation. Widen the constraint to include
 * `integration`.
 */
class CoreModuleTypeIntegration extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.modules DROP CONSTRAINT ck_modules_type');
        $this->execute(
            "ALTER TABLE core.modules ADD CONSTRAINT ck_modules_type "
            . "CHECK (type IN ('main', 'extension', 'integration'))",
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.modules DROP CONSTRAINT ck_modules_type');
        $this->execute(
            'ALTER TABLE core.modules ADD CONSTRAINT ck_modules_type '
            . "CHECK (type IN ('main', 'extension'))",
        );
    }
}

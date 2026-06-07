<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Externe API (Kap. 29): Scopes je API-Token + Audit-Spalten. Ein Token bindet
 * an einen Benutzer (Autorisierung über dessen Rechte); die Scopes schränken
 * zusätzlich ein, welche API-Fähigkeiten das Token nutzen darf.
 */
class CoreApiTokenScopes extends BaseMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE core.api_tokens ADD COLUMN IF NOT EXISTS scopes jsonb NOT NULL DEFAULT '[]'::jsonb");
        $this->execute('ALTER TABLE core.api_tokens ADD COLUMN IF NOT EXISTS created_by uuid NULL');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.api_tokens DROP COLUMN IF EXISTS scopes');
        $this->execute('ALTER TABLE core.api_tokens DROP COLUMN IF EXISTS created_by');
    }
}

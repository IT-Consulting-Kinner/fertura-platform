<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Mandant am Event mitführen (B6-Entscheidung): `event_outbox.tenant_id` hält den
 * Mandantenkontext, in dem ein Event publiziert wurde. Der Outbox-Worker setzt ihn
 * beim Verarbeiten als `app.current_tenant_id`, damit abgeleitete Aktionen
 * (Automation/Workflow) und Listener im **richtigen** Mandanten arbeiten (z. B. in
 * den korrekten Such-/Embedding-Index schreiben). NULL = systemweit/ohne Mandant.
 */
class CoreOutboxTenant extends BaseMigration
{
    public function up(): void
    {
        // event_outbox ist RANGE-partitioniert; ADD COLUMN am Parent propagiert.
        $this->execute('ALTER TABLE core.event_outbox ADD COLUMN tenant_id uuid NULL');
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE core.event_outbox DROP COLUMN IF EXISTS tenant_id');
    }
}

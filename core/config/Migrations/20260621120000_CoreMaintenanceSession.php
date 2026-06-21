<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Maintenance/quiesce mode — Phase 1: the DB-backed *leading state*
 * (docs/maintenance-mode-design.md). At most one open session at a time (partial
 * unique index on `status <> 'closed'`) records who engaged maintenance plus a
 * server-issued allow-token hash (so only the activator stays in). Read UNCACHED by
 * {@see \App\Service\System\MaintenanceService} — never via the settings cache,
 * whose +5min TTL would let other instances ignore the window — and changes fire
 * `pg_notify('core_maintenance')`. Platform-global (no tenant_id).
 */
class CoreMaintenanceSession extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.maintenance_session (
                id                uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                actor_user_id     uuid        NULL,
                allow_token_hash  text        NOT NULL,
                status            text        NOT NULL DEFAULT 'active',
                reason            text        NULL,
                opened_at         timestamptz NOT NULL DEFAULT now(),
                heartbeat_at      timestamptz NOT NULL DEFAULT now(),
                closed_at         timestamptz NULL,
                CONSTRAINT ck_maintenance_session_status CHECK (status IN ('active','operator_gone','closed'))
            )
            SQL);
        // At most one non-closed (active or operator_gone) session: the indexed
        // boolean is `true` for every non-closed row -> they all collide.
        $this->execute(
            "CREATE UNIQUE INDEX uq_maintenance_one_open ON core.maintenance_session ((status <> 'closed')) "
            . "WHERE status <> 'closed'",
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.maintenance_session');
    }
}

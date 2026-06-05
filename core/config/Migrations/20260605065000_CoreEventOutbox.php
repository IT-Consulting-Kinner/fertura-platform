<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Transaktionaler Event-Outbox (Step 6, Kap. 26.9.2 / 30.6, Entscheidung 168/177).
 *
 * Events werden in derselben DB-Transaktion wie die fachliche Änderung
 * geschrieben (atomar). Ein Worker stellt sie asynchron zu (LISTEN/NOTIFY +
 * Cron-Fallback), mindestens-einmal, mit Retry/Backoff und Dead-Letter.
 *
 * Zeitbereichs-partitioniert (created_at, monatlich) wie das Audit-Log (30.8).
 */
class CoreEventOutbox extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.event_outbox (
                id             uuid        NOT NULL DEFAULT core.uuid_generate_v7(),
                created_at     timestamptz NOT NULL DEFAULT now(),
                contract_name  text        NOT NULL,
                payload        jsonb       NOT NULL DEFAULT '{}'::jsonb,
                correlation_id uuid        NULL,
                status         text        NOT NULL DEFAULT 'pending',
                attempt_count  int         NOT NULL DEFAULT 0,
                max_attempts   int         NOT NULL DEFAULT 5,
                available_at   timestamptz NOT NULL DEFAULT now(),
                locked_at      timestamptz NULL,
                processed_at   timestamptz NULL,
                last_error     text        NULL,
                updated_at     timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, created_at),
                CONSTRAINT ck_outbox_status
                    CHECK (status IN ('pending','processing','done','dead_letter'))
            ) PARTITION BY RANGE (created_at)
            SQL);
        $this->execute('CREATE TABLE core.event_outbox_default PARTITION OF core.event_outbox DEFAULT');

        // Worker-Claim-Index: fällige, ausstehende Events.
        $this->execute(
            "CREATE INDEX ix_outbox_due ON core.event_outbox (available_at) WHERE status = 'pending'"
        );
        // Reclaim hängender 'processing'-Events.
        $this->execute(
            "CREATE INDEX ix_outbox_processing ON core.event_outbox (locked_at) WHERE status = 'processing'"
        );
        $this->execute('CREATE INDEX ix_outbox_contract ON core.event_outbox (contract_name, created_at DESC)');
        $this->execute('CREATE INDEX ix_outbox_status ON core.event_outbox (status)');

        $this->execute(
            'CREATE TRIGGER trg_outbox_set_updated_at BEFORE UPDATE ON core.event_outbox '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.event_outbox');
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Outbound-Webhooks (Programm Tier-1, P05): externe Zustellung von Plattform-
 * Events über HTTP.
 *
 * - `webhook_subscriptions`: vom Betreiber/Modul registrierte Ziele (URL, Event-
 *   Filter, HMAC-Geheimnis). „Deaktivieren statt löschen" über `active`.
 * - `webhook_deliveries`: pro (Subscription, Event) eine Zustell-Aufgabe — der
 *   Worker stellt sie zu (HMAC-signiert), mit Retry/Backoff und Dead-Letter,
 *   analog zur Event-Outbox. UNIQUE(subscription_id, event_id) macht das
 *   Einreihen **idempotent** (mehrfacher Dispatch erzeugt keine Doubletten).
 */
class CoreWebhooks extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.webhook_subscriptions (
                id            uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                name          text        NOT NULL,
                url           text        NOT NULL,
                event_filter  text        NOT NULL DEFAULT '*',
                secret        text        NULL,
                active        boolean     NOT NULL DEFAULT true,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute(
            'CREATE INDEX ix_webhook_subs_active ON core.webhook_subscriptions (active) WHERE active',
        );
        $this->execute(
            'CREATE TRIGGER trg_webhook_subs_updated_at BEFORE UPDATE ON core.webhook_subscriptions '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );

        $this->execute(<<<'SQL'
            CREATE TABLE core.webhook_deliveries (
                id               uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                subscription_id  uuid        NOT NULL REFERENCES core.webhook_subscriptions (id) ON DELETE CASCADE,
                event_id         uuid        NOT NULL,
                event_name       text        NOT NULL,
                payload          jsonb       NOT NULL DEFAULT '{}'::jsonb,
                status           text        NOT NULL DEFAULT 'pending',
                attempt_count    int         NOT NULL DEFAULT 0,
                max_attempts     int         NOT NULL DEFAULT 8,
                available_at     timestamptz NOT NULL DEFAULT now(),
                locked_at        timestamptz NULL,
                last_status_code int         NULL,
                last_error       text        NULL,
                delivered_at     timestamptz NULL,
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_webhook_delivery UNIQUE (subscription_id, event_id),
                CONSTRAINT ck_webhook_delivery_status
                    CHECK (status IN ('pending','delivering','done','dead_letter'))
            )
            SQL);
        $this->execute(
            "CREATE INDEX ix_webhook_deliveries_due ON core.webhook_deliveries (available_at) WHERE status = 'pending'",
        );
        $this->execute(
            'CREATE INDEX ix_webhook_deliveries_sub ON core.webhook_deliveries (subscription_id, created_at DESC)',
        );
        $this->execute(
            'CREATE TRIGGER trg_webhook_deliveries_updated_at BEFORE UPDATE ON core.webhook_deliveries '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.webhook_deliveries');
        $this->execute('DROP TABLE IF EXISTS core.webhook_subscriptions');
    }
}

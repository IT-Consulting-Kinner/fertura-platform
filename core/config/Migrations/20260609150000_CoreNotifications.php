<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Benachrichtigungs-Framework (Programm Tier-1, P09).
 *
 * - `notifications`: In-App-Benachrichtigungen je Benutzer (ungelesen/gelesen).
 * - `notification_prefs`: Kanal-Präferenzen je (Benutzer, Typ, Kanal) —
 *   `enabled=false` schaltet einen Standardkanal ab, `enabled=true` aktiviert
 *   einen Zusatzkanal (z. B. ein Modul-Kanal).
 * - Core-Contract `core.collector.notification_channel`: Module liefern
 *   zusätzliche Zustellkanäle (`NotificationChannelInterface`).
 */
class CoreNotifications extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.notifications (
                id         uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                user_id    uuid        NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
                type       text        NOT NULL,
                title      text        NOT NULL,
                body       text        NOT NULL DEFAULT '',
                data       jsonb       NOT NULL DEFAULT '{}'::jsonb,
                read_at    timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute(
            'CREATE INDEX ix_notifications_user_unread ON core.notifications (user_id, created_at DESC) '
            . 'WHERE read_at IS NULL',
        );

        $this->execute(<<<'SQL'
            CREATE TABLE core.notification_prefs (
                id         uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                user_id    uuid        NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
                type       text        NOT NULL,
                channel    text        NOT NULL,
                enabled    boolean     NOT NULL DEFAULT true,
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_notification_pref UNIQUE (user_id, type, channel)
            )
            SQL);
        $this->execute(
            'CREATE TRIGGER trg_notification_prefs_updated_at BEFORE UPDATE ON core.notification_prefs '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );

        $this->execute(<<<'SQL'
            INSERT INTO core.contracts
                (owner_module_key, name, contract_type, version, multi_use, active, description)
            VALUES
                ('core', 'core.collector.notification_channel', 'collector', '1.0.0', true, true,
                 'Zusätzliche Benachrichtigungs-Zustellkanäle der Module (NotificationChannelInterface).')
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        $this->execute("DELETE FROM core.contracts WHERE name = 'core.collector.notification_channel'");
        $this->execute('DROP TABLE IF EXISTS core.notification_prefs');
        $this->execute('DROP TABLE IF EXISTS core.notifications');
    }
}

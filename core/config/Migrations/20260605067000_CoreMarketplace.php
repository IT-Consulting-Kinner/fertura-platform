<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Marketplace / Lizenz / Update (Step 8, Kap. 28 / 24.9).
 *
 * - trust_anchors: Vertrauensanker (Root-/Publisher-Schlüssel, Ed25519).
 * - revoked_keys: Sperrliste (CRL-Cache).
 * - licenses: signierte Lizenzen (offline-first).
 * - update_history: Update-/Rollback-Protokoll.
 */
class CoreMarketplace extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.trust_anchors (
                id         uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                key_id     text        NOT NULL,
                public_key text        NOT NULL,
                key_type   text        NOT NULL DEFAULT 'root',
                publisher  text        NULL,
                signed_by  text        NULL,
                valid_from timestamptz NULL,
                valid_to   timestamptz NULL,
                active     boolean     NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_trust_anchors_key UNIQUE (key_id),
                CONSTRAINT ck_trust_anchors_type CHECK (key_type IN ('root','publisher'))
            )
            SQL);
        $this->execute(
            'CREATE TRIGGER trg_trust_anchors_set_updated_at BEFORE UPDATE ON core.trust_anchors '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );

        $this->execute(<<<'SQL'
            CREATE TABLE core.revoked_keys (
                id         uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                key_id     text        NOT NULL,
                reason     text        NULL,
                source     text        NOT NULL DEFAULT 'manual',
                revoked_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_revoked_keys_key UNIQUE (key_id)
            )
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE core.licenses (
                id                 uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                module_key         text        NOT NULL,
                license_data       jsonb       NOT NULL,
                signature          text        NOT NULL,
                signed_key_id      text        NOT NULL,
                valid_from         timestamptz NULL,
                valid_to           timestamptz NULL,
                grace_window_days  int         NULL,
                online_enforcement boolean     NOT NULL DEFAULT false,
                last_online_check  timestamptz NULL,
                status             text        NOT NULL DEFAULT 'active',
                installed_at       timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_licenses_module UNIQUE (module_key),
                CONSTRAINT ck_licenses_status CHECK (status IN ('active','expired','revoked'))
            )
            SQL);
        $this->execute(
            'CREATE TRIGGER trg_licenses_set_updated_at BEFORE UPDATE ON core.licenses '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()'
        );

        $this->execute(<<<'SQL'
            CREATE TABLE core.update_history (
                id             uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                component_type text        NOT NULL,
                component_key  text        NOT NULL,
                old_version    text        NULL,
                new_version    text        NULL,
                result         text        NOT NULL,
                error          text        NULL,
                recovery_point text        NULL,
                executed_at    timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_updhist_type CHECK (component_type IN ('core','module')),
                CONSTRAINT ck_updhist_result CHECK (result IN ('success','failed','rolled_back'))
            )
            SQL);
        $this->execute('CREATE INDEX ix_update_history_component ON core.update_history (component_type, component_key, executed_at DESC)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.update_history');
        $this->execute('DROP TABLE IF EXISTS core.licenses');
        $this->execute('DROP TABLE IF EXISTS core.revoked_keys');
        $this->execute('DROP TABLE IF EXISTS core.trust_anchors');
    }
}

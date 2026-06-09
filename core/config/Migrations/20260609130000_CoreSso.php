<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * SSO-Identitätsföderation (Programm Tier-1, P06): OIDC- und SAML-Login als
 * parallele Flows neben der lokalen Anmeldung (Break-Glass bleibt).
 *
 * - `sso_providers`: konfigurierte Identity Provider (Typ oidc/saml, nicht-
 *   geheime Konfig als JSONB, Client-Secret AES-verschlüsselt). „Deaktivieren
 *   statt löschen" über `active`.
 * - `identity_links`: verknüpft eine externe Identität (Provider + Subject) mit
 *   einem Core-Benutzer (Just-in-Time-Provisioning/Account-Linking).
 *   Identitäten/Autorisierung bleiben Core-verwaltet (Kap. 27.2.1).
 */
class CoreSso extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.sso_providers (
                id               uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                type             text        NOT NULL,
                name             text        NOT NULL,
                button_label     text        NOT NULL DEFAULT 'Single Sign-On',
                active           boolean     NOT NULL DEFAULT true,
                config           jsonb       NOT NULL DEFAULT '{}'::jsonb,
                secret_encrypted text        NULL,
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_sso_type CHECK (type IN ('oidc','saml'))
            )
            SQL);
        $this->execute(
            'CREATE INDEX ix_sso_providers_active ON core.sso_providers (active) WHERE active',
        );
        $this->execute(
            'CREATE TRIGGER trg_sso_providers_updated_at BEFORE UPDATE ON core.sso_providers '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );

        $this->execute(<<<'SQL'
            CREATE TABLE core.identity_links (
                id          uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                user_id     uuid        NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
                provider_id uuid        NOT NULL REFERENCES core.sso_providers (id) ON DELETE CASCADE,
                subject     text        NOT NULL,
                created_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_identity_link UNIQUE (provider_id, subject)
            )
            SQL);
        $this->execute('CREATE INDEX ix_identity_links_user ON core.identity_links (user_id)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.identity_links');
        $this->execute('DROP TABLE IF EXISTS core.sso_providers');
    }
}

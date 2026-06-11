<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Sicherheits-Kür (E130): **Passkeys/WebAuthn** als zweiter Faktor
 * (`user_webauthn_credentials`: Public Key + Sign-Counter je Credential) und
 * **bekannte Geräte** für die Session-Anomalie-Erkennung
 * (`user_known_devices`: Fingerprint aus IP+User-Agent; neues Gerät → Hinweis).
 */
class CoreWebauthnDevices extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.user_webauthn_credentials (
                id             uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                user_id        uuid        NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
                credential_id  text        NOT NULL,
                public_key_pem text        NOT NULL,
                sign_count     bigint      NOT NULL DEFAULT 0,
                label          text        NULL,
                created_at     timestamptz NOT NULL DEFAULT now(),
                last_used_at   timestamptz NULL,
                CONSTRAINT uq_webauthn_credential UNIQUE (credential_id)
            )
            SQL);
        $this->execute('CREATE INDEX ix_webauthn_user ON core.user_webauthn_credentials (user_id)');

        $this->execute(<<<'SQL'
            CREATE TABLE core.user_known_devices (
                id               uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                user_id          uuid        NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
                fingerprint_hash text        NOT NULL,
                first_seen_at    timestamptz NOT NULL DEFAULT now(),
                last_seen_at     timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_known_device UNIQUE (user_id, fingerprint_hash)
            )
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.user_known_devices');
        $this->execute('DROP TABLE IF EXISTS core.user_webauthn_credentials');
    }
}

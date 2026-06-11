<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * MFA (TOTP) für lokale Konten: `users.totp_secret` (AES-verschlüsselt via
 * SecretCipher, NIE Klartext) + `totp_enabled_at` als Aktiv-Flag, dazu
 * **einmal-verwendbare Recovery-Codes** (nur als SHA-256-Hash gespeichert).
 * SSO-Logins sind unberührt (MFA-Durchsetzung liegt beim IdP).
 */
class CoreUserMfa extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.users ADD COLUMN totp_secret text NULL');
        $this->execute('ALTER TABLE core.users ADD COLUMN totp_enabled_at timestamptz NULL');
        $this->execute(<<<'SQL'
            CREATE TABLE core.user_mfa_recovery_codes (
                id         uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                user_id    uuid        NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
                code_hash  text        NOT NULL,
                used_at    timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute('CREATE INDEX ix_mfa_recovery_user ON core.user_mfa_recovery_codes (user_id)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.user_mfa_recovery_codes');
        $this->execute('ALTER TABLE core.users DROP COLUMN IF EXISTS totp_enabled_at');
        $this->execute('ALTER TABLE core.users DROP COLUMN IF EXISTS totp_secret');
    }
}

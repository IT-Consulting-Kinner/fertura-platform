<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Einladungs-/Passwort-Setz-Token (Kap. 27.2/27.15): ermöglicht es, dass ein
 * über die GUI angelegter („invited") Benutzer ein Passwort setzt und aktiv
 * wird. Der E-Mail-Versand des Links ist Modul-Scope; der Core erzeugt den
 * Token und nimmt das gesetzte Passwort entgegen.
 *
 * Gespeichert wird nur der SHA-256-Hash des Tokens (kein Klartext in der DB).
 */
class CorePasswordResetTokens extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.password_reset_tokens (
                id         uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                user_id    uuid        NOT NULL,
                token_hash text        NOT NULL,
                purpose    text        NOT NULL DEFAULT 'reset',
                expires_at timestamptz NOT NULL,
                used_at    timestamptz NULL,
                created_by uuid        NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
                CONSTRAINT fk_prt_created_by FOREIGN KEY (created_by) REFERENCES core.users(id) ON DELETE SET NULL,
                CONSTRAINT uq_prt_hash UNIQUE (token_hash),
                CONSTRAINT ck_prt_purpose CHECK (purpose IN ('invite', 'reset'))
            )
            SQL);
        $this->execute('CREATE INDEX ix_prt_user ON core.password_reset_tokens (user_id)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.password_reset_tokens');
    }
}

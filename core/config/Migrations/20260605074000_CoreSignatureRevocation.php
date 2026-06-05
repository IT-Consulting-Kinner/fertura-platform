<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Nachträgliche Signatur-Widerrufe für installierte Module (Kap. 24.9.2) und
 * CRL-Cache-Zustand (Alter/Stale-Warnung).
 *
 * - modules.signature_key_id: Schlüssel, der das installierte Paket signierte.
 * - modules.signature_status: valid | revoked (wird bei CRL-Sync abgeglichen).
 * - core.marketplace_meta: Schlüssel/Wert-Zustand (u. a. letzter CRL-Abruf).
 */
class CoreSignatureRevocation extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.modules ADD COLUMN IF NOT EXISTS signature_key_id text NULL');
        $this->execute("ALTER TABLE core.modules ADD COLUMN IF NOT EXISTS signature_status text NOT NULL DEFAULT 'valid'");
        $this->execute(<<<'SQL'
            DO $do$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_modules_signature_status') THEN
                    ALTER TABLE core.modules ADD CONSTRAINT ck_modules_signature_status
                        CHECK (signature_status IN ('valid', 'revoked'));
                END IF;
            END $do$;
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE IF NOT EXISTS core.marketplace_meta (
                meta_key   text        NOT NULL PRIMARY KEY,
                meta_value text        NULL,
                updated_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.marketplace_meta');
        $this->execute('ALTER TABLE core.modules DROP CONSTRAINT IF EXISTS ck_modules_signature_status');
        $this->execute('ALTER TABLE core.modules DROP COLUMN IF EXISTS signature_status');
        $this->execute('ALTER TABLE core.modules DROP COLUMN IF EXISTS signature_key_id');
    }
}

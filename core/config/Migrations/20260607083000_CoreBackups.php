<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Core-Backup (Kap. 20.1, bewusste Abweichung „keine Systemfunktion" auf
 * Nutzer-Wunsch): Metadaten je Sicherung. Der Inhalt (DB-Dump + Datei-Stores)
 * liegt im Backup-Verzeichnis; die DB hält nur Verwaltungsdaten + Checksummen
 * für die Prüfbarkeit.
 */
class CoreBackups extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.backups (
                id           uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                created_at   timestamptz NOT NULL DEFAULT now(),
                core_version text        NOT NULL,
                status       text        NOT NULL DEFAULT 'creating',
                db_bytes     bigint      NULL,
                files_bytes  bigint      NULL,
                db_sha256    text        NULL,
                files_sha256 text        NULL,
                path         text        NOT NULL,
                note         text        NULL,
                created_by   uuid        NULL,
                CONSTRAINT ck_backups_status CHECK (status IN ('creating','complete','failed'))
            )
            SQL);
        $this->execute('CREATE INDEX ix_backups_created ON core.backups (created_at DESC)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.backups');
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Backup-Härtung (Kap. 20.1.2): Verschlüsselungs-/Validitäts-Flags je Sicherung
 * + Operationsprotokoll (Backups UND Restores), das in der GUI gelistet wird.
 */
class CoreBackupLog extends BaseMigration
{
    public function up(): void
    {
        $this->execute('ALTER TABLE core.backups ADD COLUMN IF NOT EXISTS encrypted boolean NOT NULL DEFAULT false');
        $this->execute('ALTER TABLE core.backups ADD COLUMN IF NOT EXISTS verified boolean NOT NULL DEFAULT false');

        $this->execute(<<<'SQL'
            CREATE TABLE core.backup_log (
                id            uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                occurred_at   timestamptz NOT NULL DEFAULT now(),
                operation     text        NOT NULL,
                backup_id     uuid        NULL,
                source        text        NOT NULL DEFAULT 'cli',
                actor_user_id uuid        NULL,
                result        text        NOT NULL DEFAULT 'ok',
                message       text        NULL,
                CONSTRAINT ck_backup_log_op CHECK (operation IN
                    ('create','restore','restore_from','delete','verify','test_restore','prune')),
                CONSTRAINT ck_backup_log_result CHECK (result IN ('ok','error'))
            )
            SQL);
        $this->execute('CREATE INDEX ix_backup_log_time ON core.backup_log (occurred_at DESC)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.backup_log');
        $this->execute('ALTER TABLE core.backups DROP COLUMN IF EXISTS encrypted');
        $this->execute('ALTER TABLE core.backups DROP COLUMN IF EXISTS verified');
    }
}

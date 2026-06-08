<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Backup-Log-Härtung: append-only (unveränderlich) + Operation `download`
 * (Datenexport ist eine protokollierungswürdige sensible Aktion).
 */
class CoreBackupLogHarden extends BaseMigration
{
    public function up(): void
    {
        // download als loggbare Operation zulassen.
        $this->execute('ALTER TABLE core.backup_log DROP CONSTRAINT IF EXISTS ck_backup_log_op');
        $this->execute(
            "ALTER TABLE core.backup_log ADD CONSTRAINT ck_backup_log_op CHECK (operation IN "
            . "('create','restore','restore_from','delete','verify','test_restore','prune','download'))",
        );

        // Unveränderlich (append-only), analog zum Audit-Log.
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.backup_log_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $func$
            BEGIN
                RAISE EXCEPTION 'core.backup_log ist append-only (% nicht erlaubt)', TG_OP;
            END
            $func$
            SQL);
        $this->execute(
            'CREATE TRIGGER trg_backup_log_immutable '
            . 'BEFORE UPDATE OR DELETE ON core.backup_log '
            . 'FOR EACH ROW EXECUTE FUNCTION core.backup_log_immutable()',
        );
    }

    public function down(): void
    {
        // Trigger zuerst entfernen, damit die Bereinigung möglich ist (das Log
        // ist sonst append-only).
        $this->execute('DROP TRIGGER IF EXISTS trg_backup_log_immutable ON core.backup_log');
        $this->execute('DROP FUNCTION IF EXISTS core.backup_log_immutable()');
        $this->execute('ALTER TABLE core.backup_log DROP CONSTRAINT IF EXISTS ck_backup_log_op');
        // Die Operation `download` existierte vor dieser Migration nicht; etwaige
        // währenddessen entstandene Zeilen entfernen, sonst verletzt das erneute
        // (engere) CHECK vorhandene Daten und die down-Migration bricht ab.
        $this->execute("DELETE FROM core.backup_log WHERE operation = 'download'");
        $this->execute(
            "ALTER TABLE core.backup_log ADD CONSTRAINT ck_backup_log_op CHECK (operation IN "
            . "('create','restore','restore_from','delete','verify','test_restore','prune'))",
        );
    }
}

<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tabelle für den DB-Treiber des generischen Job-Queue-Transports (#10). Reserve
 * über `FOR UPDATE SKIP LOCKED` (multi-consumer-sicher). Reservierte Jobs lassen
 * sich nach Timeout zurücksetzen (Crash-Recovery durch den Aufrufer).
 */
class CoreJobQueue extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.job_queue (
                id          uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                queue       text        NOT NULL,
                payload     jsonb       NOT NULL DEFAULT '{}'::jsonb,
                status      text        NOT NULL DEFAULT 'ready',
                reserved_at timestamptz NULL,
                created_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ck_job_status CHECK (status IN ('ready','reserved'))
            )
            SQL);
        $this->execute(
            "CREATE INDEX ix_job_queue_ready ON core.job_queue (queue, created_at) WHERE status = 'ready'",
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.job_queue');
    }
}

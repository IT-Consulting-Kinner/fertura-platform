<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Async GUI module installation (Audit Prio 1). One queued job per uploaded,
 * signed module package: the web request only enqueues + stores the upload; the
 * worker ({@see \App\Service\Module\ModuleInstallRunner}) extracts and installs it
 * (migrations may be long-running) and writes the result back, while the GUI polls
 * the status. Platform-global (a module is installed once for the whole platform,
 * not per tenant) — hence no tenant_id / RLS, like core.modules.
 */
class CoreModuleInstallJobs extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.module_install_jobs (
                id                uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                status            text        NOT NULL DEFAULT 'queued',
                package_path      text        NOT NULL,
                original_filename text        NULL,
                isolation         text        NOT NULL DEFAULT 'in_process',
                module_key        text        NULL,
                message           text        NULL,
                actor_user_id     uuid        NULL,
                created_at        timestamptz NOT NULL DEFAULT now(),
                started_at        timestamptz NULL,
                finished_at       timestamptz NULL,
                CONSTRAINT ck_module_install_jobs_status CHECK (status IN ('queued','running','succeeded','failed')),
                CONSTRAINT ck_module_install_jobs_isolation CHECK (isolation IN ('in_process','out_of_process'))
            )
            SQL);
        $this->execute('CREATE INDEX ix_module_install_jobs_created ON core.module_install_jobs (created_at DESC)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.module_install_jobs');
    }
}

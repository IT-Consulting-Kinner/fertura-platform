<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Maintenance mode — Phase 6: the critical-action payload + a queue index.
 *
 * Phase 6 runs REAL critical actions (module install, tenant provision, secret/
 * trust rotate) through the protected sequence. The operator queues an action
 * (status='quiescing') with its parameters in `payload`; the worker — while the
 * platform is quiesced — claims the oldest queued action and drives it through
 * {@see \App\Service\System\CriticalActionRunner}. The partial index keeps that
 * claim (FOR UPDATE SKIP LOCKED) cheap.
 */
class CoreCriticalActionPayload extends BaseMigration
{
    public function up(): void
    {
        $this->execute("ALTER TABLE core.critical_action ADD COLUMN payload jsonb NOT NULL DEFAULT '{}'::jsonb");
        $this->execute(
            'CREATE INDEX ix_critical_action_queued ON core.critical_action (created_at) '
            . "WHERE status = 'quiescing'",
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS core.ix_critical_action_queued');
        $this->execute('ALTER TABLE core.critical_action DROP COLUMN IF EXISTS payload');
    }
}

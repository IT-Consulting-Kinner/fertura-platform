<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Registration-reconcile hardening (KB-AI-Connector finding): the registry used to
 * INSERT a fresh contract_registrations row on every (re)activation while a
 * deactivate only soft-marked the old one, so inactive orphan rows piled up over
 * every deactivate/activate (or module-update) cycle. register() now UPSERTs by
 * (module_key, contract_id, registration_type), keeping at most one row per triple.
 *
 * This migration (1) purges the historical orphans — collapsing each identity to its
 * single live row (the active one, else the newest) — and (2) adds a partial unique
 * index as the DB-side safety net that guarantees the invariant going forward: at
 * most ONE active registration per (module, contract, type, implementation_class).
 * It complements the existing global provider-slot index uq_registrations_active_provider.
 *
 * implementation_class is part of the identity because a single module legitimately
 * registers MULTIPLE collector/listener classes on ONE contract (e.g. Ticketing's
 * seven scheduled tasks on core.collector.scheduled); it is NULL (COALESCE '') for
 * providers/consumers, keeping those one-per-(module,contract,type).
 */
class CoreRegistrationReconcile extends BaseMigration
{
    public function up(): void
    {
        // Collapse each (module, contract, type, impl_class) group to one row:
        // prefer the active one, then the newest. Never deletes a live/active row
        // (rn=1 for any group that has an active member), and never merges two
        // distinct collector/listener classes. Nothing FKs to registration ids, so
        // removing the dead duplicates is safe.
        $this->execute(<<<'SQL'
            DELETE FROM core.contract_registrations cr
            USING (
                SELECT id,
                       row_number() OVER (
                           PARTITION BY module_key, contract_id, registration_type,
                                        COALESCE(implementation_class, '')
                           ORDER BY active DESC, created_at DESC, id DESC
                       ) AS rn
                FROM core.contract_registrations
            ) ranked
            WHERE cr.id = ranked.id AND ranked.rn > 1
            SQL);

        $this->execute(<<<'SQL'
            CREATE UNIQUE INDEX uq_registrations_active_module_contract_type
                ON core.contract_registrations
                   (module_key, contract_id, registration_type, COALESCE(implementation_class, ''))
                WHERE active
            SQL);
    }

    public function down(): void
    {
        // The de-duplication is one-way (registration rows are derived state,
        // rebuildable by reinstall); only the index is reversible.
        $this->execute('DROP INDEX IF EXISTS core.uq_registrations_active_module_contract_type');
    }
}

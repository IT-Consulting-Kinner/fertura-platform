<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Per-user, per-list view preferences (Paket 2): the stored filter values and
 * page size a user last chose for a given admin list, reapplied on the next
 * visit. Keyed by (user_id, list_key); the payload is a small JSONB bag so a
 * new filter needs no schema change.
 *
 * Built through the canonical tenant-table helper (Inc 9a): tenant_id NOT NULL
 * DEFAULT core.current_tenant(), RLS ENABLED + FORCED, fail-closed policy — so
 * a user of one tenant can never read another tenant's stored preferences, and
 * the table passes the Inc 9c conformance gate by construction. user_id is a FK
 * with ON DELETE CASCADE (prefs are worthless once the user is gone).
 */
class CoreUserListPrefs extends BaseMigration
{
    public function up(): void
    {
        // No DEFAULT on prefs (the service always inserts a value) — keeps the
        // columns string free of nested quotes inside create_tenant_table's %s.
        $this->execute(
            "SELECT core.create_tenant_table('core', 'user_list_prefs', "
            . "'user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE, "
            . 'list_key text NOT NULL, '
            . 'prefs jsonb NOT NULL, '
            . "updated_at timestamptz NOT NULL DEFAULT now()')",
        );
        // One row per user per list; the upsert in ListPrefsService targets it.
        $this->execute(
            'ALTER TABLE core.user_list_prefs '
            . 'ADD CONSTRAINT uq_user_list_prefs UNIQUE (user_id, list_key)',
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.user_list_prefs');
    }
}

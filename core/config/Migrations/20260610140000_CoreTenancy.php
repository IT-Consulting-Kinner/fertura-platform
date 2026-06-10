<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Mandantenfähigkeits-Fundament (Wettbewerbs-Hebel 1/3).
 *
 * Führt die **Mandanten-Dimension** ein, ohne den Single-Org-Betrieb zu brechen:
 * - `core.tenants` + ein fest-ID'ter **Default-Mandant**; alle bestehenden/neuen
 *   Benutzer hängen über `users.tenant_id` (Default = Default-Mandant) daran.
 * - RLS-Helfer `core.current_tenant()` (analog zu `current_user_id()`), den
 *   mandanten-bezogene Tabellen in ihrem Policy-Prädikat nutzen:
 *     CREATE POLICY p_tenant ON <tbl>
 *       USING (tenant_id = core.current_tenant() OR core.rls_bypass());
 *
 * **Fail-closed:** Ohne gesetzten Mandantenkontext liefert `current_tenant()`
 * NULL → `tenant_id = NULL` ist nie wahr → mandanten-bezogene Daten sind
 * unsichtbar. Eine nur teilweise eingeführte Mandantentrennung kann daher NICHT
 * über Mandantengrenzen hinweg lecken. `core.users` selbst erhält bewusst KEINE
 * sperrende Mandanten-Policy (der Pre-Auth-Login muss Benutzer lesen können).
 */
class CoreTenancy extends BaseMigration
{
    /** Stabile ID des Default-Mandanten (= `users.tenant_id`-Default, Single-Org). */
    private const DEFAULT_TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.tenants (
                id         uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
                key        text        NOT NULL,
                name       text        NOT NULL,
                active     boolean     NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT uq_tenants_key UNIQUE (key)
            )
            SQL);
        $this->execute(
            "INSERT INTO core.tenants (id, key, name) VALUES ('"
            . self::DEFAULT_TENANT_ID . "', 'default', 'Default')",
        );
        $this->execute(
            'CREATE TRIGGER trg_tenants_updated_at BEFORE UPDATE ON core.tenants '
            . 'FOR EACH ROW EXECUTE FUNCTION core.set_updated_at()',
        );

        // Benutzer an den Mandanten binden. NOT NULL + Default = Default-Mandant:
        // bestehende Zeilen werden automatisch befüllt, Single-Org bleibt unverändert,
        // und INSERTs ohne tenant_id (SSO-Provisioning, Einladung) erhalten den Default.
        $this->execute(
            "ALTER TABLE core.users ADD COLUMN tenant_id uuid NOT NULL DEFAULT '"
            . self::DEFAULT_TENANT_ID . "'",
        );
        $this->execute(
            'ALTER TABLE core.users ADD CONSTRAINT fk_users_tenant '
            . 'FOREIGN KEY (tenant_id) REFERENCES core.tenants (id)',
        );
        $this->execute('CREATE INDEX ix_users_tenant ON core.users (tenant_id)');

        // RLS-Helfer: aktueller Mandant aus dem per-Transaktion gesetzten Kontext
        // (app.current_tenant_id, vgl. RlsContext). STABLE, NULL wenn ungesetzt.
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.current_tenant()
            RETURNS uuid LANGUAGE sql STABLE AS $func$
                SELECT nullif(current_setting('app.current_tenant_id', true), '')::uuid
            $func$
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP FUNCTION IF EXISTS core.current_tenant()');
        $this->execute('ALTER TABLE core.users DROP CONSTRAINT IF EXISTS fk_users_tenant');
        $this->execute('DROP INDEX IF EXISTS core.ix_users_tenant');
        $this->execute('ALTER TABLE core.users DROP COLUMN IF EXISTS tenant_id');
        $this->execute('DROP TABLE IF EXISTS core.tenants');
    }
}

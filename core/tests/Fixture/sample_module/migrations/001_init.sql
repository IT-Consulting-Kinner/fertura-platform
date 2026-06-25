-- Beispiel-Modul-Migration: Tabelle im Modul-Schema (mod_sample_module).
-- Die Ressource ist is_scoped (Manifest) -> muss tenant-konform sein (Kap. 24/30.3,
-- E47, Decision 185): tenant_id + RLS + Tenant-Policy, ORTHOGONAL zur Owner-RLS.
CREATE TABLE ping_log (
    id          uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
    tenant_id   uuid        NOT NULL DEFAULT core.current_tenant(),
    owner_id    uuid        NULL,
    payload     jsonb       NULL,
    received_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE ping_log ENABLE ROW LEVEL SECURITY;
-- Owner/visibility policy (the feature under test): bypass, own rows, or public.
CREATE POLICY ping_log_scope ON ping_log
    USING (
        current_setting('app.bypass_rls', true) = 'true'
        OR owner_id IS NULL
        OR owner_id::text = current_setting('app.current_user_id', true)
    );
-- Tenant policy (Decision 185): RESTRICTIVE so it ANDs on top of the owner policy,
-- fail-closed. This is the shape the Inc 9c install gate requires.
CREATE POLICY ping_log_tenant ON ping_log AS RESTRICTIVE
    USING (core.rls_bypass() OR tenant_id = core.current_tenant())
    WITH CHECK (core.rls_bypass() OR tenant_id = core.current_tenant());
-- @DOWN
DROP TABLE ping_log;

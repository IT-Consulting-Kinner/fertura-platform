-- Beispiel-Modul-Migration: Tabelle im Modul-Schema (mod_sample_module).
-- Die Ressource ist is_scoped (Manifest) -> muss RLS mitbringen (Kap. 30.3, E47).
CREATE TABLE ping_log (
    id          uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
    owner_id    uuid        NULL,
    payload     jsonb       NULL,
    received_at timestamptz NOT NULL DEFAULT now()
);

-- RLS-Pflicht: aktivieren + Policy gegen den Core-Zugriffskontext
-- (SET LOCAL app.current_user_id / app.bypass_rls, s. RlsContext).
ALTER TABLE ping_log ENABLE ROW LEVEL SECURITY;
CREATE POLICY ping_log_scope ON ping_log
    USING (
        current_setting('app.bypass_rls', true) = 'true'
        OR owner_id IS NULL
        OR owner_id::text = current_setting('app.current_user_id', true)
    );
-- @DOWN
DROP TABLE ping_log;

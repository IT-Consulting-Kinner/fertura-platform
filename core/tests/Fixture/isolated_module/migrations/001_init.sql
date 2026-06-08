-- Modul-Migration (läuft bei out_of_process UNTER der Modul-Rolle, Kap. 23.16.2).
-- is_scoped-Ressource -> RLS-Pflicht (Kap. 30.3).
CREATE TABLE ping_log (
    id          uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
    owner_id    uuid        NULL,
    payload     jsonb       NULL,
    received_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE ping_log ENABLE ROW LEVEL SECURITY;
CREATE POLICY ping_log_scope ON ping_log
    USING (
        current_setting('app.bypass_rls', true) = 'true'
        OR owner_id IS NULL
        OR owner_id::text = current_setting('app.current_user_id', true)
    );
-- @DOWN
DROP TABLE ping_log;

-- Modul-Tabelle mit personenbezogener Freitextspalte (is_scoped -> RLS-Pflicht).
-- Läuft (out_of_process) UNTER der Modul-Rolle (Kap. 23.16.2).
CREATE TABLE user_data (
    id       uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
    owner_id uuid NULL,
    note     text
);

ALTER TABLE user_data ENABLE ROW LEVEL SECURITY;
CREATE POLICY user_data_scope ON user_data
    USING (
        current_setting('app.bypass_rls', true) = 'true'
        OR owner_id IS NULL
        OR owner_id::text = current_setting('app.current_user_id', true)
    );
-- @DOWN
DROP TABLE user_data;

-- Beispiel-Modul-Migration: Tabelle im Modul-Schema (mod_sample_module).
CREATE TABLE ping_log (
    id          uuid        NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY,
    payload     jsonb       NULL,
    received_at timestamptz NOT NULL DEFAULT now()
);
-- @DOWN
DROP TABLE ping_log;

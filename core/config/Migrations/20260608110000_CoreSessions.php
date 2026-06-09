<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Instanzübergreifender Session-Speicher (Kap. 20.8/30.7, HA-Voraussetzung).
 *
 * Datei-basierte Sessions sind nicht instanzübergreifend; für einen
 * Mehrinstanz-Betrieb der Web-Schicht (HA) liegt der Session-Zustand in der
 * gemeinsamen Datenbank (`core.sessions`, CakePHP `DatabaseSession`). Überlebt
 * zudem Container-Recreates (kein Zwangs-Logout beim Deploy). Aktivierung über
 * `SESSION_DEFAULTS=database`. Infrastruktur-Tabelle ohne RLS (Zugriff über die
 * App-Rolle); GC entfernt abgelaufene Einträge.
 */
class CoreSessions extends BaseMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE core.sessions (
                id      varchar(255) NOT NULL PRIMARY KEY,
                data    bytea        NULL,
                expires bigint       NULL,
                created timestamptz  NOT NULL DEFAULT now()
            )
            SQL);
        $this->execute('CREATE INDEX ix_sessions_expires ON core.sessions (expires)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS core.sessions');
    }
}

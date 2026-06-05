<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Core-Fundament (Step 1): Schema-Trennung, benoetigte PostgreSQL-Extensions
 * und gemeinsame Hilfsfunktionen.
 *
 * Grundlage fuer alle folgenden Migrationen. Konventionen: siehe DB_CONVENTIONS.md.
 * Anforderungen: Kap. 30 (Constraint-First, JSONB, Partitionierung), 1.8 / 28.14.2
 * (reversible Migrationen, transaktionales DDL, expand/contract).
 *
 * Hinweis: PostgreSQL fuehrt jede Migration in einer Transaktion aus
 * (transaktionales DDL) -> Fehler werden atomar zurueckgerollt.
 */
class CoreFoundation extends BaseMigration
{
    /**
     * Vorwaerts-Migration.
     */
    public function up(): void
    {
        // 1. Dediziertes Schema fuer Core-Plattformtabellen.
        //    Module verwenden eigene Schemas (mod_<modulkey>); public bleibt
        //    fuer Extensions/uebergreifende Objekte reserviert.
        $this->execute('CREATE SCHEMA IF NOT EXISTS core');
        $this->execute("COMMENT ON SCHEMA core IS 'Fertura Core-Plattform (Step 1 Fundament)'");

        // 2. btree_gist: ermoeglicht Exclusion-Constraints, die Gleichheit (=)
        //    mit Bereichsueberlappung (&&) kombinieren, z. B.
        //    EXCLUDE USING gist (scope_id WITH =, period WITH &&).
        //    (Constraint-First, Kap. 30.1.)
        $this->execute('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // 3. Gemeinsame Trigger-Funktion: pflegt updated_at (timestamptz) bei UPDATE.
        //    Tabellen haengen sich per BEFORE UPDATE-Trigger an (siehe Konventionen).
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.set_updated_at()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $func$
            BEGIN
                NEW.updated_at := now();
                RETURN NEW;
            END;
            $func$
            SQL);
        $this->execute(
            "COMMENT ON FUNCTION core.set_updated_at() IS "
            . "'BEFORE UPDATE-Trigger-Funktion: setzt updated_at = now()'"
        );
    }

    /**
     * Rueckwaerts-Migration (verpflichtend, Kap. 28.14.2 / Entscheidung 155).
     * Reihenfolge invers zur up()-Methode.
     */
    public function down(): void
    {
        $this->execute('DROP FUNCTION IF EXISTS core.set_updated_at()');
        // Extension nur entfernen, wenn keine abhaengigen Objekte mehr existieren.
        $this->execute('DROP EXTENSION IF EXISTS btree_gist');
        // RESTRICT (kein CASCADE): scheitert bewusst, falls noch Objekte im
        // core-Schema liegen -> spaetere Migrationen muessen vorher zurueckgerollt sein.
        $this->execute('DROP SCHEMA IF EXISTS core RESTRICT');
    }
}

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
    public function up(): void
    {
        // 1. Dediziertes Schema fuer Core-Plattformtabellen (defensiv; primaer
        //    durch Entrypoint/`bin/cake schema_init` bereitgestellt, da die
        //    Migrations-Trackingtabelle ebenfalls in `core` liegt).
        $this->execute('CREATE SCHEMA IF NOT EXISTS core');
        $this->execute("COMMENT ON SCHEMA core IS 'Fertura Core-Plattform'");

        // 2. btree_gist (in public = gemeinsame Infrastruktur): ermoeglicht
        //    Exclusion-Constraints, die Gleichheit (=) mit Bereichsueberlappung
        //    (&&) kombinieren. (Constraint-First, Kap. 30.1.)
        $this->execute('CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public');

        // 3. UUIDv7-Generator als DB-DEFAULT (Entscheidung E6): zeitgeordnete
        //    UUIDs ohne Extension (nutzt das eingebaute gen_random_uuid() fuer
        //    die Zufallsbytes, ueberschreibt die ersten 48 Bit mit dem
        //    Millisekunden-Zeitstempel und setzt die Version-7-Bits).
        //    App-seitig erzeugt zusaetzlich ein Behavior die ID (ORM kennt sie
        //    sofort); diese Funktion ist das Netz fuer Raw-SQL/Modul-Inserts.
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.uuid_generate_v7()
            RETURNS uuid
            LANGUAGE plpgsql
            VOLATILE
            AS $func$
            BEGIN
                RETURN encode(
                    set_bit(
                        set_bit(
                            overlay(
                                uuid_send(gen_random_uuid())
                                PLACING substring(
                                    int8send(floor(extract(epoch FROM clock_timestamp()) * 1000)::bigint)
                                    FROM 3
                                )
                                FROM 1 FOR 6
                            ),
                            52, 1
                        ),
                        53, 1
                    ),
                    'hex'
                )::uuid;
            END
            $func$
            SQL);
        $this->execute(
            "COMMENT ON FUNCTION core.uuid_generate_v7() IS "
            . "'Zeitgeordnete UUIDv7 (DB-DEFAULT fuer Primaerschluessel)'"
        );

        // 4. Gemeinsame Trigger-Funktion: pflegt updated_at (timestamptz) bei UPDATE.
        $this->execute(<<<'SQL'
            CREATE OR REPLACE FUNCTION core.set_updated_at()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $func$
            BEGIN
                NEW.updated_at := now();
                RETURN NEW;
            END
            $func$
            SQL);
        $this->execute(
            "COMMENT ON FUNCTION core.set_updated_at() IS "
            . "'BEFORE UPDATE-Trigger-Funktion: setzt updated_at = now()'"
        );
    }

    public function down(): void
    {
        $this->execute('DROP FUNCTION IF EXISTS core.set_updated_at()');
        $this->execute('DROP FUNCTION IF EXISTS core.uuid_generate_v7()');
        $this->execute('DROP EXTENSION IF EXISTS btree_gist');
        // Das core-Schema wird NICHT gedroppt: es ist Infrastruktur (Entrypoint/
        // schema_init) und beherbergt die Migrations-Trackingtabelle cake_migrations.
    }
}

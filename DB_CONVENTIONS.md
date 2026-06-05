# Datenbank- & Migrationskonventionen (Core)

Verbindliche Konventionen für alle Core- und Modul-Migrationen. Grundlage:
Plattform-Anforderungsdokument v6.28, Kapitel 30 (PostgreSQL), 1.8 und 28.14.2.

Viele dieser Punkte sind im Anforderungsdokument **bewusst offen** gelassen und
hier **autonom entschieden** (siehe Entscheidungs-Log E5–E12 in
`IMPLEMENTATION_PLAN.md`). Korrekturen jederzeit möglich.

## 1. PostgreSQL ist maßgeblich

- DBMS: **PostgreSQL 17** (Entscheidung 173). Keine MySQL-Kompatibilität.
- App-Connection: Treiber `Postgres`, `encoding=utf8`, `timezone=UTC`.

## 2. Schema-Trennung (E5)

- **`core`** — alle Core-Plattformtabellen.
- **`mod_<modulkey>`** — pro Modul ein eigenes Schema (z. B. `mod_ticketing`).
- **`public`** — reserviert für Extensions und schema­übergreifende Objekte.
- `search_path` der Anwendung: `core, public` (gesetzt als Connection-`init` in
  `config/app.php`). Modul-Connections ergänzen ihr Schema bei Bedarf.
- Migrationen **qualifizieren Objektnamen explizit** (`core.<name>`), verlassen
  sich also nicht allein auf den `search_path`.

## 3. Primärschlüssel (E6 – UUIDv7)

- Standard: **`id uuid NOT NULL DEFAULT core.uuid_generate_v7() PRIMARY KEY`**.
- **UUIDv7** = zeitgeordnet → nicht erratbar/enumerierbar **und** gute Index-
  Lokalität. Damit entfällt eine separate `public_id`.
- **Erzeugung zweigleisig:**
  - App-seitig über das `UuidV7Behavior` → das ORM kennt die ID sofort.
    **Universell**: die Basisklasse `App\Model\Table\AppTable` aktiviert das
    Behavior für *jede* Core-Tabelle; das Behavior wirkt nur auf einspaltige
    `uuid`-PKs (Text-/zusammengesetzte PKs wie `admin_areas` bleiben unberührt).
    Alle Core-Table-Klassen erben von `AppTable`.
  - DB-seitig über `core.uuid_generate_v7()` als Spalten-DEFAULT → Netz für
    Raw-SQL-/Modul-/Migrationsinserts.
- Join-Tabellen erhalten ebenfalls einen `uuid`-Surrogat-PK (Konsistenz).

## 4. Standardspalten & Zeitstempel (E7)

- Zeitstempel immer **`timestamptz`** (UTC).
- Konvention für veränderliche Entitäten:
  - `created_at timestamptz NOT NULL DEFAULT now()`
  - `updated_at timestamptz NOT NULL DEFAULT now()`
- `updated_at` wird per **BEFORE UPDATE-Trigger** über die gemeinsame Funktion
  `core.set_updated_at()` gepflegt (Defense-in-Depth, unabhängig von der App).
- **Akteur-Spalten (E8):** `uuid` NULL, FK auf `core.users`, `ON DELETE SET NULL`,
  gepflegt durch das `FootprintBehavior` aus dem `ActorContext` (HTTP-Identität;
  CLI/System = NULL). Das Behavior ist spalten-tolerant (setzt nur vorhandene
  Spalten). Trennscharfe Regel:
  - **`created_by` = „durch wen entstanden"** — auf **allen** Tabellen, deren
    Zeilen durch einen Akteur entstehen, **inkl. Verknüpfungstabellen**
    (`groups_users` = wer hat zugeordnet; `user_admin_areas` = wer hat Adminrechte
    vergeben).
  - **`updated_by` = „durch wen zuletzt geändert"** — nur auf **in-place
    editierbaren** Sätzen (`users`, `groups`); **nicht** auf Append-only-/Join-
    Tabellen (keine Update-Semantik).
  - **Keine** Akteur-Spalten auf reiner Infrastruktur (Outbox, Audit-Log,
    `auth_failures`) oder statischen Stammdaten (`admin_areas`).
  Ergänzt – ersetzt nicht – das Audit-Log (Step 3).

## 5. „Deaktivieren statt löschen" (E8)

- Grundregel (Kap. 1.6): konfigurierbare/Stammdaten werden **nicht physisch
  gelöscht**, sondern deaktiviert.
- Konvention: Spalte **`active boolean NOT NULL DEFAULT true`** plus
  **`deactivated_at timestamptz NULL`** (wann deaktiviert; operationsseitig
  gesetzt). Benutzer nutzen stattdessen `status` + `deactivated_at`.
- Kein generisches `deleted_at`-Soft-Delete; Ausnahmen werden explizit begründet.
- Benutzer: keine Löschung, sondern irreversible Anonymisierung (Entscheidung 160).

## 6. Constraint-First (Kap. 30.1, Entscheidung 174)

Integritäts-/Zugriffsregeln werden, wo möglich, **in der DB** erzwungen:

- **Fremdschlüssel** für referenzielle Integrität.
- **Partielle Unique-Constraints** für „genau ein aktiver X":
  `CREATE UNIQUE INDEX uq_<tabelle>_<spalte>_active ON core.<tabelle>(<spalte>) WHERE active;`
- **Check-Constraints** für Wertebereiche/Statusinvarianten.
- **Exclusion-Constraints** (GiST, via `btree_gist`) für Überlappungsfreiheit:
  `EXCLUDE USING gist (scope_id WITH =, period WITH &&)`.
- Anwendungslogik **ergänzt** diese Regeln, ersetzt sie nicht.

## 7. JSONB (Kap. 30.5, Entscheidung 176)

- Semi-strukturierte/schemaschwache Daten → **`jsonb`**-Spalte.
- Wo gefiltert/gesucht wird: **GIN-Index** (`USING gin (<spalte> jsonb_path_ops)`
  bzw. ohne `jsonb_path_ops`, je nach Abfragemuster).
- JSONB **ersetzt nicht** das relationale Modell; häufig gefilterte Fachdaten
  bleiben normalisierte Spalten.
- Einsatz u. a.: Audit-Log-Payload, Event-Outbox-Payload, Registry-/Manifest-Metadaten.

## 8. Migrationen (Kap. 1.8 / 28.14.2, Entscheidung 155)

- Framework: **cakephp/migrations** (Phinx-basiert), Verzeichnis
  `config/Migrations`. Ausführung per `bin/cake migrations migrate` (im Entrypoint).
- **Jede Migration liefert `up()` und `down()`** (explizit; kein `change()` für
  nicht trivial umkehrbare DDL).
- **Transaktional**: PostgreSQL-DDL läuft je Migration in einer Transaktion →
  atomarer Rollback bei Fehler.
- **expand/contract verpflichtend**: destruktive Änderungen (Spalte/Tabelle
  entfernen/umbenennen) nur additiv + späterer, getrennter Entfernungsschritt.
  In-Place-destruktive Änderungen sind unzulässig.
- Neue Pflichtfelder erhalten **Defaultwerte** (Rückwärtskompatibilität).
- `CREATE INDEX CONCURRENTLY` o. Ä. (nicht transaktionsfähig) wird vermieden bzw.
  als gesonderte, nicht-transaktionale Migration markiert.
- Wiederherstellungspunkt (pg_dump) vor migrationsbehafteten Updates → Update-
  Mechanismus (Step 8).

## 9. Namenskonventionen (E9)

- Schreibweise: **snake_case**.
- Tabellen: **Plural** (CakePHP-ORM-Konvention), z. B. `users`, `groups`.
- Spalten: snake_case; PK = `id`; FK = `<singular>_id` (z. B. `group_id`).
- Constraints/Indizes (explizit benannt):
  - Fremdschlüssel: `fk_<tabelle>_<spalte>`
  - Unique: `uq_<tabelle>_<spalten>` (partiell: `…_active`)
  - Check: `ck_<tabelle>_<regel>`
  - Exclusion: `ex_<tabelle>_<regel>`
  - Index: `ix_<tabelle>_<spalten>`; GIN: `gin_<tabelle>_<spalte>`
  - Trigger: `trg_<tabelle>_<zweck>`

## 10. Row-Level Security (Kap. 30.3, Entscheidung 175) — ab Step 9

- Gruppen-/bereichs-scoped **Modul**tabellen: `ENABLE` + `FORCE ROW LEVEL SECURITY`.
- Zugriffskontext pro Transaktion über `SET LOCAL` (pooling-kompatibel).
- Policy-Prädikate indexnah; definierte, auditierbare `BYPASSRLS`-Pfade
  (Wartung, Migrationen, Hintergrund-Jobs, DSGVO-Vorgänge).
- Details und Helfer folgen in Step 9.

## 11. Partitionierung (Kap. 30.8, Entscheidung 179) — ab Step 3/6

- Kontinuierlich wachsende Tabellen (**Audit-Log**, **Event-Outbox**) als
  deklarative **Zeitbereichs-Partitionen** (z. B. monatlich).
- Gezielte Indizes für häufige Abfragen (Benutzer, Zeitraum, Entitätstyp).

## 12. Nebenläufigkeit (Kap. 30.7, Entscheidung 178) — ab Step 7

- Lifecycle-Lock als **PostgreSQL-Advisory-Lock** (knotenübergreifend).

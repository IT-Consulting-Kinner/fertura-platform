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

## 13. Audit-Log (Step 3, Kap. 1.6 / 24.16 / 20.6 / 27.18; E16)

- Tabelle `core.audit_log`, **append-only** (Immutability-Trigger blockiert
  UPDATE/DELETE; Bypass nur via `SET LOCAL app.allow_audit_mutation = 'on'`).
- **RANGE-Partitionierung** über `created_at` (monatlich) + DEFAULT-Partition;
  Monatspartitionen stellt `bin/cake audit_partition` sicher.
- **Personen per auflösbarer UUID** (`actor_user_id`, person-`entity_id`) — **kein**
  denormalisierter Klartext (Name/E-Mail) und **keine PII** in `old_value`/
  `new_value`. So wirkt eine Anonymisierung automatisch, ohne das Log zu ändern.
- **Referenzrobuste Textkopien** (`entity_label`, `module_key/name/version`) nur
  für **nicht-personenbezogene** Entitäten (Module/Config) → bleiben nach Löschung
  lesbar.
- Schreiben über `App\Audit\AuditLogger` (transaktional, gleiche Connection wie
  die fachliche Änderung). `correlation_id` verknüpft zusammengehörige Einträge.
- Felder: `actor_user_id`, `action`, `entity_type`, `entity_id`, `entity_label`,
  `module_key/name/version`, `component`, `correlation_id`, `old_value`/`new_value`
  (jsonb, GIN-indiziert).

## 14. Konfigurationsspeicher (Step 4, Kap. 1.4 / 23.3; E18)

- Tabelle `core.settings`: `(namespace, config_key)` unique; `value` jsonb für
  Klartextwerte, `value_encrypted` (text) für Geheimnisse (`is_secret`).
- **DB vs. app.php:** Anwendungs-/Modul-Konfiguration in die DB; nur
  Infrastruktur (DB-Verbindung, Salt, Encryption-Key, Cache) bleibt in app.php
  (Entscheidung 159).
- **Sichere Vorgabewerte** im Code-Katalog (`SettingsCatalog`) — greifen auch
  ohne DB-Eintrag (Entscheidung 162); dort auch Typ-/Bereichsvalidierung.
- **Secrets verschlüsselt** (AES-256-GCM, `SecretCipher`); Schlüssel aus
  `Security.encryptionKey` (env), **nicht** aus der DB.
- Zugriff über `App\Service\Settings\SettingsManager` (`get`/`set`),
  transaktional + Audit (`config.update`, ohne Klartext bei Secrets) + Footprint.
- „Deaktivieren statt löschen" gilt für Konfig-*objekte* (Stammdaten), **nicht**
  für Setting-*werte* (dürfen auf Default zurückgesetzt werden).

## 15. Event-Outbox (Step 6, Kap. 26.9.2 / 30.6; E20)

- `core.event_outbox`, RANGE-partitioniert (created_at, monatlich; via
  `audit_partition`). Status `pending|processing|done|dead_letter`.
- **Emission:** Module/Core schreiben Events über `App\Service\Event\OutboxPublisher`
  **innerhalb der Transaktion der fachlichen Änderung** (atomar). `pg_notify`
  läuft in derselben Transaktion → Zustellung erst nach COMMIT.
- **Worker** (`event_worker` = worker-Container): LISTEN/NOTIFY + Poll-Fallback;
  Claim `FOR UPDATE SKIP LOCKED` (mehrere Worker möglich); Listener aus der
  Registry, isoliert; Retry mit exponentiellem Backoff; nach `max_attempts` →
  `dead_letter`; Reclaim hängender `processing`-Events.
- **Listener** implementieren `App\Event\EventListenerInterface` und MÜSSEN
  **idempotent** sein (mindestens-einmal-Zustellung); Dedup über `event_id`.

## 16. Module & Lifecycle (Step 7, Kap. 24; E21)

- Stammdaten in `core.modules` (Zustandsautomat: `installed_inactive` →
  `active` ↔ `inactive`, Fehlerzustände); `module_dependencies`,
  `module_migrations_log`. Registrierungen = Step-5-`contract_registrations`.
- **Modul-Tabellen** liegen im Schema **`mod_<module_key>`** (Install legt es an;
  Delete droppt es CASCADE). Gruppen-/bereichs-scoped Modultabellen MÜSSEN RLS
  führen (Entscheidung 175, Step 9).
- **Modul-Migrationen**: versionierte SQL-Dateien `migrations/NNN_name.sql` mit
  `-- @DOWN`-Trenner; vom Core transaktional im Modul-Schema ausgeführt + in
  `module_migrations_log` getrackt. Reversibel; expand/contract.
- **Manifest** (`manifest.json`): Pflichtfelder Kap. 24.4; Main-Module ohne
  `contracts_used`. Modul-Code wird per PSR-4 (`php_namespace` → `src/`) geladen.
- Lifecycle-verändernde Operationen laufen unter **PostgreSQL-Advisory-Lock**
  (knotenübergreifend serialisiert).

## 17. Marketplace / Signatur / Lizenz / Update (Step 8, Kap. 28/24.9; E22–E25)

- **Signatur (Ed25519):** Pakete/Lizenzen/CRL/Anker werden signiert; geprüft VOR
  dem Entpacken gegen aktive, nicht-widerrufene Vertrauensanker
  (`core.trust_anchors`, `core.revoked_keys`). Paket-Digest deckt **alle** Dateien
  ab. Setting `core.require_module_signature` (Default true).
- **Lizenz** (`core.licenses`): signierte Lizenzdatei, **offline** geprüft;
  Aktivierungs-Gate bei `requires_license`; Ablauf → deaktivieren (kein Datenverlust).
- **Update** (`UpdateManager`, `core.update_history`): Signatur-/Kompatibilitäts-
  prüfung; **verpflichtender `pg_dump`-Wiederherstellungspunkt** bei Migrationen;
  Rollback über Down-Migrationen (Dump = manuelle letzte Zuflucht).
- **Marketplace** (`MarketplaceClient`, Setting `core.marketplace.base_url`):
  signierte CRL/Anker abrufen + verifizieren; reiner Metadatenabruf ohne Systemwirkung.
- **Wartungsmodus** (`core.maintenance_mode`): MaintenanceMiddleware liefert 503.

## 18. BREAD & Row-Level Security (Step 9, Kap. 25/30.3; E26)

- **BREAD** (`group_resource_permissions`): Gruppe → Ressource → can_browse/read/
  add/edit/delete + `extra_actions` (jsonb). `resource_key` NULL = Objektklasse.
  Prüfung über `App\Service\Permission\PermissionService::canPerform()` — **rein
  additiv** über aktive Gruppen (keine Deny-Regeln, Entscheidung 124); immer
  serverseitig. Ressourcen (`resources`) deklarieren Module im Manifest.
- **RLS (Entscheidung 175):** gruppen-/bereichs-scoped **Modul**tabellen MÜSSEN
  `ENABLE` + `FORCE ROW LEVEL SECURITY` + Policy. Empfohlenes Muster:
  `USING (group_id = ANY(core.current_group_ids()))`.
- **Zugriffskontext** via **SET LOCAL** (pro Transaktion, pooling-sicher):
  `app.current_user_id`, `app.current_group_ids` (CSV uuids). Gesetzt von
  `RlsContext` / `TransactionRlsMiddleware` (Request = eine Transaktion).
- **Bypass** = **privilegierte DB-Rolle** (Migrationen/Wartung/Worker/DSGVO), NICHT
  die settbare GUC. **Wichtig:** Die App-Connection muss als **NOBYPASSRLS-Rolle**
  laufen, damit RLS greift (Superuser umgeht RLS).

# Per-Tenant Backup & Restore (Increment 6) — Design

Status: **Entwurf zur Freigabe.** Bindende Vorentscheidung (Session): *Disaster Recovery /
cross-tenant Full-Restore = ausschließlich CLI; Backup & Restore innerhalb EINES Mandanten
(Daten, Dateien) = GUI.* Multi-Tenancy-Norm: Decision 185 (konsequent multi-tenant, fail-closed,
revisionssicher). „Core editiert nie Module" bleibt absolut — Modulteilnahme nur deklarativ/
introspektiv, kein Eingriff in Modulcode.

## 1. Verhältnis zum bestehenden System-Backup (unangetastet)

Es existiert bereits ein vollständiges **System-Backup** (`BackupService` + `BackupController`
[operator, `core_config`] + `BackupCommand`): `pg_dump -Fc` der GANZEN DB + Datei-Stores
(`language-store`, `marketplace-data`, `modules`) als ein ZIP (AES-256 optional, Manifest +
SHA-256, Verify, Probe-Restore, Advisory-Lock, Offsite). **Restore ist bewusst CLI-only und
destruktiv** (`bin/cake backup restore … --yes`, Maintenance-Mode 503) — das IST die DR-Schiene
und bleibt unverändert.

Inc 6 baut **daneben** eine **separate, mandanten-eigene** Schiene: ein Mandant-Admin sichert/
stellt **nur die Daten SEINES Mandanten** wieder her, während andere Mandanten live bleiben.
Wiederverwendet werden Bausteine von `BackupService` (ZIP/AES-Bau, Verify, Manifest/Checksums,
Storage), **nicht** der full-DB-Dump/Restore.

## 2. Was „die Daten eines Mandanten" sind

Per-Tenant-Backup ist ein **logischer Zeilen-Export** (nicht pg_dump — pg_dump kann nicht je
Tabelle zeilenweise nach `tenant_id` filtern): pro tenant-scoped Tabelle die Zeilen
`WHERE tenant_id = :tenant`, in FK-Reihenfolge, **data-only** (Schema ist plattform-/modul-
eigen, nicht Mandanten-Besitz). Format: ein Archiv mit pro-Tabelle-Daten (NDJSON je Tabelle +
Manifest mit Tabellen-Reihenfolge, Zeilenzahlen, SHA-256), via `BackupService`-ZIP/AES gebaut.

**Tabellen-Set (Core, stabil — als Code-Liste in fester FK-Reihenfolge):**
`groups`, `groups_users`, `group_resource_permissions`, `tenant_modules`, `settings`
(nur Zeilen mit `tenant_id = :t`, NICHT die globalen NULL-Defaults), `automation_rules`,
`workflow_definitions`, `workflow_instances`, `notifications`, `notification_prefs`,
`webhook_subscriptions`, `webhook_deliveries`, `search_index`, `embeddings`.

**Sonderfälle:**
- **`audit_log`**: in den Export aufnehmen (lesbar, revisionssicher), aber **niemals beim
  Restore löschen/überschreiben** (Append-only-Trigger; Restore schreibt stattdessen einen
  `tenant.restore`-Eintrag). Backup = ja, Restore = nein.
- **`users`** (Mandanten-Benutzer, `tenant_id`, **keine RLS** = Pre-Auth-Ausnahme): heikel —
  Identität, referenziert von `audit_log.actor`, Tokens, Sessions, `user_admin_areas`,
  `groups_users`. **Vorschlag: in Inc 6 NICHT per Restore zurückschreiben** (Identitäts-/Auth-
  Risiko); optional in den Export aufnehmen (zur Vollständigkeit/Offline-Inspektion), Restore
  später als eigener, vorsichtiger Schritt. Zu klären (Entscheidung A).
- **`event_outbox`** (`tenant_id`, keine FK, append-only-artig): aus Backup/Restore ausnehmen
  (flüchtige Zustellung, nicht Mandanten-Geschäftsdaten).
- **NICHT-tenant-Tabellen** (`tenants`, `modules`, `admin_areas`, `resources`, `contracts`, …):
  müssen im Ziel bereits existieren (FK-Targets); werden nie per-Tenant restauriert.

**Modul-Daten:** Modul-Tabellen liegen in modul-eigenen Schemas. Discovery per **PostgreSQL-
Introspektion**: tenant-scoped = RLS-aktiv (`relrowsecurity`) **und** Spalte `tenant_id` vorhanden,
im Schema des Moduls. Kein Modulcode-Eingriff, keine Manifest-Pflicht (eine spätere
Manifest-/Contract-Variante für Ausschlüsse/Reihenfolge bleibt optional). Zu klären (Entscheidung B).

## 3. Restore-Mechanik & Sicherheit

Per-Tenant-Restore = transaktional, **nur** für den aufrufenden Mandanten:
1. Privilegierte Verbindung (`Db::privileged()` / `app.bypass_rls`), Kontext
   `app.current_tenant_id = :t` gesetzt.
2. In **umgekehrter** FK-Reihenfolge: `DELETE … WHERE tenant_id = :t` je Tabelle (andere
   Mandanten unberührt). `audit_log` ausgenommen.
3. In FK-Reihenfolge: Zeilen aus dem Archiv wieder einfügen (`COPY`/INSERT), `tenant_id`
   bleibt `:t`.
4. Alles in **einer** Transaktion → Fehler = Rollback = kein Halb-Zustand.
5. Abschluss: ein `tenant.restore`-Audit-Eintrag (forward event).

**Sicherheits-Invarianten:** der Restore berührt ausschließlich Zeilen mit `tenant_id = :t`
(der aufrufende Mandant); die GUI erlaubt **nur** den eigenen Mandanten (`core.current_tenant()`);
der destruktive cross-tenant Full-Restore bleibt CLI-only (`BackupService`). Upload-Restore (aus
hochgeladenem Archiv) validiert, dass das Archiv-`tenant_id` zum aktuellen Mandanten passt
(sonst Ablehnung — kein Einspielen fremder Daten).

## 4. Dateien (Mandanten-Dateien) — Datei-Scoping-Contract (entschieden: Teil von Inc 6)

**Befund:** Es gibt **kein** konsistentes Per-Tenant-Datei-Pfadschema — der Core legt Dateien
generisch (path-basiert) ab; Modul-Dateipfade sind modul-definiert. **Entscheidung (C):** Inc 6
etabliert daher eine **Per-Tenant-Datei-Konvention** und sichert/restauriert die Dateien des
Mandanten mit.

**Konvention:** Alle mandanten-eigenen Dateien liegen unter dem Präfix `tenant/<tenant_id>/…`
im StorageManager-Root. Der Core stellt dafür einen **tenant-aware Storage-Helfer** bereit
(z. B. `TenantStorage`/`StorageManager::tenantPath()`), der `tenant/<core.current_tenant()>/…`
auflöst. Module **übernehmen** die Konvention deklarativ, indem sie ihre Mandanten-Dateien über
den Helfer/das Präfix ablegen — kein Eingriff in Modulcode durch den Core. Bestandsdateien
außerhalb des Präfixes sind nicht per-Tenant sicherbar (dokumentiert; Migration der Module ist
deren Sache).

**Backup/Restore:** das Per-Tenant-Archiv enthält den Teilbaum `tenant/<tenant_id>/` (tar);
Restore ersetzt **nur** diesen Teilbaum (andere Mandanten unberührt). *(Die DR-Schiene sichert
Dateien ohnehin vollständig — nur nicht mandanten-selektiv.)*

## 5. Oberfläche

Neue **Tenant-Area `tenant_backup`** (in `AdminController::TENANT_AREAS`, kein Operator-Tor),
Migration seedet die Area, NAV-Eintrag, i18n. `TenantBackupController` (requiredArea=
`tenant_backup`, alles auf `core.current_tenant()` scoped): `index` (Backups des Mandanten),
`create` (Backup erstellen), `download`, `restore` (destruktiv-aber-scoped, Confirm-Modal),
`upload`+`restore` (mit tenant-Match-Validierung). Metadaten in einer `tenant_backups`-Tabelle
(RLS tenant-scoped) oder `core.backups` + `tenant_id`-Spalte. Zu klären (Entscheidung D).

## 6. Increment-Plan

- **6a ✅** — Fundament + DB-Export: `TenantBackupService` (feste FK-geordnete Core-Tabellen-Liste;
  scoped NDJSON-Export `WHERE tenant_id=:t` + Manifest, ZIP mit AES-256 fail-closed),
  `core.tenant_backups` (neue RLS-Tabelle), Tenant-Area `tenant_backup` + `TenantBackupController`
  (index/create/download), i18n de/en. Tests `TenantBackupControllerTest` (Index, Create+Datei+Audit,
  Download, Cross-Tenant-Isolation). Adversarial reviewed → ein medium-Befund gefixt (verwaistes
  Archiv bei fehlgeschlagenem Metadaten-INSERT wird im catch entfernt). **DB-only, Core-Tabellen**
  (`users`/`audit_log` backup-only). *(Self-contained ZIP/Passwort-Logik gespiegelt von
  `BackupService`; DRY-Refactor später.)*
- **6b ✅** — Scoped Restore: `TenantBackupService::restore` öffnet das Archiv (Einträge per
  `getFromName` — zip-slip-sicher), prüft **Manifest-Tenant-Match**, dann in **einer** Transaktion
  auf einer **separaten** `Db::privileged()`-Verbindung (echte Atomarität) Delete (reverse FK) +
  Reinsert (forward FK) nur der restorable Tabellen; `tenant_id` erzwungen, Spaltennamen
  identifier-validiert + gequotet; `audit_log`/`users` nie angefasst; `tenant.restore`-Audit nach
  Commit. Controller `restore(id)` (POST) + destruktiver `confirmPost`-Button, i18n. Adversarial
  reviewed → **3 Befunde gefixt**: (HIGH) Datenverlust bei fehlgeschlagenem Reinsert → separate
  Transaktion statt Savepoint-Abhängigkeit; (HIGH) GENERATED-Spalte (`search_index.tsv`) → Export
  nur nicht-generierte Spalten; (medium) reservierte-Wort-Spaltennamen → gequotet. Tests:
  Round-Trip (A zurückgesetzt, B unberührt, auditiert) + **Atomarität** (fehlgeschlagener Restore
  rollt zurück, kein Datenverlust). **Upload-Restore** (aus hochgeladenem Archiv, mit demselben
  Tenant-Match) ist ein späterer Zusatz.
- **6c** — Datei-Scoping-Contract + Per-Tenant-Dateien: Core-Konvention `tenant/<id>/` + Storage-
  Helfer; Backup tar't den Teilbaum, Restore ersetzt nur ihn; Tests.
- **6d** — Modul-Daten: RLS-Introspektion der Modul-Schemas (tenant-scoped Tabellen) in Export +
  Restore aufnehmen, Tests mit einem Fixture-Modul.

Jeder Increment: phpstan + phpcs + Tests grün, adversarial review (sicherheits-/datenkritisch).

## 7. Entscheidungen (freigegeben)

- **A — `users`:** **backup-only, kein Restore** in Inc 6 (Identitäts-/Auth-Risiko; im Export für
  Vollständigkeit, Restore-Schritt später vorsichtig).
- **B — Modul-Daten:** **ja**, per RLS-Introspektion automatisch (6d), kein Modulcode-Eingriff.
- **C — Dateien:** **Datei-Scoping-Contract Teil von Inc 6** (6c) — Core-Konvention `tenant/<id>/`
  + Helfer, Module übernehmen sie deklarativ.
- **D — Metadaten:** **neue `tenant_backups`-Tabelle** (RLS-scoped; saubere Trennung System- vs.
  Mandanten-Backups).

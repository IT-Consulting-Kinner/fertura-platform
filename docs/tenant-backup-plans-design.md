# Inc 7 — Mandanten-Backup-Pläne + Verbrauchs-Dashboard (Design)

Erweitert das Per-Tenant-Backup (Inc 6, `docs/tenant-backup-design.md`) um **geplante, gescopte**
Backups, **Ad-hoc**-Backups, **beschriftete** Restore-Punkte und ein **Verbrauchs-Dashboard**
(Mandant + Betreiber-Sicht für die Abrechnung). Betreiber-DR-Backup (`BackupService`) bleibt
unberührt.

## Freigegebene Entscheidungen
- **Scope-Granularität:** `full` (ganzer Mandant) · `core` (Kerndaten: mandanten-eigene Core-
  Tabellen ohne Modul-Daten) · `<module-key>` (genau ein Modul, z. B. `ticketing`/`knowledgebase`).
- **Kadenz:** Presets — `daily`/`weekly`/`monthly` + Uhrzeit (+ Wochentag bei weekly, Tag bei monthly).
- **Speicher-Metrik:** Backup-Archive (`tenant_backups.bytes`) + Datei-Store (`tenant/<id>/`) +
  DB-Footprint **approx.** (Zeilen je tenant-scoped Tabelle).

## Scope-Modell
`TenantBackupService::backupTables()` = Core-Specs (schema `core`) + `moduleTableSpecs()`
(introspiziert `mod_<key>`-Schemas mit RLS+tenant_id). Scoping filtert diese Liste:
- **full** → alle Specs + `tenant/<id>/` (alle Dateien). Wie Inc 6.
- **core** → nur `schema = 'core'`-Specs. Keine Dateien (Kerndaten haben keinen Datei-Store).
- **\<module-key\>** → nur Specs mit `schema = 'mod_<key>'` + Dateien unter `tenant/<id>/<key>/`.

`users` + `audit_log` bleiben **backup-only** in jedem Scope. Restore eines gescopten Backups
ersetzt **nur** die Tabellen/Dateien des Scopes (scoped delete+reinsert auf `restorableSpecs()`,
gefiltert auf den Scope).

**Datei-Konvention (Modul-Adoption):** Module legen per-Tenant-Dateien unter `tenant/<id>/<key>/…`
ab, damit Modul-Scope ihre Dateien erfasst. Module, die das (noch) nicht tun, werden nur vom
`full`-Scope erfasst — dokumentierte Grenze + modul-seitige Adoptions-Aufgabe (Hand-off, Boundary).

## Increment-Plan
- **7a — Scoped Backup:** `tenant_backups.scope` (+ `plan_id` nullable FK); `create(scope)` +
  scoped Restore; GUI: Ad-hoc mit Scope-Auswahl, Restore-Punkte zeigen Scope-Beschreibung + Größe.
- **7b — Pläne:** Tabelle `tenant_backup_plans` (RLS, tenant-scoped): name, scope, cadence,
  hour, weekday/day, retention_keep, active, last_run_at, next_run_at. CRUD-GUI im `tenant_backup`-Bereich.
- **7c — Scheduler:** `TenantBackupScheduledTask implements ScheduledTaskInterface` — iteriert
  aktive Mandanten, **setzt je Mandant den RLS-Tenant-Kontext** (F13-Lehre: kein no-op unter
  NOBYPASSRLS), erzeugt fällige Pläne via `create(scope, planId)`, prunt nach `retention_keep`,
  setzt `last/next_run_at`.
- **7d — Verbrauchs-Dashboard (Mandant):** Funktionen (aktive `tenant_modules`) + Speicher
  (Archive + Datei-Store + DB-Footprint approx.).
- **7e — Betreiber-Sicht:** operator-gated Seite, pro Mandant Funktionen + Speicher → Abrechnungsbasis.

Jeder Sub-Increment: phpstan + phpcs + Tests grün, adversarial review (daten-/sicherheitskritisch).

# Backup & Wiederherstellung (Core-Funktion)

Backup/Restore erfolgt zweistufig (Kap. 20.1):

- **Infrastruktur-Backup/-Restore** (20.1.1) — Host-/Volume-Snapshots, PITR/
  Replikation, Off-Site, Scheduling, Aufbewahrung — liegt **außerhalb von
  Fertura** beim Systemadministrator.
- **Daten-Backup/-Restore** (20.1.2) — die **konsistente, prüfbare** Sicherung der
  Anwendungsdaten (DB + persistente Datei-Stores) ist eine **Core-Systemfunktion**
  und hier beschrieben.

## Was wird gesichert

- **Gesamte Datenbank** (`pg_dump -Fc`, custom-format → mit `pg_restore` einspielbar):
  Core-Schema + alle Modul-Schemata `mod_*` + Migrations-Tracking.
- **Persistente Datei-Stores:** `language-store` (Sprachpakete), `marketplace-data`,
  `modules` (installierter Modulcode).

**Konsistenz:** Erstellung läuft unter dem **Lifecycle-Advisory-Lock** — während
des Snapshots gibt es keine Modul-Installation/-Sprachschreibvorgänge, DB und
Dateien passen also zueinander. Je Artefakt wird ein **SHA-256** abgelegt.

## CLI

```bash
bin/cake backup create [--note "vor Update X"]   # erstellt Sicherung
bin/cake backup list                              # listet Sicherungen
bin/cake backup verify <id>                       # Checksummen prüfen
bin/cake backup test-restore <id>                 # Probe-Restore in Scratch-DB
bin/cake backup restore <id> --yes                # DESTRUKTIV: Produktion
bin/cake backup delete <id>
```

- **`test-restore`** spielt den DB-Dump in eine **Wegwerf-Datenbank**
  (`fertura_verify_*`) ein und prüft ihn dort (Anzahl `core`-Tabellen), **ohne**
  die Produktion zu berühren → so ist eine Sicherung *prüfbar*, bevor man sie braucht.
- **`restore --yes`** ist destruktiv (`pg_restore --clean` + Entpacken der Stores).
  Danach Dienste neu starten (`docker compose restart core worker`). Bewusst
  **CLI-only** (nicht über die GUI auslösbar).

## GUI

`Admin → Core-Konfiguration → Backup` (`/admin/backup`): Sicherung erstellen,
auflisten, **Prüfen** (Checksumme), **Probe-Restore** und Löschen. Die
destruktive Produktions-Wiederherstellung bleibt der CLI vorbehalten.

## Ablage & Aufbewahrung

- Sicherungen liegen auf dem persistenten Volume `core_backups`
  (`/var/www/html/backups/<id>/` mit `database.dump`, `files.tar.gz`,
  `manifest.json`); Metadaten in `core.backups`.
- **Off-Site:** Das Volume sollte zusätzlich extern gesichert/repliziert werden
  (Betreiber). Planung/Scheduling/Aufbewahrungsdauer sind betreiberseitig — ein
  periodisches `backup create` lässt sich z. B. als Modul-Scheduled-Task
  (`core.collector.scheduled`, s. `MODULE_DEVELOPMENT.md`) oder per Host-Cron
  einrichten.

## Wiederherstellungspunkt vor Updates

Unabhängig davon erstellt der Update-Manager vor migrationsbehafteten Updates
automatisch einen eigenen Wiederherstellungspunkt (Kap. 28.14.2).

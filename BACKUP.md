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

**Format:** **ein ZIP-Archiv** `<id>.zip` mit `database.dump`, `files.tar.gz` und
`manifest.json` — alle Daten zusammen in einer Datei.

**Konsistenz:** Erstellung läuft unter dem **Lifecycle-Advisory-Lock** — während
des Snapshots gibt es keine Modul-Installation/-Sprachschreibvorgänge, DB und
Dateien passen also zueinander. Je Artefakt wird ein **SHA-256** abgelegt
(Manifest + DB) → prüfbar.

## CLI

```bash
bin/cake backup create [--note "vor Update X"] [--path <dir>]
bin/cake backup list
bin/cake backup verify <id>                       # Prüfsummen prüfen
bin/cake backup test-restore <id>                 # Probe-Restore in Scratch-DB
bin/cake backup restore <id> --yes                # DESTRUKTIV: Produktion
bin/cake backup restore --from <pfad.zip> --yes   # aus beliebigem Archiv
bin/cake backup delete <id>
```

- **`test-restore`** spielt den DB-Dump in eine **Wegwerf-Datenbank**
  (`fertura_verify_*`) ein und prüft ihn dort (Anzahl `core`-Tabellen), **ohne**
  die Produktion zu berühren → so ist eine Sicherung *prüfbar*, bevor man sie braucht.
- **`restore`** ist destruktiv (`pg_restore --clean` ohne `--no-privileges`, damit
  die App-Rollen-GRANTs erhalten bleiben + Entpacken der Stores). Danach Dienste
  neu starten. Bewusst **CLI-only**. `--from` restauriert aus einer beliebigen
  ZIP-Datei (Linux-/Windows-Pfad).

## GUI

`Admin → Core-Konfiguration → Backup` (`/admin/backup`): Sicherung erstellen
(optional mit Zielpfad), auflisten, **Prüfen**, **Probe-Restore** und Löschen;
Anzeige von Ablageort + Scheduler-Status. Die destruktive Produktions-
Wiederherstellung bleibt der CLI vorbehalten.

## Ablageort, Scheduler & Aufbewahrung

- **Ablageort konfigurierbar** (`backup.path`; GUI/`--path`). Akzeptiert Linux-
  (`/mnt/backups`) **und** Windows-Pfade (`C:\Backups`, `\\server\share`); im
  Container muss ein **gemounteter Linux-Pfad** verwendet werden (Windows-Ordner
  per Docker-Volume mounten). Standard: Volume `core_backups`
  (`/var/www/html/backups`). Metadaten in `core.backups`.
- **Automatik (Scheduler):** `backup.schedule.enabled`,
  `backup.schedule.interval_hours`, `backup.retention` (kappt älteste). Läuft im
  Core-Worker (`BackupScheduledTask` über `core.collector.scheduled`).
- **Off-Site:** Den Ablageort zusätzlich extern replizieren (Infra-Aufgabe, 20.1.1).

## Wiederherstellungspunkt vor Updates

Unabhängig davon erstellt der Update-Manager vor migrationsbehafteten Updates
automatisch einen eigenen Wiederherstellungspunkt (Kap. 28.14.2).

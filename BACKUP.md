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

**Format:** **ein ZIP-Archiv** `<YYYYMMDD-HHMMSS>_<id>.zip` (UTC-Zeitstempel im
Namen → gezielt identifizierbar) mit `database.dump`, `files.tar.gz` und
`manifest.json` — alle Daten zusammen in einer Datei.

**Konsistenz:** Erstellung läuft unter dem **Lifecycle-Advisory-Lock** — während
des Snapshots gibt es keine Modul-Installation/-Sprachschreibvorgänge, DB und
Dateien passen also zueinander. Je Artefakt wird ein **SHA-256** abgelegt
(Manifest + DB) → prüfbar.

## Schutz, Verifikation & Protokoll (E56)

- **Verschlüsselung (Segregation of Duty):** Ist `backup.password` gesetzt, wird
  der **Archivinhalt AES-256-verschlüsselt** (DB-Dump, Stores, Manifest). Ohne
  Passwort ist nichts lesbar. Das Passwort ist ein **Secret-Setting** (nie im
  Klartext angezeigt); ohne Passwort warnt die GUI. **Wichtig:** Geht das
  Passwort verloren, sind die Backups unwiederbringlich — Passwort **getrennt**
  vom Backup verwahren (idealerweise ein anderer Verantwortlicher als der
  System-Admin → echte SoD). Restore: `--password` überschreibt das Setting.
- **Verifikation vor Abschluss:** Nach dem Schreiben prüft der Core **immer** die
  Integrität (Prüfsummen) und — bei `backup.verify_on_create=true` (Default) —
  zusätzlich einen **Probe-Restore in eine Scratch-DB**. Schlägt etwas fehl, wird
  das Backup als `failed` verworfen → nur **verifizierte** Backups zählen.
- **Protokoll:** Jede Operation (Backup, Restore, Restore-from, Löschen) wird in
  `core.backup_log` mit Zeit, Herkunft (cli/gui/scheduler), Benutzer und Ergebnis
  festgehalten und in der GUI gelistet.
- **Health:** Subsystem `backup` meldet `degraded`, wenn der Scheduler aktiv ist
  und das jüngste Backup fehlt/fehlschlug/überfällig (> 2× Intervall) ist.

## Weitere Betriebsfunktionen (E57)

- **Unveränderliches Protokoll:** `core.backup_log` ist append-only (DB-Trigger,
  wie das Audit-Log) — Einträge können nicht geändert/gelöscht werden.
- **Download aus der GUI:** Jede Sicherung lässt sich als ZIP herunterladen
  (`/admin/backup/download/<id>`); der Export wird als sensible Aktion
  **protokolliert** (`operation=download`).
- **Aufbewahrung nach Alter:** zusätzlich zu „letzte N" (`backup.retention`) per
  `backup.retention_days` (0 = aus); der Scheduler wendet beide an.
- **Pre-Flight-Speicherprüfung:** vor dem Dump wird der freie Platz am Zielort
  geprüft (Schätzung DB-Größe + Stores, mind. `backup.min_free_mb`) → bricht
  früh ab statt eine halbe Datei zu schreiben.
- **Alarm bei Fehlschlag:** ist `backup.alert_email` gesetzt, geht bei jedem
  fehlgeschlagenen Backup (inkl. Pre-Flight, v. a. unbeaufsichtigt im Scheduler)
  eine E-Mail über den Core-`MailService`.

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

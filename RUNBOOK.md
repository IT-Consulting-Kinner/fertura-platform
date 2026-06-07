# Betriebs-Runbook — Backup, Restore & Updates

Zwei Ebenen mit getrennter Zuständigkeit (Kap. 20.1):

- **Infrastruktur** (Systemadministrator, außerhalb Fertura): Host-/Volume-
  Snapshots, PITR/Replikation, **Off-Site-Ablage**, Scheduling/Aufbewahrung der
  Infrastruktur. Sichert u. a. das Container-Image (= **Core-Code**, als
  Versions-Tag reproduzierbar) und die Volumes.
- **Daten** (Fertura Core, 20.1.2): konsistente, prüfbare Sicherung von **DB +
  persistenten Stores** (`language-store`, `marketplace-data`, `modules` =
  Modul-/Extension-Code + Sprachen) als **ein ZIP-Archiv**.

## Kern-Invariante

**Core-Version ↔ DB-Schema-Version müssen beim Restore zusammenpassen.** Ein
Core-Update fährt i. d. R. Migrationen. Infra-Backup (Core-Code vX) und
Datenbackup (Schema vY) müssen beim Restore ein **versionsgleiches Paar** sein.
Daher: pro Datenbackup die **Core-Version notieren** (steht im Archiv-Manifest)
und beim Restore das passende Image (Tag vN) ziehen.

## Fertura-Daten-Backup

```bash
bin/cake backup create [--note "vor Update 1.1.0"] [--path <dir>]
bin/cake backup list
bin/cake backup verify <id>
bin/cake backup test-restore <id>                 # Probe-Restore in Wegwerf-DB
bin/cake backup restore <id> --yes                # DESTRUKTIV (Produktion)
bin/cake backup restore --from <pfad.zip> --yes   # aus beliebigem Archiv
bin/cake backup delete <id>
```

- **Ablageort** konfigurierbar: Setting `backup.path` (GUI: Core-Konfiguration)
  oder `--path`. Linux- **und** Windows-Pfade werden akzeptiert; im Container muss
  ein **gemounteter Linux-Pfad** verwendet werden (Windows-Ordner per Volume
  mounten). Off-Site-Replikation des Ziels = Infra-Aufgabe.
- **Automatik:** `backup.schedule.enabled` + `backup.schedule.interval_hours` +
  `backup.retention` (älteste werden gekappt). Läuft im Core-Worker.
- **GUI:** `Admin → Core-Konfiguration → Backup` (Erstellen/Prüfen/Probe-Restore/
  Löschen). Die destruktive Wiederherstellung bleibt CLI-only.

## Ablauf: Core-Update

| Phase | Aktion |
|---|---|
| **Vor Update** | Infra-Snapshot **+** `backup create --note "vor vX"` = Paar „vAlt". (Fertura erzeugt zusätzlich automatisch einen Wiederherstellungspunkt, 28.14.2.) |
| **Update** | Core aktualisieren |
| **Fehlschlag** | Infra(vAlt) zurück **→ danach** Datenbackup(vAlt) zurück → Stand vor Update |
| **Erfolg** | **sofort** Infra-Snapshot **und** `backup create` = Paar „vNeu"; Image-Tag vNeu festhalten |

## Ablauf: laufender Betrieb

- **Täglich** automatisches Datenbackup (Scheduler), off-site repliziert (Infra),
  periodisch `test-restore` zur Prüfung.
- **Systemfehler — Fehlertyp unterscheiden:**
  - *Nur Daten defekt* (App/Code ok): nur Fertura-**Daten**restore (gleiche
    Version) — schneller, kein Infra-Eingriff.
  - *Laufzeit/Volume/Host defekt*: **Infra zuerst** (Core vNeu) → **dann** das
    letzte passende Datenbackup.

## Restore-Reihenfolge & Nacharbeit

1. (falls nötig) Infrastruktur wiederherstellen / passendes Image (vN) ziehen.
2. Fertura-Datenbackup einspielen (`restore --yes` / `--from`) — **zuletzt**, da
   autoritativ für DB + Module + Sprachen. Der Restore erhält die App-Rollen-
   GRANTs + RLS.
3. Dienste neu starten: `docker compose restart core worker`.
4. Prüfen: `/health` bzw. `/api/v1/health`, Login, Modul-Status.

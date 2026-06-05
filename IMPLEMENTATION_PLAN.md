# Core-Implementierungsplan

Lebende Checkliste für die Umsetzung des **Core** (= die Plattform). Baseline
für Verifikation ist das **Plattform-Anforderungsdokument v6.28**
(`Plattform_Anforderungsdokument_v6_25.md`). Das Ticketing-Dokument ist ein
*Modul* und nicht Teil des Core.

## Arbeitsweise

- **Modus:** autonom (b). Pro Schritt: umsetzen → gegen die relevanten
  Plattform-Kapitel verifizieren → **Container-Lauffähigkeit** prüfen →
  Verifikationsbericht in diesem Dokument festhalten → erst dann weiter.
- **Stopp nur** bei echten Problemen oder Entscheidungen, die über den
  Anforderungskontext hinausgehen.
- **Autonome Entscheidungen** werden im Abschnitt „Entscheidungs-Log"
  dokumentiert (für spätere Durchsicht/Korrektur).
- **Branching:** ein laufender Branch `feat/core-foundation`, ein Commit pro
  (Teil-)Schritt; PR(s) an kohärenten Meilensteinen.

## Status-Legende

`[ ]` offen · `[~]` in Arbeit · `[x]` umgesetzt + verifiziert

## Schritte

- `[x]` **0. Fundament & Reproduzierbarkeit** — Entrypoint (`composer install`
  bei fehlendem vendor, Warten auf DB, geguardetes `migrations migrate`),
  Autostart; frische Klone + Autostart laufen sauber. *(Kap. 20.8, 28.14)*
- `[x]` **1. Migrations-Fundament & DB-Konventionen** — `cakephp/migrations`,
  Migration-Runner, Constraint-First (partielle Unique/Check/Exclusion),
  JSONB-Konventionen. *(Kap. 30, 1.8, 28.14.2)* → siehe `DB_CONVENTIONS.md`
- `[ ]` **2. Identität & Zugriff** — Benutzer, Gruppen, Gruppen-
  mitgliedschaften, Core-Administrationsbereiche, lokale Auth
  (Resolver-Default), Passwort-Policy, Anmeldeschutz, Anonymisierung.
  *(Kap. 27, 25.x soweit Core)*
- `[ ]` **3. Audit-Log & Logging** — referenzrobuste Einträge (textuelle
  Bezeichner), JSONB-Payloads, unveränderlich. *(Kap. 1.6, 24.16, 20.6)*
- `[ ]` **4. Konfigurationsspeicher** — Core-Settings (Key/Value + JSONB),
  „deaktivieren statt löschen", Audit. *(Kap. 23.3, 1.6)*
- `[ ]` **5. Contract-/Capability-Registry** — 4 Contract-Typen
  (Resolver/Collector/Event/Service), Registry, Capability-Bindung,
  Validierung, Versions-Matching. *(Kap. 26, 26.6.4)*
- `[ ]` **6. Event-Outbox + Worker** — transaktionaler Outbox,
  LISTEN/NOTIFY, Worker-Command, mindestens-einmal/idempotent.
  *(Kap. 26.9.2, 30.6)*
- `[ ]` **7. Modul-Manifest & Lifecycle** — Manifest, Paketformat,
  Install/Aktivieren/Deaktivieren/Update/Löschen, Abhängigkeits-/
  Kompatibilitätsprüfung, Signatur/Vertrauensanker, Advisory-Lock.
  *(Kap. 24, 23.10, 24.18)*
- `[ ]` **8. Marketplace / Lizenz / Update-Manager** — Marketplace-Komm.,
  Signaturprüfung, Offline-first-Lizenz, Core-/Modul-Update,
  Wiederherstellungspunkt. *(Kap. 28, 24.9.2)*
- `[ ]` **9. BREAD + RLS-Infrastruktur** — BREAD-Ressourcen/Aggregation
  (Core-Seite), RLS-Konventionen + Session-Kontext, Bypass-Pfade.
  *(Kap. 25, 30.3)*
- `[ ]` **10. Admin-Bereich (GUI)** — SSR/Bootstrap: Benutzer/Gruppen/
  Administrationsbereiche, Registry-Sichten, Modulverwaltung,
  Abhängigkeitsgraph, Update-Oberfläche. *(Kap. 23.8, 27.17, 28.17)*
- `[ ]` **11. Öffentliche Modul-Interfaces / Integrations-Infra** —
  Service-Contracts, Interface-Registry, registrierte Nutzung.
  *(Kap. 29)*
- `[ ]` **12. Observability** — `/health` (Liveness + Detail), Health-
  Collector, strukturierte Logs, Admin-Statusfläche. *(Kap. 20.2)*

## Verifikationsbericht je Schritt

> Wird je Schritt befüllt: geprüfte Kapitel, Soll/Ist, Container-Lauf,
> Testergebnis, offene Punkte.

### Schritt 0 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 20.8 (getrennte Dienste / „clone & up"), 28.14 (Bereitstellung als Container).

**Soll → Ist:**
- Reproduzierbarer Start ohne manuelle Schritte → `docker/php/entrypoint.sh` installiert bei fehlendem `vendor/` automatisch (`composer install`), wartet per `pg_isready` auf die DB, führt `migrations migrate` (geguardet) aus und startet dann erst `php-fpm`. ✔
- Getrennte Dienste (core/web/db/worker/mail) → in `docker-compose.yml` vorhanden, alle `restart: unless-stopped`. ✔
- Rollenabhängiges Verhalten → `ROLE=core` initialisiert (Deps + Migrationen); `ROLE=worker` wartet auf das von core erzeugte `vendor/autoload.php` (kein Race auf dem geteilten Volume). ✔
- Frische Klone laufen sauber → mit leerem `vendor`-Volume (Fresh-Clone-Simulation) automatisch befüllt und hochgefahren. ✔
- Autostart ohne offenes Terminal → systemd in WSL aktiv, `docker.service` `enabled`; zusätzlich Windows-Anmelde-Aufgabe „Fertura Dev Autostart" bootet die Distro + `docker compose up -d`. ✔

**Container-Lauf / Testergebnis (Fresh-Clone-Verifikation):**
- Entrypoint-Log: `composer install` → „Datenbank erreichbar" → `migrations migrate` → „All Done. Took 1.1161s" → „starte: php-fpm" → „fpm is running, pid 1".
- `bin/cake version` → `5.3.6`, RC=0 (nach Behebung des 9p-Stalls, s. E3).
- HTTP `http://localhost:8080/`: 1. Request 504 (Cold-Start, s. offene Punkte), 2. Request **200**, CakePHP-5.3.6-Welcome inkl. grüner DB-Verbindung.
- `docker compose ps`: alle Dienste `Up`, db `healthy`, core/worker mit Entrypoint `/usr/local/bin/entrypoint.sh`.
- Autostart-Skript `docker/autostart/fertura-up.ps1` idempotent getestet (laufende Container bleiben), Scheduled Task State `Ready`.

**Offene Punkte / Beobachtungen:**
- **Cold-Start-504:** Der allererste HTTP-Request nach `up` kann einmalig 504 liefern, weil php-fpm die Quelldateien erstmalig über den 9p-Bind-Mount lädt; ab dem 2. Request stabil 200. Akzeptabel für Dev; ggf. später per OpCache-Preload/Warmup-Request entschärfen.
- Voraussetzung für „kein Terminal offen": WSL-`systemd=true` + `systemctl enable docker` (beides vorhanden); Doku-Hinweis in README sinnvoll.

### Schritt 1 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 30.1 (Constraint-First), 30.5 (JSONB), 1.8 + 28.14.2
(reversible/transaktionale Migrationen, expand/contract), Entscheidungen 155, 173,
174, 176.

**Soll → Ist:**
- Migrations-Framework/Runner → `cakephp/migrations` 5.2.1, Verzeichnis
  `core/config/Migrations`, Ausführung via `bin/cake migrations migrate` (im
  Entrypoint automatisiert). ✔
- DB-Konventionen festgelegt + dokumentiert → `DB_CONVENTIONS.md` (Schema-Trennung,
  PK, Zeitstempel, deaktivieren-statt-löschen, Constraint-First, JSONB, Naming,
  RLS/Partitionierung/Advisory-Lock als Ausblick). ✔
- Erste Fundament-Migration `CoreFoundation` → Schema `core`, Extension
  `btree_gist` (für Exclusion-Constraints), gemeinsame Trigger-Funktion
  `core.set_updated_at()`. Explizite `up()`/`down()`. ✔
- Connection auf Postgres scharfgestellt → `config/app.php`: Default-Treiber
  `Postgres` (zuvor MySQL-Skeleton-Rest), `encoding=utf8`, `init` setzt
  `search_path=core, public`. ✔

**Container-Lauf / Testergebnis:**
- `migrate`: „CoreFoundation: migrated", Status `up`.
- Verifiziert via `psql`: `core`-Schema, `btree_gist`, `core.set_updated_at` vorhanden.
- **Reversibilität:** `rollback` → `core`-Schema + `btree_gist` entfernt (count 0);
  erneutes `migrate` → wiederhergestellt (count 1).
- Trackingtabelle: `public.cake_migrations` (Standard; `core` entsteht erst durch
  die Migration → bestätigt, dass `DROP SCHEMA core RESTRICT` im `down()` greift).
- HTTP `http://localhost:8080/` → 200 (Postgres-Default + search_path-init ok).

**Offene Punkte / Beobachtungen:**
- `cake_migrations` liegt in `public` (nicht `core`). Bewusst akzeptiert: Standard-
  ort des Runners; hält das `core`-Schema unabhängig droppbar. Bei Bedarf später
  in `core` verschiebbar.
- Constraint-First-Muster (partielle Unique/Check/Exclusion) sind als Konvention
  + Extension-Voraussetzung etabliert; konkrete Constraints entstehen an realen
  Tabellen ab Step 2.

## Entscheidungs-Log (autonome Entscheidungen)

| Nr. | Schritt | Entscheidung | Begründung (Anforderungskontext) |
| --- | --- | --- | --- |
| E1 | allg. | Branching: ein Branch `feat/core-foundation`, Commit pro (Teil-)Schritt, PR an Meilensteinen | Autonomer Mehrschritt-Modus; PR-pro-Schritt wäre Overhead. Nachvollziehbar über Commits. |
| E2 | 0 | Reproduzierbarkeit über Container-Entrypoint: bei fehlendem `vendor/` `composer install`; auf DB warten; `migrations migrate` geguardet. Nur `core` initialisiert (Deps + Migrationen); `worker` wartet auf `vendor`. | „clone & up" (Kap. 20.8.2) + Vermeidung von Race beim parallelen Start (core/worker teilen das Volume). |
| E3 | 0 | `vendor/` liegt auf einem Docker **Named Volume** (`core_vendor`, ext4 in der WSL-VM), **nicht** auf dem 9p-Windows-Bind-Mount. Quellcode bleibt gemountet (Live-Edit). | **Root Cause eines Boot-Hängers gefunden:** der CakePHP-Bootstrap blockierte in `p9_client_rpc` (State `D`), weil das file-lastige Autoloading über den 9p-Bridge-Mount (`/mnt/c/...`) stallt. vendor (tausende Dateien) auf ein VM-natives Volume zu legen, ist das Standard-WSL2-Muster und behebt den Hänger vollständig (`bin/cake version` 5.3.6/RC=0, HTTP 200). Nur Dev-Optimierung, kein Einfluss auf Core-Anforderungen/-Code. |
| E4 | 0 | Autostart über (a) systemd `docker.service` `enabled` in der WSL-Distro + `restart: unless-stopped` und (b) Windows-Anmelde-Aufgabe „Fertura Dev Autostart" (`docker/autostart/fertura-up.ps1`), die die Distro bootet und `docker compose up -d` ausführt. | Distro selbst startet nach Windows-Login nicht von allein; der Logon-Task ist der fehlende Auslöser. Skript ist idempotent und im Repo versioniert (auch für frische Klone verwendbar). |
| E5 | 1 | Schema-Trennung: `core` (Core), `mod_<modulkey>` (je Modul), `public` (Extensions/übergreifend). `search_path=core, public` als Connection-`init`; Migrationen qualifizieren `core.<name>` explizit. | Plattform trennt Core/Module klar und fordert RLS pro Modul (Kap. 30.3); Schemas bilden das sauber ab und erleichtern Grants/Scoping. Doku-offen → autonom. |
| E6 | 1 | Primärschlüssel: Standard `id bigint GENERATED BY DEFAULT AS IDENTITY`. UUID nur als zusätzliche `public_id uuid` (`gen_random_uuid()`), wo extern exponiert/nicht erratbar. | On-Prem; bigint = kompakt/indexfreundlich. UUID gezielt bei externer Sichtbarkeit. Doku-offen → autonom. |
| E7 | 1 | Zeitstempel `timestamptz` (UTC); Standardspalten `created_at`/`updated_at`; `updated_at` per BEFORE-UPDATE-Trigger über `core.set_updated_at()`. `created_by`/`updated_by` pro Tabelle ab Step 2. | Trigger = Defense-in-Depth unabhängig von App; Akteur-FK erst nach Existenz von `core.users`. Doku-offen → autonom. |
| E8 | 1 | „Deaktivieren statt löschen" via Spalte `active boolean NOT NULL DEFAULT true`; kein generisches `deleted_at`. | Direkte Umsetzung der Grundregel Kap. 1.6; passt zu partiellen Unique-Constraints (`WHERE active`). |
| E9 | 1 | Namenskonventionen: snake_case, Tabellen Plural; Constraints/Indizes explizit benannt (`fk_`, `uq_`, `ck_`, `ex_`, `ix_`, `gin_`, `trg_`). | Konsistenz/Lesbarkeit; CakePHP-ORM-kompatibel. Doku-offen → autonom. |
| E10 | 1 | `config/app.php`: Default-Connection von MySQL- auf Postgres-Treiber umgestellt (`encoding=utf8`), inkl. `test`-Connection. | Skeleton-Altlast; Projekt ist Postgres-only (Entscheidung 173). Bisher nur durch `url` zur Laufzeit kaschiert. |
| E11 | 1 | Migrations-Trackingtabelle `cake_migrations` verbleibt in `public`. | Runner-Standardort; `core` entsteht erst durch Migration. Hält `core` per `RESTRICT` unabhängig droppbar. |

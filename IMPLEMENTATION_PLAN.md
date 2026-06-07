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
- `[x]` **2. Identität & Zugriff** — Benutzer, Gruppen, Gruppen-
  mitgliedschaften, Core-Administrationsbereiche, lokale Auth
  (Resolver-Default), Passwort-Policy, Anmeldeschutz, Anonymisierung.
  *(Kap. 27, 25.x soweit Core)* — Teilschritte 2a (Datenmodell), 2b (lokale
  Auth), 2c (Anonymisierung + Anmeldeschutz).
- `[x]` **3. Audit-Log & Logging** — referenzrobuste Einträge (textuelle
  Bezeichner), JSONB-Payloads, unveränderlich. *(Kap. 1.6, 24.16, 20.6)*
- `[x]` **4. Konfigurationsspeicher** — Core-Settings (Key/Value + JSONB),
  „deaktivieren statt löschen", Audit. *(Kap. 23.3, 1.6)*
- `[x]` **5. Contract-/Capability-Registry** — 4 Contract-Typen
  (Resolver/Collector/Event/Service), Registry, Capability-Bindung,
  Validierung, Versions-Matching. *(Kap. 26, 26.6.4)*
- `[x]` **6. Event-Outbox + Worker** — transaktionaler Outbox,
  LISTEN/NOTIFY, Worker-Command, mindestens-einmal/idempotent.
  *(Kap. 26.9.2, 30.6)*
- `[x]` **7. Modul-Manifest & Lifecycle** — Manifest, Paketformat,
  Install/Aktivieren/Deaktivieren/Update/Löschen, Abhängigkeits-/
  Kompatibilitätsprüfung, Signatur/Vertrauensanker, Advisory-Lock.
  *(Kap. 24, 23.10, 24.18)* — Update + Signatur/Lizenz = Step 8.
- `[x]` **8. Marketplace / Lizenz / Update-Manager** — Marketplace-Komm.,
  Signaturprüfung, Offline-first-Lizenz, Core-/Modul-Update,
  Wiederherstellungspunkt. *(Kap. 28, 24.9.2)*
- `[x]` **9. BREAD + RLS-Infrastruktur** — BREAD-Ressourcen/Aggregation
  (Core-Seite), RLS-Konventionen + Session-Kontext, Bypass-Pfade.
  *(Kap. 25, 30.3)*
- `[x]` **10. Admin-Bereich (GUI)** — SSR/Bootstrap: Benutzer/Gruppen/
  Administrationsbereiche, Registry-Sichten, Modulverwaltung,
  Abhängigkeitsgraph, Update-Oberfläche. *(Kap. 23.8, 27.17, 28.17)*
- `[x]` **11. Öffentliche Modul-Interfaces / Integrations-Infra** —
  Service-Contracts, Interface-Registry, registrierte Nutzung.
  *(Kap. 29)*
- `[x]` **12. Observability** — `/health` (Liveness + Detail), Health-
  Collector, strukturierte Logs, Admin-Statusfläche. *(Kap. 20.2)*

## Spätere/offene projektweite Aufgaben

> Alle drei zuvor offenen Aufgaben sind am 2026-06-05 umgesetzt & verifiziert
> (Branch `feat/security-hardening`). Details: Abschnitt „Sicherheits-Härtung".

- `[x]` **App-DB-Rolle ohne Superuser (für RLS-Wirksamkeit):** Default-Connection
  läuft als **NOBYPASSRLS-Rolle** `fertura_app`; privilegierte Pfade (DDL,
  Migrationen, Modul-Lifecycle, Update, Worker) nutzen die `privileged`-Connection
  (Superuser). RLS greift damit zur Laufzeit. *(erledigt, E33)*
- `[x]` **Lizenz- & Signaturerstellungs-Verfahren (Betreiber-/Marketplace-Seite):**
  Root→Publisher-Schlüsselhierarchie, Zertifikatsausstellung (`mp_tool sign-key`),
  Dokument-Signatur (`sign-doc`), persistierte + geprüfte Vertrauenskette;
  dokumentiert in `SIGNING.md`. *(erledigt, E32)*
- `[x]` **Schlüsselrotation-CLI** (`secret rotate`, Re-Encryption verschlüsselter
  Settings, Entscheidung 164). *(erledigt, E31)*

## Merkliste / offene Punkte (Verifikation 2026-06-05)

Ergebnis einer systematischen Re-Verifikation des Codes gegen Kap. 1, 20, 23–30.
Die zentralen Muss-Mechanismen (Signatur/Vertrauenskette, Lifecycle, BREAD,
Registry/Contracts, Outbox, RLS-Wirksamkeit, Health, Audit-Unveränderlichkeit,
Container-Deployment) sind erfüllt. Offen sind v. a. Rand-/Transparenz- und
GUI-Funktionen:

### A. Echte Muss-Lücken — ✅ alle geschlossen & verifiziert (2026-06-05, Branch `feat/release-gaps`)

> Alle 8 Punkte umgesetzt und im Container verifiziert. Belege je Punkt in
> Klammern. Frischer Bootstrap (force-recreate) mit Migrationen 071–074 sauber;
> alle Admin-/Public-Seiten HTTP 200 als NOBYPASSRLS-App-Rolle.

1. ✅ **Lizenz: Online-Enforcement + Karenzfenster** (28.7.3.1). `LicenseService::
   evaluate()` wertet Karenzfenster + Online-Bestätigungsalter aus → Status
   valid|grace|needs_online|expired; `recordOnlineCheck()`. *(evaluate liefert
   valid/grace/expired/needs_online/valid/grace wie erwartet)*
   Felder `online_enforcement`/`grace_window_days`/`last_online_check` werden
2. ✅ **Widerrufene Signatur installierter Module** (24.9.2). `modules.
   signature_key_id` beim Install erfasst; `TrustStore::revokeKey/
   reconcileModuleSignatures` markiert betroffene Module `signature_status=revoked`
   (keine Auto-Deaktivierung), Anzeige in Modul-Liste + Health. *(Widerruf →
   status=revoked)*
3. ✅ **CRL-Cache-Alter / Stale-Warnung** (24.9.2). `MarketplaceClient` datiert
   CRL-Abrufe (`marketplace_meta`), `crlState()` liefert Alter/Schwelle/stale;
   Health-Subsystem `marketplace` warnt. *(30 Tage → stale, frisch → ok)*
4. ✅ **Sicherheitsupdate-Kennzeichnung** (28.10). Manifest `security`/`severity`,
   `update_history.is_security`/`severity`; Vorschau + Historie heben sie hervor.
   *(security=true/severity=high in Historie, Badge sichtbar)*
5. ✅ **Migrationsvorschau vor Update** (24.13/28.8.1). `UpdateManager::previewModule/
   previewCore` + `ModuleMigrationRunner::listPending`; GUI führt über Vorschau mit
   Bestätigung. *(Vorschau zeigt Zielversion+Migration, führt nichts aus)*
6. ✅ **Session-Timeout verdrahtet** (27). `Application::bootstrap` wendet
   `session.timeout_minutes` auf `Session.timeout` an. *(120 → 7 greift)*
7. ✅ **Einladungs-/Passwort-Setz-Flow** (27.2/27.15). `password_reset_tokens` +
   `PasswordResetService`; Admin erzeugt Einladungslink / setzt Passwort direkt;
   öffentliche `/set-password`. *(invited → Link → Passwort → active → Login;
   Token-Wiederverwendung abgelehnt)*
8. ✅ **BREAD-Admin-UI vollständig** (25.11/25.12). `resources.group_capable`;
   `setPermission` mit Einzelobjekt + Zusatzaktionen; nicht-gruppenfähige
   Ressourcen ausgeblendet. *(report ausgeblendet, extra[trigger]+resource_key
   persistiert)*

Begleitend behoben: Core-Update-Migrationen laufen über die `privileged`-
Connection (`Db::privilegedName`), da der Default zur Laufzeit die NOBYPASSRLS-
Rolle ist.

### B. Soll / Robustheit

> Onboarding-Paket (Branch `feat/user-onboarding`, 2026-06-05) hat mehrere Punkte
> geschlossen — s. ✅ unten.

- ✅ **Selbst-Aussperr-Schutz** (E35): kein Selbst-Deaktivieren/-Anonymisieren;
  letzter aktiver `user_group_admin`-Träger geschützt; Aktivierung nur mit
  gesetztem Passwort.
- ✅ **Core-Mailversand für Identitätsmails** (E35): `MailService` versendet
  Einladung + Passwort-Reset über den konfigurierten Transport; Self-Service
  `/forgot-password`. (Schließt die E-Mail-Naht der Onboarding-Review.)
- ✅ **„Benutzer bearbeiten"** (E35): Name/E-Mail nachträglich änderbar.
- ✅ **Anker-Gültigkeitsdauer** (`valid_from/valid_to`, E45, 2026-06-07): an allen
  Verifikationspfaden durchgesetzt — Paketinstallation (`PackageVerifier`),
  Vertrauenskette (`TrustChain`, Root-Fenster) und Lizenzprüfung
  (`LicenseService`). `TrustStore::validity()` (NULL = unbegrenzt); `addAnchor`
  + CLI `trust add-anchor --valid-from/--valid-to` setzen das Fenster, `trust
  list` zeigt es + `UNGUELTIG`. *(Unit + signiertes Paket: abgelaufen/noch-nicht
  → Abbruch, innerhalb/unbegrenzt → OK.)*
- ✅ **Externe API / Token-Authentifizierung** (Kap. 29, E49, 2026-06-07; vom
  Nutzer als „voll ausbauen" beauftragt): Bearer-Token-Schicht unter `/api/v1`
  (`ApiAuthMiddleware`, CSRF-Skip für `/api`), Scopes je Token (`TokenService`,
  SHA-256-Hash, Klartext einmalig), JSON 401/403; Endpunkte `me`/`health`/
  `modules` (scope-geschützt); Self-Service-GUI `/admin/tokens` (erstellen/
  widerrufen, Secret-Einmalanzeige); Audit; `API.md`. *(E2E: 200/403/401/Widerruf,
  Scope-Gate, last_used; GUI-Smoke create/once-shown/revoke verifiziert)*
- ✅ **Cron-Status-Widget** (20.3, 2026-06-07): Worker schreiben jetzt `duration_ms`
  + `interval_seconds` in den Heartbeat-`detail`; `HealthService::checkWorkers`
  nutzt einen **per-Worker-Schwellwert** (überfällig = Alter > 2× Intervall, sonst
  globaler Max-Alter-Fallback) und liefert Lauf-Dauer + `overdue`; die Admin-
  Health-Sicht zeigt Dauer-Spalte + „überfällig"-Badge. *(Overdue-Logik per
  Harness; echter Worker schreibt `duration_ms=6/interval=5s` verifiziert)*
- **Strukturierte Logs** (20.2.3 Soll): `component/module/correlation_id` nur,
  „sofern am Aufrufort mitgegeben" — kein erzwingender Processor.
- ✅ **Dead-Letter-Admin-Sicht/Retry** (26.9.2, E48, 2026-06-07): Admin-Sicht
  `/admin/outbox` (unter Core-Konfiguration) mit Status-Zählern + Dead-Letter-
  Liste; pro Event **Retry** (→ `pending`, Zähler/Lock/Fehler zurückgesetzt) oder
  **Verwerfen** (neuer Terminalstatus `discarded`), plus „alle wiedereinstellen".
  Beide Aktionen auditiert (`outbox.retry/discard/retry_all`). *(Service-Harness
  retry/discard/retryAll + Audit; GUI-Smoke render + Retry→pending verifiziert)*
- **Grafische Abhängigkeits-/Slot-Darstellung** (23.13.1/24.15.1, Soll): nur Liste.
- ✅ **RLS-Verpflichtung erzwungen** (30.3, E47, 2026-06-07): Wer `is_scoped`-
  Ressourcen deklariert, dessen Modul-Schema muss nach den Migrationen mindestens
  eine RLS-aktivierte Tabelle **mit** Policy enthalten — sonst Install-Abbruch mit
  sauberem Rückbau (Schema/Stammdaten/Verzeichnis). Fixture `sample_module` als
  vorbildliches Modul (RLS + Policy gegen den Core-Kontext). *(vorbildlich →
  Install ok; scoped ohne RLS → blockiert + Rollback verifiziert)*
- ✅ **Manifest-Pflichtfelder** (24.4.1, E46, 2026-06-07): `description` und
  `publisher` werden jetzt validiert (waren dokumentiert-pflichtig, aber
  ungeprüft). Spec-`entrypoint` ist über `php_namespace` (Autoload-Wurzel,
  `ModuleAutoloader`) realisiert; `signature` ist die separate Paketsignatur.
  *(Fixture gültig; ohne description/publisher → beide Felder gemeldet)*

### C. Bewusst Modul-/Betreiber-Scope bzw. „spätere Version" (keine Abnahme-Lücke)

- Out-of-Process-Sandbox / Drittanbieter-Isolation (23.16, spätere Version).
- E-Mail-Betrieb 20.4, `fetch_mails`/`check_escalations`/… (Ticketing-Modul).
- Matrix-Konfiguration 1.5, fachliche Entitäten 1.6 (Ticketing-Modul).
- Backup/Restore 20.1, Betreiber-Alerting/Dashboards 20.2.5 (Betreiber).
- Integrations-Extension-Module + deren Datenhaltung 29.9/29.10 (Modul).
- Konkrete RLS-Policies je Modultabelle (Modul liefert via Migrationen).
- Gleitende Zero-Downtime-Schlüsselrotation mit Key-ID (1.4, spätere Version).

### D. Bekannte Dev-/Betriebsbeobachtungen (dokumentiert)

- Cold-Start-504 beim allerersten Request (9p-Mount, Dev).
- Langlaufende Worker brauchen Neustart nach Code-Änderung.
- Worker-Heartbeat liegt in `core.worker_heartbeats` statt `system_settings`
  (sachlich gleichwertig; Abweichung dokumentiert).

## i18n / Mehrsprachigkeit — Implementierungsplan (finalisiert 2026-06-05)

Design finalisiert mit dem Nutzer (Entscheidungen E37–E41). Umsetzung in 8
verifizierbaren Etappen; nach jeder Etappe Container-Verifikation, dann die
nächste.

**Querschnitt-Prinzipien:** Basissprache **Englisch**; **symbolische Schlüssel**
(`<bereich>.<sache>.<variante>`); Domain = `component_key` (Core: `default`);
Locale `ll_CC`, **flacher** Fallback auf Englisch der Version; jeder Text über
`__()/__d()/__x()`.

- `[x]` **i18n-1 — Laufzeit & Locale-Auflösung** (verifiziert 2026-06-05): `LocaleMiddleware` (Präzedenz
  Session/`?lang` → `user.locale` → opt. `Accept-Language` → System-Default),
  `I18n::setLocale`+`intl.default_locale`; Settings `locale.default`/`locale.enabled`;
  Englisch-Fallback. *Verifik: öffentliche Auth-Seiten als Testfläche, ?lang
  schaltet, fehlender Key → Englisch, Datums-/Zahlenformat folgt.*
- `[x]` **i18n-2 — Core-UI auslagern** (verifiziert 2026-06-05): alle harten Strings → `__()`; **`en_US`**
  (kanonisch) + **`de_DE`** (bisherige Texte, keine Regression); Schlüssel-
  konvention `<bereich>.<element>`. ~330 Schlüssel; statische Vollabdeckung
  (328/328) + CLI-Fallback + HTTP-Render de/en verifiziert.
- `[x]` **i18n-3 — Managed Locale Store + Metadaten + sicheres Schreiben** (verifiziert 2026-06-06):
  persistentes Volume `core_langstore`; Metadaten-Migration `core.language_packs`;
  `LanguagePackStore` mit `.tmp`+`fsync`→atomarer Rename (CakePHP liest PO direkt
  → kein MO nötig); Recovery/Cleaner mit **pg-Advisory-Lock** (in-flight vs.
  verwaist), Selbstheilung/Bereinigung; CLI `lang recover`. *(save/read/recover
  clean/promote/in-flight verifiziert)*
- `[x]` **i18n-4 — Komponenten-Integration** (verifiziert 2026-06-06): Manifest `locales`; Install kopiert
  Paket-`locales/` in den Store; Aktivierung registriert Domain; Deinstallation
  behält Dateien; Modul-Fixture.
- `[x]` **i18n-5 — Auflösung, Versions-Gate & Status** (verifiziert 2026-06-07):
  `LocaleResolver` (exakt→clean > Same-Major-höchste→notice > Major-Mismatch→null/
  Englisch-Fallback/error); `packStatuses` (clean/notice/error je Pack);
  `availableLocales` (Core-Kataloge resources/locales + nutzbare Core-Store-Packs).
  `StoreLocaleLoader` nutzt das Gate (beste statt exakter Version); Core-Domain
  `default` überlagert nachgeladene Core-Store-Packs. *(Gate exakt/notice/error +
  major0 + packStatuses + availableLocales verifiziert; i18n-4 Exakt-Auflösung
  unverändert grün.)*
- `[x]` **i18n-6 — Sprachverwaltung (Admin-Bereich `localization`, 7.)** (verifiziert
  2026-06-07): 7. Admin-Bereich (Migration + NAV); `LocalizationController` +
  `LanguagePackAdmin` + `PoDocument` (verlustfreier Parser/Serializer). Übersicht
  (aktiv/inaktiv, Status clean/notice/error, Flags signed/reviewed/edited);
  **Feld-Editor** (nur msgstr, Struktur erhalten; Save→edited=yes/reviewed=yes via
  atomarem Store-Write); Import = unsignierter `.po`-Upload (Review-Vorschau,
  Re-Import-Warnung bei `edited`, Commit→signed=no/reviewed=no/source=upload, E42);
  Löschregeln (aktiv: nicht Englisch; inaktiv: alles); Review. *(PO-Roundtrip 402
  Einträge verlustfrei; overview/edit/delete-Regeln/import CLI-verifiziert; GUI-
  Smoke index/edit/import je HTTP 200, kein Raw-Key-Leak.)*
- `[x]` **i18n-7 — Umschalter, Benutzer-/Session-Locale, Einstellungen** (verifiziert
  2026-06-07): View-Cell `LocaleSwitcher` (No-JS-Inline-Buttons) im Admin-Layout
  (persistent) und Login-Layout (Session via `?lang`); `LocaleController::change`
  schreibt Session + `user.locale` (privilegiert); `LocaleResolver::selectableLocales`
  (`locale.enabled` ∩ Core-nutzbar); Accept-Language-Fallback in der
  `LocaleMiddleware` (q-Gewicht, Sprach-Präfix); Settings `locale.default/enabled`
  über die Config-GUI editierbar (i18n-1). *(selectable/Accept-Language CLI-grün;
  HTTP: Accept-Language→`html lang`, Switcher Login+Admin, persistenter Wechsel
  → `user.locale=de_DE` + Admin auf Deutsch.)*
- `[x]` **i18n-8 — Audit, Health, Entwicklerdoku** (verifiziert 2026-06-07):
  Verwaltungsaktionen schreiben Audit (`lang.import/edit/delete/review`,
  `entity_type=language_pack`); Health-Subsystem `localization` (fehlende
  Englisch-Basis aktiver Komponenten, Versionsfehler Major-Mismatch, verwaiste/
  in-flight `.tmp`; read-only, Heilung via `lang recover`); Entwicklerleitfaden
  `I18N.md`. *(Audit aller 4 Aktionen geschrieben; Health meldet Versionsfehler
  live + erkennt stray `.tmp`; missing_base korrekt.)*

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

### Schritt 2 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 27 (Identität & Zugriff), Entscheidungen 135 (Zwei-Ebenen-
Rechtemodell), 160 (Anonymisierung), 162 (Anmeldeschutz/Token), 170 (6 feste
Administrationsbereiche), 171 (Auth-Resolver, lokal als Default). Bewusst NICHT
in Step 2 (spätere Schritte): BREAD-Ressourcen (25)→Step 9, RLS (30.3)→Step 9,
Admin-GUI/Login-Formular→Step 10, Resolver-Registry→Step 5, Config-Store
(Policy/Schwellen in DB)→Step 4.

**2a — Datenmodell (core-Schema):** `users` (Status invited|active|disabled|
anonymized, case-insensitive unique), `groups` (active), `groups_users`
(Mehrfachmitgliedschaft), `admin_areas` (6 Bereiche geseedet), `user_admin_areas`,
`api_tokens`, `auth_failures`. Constraint-First (FK/Unique/Check/Trigger).
Verifiziert: migrate/rollback/migrate, Seed, Constraints.

**2b — Lokale Auth:** cakephp/authentication; Form+Session-Authenticator,
Password-Identifier gegen `core.users` (Finder „active"), bcrypt; Command
`create_admin` (Volladmin + 6 Bereiche). Schema-Konsistenz: `schema=core` für
Reflektion, `schema_init`-Command + Entrypoint stellen `core` vor dem Runner
bereit → `cake_migrations` liegt in `core`. Verifiziert per **sauberem
Rebuild/Fresh-Bootstrap**: beide Migrationen `up`, Admin (active, 6 Bereiche),
Passwort-Hash correct=true/wrong=false, HTTP 200.

**2c — Anonymisierung & Anmeldeschutz:** `UsersTable::anonymize()` (irreversibel,
transaktional: Identitätsfelder → nicht rückführbare Platzhalter, password_hash→
null, Tokens widerrufen, ID/Mitgliedschaften/Referenzen bleiben); Command
`anonymize_user` (Einladungs-Accounts physisch löschbar). `LoginThrottle`-Service
mit sicheren Defaults (10 Versuche / 15 min) auf `auth_failures`. Verifiziert:
Feld-Scrubbing, Einladungslöschung, Fensterlogik (alter Versuch ausgeschlossen),
HTTP 200.

**Offene Punkte / Beobachtungen:**
- Audit-Einträge für Identity-Events (Kap. 27.18) werden in **Step 3** ergänzt
  (Audit-Log). Hooks an create_admin/anonymize/Gruppenänderungen dann nachziehen.
- HTTP-Login-Formular + Throttle-/Auth-Erzwingung im Request-Pfad: **Step 10**
  (Admin-GUI). Logik/Daten dafür liegen bereits vor.
- Passwort-Policy/Throttle-Schwellen aktuell als Code-Defaults; DB-Konfiguration
  ab **Step 4**.
- Assoziationen von `UsersTable` (Groups/AdminAreas/Tokens als ORM-Relationen)
  folgen mit ihren Table-Klassen, wo benötigt (Step 9/10).

### Schritt 3 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 1.6, 24.16/24.16.1 (Entscheidung 163 Referenzrobustheit),
20.6 (Unveränderlichkeit/Partitionierung), 27.18 (Identity-Ereignisse), 30.5
(JSONB/GIN), 30.8 (Entscheidung 179 Partitionierung).

**Soll → Ist:**
- Unveränderliches Audit-Log → `core.audit_log`, **RANGE-partitioniert** über
  `created_at`; Immutability-Trigger blockiert UPDATE/DELETE (Bypass nur via
  `SET LOCAL app.allow_audit_mutation='on'`). ✔
- Referenzrobustheit/PII → Personen per auflösbarer UUID (`actor_user_id`,
  person-`entity_id`); textuelle Schnappschüsse (`entity_label`, `module_*`) nur
  für nicht-personenbezogene Entitäten (E16). ✔
- JSONB-Payload + GIN → `old_value`/`new_value` jsonb, GIN-Indizes; B-Tree-Indizes
  auf actor/entity/action/module/correlation. ✔
- Partitionierung → DEFAULT-Partition (Netz) + Monatspartitionen via
  `bin/cake audit_partition` (Entrypoint, vor dem ersten Schreiben). ✔
- Schreib-Service → `App\Audit\AuditLogger` (transaktional über Default-Connection). ✔
- Identity-Events nachgerüstet (Kap. 27.18) → `create_admin` schreibt
  `user.create` + `admin_access.grant` (verknüpft via correlation_id);
  `UsersTable::anonymize()` schreibt `user.anonymize`. ✔

**Container-Lauf / Test:** partitionierte Tabelle + Default + 3 Monatspartitionen
(Indizes propagiert); Audit-Einträge mit JSONB + gemeinsamer correlation_id,
actor=<system> bei CLI; Routing in Monatspartition; UPDATE/DELETE blockiert,
Bypass funktioniert; `user.anonymize` erfasst; HTTP 200.

**Offene Punkte / Beobachtungen:**
- **Generisches Auto-Auditing** (Behavior/Event-Listener für CRUD, Kap. 1.8)
  folgt mit dem Admin-CRUD in **Step 10**; aktuell explizite Audit-Aufrufe an den
  vorhandenen Operationen.
- **Strukturiertes technisches Logging** (Kap. 20.2, SIEM, Korrelation) → **Step 12**.
- **Laufende Partitionspflege/Archivierung** (vorausschauend, alte Partitionen
  abtrennen) → Wartungs-Worker **Step 6**.
- Audit-Anzeige/Filter im Admin-Bereich → **Step 10**.

### Schritt 4 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 1.4 (Konfiguration in DB vs. app.php, Entscheidung 159),
23.3 (Konfigurationsspeicher), 1.6 (Audit), 27.16.3 (sichere Vorgabewerte,
Entscheidung 162), 30.5 (JSONB, Entscheidung 176), Entscheidung 164 (Secrets/
Schlüssel).

**Soll → Ist:**
- `core.settings` (namespace, config_key, value jsonb, value_encrypted, is_secret,
  Footprint, Trigger, unique(namespace,config_key)). ✔
- `SettingsManager.get/set` mit Katalog-Defaults (greifen ohne DB-Eintrag),
  Validierung (Typ/Bereich), transaktionalem Audit. ✔
- **Secrets verschlüsselt** (AES-256-GCM, `SecretCipher`, Schlüssel aus
  `Security.encryptionKey`/env, nicht aus der DB) — verifiziert: Klartext nicht im
  Chiffrat, Audit ohne Klartext. ✔
- **Code-Defaults aus Step 2 jetzt DB-konfigurierbar:** Passwort-Policy
  (`PasswordPolicy`, in `create_admin` durchgesetzt) + Anmeldeschutz-Schwellen
  (`LoginThrottle` liest aus Settings). ✔
- `setting`-CLI-Command (get/set) bis zur GUI (Step 10). ✔
- app.php/Compose/.env: `APP_ENCRYPTION_KEY` (+ `SECURITY_SALT`) verankert. ✔

**Container-Lauf / Test:** Migration inkrementell angewandt; Defaults, set/get,
Validierung, Secret-Round-Trip (kein Klartext-Leak), Audit `config.update`,
Throttle-Wiring; HTTP 200 nach Recreate (ohne web-Restart, E17).

**Offene Punkte / Beobachtungen:**
- **„Deaktivieren statt löschen"** gilt laut Kap. 23.3.1 für Konfigurations-
  *objekte* (Stammdaten), **nicht** für Setting-*Werte*: Settings dürfen auf den
  Default zurückgesetzt/gelöscht werden. Bewusst so umgesetzt (kein `active` auf
  `settings`).
- **Re-Encryption-CLI** (Schlüsselrotation, Entscheidung 164 = *Soll*) → bewusst
  zurückgestellt; Struktur (SecretCipher) vorbereitet.
- **Session-Timeout-Setting** definiert, aber Wiring an die Session folgt mit der
  GUI/Session-Konfiguration (Step 10).
- **Caching** der Settings (In-Memory/TTL) → später; aktuell direkter DB-Read.
- **Modul-Settings** (`<modul_key>.*`): Schema vorhanden, Verwaltung/Schemas via
  Manifest → Step 7.

### Schritt 5 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 26 (Contract-Modell, 4 Typen), 26.6.4 (Versions-Matching),
26.7 (Resolver-Slots, Entscheidung 129), 26.13.2 (Capability-Bindung,
Entscheidung 151), 26.17 (Auditierbarkeit), 29 (Service-Interfaces, soweit
Registry).

**Soll → Ist:**
- Datenmodell `contracts` / `contract_registrations` / `capability_bindings`
  (core-Schema, Footprint, Constraint-First inkl. Version-Format-Check). ✔
- **Slot-Exklusivität** (genau 1 aktiver Provider): partieller Unique-Index
  `… WHERE provider AND active` + sauberer Service-Fehler + Audit. ✔
- **Versions-Matching (26.6.4):** `SemVer` + `VersionConstraint` (exakt oder
  expliziter Bereich; Caret/Tilde verboten; gleiche Major + Angebot ≥ Anforderung). ✔
- **Capability-Bindung (151):** persistierte `capability_bindings` + Laufzeit-
  `CapabilityHandle`; Handle nur bei aktiver Bindung; Deaktivierung → sofort ungültig. ✔
- **ContractRegistry**: registerContract/register (Validierung: Existenz, Version,
  Slot, Typ-Match), deactivate, resolveProvider/collect/listeners, Bindings. ✔
- **Audit** aller Vorgänge (Kap. 26.17). ✔
- Verifikation ohne echte Module: `registry_selftest` (14 Checks grün) +
  read-only `registry_list`. ✔

**Container-Lauf / Test:** Migration inkrementell; partieller Unique-Index;
Selbsttest deckt Resolver/Collector/Service, Slot-Konflikt, Versions-Matching,
Handle-Guard, Deaktivierungs-Fallback, Audit ab; HTTP 200.

**Offene Punkte / Beobachtungen:**
- **Echte Modul-Registrierung** (aus dem Manifest bei Install/Activate) treibt die
  Registry in **Step 7** an; Step 5 liefert die Maschinerie + Self-Test.
- **Event-Zustellung** (Outbox/Worker, Listener-Aufruf) → **Step 6**.
- **Runtime-Dispatch** (Implementierungsklassen instanziieren/aufrufen) wird mit
  echten Modulen ab **Step 7** verdrahtet; Handle liefert bereits die Auflösung.
- **Lizenz-/Signaturprüfung** bei Registrierung → **Step 8** (Felder/Hooks bereit).
- Registry-Ansicht im Admin-Bereich → **Step 10**.

### Schritt 6 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 26.9.2 (transaktionaler Outbox, Entscheidung 168), 30.6
(LISTEN/NOTIFY, Entscheidung 177), 26.16 (Fehler-Isolation), 30.8 (Partitionierung).

**Soll → Ist:**
- `core.event_outbox` (RANGE-partitioniert über created_at; Status
  pending/processing/done/dead_letter; attempt_count/max_attempts/available_at/
  last_error). ✔
- **Transaktionaler Outbox:** `OutboxPublisher.publish()` schreibt + `pg_notify`
  in derselben Transaktion (Zustellung erst nach COMMIT). ✔
- **Worker** (`event_worker`, jetzt der echte worker-Container): LISTEN/NOTIFY +
  Poll-Fallback; Claim per `FOR UPDATE SKIP LOCKED` (mehrere Worker möglich);
  Listener aus der Registry, **isoliert** aufgerufen. ✔
- **Mindestens-einmal + Retry/Backoff + Dead-Letter:** Erfolg→done; Fehler→Retry
  (exponentiell, Basis 5 s); nach max_attempts→dead_letter (sichtbar via
  `outbox_status`, nie auto-gelöscht). Reclaim hängender 'processing' nach 5 min. ✔
- `EventListenerInterface` (idempotente Listener); `pcntl`-Graceful-Shutdown. ✔
- Partition-Command erzeugt nun Monatspartitionen für audit_log **und** event_outbox. ✔

**Container-Lauf / Test:** Fresh-Rebuild (pcntl, Worker-Command); E2E (publish →
Worker → done); Integrations-Selbsttest (7 Checks): Erfolg, Retry→Dead-Letter
(attempt=max, last_error), Listener-Isolation; HTTP 200.

**Offene Punkte / Beobachtungen / autonome Defaults:**
- Konkrete Werte (doku-offen → autonom): max_attempts=5 (pro Event override),
  Backoff exponentiell Basis 5 s (cap 1 h), Reclaim 5 min, Poll-Fallback 5 s,
  Batch 50, Channel `core_event_outbox`. Ab späterer Stufe via Settings (Step 4)
  konfigurierbar machbar.
- **Health-Endpoint** (Worker-Aktualität, Dead-Letter-Zähler) → **Step 12**.
- **Admin-GUI** für Dead-Letter/Retry → **Step 10** (CLI `outbox_status` bis dahin).
- **Core-eigene Events** (z. B. „Benutzer angelegt") optional; Infrastruktur steht,
  konkrete Emission folgt nach Bedarf (Module ab Step 7).

### Schritt 7 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 24.2–24.16 (Paket/Manifest/Lifecycle/Audit), 24.7.3
(Typregeln), 24.18 + 30.7 (Advisory-Lock, Entscheidung 165/178), 26.6.4
(Versions-Matching). Nutzer-Scope: Update + Signatur/Lizenz → Step 8.

**Soll → Ist:**
- Datenmodell `modules` (Zustandsautomat, Manifest-Kopie jsonb),
  `module_dependencies`, `module_migrations_log`. ✔
- `ModuleManifest` (Parser + Validierung: Pflichtfelder, SemVer, Typregel
  „Main ohne contracts_used", Core-Kompatibilität). ✔
- `ModuleLifecycle` (install/activate/deactivate/delete) **unter Advisory-Lock**
  (`pg_try_advisory_lock`, knotenübergreifend serialisiert); treibt die Step-5-
  Registry an (contracts_provided registrieren; Provider/Collector/Listener/
  Consumer bei Aktivierung; Deaktivierung → Registrierungen deaktivieren +
  Bindings widerrufen; Delete → Contracts/Registrierungen/Schema/Dateien weg). ✔
- **Modul-Migrationen** (SQL-Dateien, transaktional, im Modul-Schema `mod_<key>`,
  getrackt). ✔
- **Echtes Modul-Code-Laden:** eigener PSR-4-Autoloader (`ModuleAutoloader`);
  aktive Module beim App-Bootstrap + per Worker-Wake registriert. ✔
- Lifecycle vollständig **auditiert** (Kap. 24.16, referenzrobust). ✔
- `module`-CLI (list/install/activate/deactivate/delete) bis GUI (Step 10). ✔

**Container-Lauf / Test (Beispiel-Modul `sample_module`):** install (Schema +
Migration `ping_log` + Contract), activate (Listener registriert), **E2E**:
Ping-Event → Worker lädt Modul-Listener → Eintrag in `mod_sample_module.ping_log`,
Event `done`; deactivate → kein neuer Eintrag (Fallback); delete → restloses
Cleanup; Manifest-Validierung greift; HTTP 200.

**Offene Punkte / Beobachtungen:**
- **Update-Operation** (Kap. 24.13) + **echte Signatur-/Lizenzprüfung** (24.9,
  Vertrauensanker/Sperrliste) + **Wiederherstellungspunkt** (pg_dump, 28.14.2) +
  **Marketplace** → **Step 8** (Signatur ist als Hook `verifySignature()` vorbereitet).
- **Modul-Schema-Trennung** (`mod_<key>`) hier umgesetzt (über E5 hinaus; das
  Dokument ließ Schema-Trennung als „spätere Version" offen — wir trennen sauber).
- Admin-GUI für Modulverwaltung/Abhängigkeitsgraph → **Step 10**.
- Installation aus lokalem Verzeichnis; Paket-Upload/ZIP-Entpacken → Step 8/10.

### Schritt 8 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 24.9 (Signatur/Vertrauensanker/Sperrliste), 28.4–28.7
(Marketplace, Lizenz offline-first, Entscheidung 158), 28.8–28.13 (Update),
28.14.2 (Wiederherstellungspunkt/Rollback, Entscheidung 155), 28.11 (Wartung).

**Soll → Ist:**
- **Signatur (Ed25519):** `Signer`, `PackageVerifier` (Paket-Digest über ALLE
  Dateien → Manipulation erkannt), `TrustStore` (Anker + Sperrliste). Prüfung
  VOR Entpacken; unsigniert/manipuliert/widerrufen → blockiert. In den Step-7-
  Lifecycle eingeklinkt. ✔
- **Lizenz offline-first:** `LicenseService` (signierte Lizenzdatei, Modulbezug,
  Gültigkeit; Aktivierungs-Gate bei `requires_license`). Ablauf → Aktivierung
  blockiert, kein Datenverlust. ✔
- **Update-Manager:** `UpdateManager.updateModule` + `updateCore` mit Signatur-/
  Kompatibilitätsprüfung, **verpflichtendem pg_dump-Wiederherstellungspunkt** bei
  Migrationen, Migration, Registry-Revalidierung, `update_history` + Audit. ✔
- **Rollback (sicher):** Down-Migrationen → Datei-/Stammdaten-/Registry-
  Rücksetzung; der pg_dump-Recovery-Point bleibt als **manuelle** letzte Zuflucht
  (kein gefährlicher Auto-Restore — der hatte bei Teilfehler die DB korrumpiert). ✔
- **Marketplace-Client gegen Test-Server** (nginx-Service): signierte CRL/Anker
  abrufen + verifizieren + anwenden. Wartungsmodus (503). ✔
- `postgresql-client-17` im Image (pg_dump passend zum PG17-Server).

**Container-Lauf / Test:** signiertes Modul installiert; unsigniert/manipuliert/
widerrufener-Schlüssel abgelehnt; Lizenz-Gate (gültig/abgelaufen/fehlt);
Modul-Update v1.0.1 (Migration+Recovery-Point) → success; Update v1.0.2 (kaputte
Migration) → **rolled_back** (Version/Dateien zurück, keine Korruption);
Core-Update 1.0.1 success, 2.0.0 inkompatibel→blockiert; Marketplace-Sync widerruft
Schlüssel; Wartungsmodus 503↔200; HTTP 200.

**Offene Punkte / Beobachtungen / autonome Entscheidungen:**
- **Ed25519** als Signaturalgorithmus (doku-offen → autonom, vom Nutzer bestätigt).
- **Vertrauenskette** vereinfacht (aktiver, nicht-widerrufener Anker + Publisher-
  Bindung); vollständige X.509-Kette = spätere Version.
- **Auto-Restore aus pg_dump bewusst NICHT** (Korruptionsgefahr bei Teilfehler);
  Rollback primär über Down-Migrationen (Doku-Kaskade), Dump als manuelle Zuflucht.
- **Online-Enforcement** (periodische Online-Bestätigung): Datenmodell/Felder
  vorhanden, voller Server-Confirm-Zyklus skizziert; Schlüsselrotation-CLI
  (Entscheidung 164, Soll) zurückgestellt.
- Admin-GUI (Marketplace/Update/Lizenz/Recovery) → **Step 10**.

### Schritt 9 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 25 (BREAD), 27.6–27.9/27.16 (Rechtemodell/Prüfung,
Entscheidung 124/135/172), 30.3 (RLS, Entscheidung 175).

**Soll → Ist:**
- Datenmodell `resources` + `group_resource_permissions` (BREAD-Spalten +
  Zusatzaktionen jsonb). Ressourcen werden beim Modul-Install aus dem Manifest
  (`permissions`) befüllt (Step-7-Hook), bei Delete entfernt. ✔
- **`PermissionService`**: serverseitige, **rein additive** Aggregation über die
  aktiven Gruppen eines aktiven Benutzers (keine Deny-Regeln); Klassen- + Objekt-
  Rechte vereint; deaktivierte Gruppen/Benutzer → keine Rechte. ✔
- **RLS-Infrastruktur:** Core-Helfer `core.current_user_id/current_group_ids/
  rls_bypass`; `RlsContext` setzt Kontext via **SET LOCAL**; **TransactionRls-
  Middleware** hüllt jeden Request in eine Transaktion (Entscheidung 175,
  pooling-sicher). ✔
- **`permission`-CLI** (check/grant/revoke/resources); Audit der Rechteänderungen.

**Container-Lauf / Test:** BREAD-Union (browse/edit/approve vereint, add/reject
deny; inaktive Gruppe/Benutzer ausgeschlossen); RLS-Isolation mit non-superuser-
Rolle (G1→2, G2→1, ohne Kontext→0, Superuser-Bypass→3); Resource-Hook; HTTP 200.

**Offene Punkte / Beobachtungen / autonome Entscheidungen:**
- **RLS greift nur mit NOBYPASSRLS-App-Rolle** — Superuser umgeht RLS. Duale
  Rollen-/Connection-Einrichtung als Deployment-Aufgabe notiert (s. o.). Maschinerie
  per Testrolle nachgewiesen.
- **Bypass-Pfad = privilegierte Rolle** (sicher), nicht die settbare GUC
  `app.bypass_rls` (Footgun, nur für vertrauenswürdige Kontexte; Helfer bleibt
  verfügbar, aber empfohlenes Policy-Muster nutzt nur `current_group_ids`).
- **Konkrete Modul-Policies + Ressourcen** liefern die Module; Admin-GUI für
  Gruppen-Ressourcen-Zuordnung → **Step 10**.

### Schritt 10 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 23.8 (Admin-GUI/SSR), 27.3.1/27.16.2 (scoped Administration,
Entscheidung 170: 6 feste Bereiche, Sichtbarkeit = serverseitige Berechtigung),
27.17 (Benutzer-/Gruppen-/Rechteverwaltung), 28.17 (Modul-/Update-Oberfläche).

**Soll → Ist:**
- **Login/Logout im Request-Pfad** (`AuthController`, `/login`+`/logout`): Form-
  Auth + Session, `LoginThrottle` (Sperre/Reset/Record), `unauthenticatedRedirect`
  auf `/login`. `AppController` lädt die Authentication-Komponente. ✔
- **Scoped-Admin-Basis** (`Admin\AdminController`): erzwingt serverseitig
  angemeldet **und** Halten des geforderten Bereichs (`requiredArea`); leere
  Bereichsmenge → `ForbiddenException`; Navigation wird auf gehaltene Bereiche
  gescoped. ✔
- **Alle 6 Bereiche voll** (Nutzerwahl): Benutzer (Liste/Detail/anlegen/
  aktivieren/deaktivieren/**Administrationsbereich-Zuweisung**/**Anonymisierung**),
  Gruppen (anlegen/aktiv-schalten/**Mitgliedschaft**/**BREAD-Ressourcenrechte**
  via `PermissionService`), Module (Liste/aktivieren/deaktivieren/entfernen +
  **Abhängigkeitsanzeige**), Registry (Contracts/Registrierungen/Bindings, lesend),
  Marketplace (Status/**Sync**) + Lizenzen (Status/**Upload+Install**), Updates
  (Historie + Modul-/Core-Update auslösen), Konfiguration (Settings-Katalog
  bearbeiten, Secrets maskiert). ✔
- **Audit-Sicht** (jeder Admin, kein fester Bereich): gefilterte Liste
  (Aktion/Entitätstyp/Modul), Akteur per UUID→Username nur zur Anzeige aufgelöst. ✔
- **Bootstrap 5 gebündelt/offline** (Nutzerwahl): `core/webroot/css/
  bootstrap.min.css` vendored; `admin`/`login`-Layout. ✔

**Container-Lauf / Test:**
- `step10test.sh`: unauth `/admin`→**302**; Login-Flow (CSRF 152 Zeichen) POST
  `/login`→**302**; alle 10 Admin-Seiten authentifiziert **200**
  (Dashboard/Users/Groups/Modules/Registry/Updates/Marketplace/Licenses/Config/
  Audit).
- `step10scope.sh`: **delegierter Admin** mit nur `user_group_admin` →
  users/groups/audit **200**, die 5 fremden Bereiche **403** (serverseitig).
  Schreibaktionen als Voll-Admin: Gruppe anlegen (302, DB-Zeile), `set-permission`
  (302, BREAD-Zeile `b=t,r=t`), `toggle-area` (302, Bereich ergänzt).

**Offene Punkte / Beobachtungen / autonome Entscheidungen:**
- Modul-/Core-**Installation** bleibt CLI-getrieben (signierte Pakete); die GUI
  steuert Lebenszyklus/Update aus bereitgestelltem Paketpfad. Begründung: sichere
  Paketherkunft/Signatur, kein Upload großer Artefakte durch die GUI. (E27)
- Settings-Editor deckt den **bekannten Katalog** ab (`SettingsCatalog::all()` neu);
  unbekannte Schlüssel sind bewusst nicht editierbar (Typ-/Bereichsvalidierung).
- CSRF-Testaufrufe benötigen `--data-urlencode` (Token enthält base64-Zeichen);
  reines `-d` verfälscht `+`→Leerzeichen (Test-Artefakt, kein App-Bug).

### Schritt 11 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 29 vollständig (öffentliche Modul-Interfaces als Service-
Contracts), insb. 29.3 (Einordnung Contract-Modell), 29.5/29.6 (Spezifikation),
29.8 (Nutzung + Capability-Bindung + Abweisung), 29.8.1 (Mehrfachnutzung), 29.12
(Interface-Registry im Admin), 29.13 (Kompatibilitätsprüfung), 29.14/29.15
(De-/Aktivierungsverhalten), 29.16 (Auditierbarkeit).

**Soll → Ist:**
- **Öffentliches Interface = Service-Contract** (keine Parallelarchitektur):
  nutzt die in Step 5 gebaute Registry (`contract_type='service'`, Bindings,
  Handles, Versions-Matching). ✔
- **Aufrufbare Schnittstelle:** `App\Service\Registry\ServiceInterface`
  (`handle(array): array`) als Implementierungsvertrag; `CapabilityHandle::invoke()`
  ruft den aktiven Provider auf, mit Guard → ohne gültige Bindung / aktiven
  Anbieter Abweisung via `CapabilityRejectedException` (Kap. 29.8.4). ✔
- **Provider-Deklaration:** neue Manifest-Sektion `services_registered`
  ({contract, version, class}) → bei Aktivierung als `TYPE_PROVIDER` registriert
  (konsistent zu resolvers/collectors/events). Service-Contract-Felder
  (multi_use, input/output/error-spec) werden bei `install` aus dem Manifest
  übernommen. ✔
- **Mehrfachnutzung (29.8.1):** bei `multi_use=false` nur ein aktiver
  CONSUMER — Slot-Prüfung in `ContractRegistry::register()` (Audit
  `interface.multiuse_conflict`). ✔
- **Interface-Registry-Admin (29.12):** `/admin/registry/interfaces` —
  Sicht je Interface (Anbieter, Provider-aktiv, Mehrfachnutzung, **aktive
  Nutzerzahl**, Input-/Output-/Fehler-Spezifikation) + Sicht je nutzendem Modul
  (Status/Kompatibilität). ✔
- **CLI `service`** (`list`/`call`) für Inspektion + Aufruf über das Handle. ✔
- **Lifecycle (29.14/29.15):** Provider-Deaktivierung → Provider-Registrierung
  inaktiv → `invoke()` weist ab, Konsument bleibt lauffähig (kein Datenverlust). ✔

**Container-Lauf / Test (`step11test.sh`, echtes Provider+2 Consumer-Fixtures):**
- `service list` zeigt beide Interfaces (echo multi_use=ja, exclusive=nein).
- Consumer-Registrierungen nach Aktivierung aktiv (echo + exclusive).
- **E2E-Aufruf** `sample_consumer → sample_module.service.echo {"msg":"hallo"}`
  → `{"echo":"hallo","length":5}`.
- Abweisung ohne Handle (`nobody`) → „Kein gültiges Handle".
- **Mehrfachnutzungs-Sperre:** Aktivierung `sample_consumer2` auf exklusivem
  Interface blockiert (aktiver Nutzer: sample_consumer); nur 1 aktiver Nutzer.
- **Abweisung nach Provider-Deaktivierung:** Aufruf → „kein aktiver Anbieter".
- Admin `/admin/registry` und `/admin/registry/interfaces` → HTTP 200.

**Offene Punkte / Beobachtungen / autonome Entscheidungen:**
- In-Process-PHP-Aufruf via `ServiceInterface` (Nutzerwahl) — passt zum
  PSR-4-Modul-Loading (Step 7), keine Serialisierung/HTTP. (E29)
- Integrations-Extension-Module (29.9/29.10): eigene Datenhaltung der
  Beziehungen liegt beim jeweiligen Modul; der Core stellt Contracts +
  Capability-Bindung + Sichtbarkeit bereit (Demo: `sample_consumer` als
  Extension, die ein Interface konsumiert).
- `invoke()` wird bewusst **nicht** je Aufruf auditiert (29.16 fordert es nicht;
  Definition/Nutzung/De-/Aktivierung/Inkompatibilität sind auditiert).

### Schritt 12 — abgeschlossen & verifiziert (2026-06-05)

**Geprüfte Kapitel:** 20.2.1 (Health-Endpoint, Muss), 20.2.2 (Health-Collector),
20.2.3 (strukturierte Logs, Soll), 20.2.4 (Admin-Statusfläche, Soll), 20.3
(Worker-Aktualität/Heartbeat, Muss-Protokollierung).

**Soll → Ist:**
- **`/health`-Endpoint:** öffentlicher **Liveness** (`GET /health` → `{"status":"up"}`
  / 503) ohne Auth; **Detail** (`GET /health/detail`) auth-/token-geschützt
  (Admin-Session **oder** `core.health_token` via `X-Health-Token`/`?token=`,
  Nutzerwahl). ✔
- **`HealthService`** aggregiert (Kap. 20.2.1): Datenbank, Datei-Storage
  (Schreib-/Lesetest), Worker-Aktualität, Registry (inkl. Orphan-Bindings),
  Modul-Zustände, Outbox-Pending + **Dead-Letter-Zähler**, Lizenzstatus, plus
  Modul-Collector-Beiträge → Gesamtstatus `up|degraded|down`. ✔
- **Health-Collector** (Kap. 20.2.2): Core-Contract `core.collector.health` per
  Migration geseedet; `HealthCheckInterface` als Beitrags-Vertrag; ohne Beiträge
  Leerergebnis. ✔
- **Worker-Heartbeat** (Kap. 20.3): dedizierte Tabelle `core.worker_heartbeats`
  (Nutzerwahl); `OutboxWorker` schreibt je Zyklus `WorkerHeartbeat::beat('outbox')`. ✔
- **Admin-Statusfläche** `/admin/health` (Kap. 20.2.4, jeder Admin): Subsystem-
  Tabelle + Worker-Aktualität. ✔
- **Strukturierte JSON-Logs** (Kap. 20.2.3): `JsonFormatter` an den File-Log-
  Engines (debug/error). ✔
- Neue Settings: `core.health_token` (secret), `core.health.worker_max_age_seconds`,
  `core.storage.path`.

**Container-Lauf / Test (`step12test.sh`):**
- Migration `CoreHealth` ✔; `worker_heartbeats` + Collector-Contract vorhanden.
- Liveness `/health` → **200** `{"status":"up"}`.
- `/health/detail` ohne Auth → **401**; falscher Token → **401**.
- `/health/detail?token=…` → **200**, Gesamtstatus „up", alle 8 Subsysteme.
- `/health/detail` mit Admin-Session → **200**.
- Worker-Heartbeat geschrieben (`outbox status=ok age=5s` nach Worker-Neustart).
- Admin `/admin/health` → **200**.

**Offene Punkte / Beobachtungen / autonome Entscheidungen:**
- Detail-Schutz = Session ODER Token (E30); Worker-Frische in dedizierter Tabelle
  statt katalog-gegateter `core.settings` (E30).
- Ticketing-spezifische CLI-Cronjobs (fetch_mails, check_escalations …) aus Kap.
  20.3/20.5 sind **Modul**-Belange; der Core liefert generisch Heartbeat-Tabelle +
  Health-Aggregation, an die Module andocken.
- **Langlaufende Worker müssen nach Code-Änderungen neu gestartet werden** (PHP-
  Prozess hält die geladenen Klassen) — im Dev über `docker compose restart worker`;
  im Betrieb über Deployment/Reload. Notiert als Betriebsbeobachtung.

### Sicherheits-Härtung (nach Core, 2026-06-05) — abgeschlossen & verifiziert

Umsetzung der drei zuvor offenen projektweiten Aufgaben (Branch
`feat/security-hardening`).

**Aufgabe 3 — Schlüsselrotation (`secret rotate`):** Re-Encryption aller
is_secret-Settings (alt→neu) mit Dry-Run + Verify, transaktional.
*Verifiziert:* Round-Trip (Klartext gesetzt → K0→K1 → Lesen mit K0 schlägt fehl
→ K1→K0 zurück → Klartext wiederhergestellt); Dry-Run schreibt nichts.

**Aufgabe 1 — Lizenz-/Signaturverfahren (Root→Publisher):** `mp_tool sign-key`
(Root signiert Publisher-Zertifikat) + `sign-doc`; `TrustChain` prüft die Kette;
`trust_anchors.key_signature` persistiert die Root-Signatur; `trust add-anchor
--cert`, `MarketplaceClient.sync` und `PackageVerifier` prüfen die Kette;
`SIGNING.md` dokumentiert das Verfahren. *Verifiziert:* Zertifikatsausstellung;
Ketten-Prüfung; manipuliertes Zertifikat abgewiesen; Publisher-Paketinstallation;
Lizenz gültig; **Root-Widerruf entzieht Publisher-Paketen nachträglich das
Vertrauen**.

**Aufgabe 2 — NOBYPASSRLS-App-Rolle:** Default-Connection = `fertura_app`
(NOBYPASSRLS) über `APP_DATABASE_URL`; `privileged`-Connection (Superuser) für
DDL/Migrationen/Modul-Lifecycle/Update/Worker via `Db::privileged()`.
`db_provision_app_role` (idempotent, vom Entrypoint) legt Rolle + Grants an;
Entrypoint-Bootstrap läuft als Superuser, php-fpm als App-Rolle.
*Verifiziert:* Rolle `super=f/bypassrls=f`; App läuft als `fertura_app`
(`bypass_rls=false` im Health-Detail); alle Admin-Seiten 200; **RLS greift**
(G1=2/G2=1/ohne Kontext=0/Superuser=3); Modul-Install via privilegiertem Pfad ok;
Worker (Superuser-Pfad) + `/health` gesund; Fresh-Clone-Pfad über
`app_local.example.php`-Fallback abgedeckt.

## Entscheidungs-Log (autonome Entscheidungen)

| Nr. | Schritt | Entscheidung | Begründung (Anforderungskontext) |
| --- | --- | --- | --- |
| E1 | allg. | Branching: ein Branch `feat/core-foundation`, Commit pro (Teil-)Schritt, PR an Meilensteinen | Autonomer Mehrschritt-Modus; PR-pro-Schritt wäre Overhead. Nachvollziehbar über Commits. |
| E2 | 0 | Reproduzierbarkeit über Container-Entrypoint: bei fehlendem `vendor/` `composer install`; auf DB warten; `migrations migrate` geguardet. Nur `core` initialisiert (Deps + Migrationen); `worker` wartet auf `vendor`. | „clone & up" (Kap. 20.8.2) + Vermeidung von Race beim parallelen Start (core/worker teilen das Volume). |
| E3 | 0 | `vendor/` liegt auf einem Docker **Named Volume** (`core_vendor`, ext4 in der WSL-VM), **nicht** auf dem 9p-Windows-Bind-Mount. Quellcode bleibt gemountet (Live-Edit). | **Root Cause eines Boot-Hängers gefunden:** der CakePHP-Bootstrap blockierte in `p9_client_rpc` (State `D`), weil das file-lastige Autoloading über den 9p-Bridge-Mount (`/mnt/c/...`) stallt. vendor (tausende Dateien) auf ein VM-natives Volume zu legen, ist das Standard-WSL2-Muster und behebt den Hänger vollständig (`bin/cake version` 5.3.6/RC=0, HTTP 200). Nur Dev-Optimierung, kein Einfluss auf Core-Anforderungen/-Code. |
| E4 | 0 | Autostart über (a) systemd `docker.service` `enabled` in der WSL-Distro + `restart: unless-stopped` und (b) Windows-Anmelde-Aufgabe „Fertura Dev Autostart" (`docker/autostart/fertura-up.ps1`), die die Distro bootet und `docker compose up -d` ausführt. | Distro selbst startet nach Windows-Login nicht von allein; der Logon-Task ist der fehlende Auslöser. Skript ist idempotent und im Repo versioniert (auch für frische Klone verwendbar). |
| E5 | 1 | Schema-Trennung: `core` (Core), `mod_<modulkey>` (je Modul), `public` (Extensions/übergreifend). `search_path=core, public` als Connection-`init`; Migrationen qualifizieren `core.<name>` explizit. | Plattform trennt Core/Module klar und fordert RLS pro Modul (Kap. 30.3); Schemas bilden das sauber ab und erleichtern Grants/Scoping. Doku-offen → autonom. |
| E6 | 1 | **(revidiert nach Review, 2026-06-05)** Primärschlüssel: **UUIDv7** — `id uuid NOT NULL DEFAULT core.uuid_generate_v7()`. Erzeugung zweigleisig: App `UuidV7Behavior` (`symfony/uid`) + DB-DEFAULT-Funktion als Netz. Kein separater `public_id` nötig. | Zeitgeordnet (gute Index-Lokalität) **und** nicht erratbar/enumerierbar. PG17 ohne eingebautes `uuidv7()` → eigene plpgsql-Funktion. Vom Nutzer bestätigt. |
| E7 | 1 | Zeitstempel `timestamptz` (UTC); `created_at`/`updated_at`; `updated_at` per BEFORE-UPDATE-Trigger `core.set_updated_at()`. **(revidiert)** Akteur-Spalten `created_by`/`updated_by` werden auf Fachtabellen geführt (s. E8). | Trigger = Defense-in-Depth. |
| E8 | 1 | **(revidiert nach Review)** „Deaktivieren statt löschen" via `active` **plus `deactivated_at`**. Akteur-Spalten (`uuid` NULL, FK→users, ON DELETE SET NULL), gepflegt vom spalten-toleranten `FootprintBehavior` aus dem `ActorContext` (HTTP; CLI/System = NULL). **Trennscharfe Regel:** `created_by` = „durch wen entstanden" auf allen akteur-erzeugten Tabellen **inkl. Joins** (`groups_users`, `user_admin_areas`); `updated_by` = „durch wen geändert" nur auf in-place-editierbaren Sätzen (`users`, `groups`). Nichts auf Infra (`auth_failures`, Outbox, Audit-Log) / Stammdaten (`admin_areas`). Ergänzt das Audit-Log (Step 3). | Kap. 1.6; `deactivated_at` = „wann deaktiviert". Akteur am Datensatz für Herkunft/Verantwortlichkeit (besonders Rechtevergabe). Reichweite konsequent. Vom Nutzer bestätigt. |
| E9 | 1 | Namenskonventionen: snake_case, Tabellen Plural; Constraints/Indizes explizit benannt (`fk_`, `uq_`, `ck_`, `ex_`, `ix_`, `gin_`, `trg_`). | Konsistenz/Lesbarkeit; CakePHP-ORM-kompatibel. Doku-offen → autonom. |
| E10 | 1 | `config/app.php`: Default-Connection von MySQL- auf Postgres-Treiber umgestellt (`encoding=utf8`), inkl. `test`-Connection. | Skeleton-Altlast; Projekt ist Postgres-only (Entscheidung 173). Bisher nur durch `url` zur Laufzeit kaschiert. |
| E11 | 1 | ~~Migrations-Trackingtabelle `cake_migrations` in `public`.~~ **Ersetzt durch E12.** | — |
| E12 | 2 | `schema=core` an der Connection (für Reflektion). `core`-Schema wird vor dem Migrations-Runner durch `bin/cake schema_init` (im Entrypoint) bereitgestellt → `cake_migrations` liegt in `core`. `CoreFoundation` legt das Schema nur noch defensiv an und droppt es nicht mehr; `btree_gist` in `public`. | CakePHP-Reflektion filtert `information_schema` nach `schema`; ohne `schema=core` werden core-Tabellen nicht gefunden. Konsistenz: Tracking + Daten im selben Schema. Henne-Ei (Schema vs. erste Migration) über Infrastruktur-Bootstrap gelöst; per sauberem Rebuild verifiziert. |
| E13 | 2 | **(revidiert nach Review)** Lokale Auth: `cakephp/authentication`, Form+Session, Identifier-Finder „active", `quoteIdentifiers=true`. Hashing **Argon2id** (PHP-Defaults) **mit bcrypt-Fallback** (`FallbackPasswordHasher`): erzeugt Argon2id, verifiziert auch bcrypt. | Argon2id = OWASP-Empfehlung, nativ verfügbar. Fallback macht spätere Hash-Importe zukunftssicher. Vom Nutzer bestätigt. |
| E14 | 2 | Anonymisierung: `username='geloeschter_benutzer_<id>'`, `email='anonymized-<id>@invalid.local'`, Vor-/Nachname/locale/timezone/`password_hash`→NULL, `status=anonymized`, `anonymized_at=now()`, Tokens widerrufen; ID + Mitgliedschaften bleiben; eingeladene Accounts physisch löschbar. | Setzt Entscheidung 160 / Kap. 27.15.3 um (irreversibel, keine Zuordnungstabelle). Konkrete Platzhalter waren doku-offen → autonom. |
| E15 | 2 | Anmeldeschutz-Defaults: 10 Fehlversuche / 15-min-Fenster, dann temporäre Sperre (`LoginThrottle`, persistiert in `auth_failures`). | Entscheidung 162 fordert „sicheren Vorgabewert ohne Konfiguration". Konkrete Schwellen doku-offen → autonom; ab Step 4 DB-konfigurierbar. |
| E16 | 3 | Audit-Log-Design: (a) **Personen per auflösbarer UUID** (kein denormalisierter Klartext-Name/E-Mail) → Anonymisierung wirkt ohne Log-Mutation; **textuelle Schnappschüsse nur für nicht-personenbezogene** Entitäten (Module/Config) = referenzrobust. (b) **Unveränderlichkeit per Trigger** (UPDATE/DELETE blockiert; Bypass nur via `SET LOCAL app.allow_audit_mutation`). (c) **Monats-RANGE-Partitionierung** + DEFAULT-Partition; `audit_partition`-Command stellt Monatspartitionen im Entrypoint sicher. (d) `AuditLogger`-Service schreibt transaktional. | Vereint Referenzrobustheit (24.16.1) und DSGVO-Anonymisierung (27.15.3) ohne Konflikt mit der Unveränderlichkeit (20.6). Partitionierung gem. 30.8/Entscheidung 179. Konkrete Felder/Platzhalter doku-offen → autonom. |
| E17 | 3 | nginx löst den Upstream `core` zur Laufzeit über den Docker-Resolver (`127.0.0.11`) + Variable im `fastcgi_pass` auf, statt die IP beim Start zu cachen. | Behebt 502 „Connection refused" nach `docker compose up -d --force-recreate core` (neue Container-IP). Robustheit für Recreate/Autostart. Verifiziert. |
| E49 | API | **Externe API v1 voll ausgebaut** (Kap. 29, Merkliste B #1; Nutzer-Entscheidung „voll ausbauen"): Bearer-Token unter `/api/v1` (`ApiAuthMiddleware`, nur `/api/`-Pfade, JSON 401/403; CSRF-Skip für `/api`), Token-**Scopes** (`me:read`/`health:read`/`modules:read`/`*`) zusätzlich zur Benutzer-Autorisierung (RLS/Permissions); `TokenService` (SHA-256-Hash, Klartext nur bei Erzeugung, Ablauf/Widerruf, `last_used_at`); Endpunkte `GET me/health/modules`; Self-Service-GUI `/admin/tokens`; Audit (`api_token.create/revoke`); `API.md`. Rate-Limiting bewusst späterer Ausbau. | Externer Integrationszugang ergänzend zu den in-process Modul-Interfaces; Token bindet an Benutzer, Scopes engen ein. Vom Nutzer beauftragt. |
| E48 | Betrieb | **Dead-Letter-Verwaltung** (Kap. 26.9.2, Merkliste B): Admin-Sicht `/admin/outbox` (Menüpunkt unter `core_config`) mit Status-Zählern + Dead-Letter-Liste; pro Event **Retry** (→ `pending`, `attempt_count=0`, Lock/Fehler geleert) und **Verwerfen** (neuer Terminalstatus `discarded` via Migration, abgrenzbar von `done`) + „alle wiedereinstellen". `OutboxAdmin`-Service; Aktionen auditiert. | Gehört in die Core-Betriebsfläche; `discarded` trennt manuelles Verwerfen sauber von erfolgreicher Zustellung. Heimat `core_config` gewählt (kein eigener fester Bereich nötig). Selbst entschieden; ggf. korrigierbar. |
| E47 | Härtung | **RLS-Pflicht beim Install durchgesetzt** (Kap. 30.3, Merkliste B): Deklariert ein Modul `is_scoped`-Ressourcen, muss sein Schema `mod_<key>` nach den Migrationen mind. eine RLS-aktivierte Tabelle **mit** Policy enthalten (`pg_class.relrowsecurity` + `pg_policies`); sonst `LifecycleException` mit sauberem Rückbau (Schema/Stammdaten/Verzeichnis), da der Install nicht in einer DB-Transaktion läuft. Fixture `sample_module` zeigt eine korrekte Policy gegen den Core-Kontext (`app.current_user_id`/`app.bypass_rls`). | Fängt die „scoped Daten ohne RLS"-Fehlkonfiguration sicher ab. Heuristik (Ressource→Tabelle ist nicht deklariert), daher Minimalbedingung „≥1 Policy". Selbst entschieden; ggf. korrigierbar. |
| E46 | Härtung | **Manifest-Pflichtfelder ergänzt** (Kap. 24.4.1, Merkliste B): `description` + `publisher` werden jetzt in `ModuleManifest::validate()` geprüft (dokumentiert-pflichtig, zuvor ungeprüft). Spec-Feld `entrypoint` („Einstiegsklasse") ist in dieser Implementierung über `php_namespace` (Autoload-Wurzel des `ModuleAutoloader`) realisiert; `signature` ist kein Manifestfeld, sondern die separate Paketsignatur. | Schließt die formale Validierungslücke; `entrypoint`-Mapping dokumentiert statt totes Pflichtfeld einzuführen. Selbst entschieden; ggf. korrigierbar. |
| E45 | Härtung | **Anker-Gültigkeitsfenster durchgesetzt** (Kap. 24.9.2, Merkliste B): `valid_from/valid_to` in `trust_anchors` werden an **allen** Verifikationspfaden geprüft — `PackageVerifier::verify` (Paket-Anker), `TrustChain::verifyPublisherCert` (signierender Root), `LicenseService::validateFile` (Lizenz-Anker). Zentraler Helfer `TrustStore::validity()` (NULL-Grenzen = unbegrenzt); `addAnchor` + CLI `trust add-anchor --valid-from/--valid-to` setzen das Fenster; `trust list` zeigt Fenster + `UNGUELTIG`. | Schließt die letzte echte Soll-Lücke des Trust-Systems (Widerruf/Kette waren bereits da). Klar Core-intern, kein Produkt-Scope. Selbst entschieden (Standing Instruction); ggf. korrigierbar. |
| E44 | i18n (7) | **Umschalter persistiert kontextabhängig:** angemeldet → `user.locale` (privilegierter Single-Column-Self-Write, umgeht RLS-Policy-Fragen) **+** Session; anonym/Login → nur Session via `?lang`. **Accept-Language** als Fallback (v. a. öffentlich/Login, nach q-Gewicht, nur aktivierte Locales, Sprach-Präfix-Match). **Wählbar** = `locale.enabled` ∩ Core-nutzbar (`LocaleResolver::selectableLocales`), Englisch immer. Umschalter als **No-JS-Inline-Buttons** (View-Cell `LocaleSwitcher`), da die Layouts kein Bootstrap-JS laden. | Persistenz ohne RLS-Reibung; sinnvoller Default für neue/öffentliche Sessions; robust ohne Client-JS. Selbst entschieden (Standing Instruction); ggf. korrigierbar. |
| E43 | i18n (5) | **Core-Kataloge bleiben in `resources/locales`** (mitgeliefert, immer aktuelle Core-Version) — **nicht** in den Store dupliziert. Der `EnglishFallbackLoader` (Core-Domain `default`) überlagert zusätzlich **nachgeladene** Core-Sprachpakete aus dem Store (Versions-Gate via `LocaleResolver`). Das Versions-Gate greift damit für Store-Packs (Module + nachgeladene Core-Sprachen); die mitgelieferten Core-Sprachen brauchen kein Gate (per Definition aktuell). `availableLocales` = `resources/locales`-Sprachen ∪ nutzbare Core-Store-Packs. | Vermeidet doppelte Quelle/Seed-Logik beim Boot; eine kanonische Quelle je Fall. Offener Punkt aus i18n-5 entschieden; ggf. korrigierbar. |
| E42 | i18n (6) | **GUI-Import von Sprachpaketen = unsignierter `.po`-Upload** (`source=upload`, `signed=false`): immer Review-vor-Import (Hinweis „unsigniert", Vorschau, Abbruch möglich; Re-Import warnt bei `edited`). **Signierte** Packs gelangen ausschließlich über die **Komponenten-Paketinstallation** (i18n-4, `PackageVerifier` gegen Trust-Anker) in den Store. Damit existiert **eine** Signatur-/PKI-Strecke (Pakete); der Fall „ungültige Signatur" ist eine Installations-, keine GUI-Upload-Sorge. | Eine `.po`-Datei trägt keine Paket-`signature.json`; eine parallele Lang-Pack-PKI wäre Overhead. Hält E38 (Signatur nur beim Import) ein, ohne zweite Vertrauensstrecke. Selbst entschieden (Standing Instruction); ggf. korrigierbar. |
| E41 | i18n | **Sprachverwaltung** als eigener fester Admin-Bereich (`localization`, 7.). **Feld-basierter, verlustfreier Editor** (msgctxt/Plural/Kommentare bleiben); nur Admins editieren. Löschregeln: aktive Komponente alles außer Englisch; inaktive inkl. Englisch (keine Leichen); inaktive nur sichtbar solange Dateien existieren; Löschen markiert die Komponente. Deinstallation behält Sprachdateien. | Saubere Trennung + Governance; verhindert kaputte Dateien per Konstruktion. Mit dem Nutzer finalisiert. |
| E40 | i18n | **Sicheres Schreiben + Recovery:** `.tmp`+`fsync`→**atomarer Rename** (kein Lösch-Fenster); Store auf persistentem Volume. **pg-Advisory-Lock** je Datei unterscheidet *in-flight* (Lock gehalten) von *verwaist* (Lock frei). Recovery (Start/periodisch/lazy): Original fehlt + valide `.tmp` → promoten (Selbstheilung); sonst verwaiste `.tmp` löschen + Fehlerhinweis (Audit/Health). Idempotent, lock-serialisiert. | Korrektheit unter Absturz/Concurrency; konsistent mit Lifecycle-Lock (E21). Mit dem Nutzer finalisiert. |
| E39 | i18n | **Versions-Gate** je Sprachdatei gegen aktive Komponentenversion: identisch = sauber; Major gleich, Minor/Patch abweichend = **genutzt + Hinweis**; Major abweichend = **nicht genutzt + Fehler** → Englisch. **Auflösung:** exakt > Same-Major-höchste (Hinweis) > Englisch. **Wählbar** = Locales, für die der **Core** eine Datei hat (Mismatch → Englisch der Version). Status wird berechnet, nicht gespeichert. | Robuster, vorhersagbarer Fallback; Major als Bruchgrenze (Key-Änderungen). Mit dem Nutzer finalisiert. |
| E38 | i18n | **Managed Locale Store:** Katalog-Inhalt in **Dateien** (PO editierbar + MO Laufzeit), **DB nur Metadaten**. Status-Trio **`signed/reviewed/edited`**: Signatur **nur beim Import** geprüft → `signed` persistiert die Herkunft; Editieren invalidiert die Signatur **nicht** → `edited=yes`, `reviewed=yes` (Admin-Edit = Review). `signed`→`reviewed=yes`; unsigniert/ungültig signiert → `reviewed=no` bis Review; ungültige Signatur = wie unsigniert + spezifischer Hinweis + Review-vor-Import + Abbruch. Re-Import bewertet frisch, warnt bei `edited`. Keine eigene Pack-Versionierung (Überschreiben je `component/version/locale`). | Herkunft vs. Bearbeitung sauber getrennt; Dateien = natives Laufzeitformat. Mit dem Nutzer finalisiert. |
| E37 | i18n | **Basissprache Englisch; symbolische Schlüssel** (Variante B, `<bereich>.<sache>.<variante>`); Domain je Komponente (`component_key`, Core `default`); Locale-Format `ll_CC`, **flacher** Fallback (gewählt → Englisch, kein `de_AT→de`). Jede Komponente bringt ≥ Englisch für ihre Version mit; weitere Sprachen über die Verwaltung nachladbar. CakePHP I18n (`__()/__d()/__x()`). | Doku-konform („CakePHP I18n, Standard: Englisch"); stabile Schlüssel passen zum Versions-Gate und zum Feld-Editor. Mit dem Nutzer finalisiert. |
| E36 | Auth-Slot | Authentifizierungsmethode über den Core-Resolver-Slot `core.auth.provider` austauschbar gemacht (Kap. 27.2.2): `AuthProviderInterface` + `LocalAuthProvider` (Default); `AuthProviderResolver` löst den aktiven Provider aus der Capability-Registry auf; `Application::getAuthenticationService` nutzt ihn. **Break-Glass:** defekter/fehlender Provider → Warnung + lokaler Fallback (nie Lockout). CLI `auth status`. Eine SSO/AD-Extension registriert künftig `resolvers_registered` für `core.auth.provider` + implementiert `AuthProviderInterface`; Identitäten/Autorisierung bleiben Core. | Schließt die in der Onboarding-Review benannte Lücke (Pluggability war konzeptuell vorgesehen, aber nicht verdrahtet). Mechanismus (Registry/Resolver) existierte bereits. Vom Nutzer beauftragt. |
| E35 | Onboarding | Identitätsmails (Einladung, Passwort-Reset) gehören in den **Core**: schlanker `MailService` über den konfigurierten Transport (`EMAIL_TRANSPORT_DEFAULT_URL`, Dev → Mailpit), Settings `mail.*`; Self-Service `/forgot-password` (neutral, keine Enumeration). Plus „Benutzer bearbeiten" und Selbst-Aussperr-Schutz (kein Selbst-Deaktivieren/-Anonymisieren, letzter `user_group_admin` geschützt, Aktivierung nur mit Passwort). Fachliche Modul-Benachrichtigungen + Ticketing-Mailbox-Betrieb (20.4) bleiben Modul-Scope. | Der Core hatte SMTP-Konfig + Mailpit, aber keinen Mailer → inkonsistent. Identität ist Plattform-Verantwortung; der Transport bleibt zentral, Fachmails liegen bei den Modulen. Vom Nutzer bestätigt (Reset-Mail-Flow in den Core). |
| E34 | Merkliste A | Acht Muss-Lücken aus der Re-Verifikation geschlossen: Lizenz-Online/Karenz-Auswertung; nachträglicher Signatur-Widerruf für installierte Module (signature_status); CRL-Cache-Alter/Stale; Sicherheitsupdate-Kennzeichnung (Manifest+Historie); Migrationsvorschau vor Update; Session-Timeout-Verdrahtung; Einladungs-/Passwort-Setz-Flow (Token, E-Mail-Versand bleibt Modul-Scope); BREAD-Admin-UI (Einzelobjekt/Zusatzaktionen/Gruppenfähigkeit). Begleitend: Core-Update-Migrationen über privileged-Connection. | Kap. 24.9.2/25.11/25.12/27/28.7.3.1/28.8.1/28.10. Releaserelevante Transparenz-/Sicherheits- und GUI-Funktionen; jede einzeln im Container verifiziert. |
| E33 | Härtung | NOBYPASSRLS-App-Rolle `fertura_app` als Default-Connection (APP_DATABASE_URL); zweite `privileged`-Connection (Superuser) für DDL/Migrationen/Modul-Lifecycle/Update/Worker (`Db::privileged()` mit Fallback). Provisionierung via idempotentem `db_provision_app_role` (Entrypoint, nach Migrationen); Bootstrap als Superuser (APP_DATABASE_URL geleert), Laufzeit als App-Rolle. Worker bleibt Superuser. | Kap. 30.3 / Entscheidung 175/E26. Erst damit greift RLS zur Laufzeit (Superuser umgeht RLS immer). Vom Nutzer bestätigt (Entrypoint-Provisionierung + privileged-Connection). |
| E32 | Härtung | Lizenz-/Signaturverfahren mit **Root→Publisher-Kette**: `mp_tool sign-key/sign-doc`; `TrustChain` verifiziert Publisher-Zertifikate gegen aktiven Root; `key_signature` persistiert; Kette geprüft bei `trust add-anchor --cert`, `marketplace.sync` und **jeder** Paketinstallation (Root-Widerruf wirkt nachträglich). `SIGNING.md`. | Kap. 24.9.2. Getrennte, kompromittierungs-eindämmende Publisher-Schlüssel statt flachem Root-Signing. Vom Nutzer bestätigt. |
| E31 | Härtung | Schlüsselrotation-CLI `secret rotate --old [--new] [--dry-run]`: re-verschlüsselt alle is_secret-Settings transaktional, mit Dry-Run und Verify gegen den neuen Schlüssel. | Entscheidung 164 (Soll). Betriebssicherer Schlüsselwechsel ohne Datenverlust. Vom Nutzer bestätigt (Rotation + Dry-Run + Verify). |
| E30 | 12 | Observability: `/health` öffentlicher Liveness + auth-/token-geschützter Detail (Session **oder** `core.health_token`). `HealthService` aggregiert DB/Storage/Worker/Registry/Module/Outbox+Dead-Letter/Lizenz + Modul-Collector (`core.collector.health`, `HealthCheckInterface`). Worker-Frische in **dedizierter Tabelle** `core.worker_heartbeats` (nicht in katalog-gegateter `core.settings`). Strukturierte JSON-Logs via `JsonFormatter`. Admin-Statusfläche `/admin/health`. | Kap. 20.2/20.3. Token-Pfad bedient externes Monitoring ohne Login (20.2.5); Heartbeat-Tabelle trennt Laufzeitzustand sauber von validierter Konfiguration. Alle drei Punkte vom Nutzer bestätigt (Session-oder-Token, dedizierte Tabelle, beide Soll). |
| E29 | 11 | Öffentliches Modul-Interface = **Service-Contract** (nutzt Step-5-Registry, keine Parallelarchitektur). Aufruf **in-process** über `ServiceInterface::handle(array):array` + `CapabilityHandle::invoke()` (Guard → `CapabilityRejectedException` als Abweisung, Kap. 29.8.4). Provider via neuer Manifest-Sektion `services_registered` (PROVIDER). Mehrfachnutzung (multi_use=false) = ein aktiver CONSUMER (Slot-Prüfung). Interface-Registry = auf Service-Contracts gefilterte Admin-Sicht. CLI `service list/call`. `invoke()` nicht je Aufruf auditiert. | Kap. 29.3/29.8/29.12. In-Process passt zum PSR-4-Modul-Loading (E21), kein HTTP/Serialisierungs-Overhead. Vom Nutzer bestätigt (In-Process + echtes Consumer-Fixture/CLI-E2E). |
| E27 | 10 | Admin-GUI: **SSR mit gebündeltem Bootstrap 5** (offline/vendored, Nutzerwahl); **alle 6 Administrationsbereiche voll ausgebaut** (Nutzerwahl). Scoped-Enforcement serverseitig in `Admin\AdminController::beforeFilter` (Bereichsbesitz). Modul-/Core-**Installation** bleibt CLI (signierte Pakete); GUI steuert Lebenszyklus/Update aus Paketpfad. Lizenz-Upload (Datei oder JSON) in der GUI. Audit-Sicht für jeden Admin (kein fester Bereich). | Kap. 23.8/27.3.1/27.16.2, Entscheidung 170. Offline-Bündelung = keine CDN-Abhängigkeit im Betrieb. CLI-Install = sichere Paketherkunft. Vom Nutzer bestätigt (Bootstrap gebündelt, alle 6 Bereiche voll). |
| E28 | 10 | Login/Logout im HTTP-Pfad nachgezogen: `unauthenticatedRedirect`/Form-`loginUrl` auf `/login`, `LoginThrottle` an POST `/login` gekoppelt. `SettingsCatalog::all()` als Enumerator für den Settings-Editor ergänzt. | Schließt die in Step 2 vermerkte „Login-Formular → Step 10"-Lücke. Editor braucht Katalog-Enumeration; Werte werden weiter gegen den Katalog validiert. |
| E26 | 9 | BREAD additiv (Spalten can_browse/read/add/edit/delete + extra_actions jsonb); RLS-Kontext via **SET LOCAL + Transaktion-pro-Request** (vom Nutzer bestätigt); Core-Helfer-Funktionen für Policies; **Bypass über privilegierte DB-Rolle** (nicht über settbare GUC). App muss als NOBYPASSRLS-Rolle laufen (Deployment-Aufgabe). | Kap. 25/30.3, Entscheidung 124/175. SET-LOCAL-Variante = doku-konform/pooling-sicher. Rolle statt GUC = sicher (kein Self-Bypass). |
| E22 | 8 | **Ed25519** (libsodium) für Paket-/Lizenz-/CRL-/Anker-Signaturen. Paket-Digest über **alle Dateien** (außer signature.json) → jede Manipulation (Manifest oder Code) wird erkannt. | Modern/schnell, nativ verfügbar. Doku-offen → autonom, vom Nutzer bestätigt. |
| E23 | 8 | Lizenz = signierte JSON-Lizenzdatei (`payload`+`signature`+`key_id`), offline gegen Vertrauensanker geprüft; Felder Modulbezug/Gültigkeit/Karenzfenster/Online-Enforcement. Ablauf → Aktivierung blockiert, kein Datenverlust. | Setzt Entscheidung 158/Kap. 28.7 um. Format doku-offen → autonom. |
| E24 | 8 | Wiederherstellungspunkt = `pg_dump` (verpflichtend bei Migrationen; `postgresql-client-17` im Image). **Rollback primär über Down-Migrationen** + Datei-/Stammdaten-Rücksetzung; pg_dump-Auto-Restore **bewusst nicht** (korrumpierte die DB bei Teilfehler), nur manuelle letzte Zuflucht. | Kap. 28.14.2-Kaskade (Transaktion→down→Dump). Sicherheit vor Bequemlichkeit; per Test nachgewiesen. |
| E25 | 8 | Marketplace-Server-Anbindung als eigener nginx-Test-Service (`marketplace`), der signierte Metadaten/CRL/Anker statisch serviert; `MarketplaceClient` ruft sie ab + verifiziert. | Nutzer wollte echten Test-Server. Produktiver Marketplace ist nicht Teil des Core. |
| E21 | 7 | Module = lokales **Verzeichnis** (Install kopiert nach `core/modules/<key>`, gitignored); Modul-Tabellen in eigenem Schema **`mod_<key>`** (über E5 hinaus, sauberer als „public for now"); **Modul-Migrationen als versionierte SQL-Dateien** (Core-gesteuert, transaktional, getrackt) statt per-Modul-Phinx; **eigener PSR-4-Autoloader** für Modul-Code; Lifecycle-Lock via `pg_try_advisory_lock` (klarer Fehler bei Belegung). Update/Signatur/Lizenz = Step 8. | Doku ließ Datenmodell/Paketdetails/Schema-Trennung offen → autonom. SQL-Migrationen umgehen die fragile per-Modul-Phinx-Pfadauflösung; Schema-Trennung passt zu E5/RLS (Entscheidung 175). Vom Nutzer bestätigt: echtes Laden + Update→Step 8. |
| E20 | 6 | Outbox: `pg_notify` **innerhalb der Transaktion** (Zustellung auf COMMIT, kein After-Commit-Hook nötig). Worker-Defaults: max_attempts=5, exponentielles Backoff (Basis 5 s, cap 1 h), Reclaim 5 min, Poll-Fallback 5 s, Batch 50, Channel `core_event_outbox`. Claim per `FOR UPDATE SKIP LOCKED`. `pcntl`-Graceful-Shutdown. | Kap. 26.9.2/30.6, Entscheidung 168/177. Konkrete Retry-/Backoff-Werte doku-offen → autonom. NOTIFY-in-Transaktion ist PostgreSQL-Standardverhalten und vermeidet Race/Verlust. |
| E19 | 5 | Registry referenziert Module per **`module_key` (Text, kein FK)**; **Capability-Bindings persistiert** + Laufzeit-Handle (Guard). Contract-Namen Konvention `modul.typ.name` (unique). Slot-Exklusivität per partiellem Unique-Index. Versions-Matching nur exakt/expliziter Bereich (26.6.4). | Entkoppelt Step 5 von der modules-Tabelle (Step 7); Bindings auditierbar/debugbar. Vom Nutzer bestätigt. Konkretes Datenmodell doku-offen → autonom. |
| E18 | 4 | Konfigurationsspeicher `core.settings`: Modell (namespace, config_key, `value` jsonb, `value_encrypted`, `is_secret`); **sichere Defaults im Code-Katalog** (`SettingsCatalog`, greifen ohne DB-Eintrag) inkl. Typ-/Bereichsvalidierung; **Secrets AES-256-GCM** (Schlüssel aus `Security.encryptionKey`/env, nie aus DB); Audit `config.update` (entity_type `core_setting`) **ohne** Klartext bei Secrets; Footprint. „Deaktivieren statt löschen" gilt für Konfig-*objekte*, nicht Setting-*werte* (kein `active`). | Setzt Kap. 1.4/23.3 + Entscheidungen 159/162/164/176 um. Konkrete Felder/Defaults/Validierung waren doku-offen → autonom. |

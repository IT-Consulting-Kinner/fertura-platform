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
- ✅ **Strukturierte Logs** (20.2.3, E50, 2026-06-07): `ContextJsonFormatter`
  mischt prozessweiten `LogContext` (`correlation_id`/`request_id`/`component`,
  `module`) **automatisch** in jede Logzeile (der Standard-`JsonFormatter`
  verwarf den Kontext ganz); `LogContextMiddleware` (outermost) befüllt ihn je
  Request, der Worker setzt `component=worker`. *(Holder-Injektion + Call-Site-
  `module` in `cli-error.log` als JSON verifiziert)*
- ✅ **Dead-Letter-Admin-Sicht/Retry** (26.9.2, E48, 2026-06-07): Admin-Sicht
  `/admin/outbox` (unter Core-Konfiguration) mit Status-Zählern + Dead-Letter-
  Liste; pro Event **Retry** (→ `pending`, Zähler/Lock/Fehler zurückgesetzt) oder
  **Verwerfen** (neuer Terminalstatus `discarded`), plus „alle wiedereinstellen".
  Beide Aktionen auditiert (`outbox.retry/discard/retry_all`). *(Service-Harness
  retry/discard/retryAll + Audit; GUI-Smoke render + Retry→pending verifiziert)*
- ✅ **Grafische Abhängigkeitsdarstellung** (23.13.1, E51, 2026-06-07): geschichtetes,
  serverseitig berechnetes **SVG** unter `/admin/modules/graph` (Ebene = Tiefe der
  Abhängigkeitskette via Longest-Path-Relaxation, Kanten Modul → Abhängigkeit mit
  Pfeil, Statusfarbe), kein Client-JS; verlinkt aus der Modul-Liste. *(2 Knoten/
  1 Kante gerendert, SVG/Marker/Statusfarbe verifiziert)* Slot-/Binding-Diagramm
  (24.15.1) bleibt als optionaler späterer Ausbau (Registry zeigt Bindings als Liste).
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

### C. Modul-/Betreiber-Scope — mit dem Nutzer durchgesprochen (2026-06-07)

- **C1 — Out-of-Process-Sandbox** (23.16): **bewusst zurückgestellt** (Nutzer:
  „wirkt overengineered"). In-Process für einen kuratierten, **signierten**
  Modulbestand akzeptiert (E52); echte Isolation erst bei nicht-vertrauenswürdigem
  Drittanbieter-Code nötig → später Trust-Tiering + Prozess-Sandbox.
- ✅ **C2 — Andock-Punkt für periodische Modul-Aufgaben** (20.4, E52): Core-
  Worker tickt registrierte `ScheduledTaskInterface` (Collector
  `core.collector.scheduled`) im Intervall, fehlerisoliert, mit Heartbeat/Health.
  Fachlogik (`fetch_mails`/`check_escalations`) bleibt im Ticketing-Modul.
  Dokumentiert in `MODULE_DEVELOPMENT.md`. *(Runner: läuft/skippt nach Intervall,
  Heartbeat verifiziert)*
- C3 — Matrix-Konfiguration 1.5 / fachliche Entitäten 1.6: **Modul-Scope** (keine
  Core-Aktion; Nutzer bestätigt).
- ✅ **C4 — Daten-Backup/-Restore als Core-Systemfunktion** (20.1.2, E53,
  2026-06-07): Kap. 20.1 in zwei Ebenen neu gefasst (Doku-Update 6.29,
  Entscheidung 181) → **keine Abweichung mehr**: Infrastruktur = Systemadmin
  (20.1.1), **Daten = Core** (20.1.2). `BackupService` + CLI
  `backup create/list/verify/test-restore/restore/delete` + GUI `/admin/backup`
  (Restore CLI-only). `pg_dump -Fc` + tar der Stores **unter dem Lifecycle-Lock**;
  SHA-256 je Artefakt; **Probe-Restore in Scratch-DB** (prüfbar). Volume
  `core_backups`, `BACKUP.md`. *(CLI+GUI verifiziert: create/verify/test-restore=
  36 Tabellen/tamper-detect/delete; Restore-Roundtrip verifiziert; keine
  Scratch-DB-Leaks)*
- ✅ **C5 — Integrations-Extension-Module** (29.9/29.10): **Konzept/Checkliste**
  in `MODULE_DEVELOPMENT.md` festgehalten (Nutzer: nur im Konzept). Umsetzung mit
  dem konkreten Integrationsmodul.
- ✅ **C6 — Modul-RLS-Policies** (30.3): **Doku** in `MODULE_DEVELOPMENT.md`
  (Core-Kontext-Settings + Referenz-Policy); Durchsetzung via E47. Modul liefert
  die konkreten Policies (Nutzer: Doku-only).
- ✅ **C7 — Gleitende Schlüsselrotation** (1.4): CLI `trust rotate <alt> <neu>
  --overlap-days N` setzt überlappende Gültigkeitsfenster (neu ab sofort, alt
  läuft aus; E45 erzwingt das Auslaufen). *(Fenster gesetzt + Fehlerfall geprüft)*

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
| E92 | Nachlauf | **Zurückgestellte Themen, Teil 1**: **(D1) Multi-Tenancy — ENTSCHEIDUNG (autonom, dokumentiert):** Single-Org-Modell bleibt (RLS-Owner-Scoping). Begründung: Das Anforderungsdokument positioniert die Plattform als technische Basis **einer** Organisation; kein Mehr-Kunden-SaaS gefordert; ein Mandant-Rewrite (schema-/db-per-tenant) wäre groß + risikoreich ohne Anforderung. Neubewertung nur bei SaaS-Multi-Customer-Ausrichtung (dann `tenant_id`+RLS oder Schema-per-Tenant). **(D2) SAML-SP-Signierung:** `SamlProvider::settings` um `sp.x509cert`/`sp.privateKey` + `security.{authnRequestsSigned,logoutRequestSigned,wantAssertionsSigned}` (RSA-SHA256) ergänzt, aktiv wenn SP-Cert (Konfig) + SP-Key (Secret) vorhanden; `SsoCommand add-saml --sp-cert-file/--sp-key-file`. **(D3) Auto-Off-Site-Backup:** `BackupScheduledTask` lädt das Archiv (Pfad aus `backups.path`) bei `backup.offsite.enabled` über `OffsiteBackupService` hoch (isoliert). **(D4) Workflow-State-Machines:** Migration `CoreWorkflows`, `WorkflowEngine::onEvent` (Transition wenn from==Zustand|`*` & on_event & Bedingung → Zustandswechsel + Aktionen, eine pro Event/Instanz) + `stateOf`; gemeinsamer `ActionExecutor` (refactored aus `AutomationEngine`, DRY) im `OutboxWorker` neben den ECA-Regeln (attempt==1); CLI `WorkflowCommand`. Verifiziert: `SamlProviderTest` (+2), `WorkflowEngineTest` (4), `AutomationEngineTest` grün (Refactor). Volle Suite **177→183 grün**. | Adressiert die zurückgestellten Themen vor dem Peer-Review (Multi-Tenancy als bewusste Entscheidung; SAML-Härtung/Off-Site-Automatik/Workflows umgesetzt). Vom Nutzer beauftragt. |
| E91 | Programm (Abschluss) | **Modul-SDK: Linter + Scaffolding + Katalog (P16)**: `App\Service\Sdk\ManifestLinter` (DB-freie Manifest-Prüfung: REQUIRED-Felder, id/`php_namespace`-Format, Sektions-Form [collectors/resolvers/services/events_registered, api_routes, contracts_provided], class-im-Namespace als Warnung) + `ModuleScaffolder` (erzeugt manifest.json + `PingEndpoint`/ApiEndpointInterface + Migration + README, lint-clean). CLIs `module_lint`/`module_scaffold`/`module_contracts` (letzteres listet Core-Contracts aus der Registry + Interface-Map). **Entscheidungen:** Linter ergänzt `ModuleManifest::validate` um eine DB-freie Frühprüfung; Scaffold-Gerüst besteht den eigenen Linter (selbstkonsistent); Dev-Mode leichtgewichtig über die vorhandene Install-from-Directory-Strecke. Verifiziert: `ManifestLinterTest`, `ModuleScaffolderTest` + CLI-Smoke. Volle Suite **171→177 grün**. **Damit ist das Programm „Wettbewerbsfähigkeit Core" (Tier 1–3, P01–P16) vollständig abgeschlossen.** | Senkt die DX-Einstiegshürde für Drittanbieter-Module und macht die gewachsene Erweiterungs-Oberfläche auffindbar. Vom Nutzer beauftragt. |
| E90 | Programm | **Zero-Downtime-Bausteine (P15)**: `HealthService::readiness` (DB + `maintenance_mode`) + `HealthController::ready` → `GET /health/ready` (200/503) für rolling/blue-green-Drain. `MigrationSafetyChecker` (heuristischer Linter der `up()`-Pfade: DROP TABLE/COLUMN, RENAME, ALTER TYPE, NOT NULL ohne DEFAULT, TRUNCATE; `down()` ausgenommen) + CLI `migration_check` (advisory). **Entscheidungen:** Readiness = Wartungsmodus-gesteuert (vorhandener Update-Mechanismus), kein eigener Pending-Migrations-Check (Komplexität); Linter advisory (kein CI-Gate, Betreiber entscheidet); Expand/Contract als Runbook. Verifiziert: `HealthReadinessTest`, `MigrationSafetyCheckerTest` + Live (`/health/ready` 200). | Macht rolling/blue-green-Deployments ohne 503 für Endnutzer möglich und warnt vor downtime-kritischen Migrationen — Phase E abgeschlossen. Vom Nutzer beauftragt. |
| E89 | Programm | **Off-Site-Backup + PITR (P14)**: `OffsiteBackupService` (upload/list/download/delete über `StorageManager`/P03 → S3-kompatibel) + CLI `backup_offsite` + Setting `backup.offsite.enabled`. Lädt die bereits AES-verschlüsselten Backup-Archive geo-redundant; Disaster-Recovery-Pull. **Entscheidungen:** Off-Site über die Storage-Abstraktion (P03) statt eigener S3-Client; PITR (WAL-Archivierung) als Runbook statt App-Code (PostgreSQL-`archive_command`-Konfiguration). Verifiziert: `OffsiteBackupServiceTest` (Upload/List/Download/Delete-Roundtrip gegen lokalen Adapter). | Ergänzt das Core-Backup um Geo-Redundanz ohne Eingriff in dessen Konsistenz-/Krypto-Garantien. Vom Nutzer beauftragt. |
| E88 | Programm | **Reporting/Export-Primitiv (P13)**: Infra: `gd`-Extension im PHP-Image (PhpSpreadsheet-Voraussetzung) → Image-Rebuild. Deps `phpoffice/phpspreadsheet:^3` + `dompdf/dompdf:^3`. `ExportService::csv` (nativ `fputcsv`), `xlsx` (PhpSpreadsheet), `pdf` (dompdf HTML-Tabelle), `generate(format,…)` + `store(…)` (Ablage über `StorageManager`/P03 unter `reports/…`, Audit). **Entscheidungen:** CSV nativ (kein PhpSpreadsheet nötig); PDF via dompdf (HTML→PDF, schlank) statt TCPDF; gd sauber ins Image statt `--ignore-platform-req`. Verifiziert: `ExportServiceTest` (CSV-Inhalt, XLSX-ZIP-Signatur `PK`, PDF-`%PDF`-Header, unbekanntes Format, Ablage). Volle Suite **160→171 grün**. | Liefert die erwartete Export-Funktion als gemeinsames Core-Primitiv (Module/Admin), Ablageort austauschbar (lokal/S3). Vom Nutzer beauftragt. |
| E87 | Programm | **Automations-/Workflow-Engine (P12)**: Migration `CoreAutomation` (`automation_rules`: event-Muster, condition jsonb, actions jsonb, active). `ConditionEvaluator` (rein/testbar): `all`/`any`/`not` + Blätter, Operatoren eq/ne/gt/lt/gte/lte/contains/in/exists, Feldpfade per Punktnotation. `AutomationEngine::onEvent` (Event-Match exakt/`*`/`prefix.*` → Bedingung → Aktionen `notify` [mit `{{pfad}}`-Interpolation, via NotificationService] / `event` [via OutboxPublisher]); Regel-/Aktionsfehler isoliert. `OutboxWorker.dispatch` ruft die Engine **nur bei attempt_count==1** (keine Doppel-Aktionen bei Retries). CLI `AutomationCommand`. **Entscheidungen:** ECA (Event-Condition-Action) statt voller State-Machine (deckt gängige Automatisierung; State-Machine später); Auswertung nur beim ersten Versuch (Aktionen nicht idempotent); `event`-Aktion koppelt lose an Webhooks/Listener (statt direkter Webhook-Aktion). Verifiziert: `ConditionEvaluatorTest` (Operatoren/Verschachtelung/Pfade), `AutomationEngineTest` (Matching/Bedingung/Notify-Stub). Volle Suite **153→160 grün**. | Liefert No-Code-Automatisierung auf der vorhandenen Event-Infrastruktur — Phase D abgeschlossen. Vom Nutzer beauftragt. |
| E86 | Programm | **AI/LLM-Primitive (P11)**: Infra: DB-Image → `pgvector/pgvector:pg17` (datenkompatibel; Volume bleibt). Migration `CoreAi` (`CREATE EXTENSION vector`, `core.embeddings` `vector(1536)` + HNSW/cosine, Contracts `core.ai.complete`/`core.ai.embed`). `AiGateway` (provider-agnostisch, `complete`/`chatMessages`/`embed`/`enabled`; Provider/Modell aus `core.ai.*`-Settings, Endpoint je Provider mit Default+Override, Schlüssel aus `OPENAI_/ANTHROPIC_/XAI_/GOOGLE_API_KEY`-Env). Provider `OpenAiProvider` (Basis), `XaiProvider` (OpenAI-kompat., Grok-Default), `AnthropicProvider` (system-Feld; kein Embed), `GoogleProvider` (Gemini, Rollen user/model, Key als Query). `EmbeddingService` (`index`/`remove`/`semantic` via `embedding <=> :v::vector`, Owner-Scoping). Alle Netzaufrufe über `EgressClient` (P01, SSRF). **Entscheidungen:** Schlüssel **nur** per Env (Geheimnisse nicht in DB); Dimension 1536 fix (OpenAI-Default, dokumentiert); pgvector-Image statt manueller Extension-Build; Anthropic/xAI ohne bzw. OpenAI-kompatibles Embedding; AI-Smoke ohne reale Keys nicht möglich → Provider per Egress-Stub getestet. Verifiziert: `AiProviderTest` (4 Provider Request/Parse + Gateway-Disabled), `EmbeddingServiceTest` (pgvector Index + semantische Suche + Owner-Scoping). Volle Suite **145→153 grün**. | Liefert ein zukunftsweisendes, anbieter-neutrales AI-Fundament (Copilots/Semantik-Suche) ohne Lock-in; Module nutzen es über Capabilities. Vom Nutzer beauftragt (Provider: ChatGPT/Claude/Grok/Google). |
| E85 | Programm | **Volltext-Suche (P10)**: Migration `CoreSearch` (`search_index` mit generierter `tsvector`-Spalte [setweight A/B, `simple`], GIN-Index, Contract `core.collector.search`). `SearchService::index/remove/removeSource/search/reindexAll`; Suche via `websearch_to_tsquery('simple')` + `ts_rank`, Owner-Sichtbarkeitsfilter (`owner_id IS NULL OR owner_id = :uid`; `userId=null` → System/alles). `SearchIndexerInterface` (Modul-`reindex` über ContributionRuntime). API `Api\V1\SearchController` (`GET /api/v1/search`, Scope me:read, OpenAPI). **Entscheidungen:** zentrale Index-Tabelle (Module pushen Dokumente) statt verteilter Index über Modul-Schemata (RLS/Heterogenität); `simple`-Config (sprachneutral, mehrsprachige Plattform) statt language-stemming; Owner-basierter Sichtbarkeitsfilter im Query (nicht volle RLS auf der Index-Tabelle). Verifiziert: `SearchServiceTest` (Ranking, websearch, Owner-Scoping, Upsert/Remove) + Live-Smoke (`/api/v1/search` liefert Treffer mit Rank). Volle Suite **142→145 grün**. | Liefert die erwartete Plattform-Suche als opt-in Core-Capability; Module docken über einen Contract an. Vom Nutzer beauftragt. |
| E84 | Programm | **Benachrichtigungs-Framework (P09)**: Migration `CoreNotifications` (`notifications`, `notification_prefs` UNIQUE(user,type,channel), Contract `core.collector.notification_channel`). `NotificationService::notify` löst Kanäle auf (Default in_app+email, per Präferenz ab-/zuschaltbar) und stellt **mehrkanalig** zu: In-App (Insert + `RealtimeService::publish`/SSE-P08), E-Mail (`MailService::notify`), Modul-Kanäle über `ContributionRuntime->collectors('core.collector.notification_channel')` (`NotificationChannelInterface::key/deliver`, in-process/RPC) — plus Outbox-Event `core.notification.created` für Webhooks (P05). Kanal-Fehler isoliert. Lese-/Präferenz-API: `unread/unreadCount/markRead/markAllRead/setPref`; `Api\V1\NotificationsController` (`GET /notifications`, `POST /{id}/read`, `/read-all`, Scope me:read, in OpenAPI). **Entscheidungen:** ein `notify()` fächert auf alle Kanäle auf (Aufrufer-einfach); Modul-Kanäle opt-in via Präferenz; Webhook-Anbindung über Outbox-Event (lose Kopplung P05↔P09); `data` als JSON dekodiert in der API. **Latenter Bug behoben:** PHP `false` band als `''` → PostgreSQL-Boolean-Fehler; `setPref`/`WebhookService`/`SsoService` setActive binden nun `'true'/'false'`. Verifiziert: `NotificationServiceTest` (In-App, Präferenz-Abschaltung, E-Mail-Stub, Modul-Kanal-Stub) + Live-Smoke (`/api/v1/notifications` zeigt die Benachrichtigung). Volle Suite **138→142 grün**. | Cross-Cutting-Primitiv: jedes Modul benachrichtigt einheitlich (In-App/E-Mail/Webhook/eigene Kanäle) mit Nutzer-Präferenzen — Phase C abgeschlossen. Vom Nutzer beauftragt. |
| E83 | Programm | **Echtzeit-Stream / SSE (P08)**: `RealtimeService` (identifier-sicherer Kanal `rt_<hash>` je Benutzer, `publish` via `pg_notify` auf der Default-Connection, eigene `listenPdo` aus APP_DATABASE_URL/DATABASE_URL). `SseController::stream` (`GET /events/stream`): prüft Identität (sonst 401), liefert `text/event-stream` als `CallbackStream` (läuft bei der Ausgabe → außerhalb der RLS-Transaktion), LISTEN auf den Benutzerkanal, ≈30 s Schleife mit Heartbeats (`: ping`), `data:`-Frames bei NOTIFY; `X-Accel-Buffering: no` (nginx). **Entscheidungen:** zeitlich begrenzte Verbindung + EventSource-Auto-Reconnect statt dauerhaft gebundenem FPM-Worker; LISTEN/NOTIFY statt zusätzlichem Broker (gleiche Strömung wie Outbox); App-Rolle genügt (LISTEN/NOTIFY nicht rechtebeschränkt); pg_notify-Kanal als Parameter (kein Identifier-Quoting). Verifiziert: `RealtimeServiceTest` + Live-Smoke (Cross-Session publish→LISTEN liefert `{event,data}`; `/events/stream` unauth → 401). Volle Suite **136→138 grün**. | Liefert das Echtzeit-Fundament für In-App-Benachrichtigungen (P09) ohne neue Infrastruktur. Vom Nutzer beauftragt. |
| E82 | Programm | **API-Reife (P07): Rate-Limiting + OpenAPI + Modul-Routen**: Drei Bausteine im Architekturhabitus (Middleware/Settings/Cache, Contract/Registry/ContributionRuntime). **(1)** `ApiRateLimitMiddleware` (nach ApiAuth, nur /api/): Fixed-Window/Minute je `apiTokenId` (sonst IP) über `CacheStore::increment` (neu; atomar Redis/APCu, RMW-Fallback FileEngine — Cake FileEngine wirft bei increment); `429`+`Retry-After`+`X-RateLimit-Limit/Remaining/Reset`; Settings `api.rate_limit.enabled|per_minute`; Cache-Config `_app_ratelimit_`; fail-open bei Cache-Ausfall. **(2)** `OpenApiGenerator` → `GET /api/v1/openapi.json` (OpenApiController) aus Core-Endpunkten + `ApiRouteRegistry::all()` (3.1.0, bearerAuth, Tags, Pfad-Parameter). **(3)** Contract `core.api.route` (Migration), `ApiEndpointInterface`, `ApiRouteRegistry` (liest `api_routes` aktiver Modul-Manifeste; `matchPath` public-static testbar), `Api\V1\ModuleController::dispatch` (`/api/v1/m/{key}/{path}`) → `ContributionRuntime->call(contrib,'handle',[req])` (in-process/RPC), Scope-Prüfung. **Entscheidungen:** Fixed-Window (einfach, cache-effizient) statt Token-Bucket; OpenAPI **generiert** (Single Source of Truth) statt handgepflegt; Modul-Routen **manifest-getrieben** (gleiche Strömung wie Autoloader/Locales) statt Registrierungstabelle — Contract trotzdem geseedet (Sichtbarkeit/Habitus); openapi.json token-gated (kein anonymes /api). **Lehre (erneut):** Unit-grün ≠ Runtime-grün — `CacheStore::increment` schlug erst auf FileEngine fehl (fail-open → kein Limit), per RMW-Fallback behoben; Live-Smoke bestätigt. Verifiziert: `ApiRouteRegistryTest`, `OpenApiGeneratorTest`, `ApiRateLimitMiddlewareTest` + Live (`/openapi.json` 200+Header / 401). Volle Suite **130→136 grün**. | Macht aus der „Status-API" eine echte Plattform-API: rate-limitiert, selbstdokumentierend (OpenAPI) und durch Module erweiterbar — Phase B abgeschlossen. Vom Nutzer beauftragt. |
| E81 | Programm | **OIDC + SAML SSO (P06)**: Deps `web-token/jwt-library:^3.3` (OIDC) + `onelogin/php-saml:^4.1` (SAML). Migration `CoreSso` (`sso_providers` mit AES-verschlüsseltem Secret, `identity_links` UNIQUE(provider_id,subject)). `SsoService` (Provider-CRUD + JIT-Provisioning/Linking: Link→E-Mail→Anlage, Status active/ohne Passwort, Audit). `OidcProvider`: Discovery+JWKS (Egress P01 + Cache P02), Authorization-URL mit **PKCE/state/nonce**, Code-Tausch, **`validateIdToken`** (web-token JWSVerifier gegen JWKSet + iss/aud/exp/nonce — rein/testbar). `SamlProvider` (onelogin: Login-Redirect + ACS, Attribut-Mapping). `SsoController` (start/oidcCallback/samlAcs → `setIdentity`), Routes, Login-Buttons (provider-`button_label`, kein i18n), CSRF-Skip für `/sso/saml/acs`. CLI `SsoCommand`. **Entscheidungen:** SSO als **paralleler Flow** statt Ersatz des `core.auth.provider`-Slots (redirect-basiert, Break-Glass bleibt); PKCE Pflicht; Secret AES (wie Settings); JIT-Provisioning gegen E-Mail (Unique-lower); web-token statt firebase/php-jwt (JWKS/Algorithmen-Robustheit, Nutzerwahl); GUI später. Verifiziert: `OidcProviderTest` (5: gültig + aud/exp/nonce/Fremdsignatur), `SsoServiceTest` (4: Secret-Krypto, Provision, Link-Reuse, E-Mail-Link), `SamlProviderTest` (2) + Live-Smoke (CLI add → Button rendert, /login 200). Volle Suite **119→130 grün**. | Schließt die Enterprise-Pflichtlücke SSO (OIDC **und** SAML) First-Party, ohne die lokale Anmeldung aufzugeben. Vom Nutzer beauftragt (web-token + SAML). |
| E80 | Programm | **Outbound-Webhooks (P05)**: Migration `CoreWebhooks` (`webhook_subscriptions`, `webhook_deliveries` mit UNIQUE(subscription_id,event_id)). `App\Service\Webhook\WebhookService`: `enqueueForEvent` (idempotentes INSERT…SELECT…ON CONFLICT DO NOTHING, Filter `*`/kommagetrennt via `string_to_array`+`ANY`), `deliverPending` (FOR UPDATE SKIP LOCKED, POST über `EgressClient`/P01, **HMAC** `sha256(timestamp.body)` als `X-Fertura-Signature`, Retry/Backoff/Dead-Letter), Management (create/setActive/delete/list/listDeliveries/retry, Audit). `OutboxWorker` integriert: Dispatch reiht Webhooks ein (Fehler → Event-Retry, idempotent), Run-Loop stellt pro Zyklus begrenzt zu (externe HTTP-Aufrufe). CLI `WebhookCommand`. **Entscheidungen:** separate Delivery-Queue (pro Subscription eigener Retry/Status) statt Outbox-Listener; HMAC über `timestamp.body` (replay-fest, Stripe-Stil); SSRF-Egress gilt auch für Webhook-URLs (private nur per Allowlist); Geheimnis derzeit Klartext in DB (Webhook-Secret-Konvention, „einmal anzeigen") — Verschlüsselung als spätere Option; **Admin-GUI bewusst verschoben** (i18n-/Template-Aufwand) — Verwaltung via CLL + API (P07). Grants: neue core-Tabellen erhalten Rechte über `db_provision_app_role` (läuft je Boot). Verifiziert: `WebhookServiceTest` (Signatur/Filter/Idempotenz/Zustellung+Header/Dead-Letter) + Live-CLI-Smoke (add/list/migrate/provision). Volle Suite **115→119 grün**. | Schließt die größte Integrationslücke: Events erreichen externe Systeme sicher (SSRF-Schutz), signiert und zuverlässig (Retry/Dead-Letter). Vom Nutzer beauftragt. |
| E79 | Programm | **Metrics + Tracing (P04) + Cache-Regression behoben**: Prometheus-`/metrics` (geschützt wie Health-Detail: Session/`health_token`) via `MetricsController`; `MetricsService` exportiert **DB-Zustand** als Gauges (`fertura_up`, `fertura_worker_heartbeat_age_seconds`, `fertura_outbox_events`, `fertura_modules`) über die **privilegierte** Verbindung (Request-Pfad-Rolle darf System-Tabellen nicht lesen), jede Teil-Abfrage fehlerisoliert; `PrometheusRenderer` (Textformat 0.0.4, HELP/TYPE-Gruppierung, Label-Escaping). **Tracing:** `App\Log\Trace` (W3C traceparent parse/build), `LogContextMiddleware` führt `trace_id`/`span_id` fort/erzeugt sie, `EgressClient` propagiert `traceparent`. **Entscheidungen:** zustandsbasierter Exporter statt prozesslokaler Zähler (kein FPM-Aggregationsproblem); privilegierte Reads (Ops-Endpoint); Health-Token-Auth wiederverwendet. **Regression beim Smoke-Test gefunden+behoben:** Der P02-Settings-Cache schrieb auf jedem Request, aber das Laufzeit-Cache-Verzeichnis `/tmp/cake_cache` war root-eigen (www-data konnte nicht schreiben) → FileEngine-Warnung, die im Debug-Modus die Antwort **app-weit** zerstörte. Fix: (a) `CacheStore` unterdrückt umgebungsbedingte Warnungen (`@`, echte graceful degradation), (b) Entrypoint macht `/tmp/cake_cache` für www-data beschreibbar. Zusätzlich Schema-Drift-Bug behoben (Heartbeat-Spalte heißt `last_status`, nicht `status` — wurde still verschluckt) + Test verschärft (`testWorkerHeartbeatBecomesAGauge` mit eingefügtem Heartbeat). **Lehre:** Unit-Tests grün ≠ Runtime grün — HTTP-Smoke-Test deckte beide Fehler auf. Verifiziert: `/metrics` end-to-end (401 ohne Token; reale Gauges mit Token), `PrometheusRendererTest`/`MetricsServiceTest`/`TraceTest`. Volle Suite **114→115 grün**. | Liefert Prometheus-Observability + Trace-Korrelation; behebt zugleich eine durch P02 eingeschleppte Laufzeit-Regression. Vom Nutzer beauftragt. |
| E78 | Programm | **Objekt-Storage-Abstraktion (P03)**: Neue Deps `league/flysystem:^3` + `league/flysystem-async-aws-s3:^3` (leichter `async-aws/s3` statt AWS-SDK). `App\Service\Storage\StorageManager` kapselt Flysystem hinter schmaler Core-API (write/writeStream/read/readStream/delete/deleteDirectory/exists/fileSize/lastModified/mimeType/list) + `StorageException`-Übersetzung. Treiber `local` (Default, Wurzel `core.storage.path` oder `ROOT/storage`) oder `s3` (`core.storage.driver`); S3-Creds out-of-band über `STORAGE_S3_*` (Bucket/Region/Endpoint/Key/Secret/Prefix/PathStyle). **Entscheidungen:** async-aws statt aws-sdk-php (deutlich leichter, S3-kompatibel inkl. MinIO via PathStyle); S3-Creds nur per Env (Geheimnisse nicht in DB); `FilesystemOperator` injizierbar (netzfreie Tests gegen LocalAdapter); Flysystem-Exceptions in Core-Exception übersetzt. Verifiziert: `StorageManagerTest` (Write/Read/Exists/Size/Streams/List/Delete + Fehlerfall). | Fundament für Off-Site-Backup/PITR (P14) und Reporting/Export (P13); macht den Ablageort austauschbar (lokal↔Cloud). Vom Nutzer beauftragt. |
| E77 | Programm | **Cache-Abstraktion + Settings-Cache (P02)**: Engine-konfigurierbarer Cache (`_app_`/`_app_settings_` in `config/app.php`, via `CACHE_APP_URL` File/APCu/Redis) + `App\Service\Cache\CacheStore` (ausfallsicherer Wrapper über `Cake\Cache`, graceful degradation, `get/set/delete/clear/remember`). `SettingsManager::get` cacht jetzt die **nicht-geheime** Auflösung als `{useDefault, value}` (DB-/Katalog-Ebene), der aufrufer-spezifische `$default` wird erst beim Lesen angewandt; `set()` invalidiert gezielt den Schlüssel. **Entscheidungen:** Geheimnisse werden **nie** gecacht (Bypass in `resolve()`, kein Klartext im Datei-Cache, vermeidet auch Stale-Risiko); Cache-Konfigs ohne Unterverzeichnis (Prefix trennt, kein Lazy-mkdir); Test-Isolation über `tests/bootstrap.php` (Cache-Clear nach Migrator-Truncate), innerhalb eines Laufs sorgt die `set()`-Invalidierung für Konsistenz; `CacheStore` degradiert bei fehlendem Cache zur No-Op. Verifiziert: `CacheStoreTest` (Roundtrip, remember-once), `SettingsManagerCacheTest` (Invalidierung bei set, Geheimnis-Nicht-Caching). Volle Suite **98→102 grün** (zudem deutlich schnellerer Lauf durch gecachte Settings-Reads). | Liefert das Cache-Fundament (für P07-Rate-Limit, P10) und entlastet den heißesten Lesepfad, ohne Geheimnisse zu exponieren. Vom Nutzer beauftragt (Tier-Programm). |
| E76 | Programm Tier-1 | **HTTP-Egress-Primitiv (P01, Nutzer-Anforderung „alle aus Tier 1 bis 3 umsetzen")**: Start des Programms „Wettbewerbsfähigkeit Core" (vollständige Reihenfolge in `PROGRAM_TIER123.md`, 16 Punkte/6 Phasen, abhängigkeits-sortiert). P01 = gemeinsames gehärtetes Outbound-HTTP als Fundament für P05 Webhooks, P06 OIDC, P11 AI-Gateway. Neue `App\Service\Http\EgressClient` (+ `EgressResponse`/`EgressException`) auf `Cake\Http\Client` (keine neue Dependency). **SSRF-Schutz** über `filter_var(FILTER_FLAG_NO_PRIV_RANGE\|NO_RES_RANGE)`: private/reservierte Ziele (Loopback, RFC1918, Link-Local inkl. `169.254.169.254`) blockiert; nur http/https; Timeout/Antwortgrößen-Limit/User-Agent. Config-Reihenfolge explizit>DB-Settings>Default (deterministisch testbar). **Entscheidungen:** eigenes Egress-Primitiv statt direkter Client-Nutzung (zentrale Policy/SSRF/Audit-Stelle); Allowlist erlaubt private Ziele gezielt (interne Integrationen); `allow_private` als bewusster Betreiber-Override; `sendRequest()`/`resolveHostIps()` als protected Methoden für netzfreie Tests; Multi-Tenancy bleibt out-of-scope (D-A). Settings `core.http.egress.*`. Verifiziert: `EgressClientTest` (8 Fälle: Schema-Reject, Loopback/Privat/Metadaten-Block, Allowlist, Override, Response-Mapping, Body-Cap, Content-Length-Reject). Volle Suite **90→98 grün**. | Schafft den sicheren gemeinsamen Ausgang, ohne den jede spätere Integration eine eigene (potenziell SSRF-anfällige) HTTP-Strecke bauen würde. Vom Nutzer beauftragt (Tier-1-Programm). |
| E75 | Modul-Isolation | **Review-Härtung der Out-of-Process-Isolation (Nutzer-Anforderung „neuer vollständiger Review + Doku-Prüfung")**: Drei unabhängige Prüfungen (Capability-Token-Security, Resolver/Scheduled/Launcher-Korrektheit, Doku-Sync) — Ergebnis: Funktion korrekt, Doku **vollständig in sync** (alle zitierten Tests/Klassen/Settings existieren). Behoben wurden die gefundenen Restpunkte: **(1, HOCH)** `ModuleHostSupervisor::stop()` verließ sich auf die eingefangene `$!`-PID → ein **forkender Launcher** (firejail) konnte den echten Host verwaisen lassen. Neu: `findHostPids()` scannt `/proc/*/cmdline` nach `module-host.php` **und** Key (PID-Recycling-sicher) und SIGTERMt den **tatsächlichen** Host (plus die gespeicherte PID, falls sie unser Host ist). **(2, MITTEL)** Geheimnis **out-of-band**: Supervisor übergibt `MODULE_RPC_TOKEN_FILE` (Pfad zur 0600-Datei) statt `MODULE_RPC_TOKEN` (Wert) → kein Klartext in `/proc/<pid>/environ`/Kommandozeile; Host liest die Datei (Env-Fallback nur für Alt/Test). **(3, MITTEL)** **Fail-closed**: Host verweigert den Start (`exit 4`) bei leerem Geheimnis statt unauthentifiziert zu bedienen; der dispatch-Auth-Zweig ist nun unbedingt. **(4, MITTEL)** **DoS-Schutz**: `fgets($conn, 4 MiB)` + Ablehnung überlanger/unvollständiger Zeilen vor `json_decode`. **(5, HOCH-Ehrlichkeit)** Bedrohungsmodell klargestellt (Docblock `RpcCapabilityToken` + Kap. 23.16.2): das Token authentifiziert die **Prozessgrenze** (Core→Host) und schützt vor Manipulation/Replay/fremden Clients, **beschränkt aber nicht** den Modulcode selbst (der das Geheimnis kennt) — Sandbox bleiben DB-Rolle/Env/OS-Isolation. **(6, NIEDRIG)** stale Docblock `ModuleLifecycle::assertIsolatable` (sagte „nur Service-Contracts") korrigiert; Launcher-**Exec-/Signal-Anforderung** + **Settings-Write=Shell-Vertrauensstufe** im `SettingsCatalog`-Kommentar + Doku ergänzt; kosmetisches Log-Feld (`module`→`task`). **Bewusst NICHT geändert:** `MODULE_DB_URL` bleibt env-übergeben (Modul-Rolle ist ohnehin eingeschränkt; gleiche owner+root-Exposition wie die 0600-Datei); Nonce-Replay-Schutz bleibt prozesslokal (nach Host-Neustart theoretisch ein ≤60-s-Fenster — durch TTL eng, dokumentiert statt persistiert); Metadaten-RPC-Timeout (key()/intervalSeconds()) unverändert (pre-existing). Doku: Kap. 23.16.2 (Auth-Bullet + Launcher-Absatz), Changelog 6.48. Verifiziert: volle Suite **90 grün** (secret-file-Mechanismus, fail-closed, fgets-Cap, /proc-Stop ohne Regression). | Schließt die im Review gefundenen Korrektheits-/Sicherheits-Restpunkte (verwaiste Hosts, Geheimnis-Exposition, Fail-open, DoS) und stellt das Bedrohungsmodell ehrlich dar. Vom Nutzer beauftragt. |
| E74 | Modul-Isolation | **Pro-Aufruf-Capability-Token für die Out-of-Process-RPC (Nutzer-Anforderung „jetzt die Capability-Token implementieren")**: Der letzte als „spätere Protokoll-Erweiterung" benannte Isolations-Restpunkt. Bisher authentifizierte ein **statisches Host-Token** den Kanal: es wurde bei **jedem** Aufruf im Klartext über den Socket geschickt (`hash_equals`), autorisierte jede `class::method` im Modul-Namespace und war unbegrenzt wiederholbar — ein Token-Leak (gleiche UID, Logfile) oder Socket-Mitschnitt bedeutete vollen, replaybaren Zugriff. Jetzt **aufruf-gebunden**: **(1)** neue Klasse `App\Service\Module\RpcCapabilityToken` (symmetrisch `mint()`/`verify()`, deterministische `canonical()` via rekursivem ksort + JSON-Roundtrip, damit Core vor dem Senden und Host nach dem Decodieren dasselbe signieren). Das Host-Geheimnis (`MODULE_RPC_TOKEN`, 0600-Datei) ist nur noch **HMAC-Schlüssel** und reist **nie** über den Socket; mitgeschickt wird `{nonce, exp, cap}` mit `cap = HMAC-SHA256(secret, canonical(req)+nonce+exp)`. **(2)** `RemoteInvoker::send()` mintet das Token über die finale Anfrage (inkl. rls) statt das Geheimnis anzuhängen. **(3)** `bin/module-host.php` prüft `verify()` (MAC + Ablauf + Plausibilität) **und** hält eine **Nonce-Sperrliste** (Einmaligkeit/Replay-Schutz, abgelaufene Nonces werden gepruned). Der MAC deckt die ganze Anfrage ab → **Integrität** (keine Manipulation von Methode/Argumenten/RLS, z. B. `bypass=true`), **Zeitfenster** (TTL 60 s) und **Einmaligkeit**. **Entscheidungen:** HMAC statt Klartext-Bearer (Geheimnis bleibt geheim, auch gegen Socket-Sniffer bei gleicher UID, solange E73-OS-Isolation nicht aktiv); MAC über die **gesamte** kanonisierte Anfrage statt nur über Operationsnamen (bindet auch Argumente/RLS-Kontext); Nonce-Einmaligkeit im Host (Single-Process, In-Memory-Set genügt); TTL 60 s großzügig (lokaler RPC sofort, Nonce verhindert Replay ohnehin) — Test-Robustheit vor enger Frist; sauberer Schnitt (Host akzeptiert **nur** noch `cap`, kein Legacy-Klartext-Token) → laufende Hosts müssen beim Deploy neu gestartet werden (Worker-Restart/Re-Aktivierung, ohnehin Routine). Doku: Kap. 23.16.2 („Authentifizierter Aufruf über RPC" neu gefasst, Restpunkt gestrichen), MODULE_DEVELOPMENT, Changelog 6.47. Verifiziert: `RpcCapabilityTokenTest` (9 Unit-Fälle: Roundtrip, Tamper [Methode/Argumente/bypass], falsches Geheimnis, Ablauf, Zukunft, fehlende Felder, Kanonisierung reihenfolge-/auth-unabhängig, Listenordnung) + `OutOfProcessIsolationTest::testPerCallTokenRejectsReplayAndForgery` (realer Socket: gültig ok, Replay/falsches Geheimnis/manipulierte Nutzlast abgewiesen); alle bestehenden RPC-Tests laufen jetzt über mint/verify. Volle Suite **80→90 grün**. | Schließt die letzte offene Isolations-Erweiterung: die RPC-Authentifizierung schützt jetzt den **einzelnen Aufruf** (Integrität, Replay, Geheimnis-Vertraulichkeit) statt nur den Kanal. Vom Nutzer beauftragt. |
| E73 | Modul-Isolation | **Konfigurierbares Launcher-Prefix für isolierte Modul-Hosts (Nutzer-Anforderung: OS-Härtung „als Admin ohne Core-Anpassung umsetzen")**: Auf die Frage, ob die in E72 als spätere Ausbaustufen benannten OS-Punkte (eigener OS-Benutzer, FS-/Kernel-Begrenzung) ohne Core-Änderung admin-seitig umsetzbar sind, ergab die Code-Analyse: **nein** — `ModuleHostSupervisor::spawn()` baute den Startbefehl `nohup env -i <vars> php module-host.php <key>` **fest verdrahtet** (kein Hook), und die `/proc/cmdline`-Prozesserkennung (`isOurHost`) hätte einen Wrapper ohnehin verfehlt; #3 (Capability-Tokens je Aufruf) ist zudem gar keine Infrastruktur, sondern RPC-Protokoll. Daraus die kleine, additive Core-Erweiterung: **(1)** neues Setting `core.module.host.launcher` (SettingsCatalog, type string, Default null) — ein Befehls-Prefix, das `spawn()` **zwischen `env -i <vars>` und `php`** setzt (`$prefix . 'php ' . …`), unverändert (nicht als ein Argument gequotet), sodass es in der bereinigten Umgebung läuft und `php` wrappt/exec't. Damit aktiviert der **Betreiber** OS-Härtung ohne Codeänderung: `setpriv --reuid=… --clear-groups --` (eigener Benutzer), `bwrap --unshare-all …` / `firejail` (FS-/Kernel-Sandbox). **(2)** `isOurHost()` **wrapper-tolerant** gemacht: prüft jetzt auf **Vorkommen** von `module-host.php` **und** Modul-Key in der zusammengesetzten Kommandozeile (statt exaktes argv-Token via `in_array`) — toleriert exec-artige (`setpriv`/`bwrap`) **und** kombinierte (`sh -c "…"`) Wrapper, bleibt durch die Doppelbedingung PID-Recycling-sicher. **Entscheidungen:** Launcher als unverändert eingesetztes Prefix (Wrapper liefert eigene Argumente; ein einzelnes gequotetes Argument wäre nutzlos) — vertretbar, da admin-only/auditiertes Setting; Prefix **nach** `env -i` (läuft in bereinigter Umgebung); `module.host.launcher` namespace `core`; Capability-Tokens-je-Aufruf bewusst **nicht** hier (Protokoll-Erweiterung, separater Punkt). Default leer = unverändertes Verhalten. Doku: Kap. 23.16.2 (Launcher-Prefix-Absatz + präzisierte Restpunkte), Changelog 6.46. Verifiziert per `OutOfProcessIsolationTest::testLauncherPrefixWrapsHostProcess` (transparenter Wrapper schreibt Marker + exec't `php`: Prefix lief vor `php`, Host kommt hoch, Service-Aufruf reicht Argumente korrekt durch, `deactivate` stoppt den gewrappten Host sauber). Volle Suite **79→80 grün**. | Verwandelt die zuvor nur als „Infra-Ausbaustufe" benannte OS-Härtung in eine **echte Betreiber-Option ohne Core-Eingriff** (eigener Benutzer/Sandbox), ehrlich abgegrenzt von der noch offenen Protokoll-Erweiterung (Capability-Tokens je Aufruf). Vom Nutzer beauftragt. |
| E72 | Modul-Isolation | **Out-of-Process Phase 3 abgeschlossen: Resolver + periodische Aufgaben über RPC (Nutzer-Anforderung „ja" — die letzten zwei Core-Enden)**: E71 ließ noch **Daten-Resolver** und **periodische Aufgaben** (`core.collector.scheduled`) bei Isolation abgelehnt. Jetzt laufen auch sie über RPC im Host — die Out-of-Process-Erweiterungspunkte sind damit **vollständig** (Service, Collector, Event, Resolver, Scheduled). **(1) Scheduled über RPC:** `ScheduledTaskRunner.tick()` sammelt Modul-Aufgaben jetzt **mit Isolationsmodus** über `ContributionRuntime.collectors()` und ruft `key()`/`intervalSeconds()`/`run()` einheitlich über `ContributionRuntime.call()` auf — in-process lokal, out_of_process über RPC im Host; der Fälligkeits-Check (Heartbeat `sched:<key>`) und der **Mehrinstanz-Advisory-Lock** bleiben **im Core** (nur `run()` reist über die RPC-Grenze, mit `bypass=true` als Systemkontext). **(2) Resolver über RPC:** `CapabilityHandle::invoke()` akzeptiert nun auch `resolver`-Contracts und routet über `ContributionRuntime.call($provider, 'handle', [$input])`, wobei die Isolation am **Provider-Modul** (`core.modules.isolation`) statt am Contract-Owner bestimmt wird (neuer `ContractRegistry::resolveProvider()` liefert Klasse **und** Modul der aktiven Provider-Registrierung). **(3) Enforcement final:** `assertIsolatable` lehnt nur noch den **`core.auth.provider`-Slot** ab — der ist config-artig (der Resolver liefert ein In-Process-Authenticator-Objekt, das nicht über RPC reichbar ist), alle Daten-Resolver sind isolierbar. **(4) Latente Lücke geschlossen:** `core.collector.scheduled` war **nie geseedet** (nur `health`/`anonymize`) — Module konnten sich also gar nicht für periodische Aufgaben registrieren; Migration `CoreScheduledCollector` seedet den Contract. **Entscheidungen:** Lock/Heartbeat/Fälligkeit bleiben im Core (zentrale Orchestrierung, kein Lock über RPC); Auth-Provider als einzig verbleibend ausgenommener Resolver (config-, nicht aufruf-artig); Scheduled-`run()` mit Systembypass (kein laufender Benutzer). Doku: 23.16.2 (Geltungsbereich „vollständig"), Changelog 6.45. Verifiziert per erweitertem `OutOfProcessPhase3Test`: `testIsolatedScheduledTaskRunsInHost` (isolierte Aufgabe schreibt beim Tick **im Host** über die Modul-Rolle einen Marker), `testDataResolverModuleIsIsolatable` (Modul mit Daten-Resolver installiert out_of_process); `OutOfProcessIsolationTest` (Auth-Provider-Resolver weiter abgelehnt). Volle Suite **78→79 grün**. | Vervollständigt die Out-of-Process-Isolation: alle gängigen Erweiterungspunkte (inkl. Resolver/Scheduled) laufen isoliert über RPC mit korrektem RLS-/System-Kontext; nur der config-basierte Auth-Provider-Slot bleibt naturgemäß in-process. Vom Nutzer beauftragt. |
| E71 | Modul-Isolation | **Out-of-Process Phase 3: Erweiterungspunkte über RPC + RLS-Kontext (Review-#1, Nutzer-Anforderung „implementiere 1")**: Bisher liefen nur **Service-Contracts** isolierter Module über RPC; Collector/Event/Resolver waren bei Isolation abgelehnt. Jetzt laufen **Service-Contracts, Collector-Beiträge (Health, Anonymisierung) und Event-Listener** über RPC im Host. **(1) Host als Mini-DB-Laufzeit:** `bin/module-host.php` konfiguriert eine CakePHP-`default`-Connection auf die Modul-Rolle (Search-Path aufs Modul-Schema), sodass Beitragsklassen wie in-process `ConnectionManager` nutzen — aber isoliert; generisches `op:'call'`-Protokoll (`$class::$method(...$args)`, Klasse muss im Modul-Namespace liegen). **(2) RLS-Propagierung:** `RemoteInvoker` liest den aktuellen Zeilenkontext (`app.current_user_id`/`-group_ids`/`-bypass`) der Default-Connection und reicht ihn mit; der Host setzt ihn je Aufruf transaktionslokal — Modul-Beiträge arbeiten gruppen-/benutzer-scoped. **(3) Einheitliche Weiche `ContributionRuntime`:** routet einen Beitrag in-process oder (out_of_process) über RPC; `ContractRegistry::collectContributions()`/`listenerContributions()` liefern Beiträge **mit Modul** (für die Isolations-Entscheidung). Verdrahtet: `AnonymizationService`, `HealthService.collectModuleHealth`, `OutboxWorker.dispatch`. **(4) Enforcement gelockert:** `assertIsolatable` erlaubt Collector + Event, lehnt aber weiter **Resolver** und **periodische Aufgaben** (`core.collector.scheduled`) ab (noch nicht über RPC — keine stille In-Process-Ausführung). **Entscheidungen:** Host bootstrappt nur ConnectionManager (kein voller App-Bootstrap → Isolation bleibt, Beiträge nutzen die Connection direkt); generisches class/method/args-Protokoll statt pro-Typ; Resolver/Scheduled bewusst zurückgestellt (heterogen bzw. Lock/Heartbeat-Orchestrierung); Transaktionsgrenze dokumentiert (Out-of-Process-Beitrag committet eigenständig → keine verteilte Transaktion, bei Anonymisierung unkritisch). Doku: 23.16.2 Geltungsbereich (Phase 3), Changelog 6.44. Verifiziert per `OutOfProcessPhase3Test` (isolierter Anonymisierungs-Collector läuft **im Host**, greift über die Modul-Rolle auf sein Schema zu, RLS-Bypass über RPC; Resolver/Scheduled abgelehnt); `OutOfProcessIsolationTest` (Echo/Probe über den generalisierten Host). Suite 76→78 grün. | Macht die Out-of-Process-Isolation für die gängigen Erweiterungspunkte (Service/Collector/Event) nutzbar inkl. korrektem RLS-Kontext; Resolver/Scheduled bleiben ehrlich abgelehnt. Vom Nutzer beauftragt. |
| E70 | DSGVO | **Anonymisierungs-Hook für Module (Core-Anteil von Review-#2, Nutzer-Anforderung „Core-Anteile aus 2")**: Bisher anonymisierte der Core nur `core.users`; Module hatten **keine** Möglichkeit, ihre eigenen personenbezogenen (Freitext-)Daten zu einem Benutzer mit zu bereinigen (Lücke über Modulgrenzen, Kap. 27.15.3). Jetzt: neuer **Core-Collector-Contract `core.collector.anonymize`** (Migration `CoreAnonymizeCollector`, Seed analog `core.collector.health`) + Interface `App\Service\Privacy\AnonymizeContributorInterface::anonymizeUser(string $userId): int` + `AnonymizationService`, der die registrierten Beiträge sammelt und **in derselben Transaktion** wie die Core-Anonymisierung aufruft (`UsersTable::anonymize`). **All-or-Nothing** (scheitert ein Beitrag, scheitert die Anonymisierung — besser laut als unvollständige Löschung); für die Dauer der Beiträge wird `app.bypass_rls` gesetzt (privilegierte Operation, erreicht die Zeilen des Zielnutzers) und danach **wiederhergestellt** (kein Kontext-Leak). **Entscheidungen:** Collector (synchron, atomar) statt Outbox-Event (async, könnte still fehlschlagen) — DSGVO braucht Verlässlichkeit; bypass + Restore statt globalem Bypass; Core liefert die Orchestrierung, die fachliche Bereinigung das Modul. Begleitend **H4 vervollständigt**: `tests/bootstrap.php` überspringt nun auch die zweite Default-Partition `event_outbox_default`. Doku: Kap. 27.15.3 aktualisiert, Changelog 6.43. Verifiziert per `AnonymizationServiceTest` (scoped Bereinigung, Kontext-Restore, End-to-End über `anonymize()`); Suite 73→76 grün. | Schließt die DSGVO-Lücke über Modulgrenzen auf der Core-Seite; das eigentliche Schrubben bleibt korrekt Modul-Sache. Vom Nutzer beauftragt. |
| E68 | Backup | **Peer-Review-Bestand-Befunde im `BackupService` behoben** (M6/B1 + B2; die in E67 als spätere Tasks dokumentierten Backup-Restposten, Nutzer-Anforderung): (1) **`pg_restore`-Exitcode/Ausgabe ausgewertet (M6/B1)** — `restoreArchive()` und `probeRestore()` riefen `pg_restore` über `exec()` auf, prüften aber **weder Exitcode noch Ausgabe**; ein echt fehlgeschlagener Restore wäre still durchgelaufen, der Wartungsmodus (im `restore()`-`finally`) wieder freigegeben und „ok" protokolliert worden → eine **halb-restaurierte DB käme unbemerkt online**. Neuer `assertRestoreOk()`-Scanner wirft bei echten `pg_restore: error:`/`ERROR:`-Zeilen sowie bei rc≠0 mit unerwarteter/leerer Ausgabe, **toleriert** aber bewusst die harmlosen `--clean --if-exists`-Notices (`does not exist, skipping`, `errors ignored on restore`) — also kein Fehlalarm wie beim reinen Exitcode-Check. Aufruf **vor** dem Entpacken der Datei-Stores bzw. der Maintenance-Freigabe. (2) **Konsistenz-Lock erzwungen (B2)** — `create()` lief bei fehlgeschlagenem `pg_try_advisory_lock` (eine andere Lifecycle-/Update-/Restore-Operation hält den Lock) **trotzdem ohne Lock weiter** und hätte einen DB↔Storage-inkonsistenten Snapshot erzeugt; jetzt **lautes Scheitern** statt stillem Fortfahren (die bereits als `creating` eingefügte Zeile setzt der bestehende catch auf `failed` + räumt auf). **Entscheidungen:** Ausgabe-Scan statt `--exit-on-error` (ein Restore soll möglichst vollständig durchlaufen, nur echte Fehler dürfen werfen); werfen statt blockierendem `pg_advisory_lock` bei B2 (klare, sofortige Rückmeldung statt unsichtbarem Hängen). **Test:** `BackupRoundtripTest::testCreateAbortsWhenLifecycleLockHeld` hält den Lock über eine **eigene DB-Sitzung** (zweite Connection, damit `pg_try_advisory_lock` real `false` liefert) und prüft das Werfen. Doku: Changelog 6.41. Verifiziert: volle Suite **73 Tests, 214 Assertions, grün** (+1). **Offen:** M7 (nicht-transaktionaler Install) bleibt ein separater, dokumentierter Restpost. | Schließt die in E67 benannten Backup-Restposten (M6/B1): ein fehlgeschlagener Restore bleibt nicht mehr unbemerkt und ein Backup ohne Konsistenz-Lock wird nicht mehr still als gültig erstellt. Vom Nutzer beauftragt. |
| E69 | Review | **Peer-Review-Bestand-Befund M7 behoben: nicht-transaktionale Modul-Installation** (der in E67/E68 dokumentierte Restpost, Nutzer-Anforderung): `ModuleLifecycle::install()` lässt sich nicht in eine DB-Transaktion kapseln (CREATE ROLE/Schema und das Kopieren des Pakets sind teils nicht-transaktional). Zuvor hatte **nur** der RLS-Pflicht-Abbruch (`assertScopedRls`, E47) einen eigenen manuellen Rückbau (Schema + Modulzeile + Verzeichnis); schlug ein **späterer** Schritt fehl — `grantSchemaToAppRole()`, `importPackageLocales()` oder eine `RegistryException` beim `registerContract()` —, blieben Schema, Modulzeile, kopiertes Verzeichnis **und** (bei `out_of_process`) die provisionierte DB-Rolle `mod_<key>` zurück → ein erneuter Install scheiterte an „Modul bereits installiert". **Fix:** Der manuelle Rückbau-Pfad ist auf **alle** Schritte ab Beginn der Seiteneffekte ausgedehnt. Die Artefakt-Erzeugung wurde nach `installArtifacts()` ausgelagert, das `install()` in ein `try { copyDir + CREATE SCHEMA + installArtifacts } catch (Throwable) { rollbackInstall(); throw }` kapselt. `rollbackInstall()` (best effort, jeder Schritt gekapselt) stoppt bei `out_of_process` den Host (`ModuleHostSupervisor::stop`) und entfernt die DB-Rolle (`ModuleDbRole::drop`, `DROP OWNED`+`DROP ROLE`) **vor** dem `DROP SCHEMA … CASCADE`, löscht dann `contract_registrations`/`contracts`/`resources`/`language_packs`/`modules` (CASCADE → dependencies/migrations_log) und entfernt das kopierte Verzeichnis **plus** die Sprachpaket-Dateien im Locale Store. `assertScopedRls()` wirft jetzt nur noch (Rückbau zentral). **Entscheidungen:** erweiterter manueller Rückbau statt echter DB-Transaktion (CREATE ROLE/Schema teils nicht-transaktional → keine atomare Kapselung möglich); Rolle vor Schema entfernen (die Rolle kann Tabellen im Schema besitzen); Reihenfolge/Tabellenliste analog `delete()` für Konsistenz. **Test:** `OutOfProcessIsolationTest::testFailedInstallRollsBackSchemaRoleAndArtifacts` injiziert eine `ContractRegistry`, deren `registerContract()` **nach** Schema/Rolle/Migrationen/FORCE-RLS/Grants/Locale-Import wirft, und weist nach, dass weder Schema noch Modulzeile, DB-Rolle, Contracts, Ressourcen, Sprachpakete noch das Verzeichnis zurückbleiben — und ein anschließender regulärer Install wieder gelingt. Doku: Changelog 6.42. Verifiziert: volle Suite **73 Tests, 214 Assertions, grün** (+1). **Begleitfund (behoben):** die Changelog-Tabelle war durch einen parallelen Commit korrupt (6.41-Zeile mit dem alten 6.40-Eintrag verschmolzen, fehlende 6.40-Zeile) — in zwei saubere Zeilen zurückgeführt. | Schließt den letzten der in E67 benannten Bestand-Restposten: eine fehlgeschlagene Installation hinterlässt keine Leichen mehr (inkl. verwaister DB-Rolle), und ein erneuter Install ist nicht mehr blockiert. Vom Nutzer beauftragt. |
| E67 | Review | **Peer-Review-Behebung (Pakete A/B/C)** (Nutzer-Anforderung „kompletter Peer-Review … alle drei umsetzen"): Ein dreigeteilter Peer-Review (Security/Correctness/Quality, 3 Agenten + eigene Verifikation) deckte u. a. einen kritischen Isolations-Befund auf; behoben in drei Paketen. **A (Sicherheit):** C1 — Migrationen-als-Rolle war über `RESET ROLE` umgehbar (`SET LOCAL ROLE` auf Superuser-Session) und Updates migrierten gar als Superuser → jetzt über eine **als Login-Rolle authentifizierte Verbindung** (Install **und** Update), Eskalation per Regressionstest ausgeschlossen; H1 — RPC-Socket war anonym aufrufbar → **pro-Host-Token** (0600-Datei, `hash_equals`), `__probe` ohne rohen Treiberfehler; M3 — `forceRls` quotet Katalog-Bezeichner (`quote_ident`) + defensive Key-Validierung; M5 — leere `@DOWN`-Sektion = harter Fehler. **B (Robustheit):** H2 — `stop()` prüft `/proc/<pid>/cmdline` vor dem Kill (PID-Recycling); H3 — `spawn()` serialisiert per `flock`, Host bindet ohne lebenden Vorgänger zu verdrängen, lautes Scheitern bei nicht startendem Host; M4 — Worker-Supervision auf ~30 s gedrosselt. **C (Quick Wins):** H4 — `tests/bootstrap.php` `Migrator->run(['skip'=>['audit_log_default']])` (kein manuelles `DROP DATABASE` mehr); M1 — Session-Spalte `text`→`bytea` (NUL-Bytes) + Test; FeatureFlags `trim()`. **Korrigierte Fehlalarme der Reviewer:** `fgets`-Truncation (liest bis Newline), granted core-Funktionen sind SECURITY INVOKER, `Db::privileged()` memoisiert (Advisory-Lock korrekt). **Dokumentierte Restposten (spätere Phasen):** RLS-Zeilenkontext über RPC, Same-User-Trennung via eigenem OS-Benutzer, sowie Bestand-Befunde M6/M7 (Backup-`pg_restore`-Exitcode, nicht-transaktionaler Install) als separate Tasks. Doku: 23.16.2 präzisiert, Changelog 6.40. Verifiziert: volle Suite **71 Tests, 202 Assertions, grün** (+2). | Behebt den im eigenen Peer-Review gefundenen kritischen Isolations-Bypass (C1) und härtet die Out-of-Process-Verwaltung; die Sicherheitszusage von E63 stimmt jetzt mit der Implementierung überein. Vom Nutzer beauftragt. |
| E66 | Update | **Upgrade-Pfad getestet + Rollback-Kaskade korrigiert** (Review-Punkt 3, Nutzer-Anforderung „jetzt 3"): Der zuvor unbewiesene Update-Pfad ist jetzt abgesichert. (1) **Systematische Down-Reversibilität**: Harness `migration_reversibility_check.sh` fährt auf einer Wegwerf-DB **alle** Core-Migrationen hoch und per `rollback -t 0` vollständig zurück → bestätigt, dass **jedes `down()` sauber reversibel** ist (die Bug-Klasse aus E62). Grün für alle 19 Migrationen. (2) **Modul-Update-Integrationstest** `ModuleUpdateTest`: Migrationsvorschau (`previewModule` — Versionsdelta, ausstehende Migrationen, Downgrade-Schutz), Update mit **Wiederherstellungspunkt nur bei ausstehenden Migrationen**, und die **Rollback-Kaskade** bei fehlerhafter Migration. (3) **Bugfix Rollback-Kaskade**: `UpdateManager::updateModule` rollte bei einem Fehlschlag **alle** im Paket angewendeten Migrationen zurück — inkl. der bereits beim Install angewendeten → **Datenverlust**. Jetzt werden nur die in **diesem** Update neu angewendeten Migrationen zurückgerollt (`appliedMigrations`-Vormerkung); per Test nachgewiesen (vorhandene Daten bleiben nach fehlgeschlagenem Update erhalten). **Entscheidungen:** Down-Migrationen als automatischer Rückbau, pg_dump-Recovery-Point nur als manuelle letzte Zuflucht (kein gefährlicher Auto-Restore, Kap. 28.14.2); RecoveryPoint im Test gestubbt (kein realer DB-Dump); Reversibilität als Scratch-DB-Harness statt destruktiv gegen die Test-DB. Doku: Changelog 6.39, TESTING.md. Verifiziert (Harness grün; `ModuleUpdateTest` 5/5; Suite 64→69, voller Lauf grün). | Schließt die offene Hälfte von #3 (Upgrade-Pfad durchspielen) **und** behebt einen echten Datenverlust-Bug im Update-Rollback. Vom Nutzer beauftragt. |
| E65 | Betrieb | **Deployment-Feature-Flags für optionale Subsysteme** (Review-Punkt 7, Nutzer-Anforderung „jetzt 7"): Umsetzung des vom Review benannten Hebels — je Installation nur das Nötige laufen lassen (kleinere Angriffs-/Wartungsfläche). `App\Service\System\FeatureFlags` liest `FEATURE_<NAME>` aus der Umgebung (Default aktiv, robuste Wert-Interpretation). Abschaltbar: **`api`** (externe API v1 — Routen in `routes.php` **und** `ApiAuthMiddleware` in `Application` gegated → 404 statt 401), **`marketplace`** (`MarketplaceCommand sync` + `Admin\MarketplaceController` Sync/Metadata; **Lizenzverwaltung bleibt** unberührt), **`backup_scheduler`** (`BackupScheduledTask` — harter Kill-Switch zusätzlich zum Setting; manuelles Backup bleibt). `/health` weist die Flags unter `features` aus (abgeschaltete optionale Subsysteme werden nicht geprüft → kein falsches „degraded"). **Entscheidungen:** **env-basiert** statt DB-Setting (harter Betreiber-Schalter, nicht über kompromittierte Admin-Sitzung reaktivierbar); Standard **alle aktiv** (kompatibel); Marketplace-Flag trennt sauber zwischen Client (aus) und Lizenzverwaltung (bleibt); Kern-Subsysteme bewusst nicht abschaltbar. Doku: Kap. 20.8.5 (neu), Changelog 6.38, Compose-Beispiele. Verifiziert per `FeatureFlagsTest` + `ApiFeatureFlagTest` (API 404 bei aus / 401 bei an; Health-`features`) — Suite 56→64, voller Lauf grün. | Setzt den letzten offenen (bewusst niedrig priorisierten) Review-Hebel um; der Core bleibt funktional vollständig, läuft je Deployment aber nur mit dem Nötigen. Vom Nutzer beauftragt. |
| E64 | HA | **Instanzübergreifender Session-Speicher** (Review-Punkt 5, Nutzer-Anforderung „jetzt 5"): Die zweite (von zwei) Voraussetzung für einen Mehrinstanz-/HA-Betrieb der Web-Schicht — ein geteilter Session-Speicher — ist erfüllt (die erste, der mehrknotenfähige Scheduler-Lock, kam mit E59). DB-gestützte Sessions: Migration `CoreSessions` (`core.sessions`), eigene `SessionsTable` (damit der `Sessions`-Alias auch ohne generischen Fallback / unter `Orm.mappedClassesOnly` auflöst), `app.php`-Session `defaults` über `SESSION_DEFAULTS` (Default `php`, Referenz-Compose `database`). Da die Plattform PostgreSQL-basiert ist, **kein Redis/zusätzliche Infrastruktur** nötig; Sessions überleben zudem Container-Recreates (kein Zwangs-Logout beim Deploy). `app_role`-Provisionierung deckt `core.sessions` mit ab. **Entscheidungen:** DB-Store statt Redis (vorhandene Infrastruktur nutzen); Default `php` in `app.php` (Einzelinstanz bleibt kohärenter Standard), aber Referenz-Compose auf `database` (HA-ready out of the box + überlebt Recreates); eigene `SessionsTable` statt generischem Fallback (robust unter mappedClassesOnly). Doku: Kap. 30.7.1 (neu), Changelog 6.37. Verifiziert per `DatabaseSessionTest` (Schreiben/Lesen/Update/Löschen, **Sichtbarkeit über zweite Instanz**, GC) — Suite 53→56, voller Lauf grün. **Hinweis:** vollständiges HA erfordert zusätzlich geteilte persistente Volumes (Sprachpaket-/Modul-Stores) + Lastverteiler = Infrastruktur, kein Core-Code. | Schließt die letzte Core-seitige Lücke für Mehrinstanz-Betrieb; Einzelinstanz bleibt Standard, HA ist nun ein reiner Betreiber-/Infra-Schritt. Vom Nutzer beauftragt. |
| E63 | Modul-Isolation | **Out-of-Process-Isolation Phase 2 — Finalisierung** (Review-Punkt 4, Nutzer-Anforderung „4 finalisieren"): Aus dem Phase-1-Vertikalschnitt (E60) wird eine **automatische, selbstverwaltete** Isolationsgrenze, in der **kein Modulcode mit Core-Rechten** läuft. (1) **Automatische DB-Rolle** je isoliertem Modul: `ModuleDbRole` legt `mod_<key>` (LOGIN/NOBYPASSRLS) mit zufälligem, **AES-256-GCM-verschlüsseltem** Passwort (`SecretCipher`, Spalten `modules.db_role`/`db_role_secret` via Migration `CoreModuleDbRole`) an, Rechte nur aufs eigene Schema + EXECUTE auf wenige Core-Hilfsfunktionen. (2) **Migrationen-als-Rolle**: `ModuleMigrationRunner::runUp($asRole)` führt isolierte Modul-DDL unter der Rolle aus (`SET LOCAL ROLE`), Tracking-Insert weiter als Superuser → schließt das Rest-Risiko „Install-Migration mit Superuser-Rechten"; danach `FORCE ROW LEVEL SECURITY` (sonst umginge der Tabelleneigentümer = Rolle die Policy). (3) **Supervisor** `ModuleHostSupervisor`: detached Spawn (`nohup env -i … &`, bereinigte Umgebung, eigene DSN), Stop (SIGTERM), `ensureAll`/`reapStale` (Worker-Selbstheilung); Lifecycle-Hooks (aktivieren→Host an, deaktivieren→Host aus, löschen→Host aus + Rolle weg). (4) **Enforcement**: isolierte Module dürfen nur Service-Contracts anbieten — Resolver/Collector/Event-Listener werden **früh abgelehnt** (vor Seiteneffekten), statt still in-process zu laufen. (5) CLI `module install --isolation` / `module isolate` / `module host`; Worker-Supervision-Hook; services-only-Fixture `isolated_module`. **Entscheidungen:** Migrationen-als-Rolle + FORCE RLS statt Superuser-Migrationen (echte Schließung von #3-Rest); Rolle besitzt ihre Tabellen (DDL-fähig) und FORCE statt nicht-Eigentum, weil Migrationen-als-Rolle DDL-Rechte braucht; Geltungsbereich bewusst eng (nur Services, Rest abgelehnt) — ehrliche Grenze ohne stille In-Process-Lücke; weitere Erweiterungspunkte über RPC + OS-User/Container + Capability-Tokens bleiben dokumentierte spätere Phasen. Doku: 23.16.2 erweitert, Changelog 6.36, MODULE_DEVELOPMENT §6. Verifiziert per `OutOfProcessIsolationTest` (Rolle/Migrations-Eigentum/FORCE-RLS, Supervisor-Spawn, __probe-Isolation, Echo-RPC, Enforcement) — Suite 50→53, voller Lauf grün. | Schließt #4 als echte, automatische Grenze: kein Modulcode mit Core-Rechten (Laufzeit **und** Migration), self-healing, ohne stille In-Process-Ausführung. Vom Nutzer beauftragt. |
| E62 | Härtung | **Drei Review-Restposten beseitigt** (Nutzer-Anforderung): (1) **Deprecation behoben** — `LocalAuthProvider` nutzt nicht mehr das seit cakephp/authentication 3.3.0 veraltete `AuthenticationService::loadIdentifier()`, sondern übergibt die Password-Identifier-Konfiguration **direkt am Form-Authenticator** (`identifier`-Option); verifiziert per neuem `LoginIntegrationTest` (gültig→302, falsch→200) + 0 Deprecation-Treffer. (2) **Backup-Restposten** — Wiederherstellungspunkte liegen jetzt auf dem **persistenten Backup-Volume** (`backups/recovery/`) statt im flüchtigen `tmp/recovery`, mit **Aufbewahrung** der jüngsten N (`RECOVERY_KEEP`, Default 10); der destruktive Restore schaltet für seine Dauer **automatisch den Wartungsmodus** (HTTP 503) über ein **Datei-Flag** (`App\Service\System\MaintenanceMode`, `tmp/maintenance.flag`), das den DB-Restore übersteht (ein DB-Setting würde mitten im Restore überschrieben) und danach wieder freigibt — war es vorher schon aktiv, bleibt es. (3) **Down-Migration-Bug** — `CoreBackupLogHarden.down()` entfernt nun die `download`-Zeilen (die es vor der Up-Migration nicht gab), bevor es das engere CHECK wieder setzt; zuvor brach die down-Migration bei vorhandenen `download`-Zeilen ab (gegen Scratch-DB nachgewiesen: alt→Constraint-Verletzung, neu→OK). Tests: `MaintenanceModeTest`, `RecoveryPointTest`, `LoginIntegrationTest` (Suite 46→50). Doku: RUNBOOK (Restore-Cutover, Recovery-Pfad), Changelog 6.35. | Schließt die drei vom Review benannten Restposten (#3-Bug, #6.2/6.3-Backup, Deprecation). Datei-Flag statt DB-Setting, weil der Restore die DB ersetzt; faithful-inverse-down statt NOT VALID, weil die Zeilen vor der Up-Migration nicht existieren konnten. Vom Nutzer beauftragt. Verifiziert. |
| E61 | Tests | **Integrationstests der kritischen Pfade** (Review-Punkt 1, Nutzer-Anforderung „implementieren"): Fünf DB-gestützte Integrationstest-Klassen gegen die echte PostgreSQL-Test-DB (kein Mock), die die zuvor nur smoke-getesteten Kernpfade absichern: (1) **Lifecycle+RLS** (`ModuleLifecycleTest`) — Install/Activate/Deactivate/Delete des Fixture-Moduls, Schema/Contracts/RLS-Policy, **echte RLS-Durchsetzung** über eine NOBYPASSRLS-Rolle (`SET LOCAL ROLE` + `set_config`), und E47-Abbruch bei scoped Ressource ohne RLS inkl. Rollback-Nachweis; (2) **Trust/Signatur** (`TrustChainTest`) — Root→Publisher-Kette mit echten Ed25519-Schlüsseln, gültig/manipuliert/Publisher-Mismatch/Widerruf (Publisher+Root)/Gültigkeitsfenster; (3) **Backup/Restore** (`BackupRoundtripTest`) — echtes pg_dump→ZIP, Prüfsummen-Verifikation, Probe-Restore in Scratch-DB, AES-256 richtig/falsch; (4) **i18n** (`LocaleResolutionTest`) — Versions-Gate exakt/same-major/Major-Mismatch; (5) **Auth/Token** (`TokenAuthTest`) — TokenService + HTTP über ApiAuthMiddleware (200/401/403). Suite 20→**46 Tests**. CI um PG-17-Client + sodium ergänzt; `TESTING.md`. **Entscheidungen:** keine CakePHP-Fixtures (Tests verwalten/raisieren eigene Zeilen, eindeutige Suffixe, tearDown-Cleanup); RLS-Enforcement per Rollenwechsel statt Zweitverbindung (eine Transaktion, sauber rückrollbar); Signaturpflicht im Lifecycle-Test per DB-Setting deaktiviert (unsigniertes Fixture). | Schließt den vom Review als **dominanten Reifegrad-Blocker** benannten Punkt: die Kernpfade sind jetzt automatisiert und CI-geprüft abgesichert, nicht nur smoke-getestet. Vom Nutzer beauftragt. Verifiziert (46/46 grün, voller Lauf + einzeln). |
| E60 | Modul-Isolation | **Out-of-Process-Modulausführung, Phase 1 (Managed Subprocess)** (Kap. 23.16, Nutzer-Anforderung „implementiere out-of-process", Modell „Managed Subprocess" gewählt): Ein als `out_of_process` markiertes Modul (`core.modules.isolation`, neue Spalte/CHECK via Migration `CoreModuleIsolation`) läuft in einem **separaten Prozess** (`bin/module-host.php`) mit **bereinigter Umgebung** (`env -i` → kein Core-`DATABASE_URL`/`BACKUP_PASSWORD` erreichbar) und **eigener, eingeschränkter DB-Rolle** (`mod_<key>`, LOGIN/NOBYPASSRLS, nur eigenes Schema, keine Core-Grants). Der Core ruft Service-Contracts transparent über einen **Unix-Domain-Socket** (JSON-Zeilen-RPC, `RemoteInvoker`) auf; `CapabilityHandle::invoke()` routet automatisch dorthin, sobald der Anbieter `out_of_process` ist (sonst unverändert in-process). Ein- und Ausgabe sind die bereits serialisierbaren Contract-Arrays (`array→array`, E29) → derselbe Aufrufpfad, RPC-/Container-fähig. **Verifiziert** per Harness (`tests/scripts/module_isolation_check.sh`): isolierter Prozess sieht weder Core-`DATABASE_URL` noch `BACKUP_PASSWORD`, kann `core.users` **nicht** lesen, sein eigenes Schema **schon**, `echo`-Contract korrekt; alle 20 PHPUnit-Tests grün. Spätere Phasen (Events/Collectors/Health über RPC, Auto-Spawn/Supervision, Capability-Tokens, OS-User/Container-Härtung, Migration der Bestandsmodule) bleiben offen. | Out-of-Process ist die einzige **echte** Isolationsgrenze (Nutzer verwarf DB-Least-Privilege als umgehbar und die Signiert/Unsigniert-Hybride als inkonsequent). Bereinigte Umgebung + eigene DB-Rolle verhindern Zugriff auf Superuser-DSN und Backup-Schlüssel by construction. `array→array`-Seam macht den Schritt ohne Contract-Änderung möglich; Managed Subprocess vor Container-pro-Modul, weil kein Docker-Socket nötig (RPC bleibt Container-tauglich). Vom Nutzer beauftragt + Modell gewählt. |
| E59 | Betrieb | **Multi-Worker-Sicherheit + Backup-DR-Schlüssel** (Nutzer-Anforderung): (a) `ScheduledTaskRunner` serialisiert jede periodische Aufgabe über einen **PostgreSQL-Advisory-Lock** (Fälligkeits-Check im Lock) → bei mehreren Worker-Instanzen kein Doppellauf (z.B. doppeltes geplantes Backup); Outbox war bereits über `SKIP LOCKED` sicher. Damit ist der Hintergrundprozess (Outbox+Scheduler) mehrinstanzfähig (`--scale worker=N`). (b) Backup-Passwort aus **Env/Secret** (`BACKUP_PASSWORD_FILE`/`BACKUP_PASSWORD`) mit Vorrang vor dem DB-Setting → **Desaster-Recovery**-tauglich (Schlüssel nicht im Dump). Doku 6.32. | Korrektheit bei Skalierung des Async-Tiers; DR-Fähigkeit verschlüsselter Backups. Verifiziert (gehaltener Lock → Tick übersprungen; Env-PW → verschlüsselt, ohne PW nicht lesbar). |
| E58 | Update | **Automatischer Wiederherstellungspunkt vor Boot-Migrationen** (Kap. 28.14.2, Nutzer-Anforderung): Der Entrypoint ruft beim Start `bin/cake core_migrate` statt `migrations migrate`. `CoreMigrateCommand` zieht **nur wenn Migrationen ausstehen** zuerst einen `RecoveryPoint` (pg_dump), migriert dann; scheitert der Wiederherstellungspunkt, wird **nicht** migriert. Ohne Schemaänderung kein unnötiger Dump. Schließt die zuvor benannte Prozesslücke (Auto-Migrate beim Boot ohne Sicherungsnetz) — der Betreiber muss nicht mehr daran denken (Flag-Alternative verworfen, weil vergessbar). `--skip-recovery` als Notfalloption. | Sicherheitsnetz für die Migration **vor** der Anwendung, ohne Betreiberdisziplin; ein Flag wäre vergessbar (Nutzer-Begründung). Verifiziert (Recovery nur bei Pending; Probe-Migration). |
| E57 | Backup | **Backup-Betriebsfunktionen** (Kap. 20.1.2, Nutzer-Anforderung): (1) **`core.backup_log` append-only** (Immutability-Trigger wie Audit-Log) → manipulationssicher; (2) **Download aus der GUI** (`/admin/backup/download/<id>`, gestreamt) + **Protokollierung** des Exports (`operation=download`); (3) **Aufbewahrung nach Alter** (`backup.retention_days`, zusätzlich zur Anzahl; Scheduler wendet beide an); (4) **Pre-Flight-Speicherprüfung** (DB-Größe + Stores vs. freier Platz/`backup.min_free_mb`) → früher Abbruch; (5) **Mail-Alarm** bei Fehlschlag (`backup.alert_email` via Core-`MailService`, inkl. Pre-Flight). | Manipulationssicher, nachvollziehbar, betreibertauglich (Off-Site-Export, Retention, früher Abbruch, aktive Alarmierung). Vom Nutzer beauftragt. |
| E56 | Backup | **Backup-Härtung** (Kap. 20.1.2, Nutzer-Anforderung): (1) **Zeitstempel im Dateinamen** (`<YYYYMMDD-HHMMSS>_<id>.zip`) → gezielt identifizierbar; (2) **Verifikation vor Abschluss** — Integrität (Prüfsummen) immer + optionaler Probe-Restore (`backup.verify_on_create`, Default an); bei Fehler → `failed`/verworfen; (3) **Operationsprotokoll** `core.backup_log` (Backups **und** Restores: Zeit/Herkunft/Benutzer/Ergebnis) + GUI-Liste; (4) **AES-256-Verschlüsselung** des Archivinhalts (`backup.password`, Secret) für Segregation of Duty — ohne Passwort nichts lesbar, Restore `--password`. Plus Health-Subsystem `backup` (degraded bei fehlendem/fehlgeschlagenem/überfälligem Backup bei aktivem Scheduler). | Produktionsreife: identifizierbar, nachweislich gültig, nachvollziehbar, vertraulich (SoD). Vom Nutzer beauftragt. |
| E55 | Backup | **Backup als ZIP + konfigurierbarer Pfad + Scheduler** (Kap. 20.1.2, Nutzer-Anforderung): Jede Sicherung ist **ein** `<id>.zip` (DB-Dump + Stores-Tar + Manifest, „alle Daten zusammen"). Ablageort **konfigurierbar** (`backup.path`, CLI `--path`, GUI-Feld) mit `BackupPath`-Normalisierung/-Validierung für **Linux- und Windows-Pfade** (im Container nur gemountete Linux-Pfade nutzbar — klare Fehlermeldung sonst). **Scheduler** als Core-`ScheduledTaskInterface` (`BackupScheduledTask`) über den Worker: `backup.schedule.enabled/interval_hours` + `backup.retention` (Prune). Restore aus beliebigem Archiv (`restore --from <zip>`). `RUNBOOK.md` (Update-/Restore-Strategie inkl. Versions-Paarung). | Ein File pro Backup vereinfacht Handling/Off-Site; konfigurierbarer Pfad + Scheduler decken Betreiberbedarf; Windows/Linux-Pfade syntaktisch unterstützt. Vom Nutzer beauftragt. |
| E54 | Bugfix | **sprintf-Platzhalter in i18n repariert** (bei C4 entdeckt): Die in i18n-1/i18n-5 eingeführten Custom-Loader bauten `Package` mit dem **ICU**-Formatter (`'default'`), der `%s` ignoriert → **alle** parametrisierten Core-/Modul-Meldungen zeigten literal `%s` (`flash.config.saved`, `flash.module.failed`, …). Fix: `EnglishFallbackLoader` setzt `Package::setFormatter('sprintf')`, `StoreLocaleLoader` nutzt `Package('sprintf', …)`. | App-weiter Anzeigefehler; ohne Fix wären alle dynamischen Flash-/Mail-Texte defekt. Beim Backup-`test-restore`-Flash aufgefallen, generell behoben + verifiziert. |
| E53 | Backup | **Daten-Backup/-Restore als Core-Systemfunktion** (Kap. 20.1.2; Doku in 6.29/Entscheidung 181 zweistufig neu gefasst → **spec-konform**, keine Abweichung: Infrastruktur=Systemadmin 20.1.1, Daten=Core 20.1.2): `BackupService` sichert die gesamte DB (`pg_dump -Fc`) + persistente Datei-Stores (`language-store`/`marketplace-data`/`modules`) **unter dem Lifecycle-Advisory-Lock** (DB↔Storage-Konsistenz), mit SHA-256 je Artefakt. CLI `backup create/list/verify/test-restore/restore/delete` + GUI `/admin/backup`. **Prüfbarkeit:** `test-restore` spielt den Dump in eine Scratch-DB ein (Sanity-Check ohne Produktionseingriff); `restore --yes` ist destruktiv und CLI-only. Volume `core_backups`; `BACKUP.md`. | Nur Core-koordiniert sind Konsistenz **und** Wiederherstellbarkeit garantierbar/prüfbar (Nutzer-Begründung). Off-Site/Scheduling bleibt Betreiber. Vom Nutzer beauftragt. |
| E52 | Modul-Andock | **Periodische Modul-Aufgaben + Scope-Klärungen** (Merkliste C, mit Nutzer): `ScheduledTaskInterface` + `ScheduledTaskRunner` (Collector `core.collector.scheduled`) — der Core-Worker tickt registrierte Modul-Aufgaben im Intervall, fehlerisoliert, mit Heartbeat (→ Health). Damit docken Ticketing-Jobs (`fetch_mails`/`check_escalations`) an, ohne Fachlogik im Core. `MODULE_DEVELOPMENT.md` dokumentiert Andock-Punkte (C2), Integrations-Extension-Anforderungen (C5, Konzept) und Modul-RLS-Doku (C6). **C1** (Sandbox) bewusst zurückgestellt: In-Process für signierten/kuratierten Bestand akzeptiert. **C7** `trust rotate` ergänzt. | Stellt den fehlenden Periodik-Andock-Punkt bereit, ohne Fachlichkeit in den Core zu ziehen; Sandbox erst bei nicht-vertrauenswürdigem Drittcode nötig. Vom Nutzer so entschieden. |
| E51 | GUI | **Grafische Modul-Abhängigkeitsdarstellung** (Kap. 23.13.1, Merkliste B): serverseitig berechnetes SVG `/admin/modules/graph` — Ebenen per Longest-Path-Relaxation (azyklisch), Knoten als Statusfarb-Boxen, Kanten Modul → Abhängigkeit mit Pfeilmarker; ohne Client-JS (Layouts laden keins). Slot-/Binding-Diagramm (24.15.1) bewusst späterer Ausbau (Registry listet Bindings bereits). | Visualisiert Abhängigkeiten statt reiner Liste, ohne JS-Abhängigkeit. Selbst entschieden; ggf. korrigierbar. |
| E50 | Betrieb | **Strukturierte Logs erzwungen** (Kap. 20.2.3, Merkliste B): `App\Log\ContextJsonFormatter` ersetzt den Standard-`JsonFormatter` (der `$context` komplett verwarf) und mischt den prozessweiten `App\Log\LogContext` (`correlation_id`/`request_id`/`component`, optional `module`) sowie Aufruf-Kontext in **jede** Logzeile; `LogContextMiddleware` (outermost, damit auch ErrorHandler-Logs ihn tragen) befüllt ihn je Request, der Outbox-Worker setzt `component=worker`. | Verlässliche Korrelierbarkeit ohne Aufruferdisziplin; SIEM-tauglich. Selbst entschieden; ggf. korrigierbar. |
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

# Fertura — Autonomer Testplan (Core · Ticketing · Knowledgebase · Connector)

Abgeleitet aus den Anforderungsdokumenten der vier Komponenten. Lebendes Dokument:
der autonome Loop arbeitet die Fälle ab, fixt Fehler und testet erneut.

## Venue & Methode
- Laufende App: **http://localhost:8080** (Stack via WSL/docker, alle 3 Module aktiv).
- Test-Admin: `tester` / `Fertura!2026` (Volladmin, alle Areas).
- **Browser-Tests (UI-Klickpfade):** brauchen die **Chrome-Extension** (aktuell NICHT
  verbunden → solche Fälle stehen auf `BLOCKED`, bis die Extension angebunden ist).
- **Autonom testbar jetzt:** HTTP (authentifizierte Requests + Formular-POSTs),
  API (Bearer-Token), CLI (`bin/cake`), DB-Verifikation (psql), Event-Durchstiche
  über die Outbox. Wo nötig werden Testdaten/Simulationen erzeugt.

## Status-Legende
`TODO` offen · `PASS` bestanden · `FAIL` Fehler gefunden · `FIXED` Fehler behoben +
nachgetestet · `BLOCKED` braucht Browser-Extension/externe Voraussetzung · `N/A`.

---

## Core (CORE-001 … CORE-058)
| ID | Funktion | Methode | Status | Notiz |
|---|---|---|---|---|
| CORE-001 | Login (gültig/ungültig) | HTTP | PASS | Login 302→/admin verifiziert |
| CORE-002 | Passwort-Policy (min. Länge, Hash) | HTTP/CLI | TODO | |
| CORE-003 | Session + Logout + Timeout | HTTP | TODO | |
| CORE-004 | MFA (TOTP) Einrichtung | Browser | BLOCKED | UI |
| CORE-005 | MFA Enforcement + Recovery | Browser | BLOCKED | UI |
| CORE-006 | Passkey/WebAuthn | Browser | BLOCKED | UI |
| CORE-007 | SSO OIDC Konfiguration | Browser/CLI | TODO | |
| CORE-008 | SSO SAML Konfiguration | Browser/CLI | TODO | |
| CORE-009 | SSO Login + JIT-Provisioning | Browser | BLOCKED | UI/IdP |
| CORE-010 | Einladung (Token, Ablauf) | HTTP/API | TODO | |
| CORE-011 | Passwort-Reset (Token, single-use) | HTTP/API | TODO | |
| CORE-012 | Login-Rate-Limit | HTTP | TODO | |
| CORE-013 | User aktiv/inaktiv | HTTP/API | TODO | |
| CORE-014 | User-Anonymisierung (DSGVO) | HTTP/API | TODO | |
| CORE-015 | Admin-Areas Zuweisung (scoped) | HTTP | TODO | |
| CORE-016 | Core-Admin-Areas verfügbar | HTTP | TODO | |
| CORE-017 | User anlegen (unique) | HTTP/API | TODO | |
| CORE-018 | User bearbeiten (audit) | HTTP/API | TODO | |
| CORE-019 | User Massenaktion enable/disable | HTTP | TODO | |
| CORE-020 | Gruppe anlegen (unique) | HTTP/API | TODO | |
| CORE-021 | Gruppe bearbeiten (audit) | HTTP/API | TODO | |
| CORE-022 | Gruppe aktiv/inaktiv | HTTP/API | TODO | |
| CORE-023 | User-Mehrfach-Mitgliedschaft | HTTP/API | TODO | |
| CORE-024 | Modul-Liste mit Status/Version | HTTP | PASS | Modulliste rendert, i18n-Status |
| CORE-025 | Modul installieren (Signatur/Deps/Migration) | CLI | PASS | E177/Assembly verifiziert |
| CORE-026 | Modul aktivieren (Lizenz/Registry) | CLI | PASS | Assembly verifiziert |
| CORE-027 | Modul deaktivieren (Degradation) | CLI | TODO | |
| CORE-028 | Modul aktualisieren | CLI | TODO | |
| CORE-029 | Modul löschen (Deps/Cleanup) | CLI | TODO | |
| CORE-030 | Abhängigkeitsgraph | Browser | BLOCKED | UI |
| CORE-031 | Marketplace durchsuchen | HTTP | TODO | |
| CORE-032 | Modul-Lizenzierung (Key/Scope/Ablauf) | CLI | PASS | Dev-Lizenz-Flow verifiziert |
| CORE-033 | Marketplace-Sync | CLI/API | TODO | |
| CORE-034 | Contract-Registry ansehen | HTTP | TODO | |
| CORE-035 | Resolver-Aktivierung/Default | API | TODO | |
| CORE-036 | Event/Outbox + Dead-Letter | CLI/DB | PASS | Outbox-Durchstich (Connector) |
| CORE-037 | API-Token erstellen | HTTP/API | TODO | |
| CORE-038 | API-Token-Auth | API | TODO | |
| CORE-039 | API-Token widerrufen | HTTP/API | TODO | |
| CORE-040 | Audit-Logging (who/what/when) | HTTP/DB | TODO | |
| CORE-041 | Audit-Log durchsuchen | HTTP | TODO | |
| CORE-042 | Audit-Immutabilität (append-only) | DB | PASS | Trigger blockt DELETE (bekannt) |
| CORE-043 | Core-Settings-GUI | HTTP | TODO | |
| CORE-044 | Secrets-Verschlüsselung (AES-GCM) | API | TODO | |
| CORE-045 | Backup erstellen | CLI | TODO | |
| CORE-046 | Backup wiederherstellen | CLI | TODO | |
| CORE-047 | Backup-Verschlüsselung | CLI | TODO | |
| CORE-048 | Health-Endpoint | API | TODO | |
| CORE-049 | Admin-Status-Dashboard | HTTP | PASS | Dashboard rendert |
| CORE-050 | Sprachpaket-Import | HTTP | TODO | |
| CORE-051 | Sprachumschaltung (Fallback EN) | HTTP | TODO | |
| CORE-052 | Sprachpaket-Verwaltung | HTTP | TODO | |
| CORE-053 | Core-Update-Erkennung | CLI | TODO | |
| CORE-054 | Core-Update-Prozess | CLI | TODO | |
| CORE-055 | RLS / Multi-Tenancy | DB/API | PASS | Connector-Tenant-Isolation verifiziert |
| CORE-056 | Reset-Token-Lifecycle | HTTP/API | TODO | |
| CORE-057 | Invite-Token-Lifecycle | HTTP/API | TODO | |
| CORE-058 | Modul-Signaturprüfung | CLI | PASS | PackageVerifier/E177 |

## Ticketing (TKT-001 … TKT-066)
| ID | Funktion | Methode | Status | Notiz |
|---|---|---|---|---|
| TKT-001 | Ticket via E-Mail anlegen | Sim/CLI | TODO | IMAP-Sim nötig |
| TKT-002 | Ticket via API anlegen | API | TODO | |
| TKT-003 | Ticket manuell anlegen | HTTP | TODO | |
| TKT-004 | Ticket-Liste (Filter/Export) | HTTP | TODO | |
| TKT-005 | Ticket-Detail | HTTP | TODO | |
| TKT-006 | Status-Wechsel (Workflow) | HTTP | TODO | |
| TKT-007 | Schließen Erfolg (+Grund) | HTTP | TODO | |
| TKT-008 | Schließen Fehlschlag (+Grund) | HTTP | TODO | |
| TKT-009 | Ticket pausieren (SLA-Pause) | HTTP | TODO | |
| TKT-010 | Wiedereröffnen bei Mail | Sim | TODO | |
| TKT-011 | Zuweisen an User (Event) | HTTP | TODO | |
| TKT-012 | Zuweisung entfernen | HTTP | TODO | |
| TKT-013 | Auto-Zuweisung (Regel) | Sim/API | TODO | |
| TKT-014 | Interne Notiz | HTTP | TODO | |
| TKT-015 | Öffentliche Antwort (+Mail) | HTTP | TODO | |
| TKT-016 | Gast-Kommentar | HTTP | TODO | |
| TKT-017 | Priorität setzen (SLA-Neuberechnung) | HTTP | TODO | |
| TKT-018 | Prioritätsregeln anwenden | Sim | TODO | |
| TKT-019 | Tickettyp setzen | HTTP | TODO | |
| TKT-020 | Abschlussgrund wählen | HTTP | TODO | |
| TKT-021 | Freies Tag anlegen | HTTP | TODO | |
| TKT-022 | Vordefiniertes Tag nutzen | HTTP | TODO | |
| TKT-023 | Freies Feld definieren | HTTP | TODO | |
| TKT-024 | Feldwert setzen | HTTP | TODO | |
| TKT-025 | Pflichtfeld-Regel | HTTP | TODO | |
| TKT-026 | Textbaustein anlegen | HTTP | TODO | |
| TKT-027 | Textbaustein in Antwort | HTTP | TODO | |
| TKT-028 | Makro anlegen | HTTP | TODO | |
| TKT-029 | Makro anwenden (atomar) | HTTP | TODO | |
| TKT-030 | Checklisten-Vorlage | HTTP | TODO | |
| TKT-031 | Checkliste anhängen | HTTP | TODO | |
| TKT-032 | Checklistenpunkt abhaken | HTTP | TODO | |
| TKT-033 | Queue anlegen | HTTP | TODO | |
| TKT-034 | Gruppen-Zugriff auf Queue | HTTP | TODO | |
| TKT-035 | Queue-Gruppe anlegen | HTTP | TODO | |
| TKT-036 | Mailbox konfigurieren (SecretCipher) | HTTP | TODO | |
| TKT-037 | Inbound-Mail abrufen | Sim/CLI | TODO | IMAP-Sim |
| TKT-038 | Outbound-Mail-Queue | Sim/CLI | TODO | Mailpit |
| TKT-039 | Blacklist-Eintrag | HTTP | TODO | |
| TKT-040 | Blacklist-Block inbound | Sim | TODO | |
| TKT-041 | SLA-Regel konfigurieren | HTTP | TODO | |
| TKT-042 | SLA-Fälligkeiten berechnen | API/DB | TODO | |
| TKT-043 | Eskalations-Check (Event) | CLI | TODO | |
| TKT-044 | Eskalations-Warnung (Event) | CLI | TODO | |
| TKT-045 | SLA Pause/Resume | HTTP | TODO | |
| TKT-046 | Event-Benachrichtigung | DB/Sim | TODO | |
| TKT-047 | Digest-Modus | CLI | TODO | |
| TKT-048 | Contributor anonymisieren | API | TODO | |
| TKT-049 | Soft-Delete (Trash, Event) | HTTP | PASS | Event-Durchstich (Connector) |
| TKT-050 | Restore aus Trash (Event) | HTTP | PASS | Event-Durchstich |
| TKT-051 | Hard-Delete (Event) | HTTP | PASS | Event-Durchstich |
| TKT-052 | Tickets zusammenführen (Event) | HTTP | PASS | Event-Durchstich |
| TKT-053 | Follow-up setzen (Event) | HTTP | TODO | |
| TKT-054 | @mention (Event) | HTTP | TODO | |
| TKT-055 | Mandanten-Isolation | API/DB | TODO | |
| TKT-056 | API: Ticketstatus abfragen | API | TODO | |
| TKT-057 | API: Ticket-Lookup | API | TODO | |
| TKT-058 | Gast-Portal Login | HTTP | TODO | |
| TKT-059 | Gast-Portal Ticketdetail | HTTP | TODO | |
| TKT-060 | Gast-Portal Kommentar | HTTP | TODO | |
| TKT-061 | Anhang-Richtlinie konfigurieren | HTTP | TODO | |
| TKT-062 | Anhang hochladen (MIME/Größe) | HTTP | TODO | |
| TKT-063 | Anhang an Agent ausliefern | HTTP | TODO | |
| TKT-064 | Anhang an Gast ausliefern | HTTP | TODO | |
| TKT-065 | Lesebestätigung erfassen | HTTP | TODO | |
| TKT-066 | Lesebestätigung Gast-Sicht | HTTP | TODO | |

## Knowledgebase (KB-001 … KB-050)
| ID | Funktion | Methode | Status | Notiz |
|---|---|---|---|---|
| KB-001 | Artikel anlegen (Entwurf) | HTTP | TODO | |
| KB-002 | Veröffentlichen (Version, Changelog) | HTTP | TODO | |
| KB-003 | Veröffentlichten Artikel bearbeiten | HTTP | TODO | |
| KB-004 | Bearbeiteten Entwurf neu publizieren | HTTP | TODO | |
| KB-005 | Rollback auf frühere Version | HTTP | TODO | |
| KB-006 | Artikel archivieren (Event) | HTTP | PASS | Event-Durchstich (Connector) |
| KB-007 | Artikel wiederherstellen (Event) | HTTP | PASS | Event-Durchstich |
| KB-008 | Soft-Delete (Trash) | HTTP | TODO | |
| KB-009 | Hard-Delete (DSGVO) | HTTP | TODO | |
| KB-010 | Review anfordern | HTTP | TODO | |
| KB-011 | Freigeben & publizieren | HTTP | TODO | |
| KB-012 | Review ablehnen | HTTP | TODO | |
| KB-013 | Vier-Augen-Prinzip | HTTP | TODO | |
| KB-014 | Volltextsuche | API/HTTP | TODO | |
| KB-015 | Facettensuche | HTTP | TODO | |
| KB-016 | Instant-Suche (Suggest) | HTTP | TODO | |
| KB-017 | Phrasensuche | HTTP | TODO | |
| KB-018 | Ausschlusssuche | HTTP | TODO | |
| KB-019 | Mehrsprachige Suche (Fallback) | HTTP | TODO | |
| KB-020 | Suche Berechtigungsfilter | HTTP | TODO | |
| KB-021 | Synonym-Wörterbuch | HTTP/CLI | TODO | |
| KB-022 | Stale-Translation-Erkennung | HTTP | TODO | |
| KB-023 | Content-Gap-Analyse | HTTP | TODO | |
| KB-024 | Best Bets (Kuratierung) | HTTP | TODO | |
| KB-025 | Review-Intervall fällig (Event) | CLI | TODO | |
| KB-026 | Ablauf valid_until (Event) | CLI | TODO | |
| KB-027 | Alt-Text-Pflicht | HTTP | TODO | |
| KB-028 | Sichtbarkeit intern | HTTP | TODO | |
| KB-029 | Sichtbarkeit Mitglieder | HTTP | TODO | |
| KB-030 | Sichtbarkeit öffentlich (API) | API | TODO | |
| KB-031 | Space-Sichtbarkeits-Ceiling | HTTP | TODO | |
| KB-032 | Owner-Wechsel | HTTP | TODO | |
| KB-033 | BREAD-Enforcement | HTTP | TODO | |
| KB-034 | Zugriffsanfrage | HTTP | TODO | |
| KB-035 | Hilfreich-Bewertung | HTTP | TODO | |
| KB-036 | Hilfreich-Schwellwert | HTTP | TODO | |
| KB-037 | Veraltet-Meldung | HTTP | TODO | |
| KB-038 | Feedback-Auflösung | HTTP | TODO | |
| KB-039 | Space folgen | HTTP | TODO | |
| KB-040 | Kategorie folgen | HTTP | TODO | |
| KB-041 | Artikel folgen | HTTP | TODO | |
| KB-042 | Abo-Dormanz bei Rechteverlust | HTTP | TODO | |
| KB-043 | Snippet anlegen | HTTP | TODO | |
| KB-044 | Snippet-Transklusion | HTTP | TODO | |
| KB-045 | Snippet-Deaktivierung | HTTP | TODO | |
| KB-046 | Draft-Preview-Token | HTTP | TODO | |
| KB-047 | Token-Ablauf/Widerruf | HTTP | TODO | |
| KB-048 | Token-Audit | HTTP/DB | TODO | |
| KB-049 | Embargo/geplant publizieren | CLI | TODO | |
| KB-050 | Embargo-Ablauf | CLI | TODO | |

## Connector (CON-001 … CON-030)
| ID | Funktion | Methode | Status | Notiz |
|---|---|---|---|---|
| CON-001 | Artikel↔Ticket verknüpfen | HTTP/API | TODO | |
| CON-002 | Idempotentes Re-Link | API | TODO | |
| CON-003 | Verknüpfung lösen | HTTP/API | TODO | |
| CON-004 | Doppelte aktive Links verhindern | DB/API | PASS | partial-unique (Harness) |
| CON-005 | Vorschläge im Ticket-Panel | HTTP | TODO | |
| CON-006 | Verlinkte Artikel im Panel | HTTP | TODO | |
| CON-007 | Verlinkte-Tickets-Anzahl im KB-Panel | HTTP | TODO | |
| CON-008 | Link-Audit-Trail | DB | PASS | (Harness, indirekt) |
| CON-009 | Artikel archiviert → orphan | Event | PASS | Harness |
| CON-010 | Artikel restored → reaktiviert | Event | PASS | Harness |
| CON-011 | KB nicht verfügbar (search) | Sim | TODO | |
| CON-012 | KB nicht verfügbar (get_article) | Sim | TODO | |
| CON-013 | Ticket soft_deleted → orphan | Event | PASS | Harness |
| CON-014 | Ticket restored → reaktiviert | Event | PASS | Harness |
| CON-015 | Ticket hard_deleted → purge | Event | PASS | Harness |
| CON-016 | Ticket merged → re-home | Event | PASS | Harness |
| CON-017 | Merge-Kollision dedupe | Event | TODO | |
| CON-018 | Connector deaktiviert (neutral) | Sim | TODO | |
| CON-019 | Ticketing-Collector fehlt | Sim | TODO | |
| CON-020 | kb.search mit User-Kontext | HTTP | TODO | |
| CON-021 | kb.get_article mit User-Kontext | HTTP | TODO | |
| CON-022 | Link-Berechtigung | API | TODO | |
| CON-023 | Suggest-Berechtigung | API | TODO | |
| CON-024 | Anonymisierung nullt linked_by | API/DB | TODO | |
| CON-025 | Keine Inhaltsduplikation | DB | PASS | Schema (reference-only) |
| CON-026 | Keine Ticketdaten an KB | DB | PASS | Schema |
| CON-027 | Tenant-Isolation der Links | DB/API | PASS | Harness |
| CON-028 | Reference-only FKs | DB | PASS | Schema (kein FK) |
| CON-029 | Suggestion-Feedback-Aggregation | DB | TODO | |
| CON-030 | Aktivierung scheitert ohne Mains | Sim | PASS | integration_relations |

---

## Fortschritt
- **Iteration 2 — Automatisierte Suiten (ausführbare Anforderungen) GRÜN:**
  Core **428** Tests · Ticketing **278** (2 skipped) · Knowledgebase **182** ·
  Connector **43** · Integrations-Harness **4** = **~935 Tests, 0 Fehler**.
  Diese Suiten decken den funktionalen Kern aller vier Komponenten ab (Controller,
  Services, Events, RLS, Lifecycle, Permissions). Damit gelten die jeweils per Suite
  abgedeckten CORE-/TKT-/KB-/CON-Fälle als **PASS** (automatisiert verifiziert).
- **2 Fehler gefunden + behoben + nachgetestet** (Regressionen aus dem
  Sidebar→Top-Menü-Umbau): `aria-current` am aktiven Top-Menü (A11y) + Modul-Shell-
  Test auf die Top-Menü-Shell umgestellt. Commit `af26568`.
- **Verbleibende Lücken (nicht von Suiten/HTTP abgedeckt):** reine UI-Klick-/UX-
  Pfade → **BLOCKED** bis Chrome-Extension verbunden; sowie Flows mit externer
  Infrastruktur (Live-IMAP, SSO/SAML-IdP, Backup-Restore-Roundtrip) → bei Bedarf
  mit Simulation/Mailpit gezielt nachziehen.

- **Iteration 1:** Plan erstellt. **Page-Load-Smoke (authentifiziert) GRÜN** —
  alle 27 Core-Admin-Seiten, 20 Ticketing-Admin-Seiten (`/m/ticketing/admin/*`)
  und 4 Knowledgebase-Admin-Seiten liefern **200, 0 Warnungen**. Render-Ebene
  damit komplett ohne 500er/Warnungen. Bereits per Harness/Vorarbeit verifizierte
  Fälle sind als PASS markiert.
- **Offen / nächste Iterationen:** funktionale Durchstiche der TODO-Fälle per
  HTTP-Formular-POST / API / CLI / DB (Ticket anlegen→Status→Antwort; Artikel
  anlegen→publizieren→suchen; Link Artikel↔Ticket; SLA/Eskalation per Task;
  Mailbox-Sim via Mailpit; API-Token; Audit; Backup-CLI; Anonymisierung).
- **BLOCKED:** reine UI-Klickpfade brauchen die **Chrome-Extension** (nicht
  verbunden). Sobald verbunden, werden die BLOCKED-Fälle im Browser nachgezogen.
- **Venue-Hinweis:** Die WSL-VM fährt im Leerlauf herunter → jede Test-Iteration
  wärmt `core` zuerst auf 200, bevor sie testet (gegen 502-Kaltstart).

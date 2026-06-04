# Alignment-Delta: Ticketing-Modul v6.1 → Plattform v6.25

Stand: 03. Juni 2026
Zweck: Priorisierte Liste der Änderungen, die nötig sind, um das
Ticketing-Modul-Dokument (v6.1) an die überarbeitete Plattform v6.25
anzugleichen. Jeder Punkt nennt Modulstelle, Plattform-Referenz, Problem
und einen konkreten Änderungsvorschlag. Die Einarbeitung erfolgt nach
Freigabe Punkt für Punkt (analog zum Plattform-Review).

Quellen:
- Ticketing-Modul: `Modul_Ticketing_Anforderungsdokument_v6_1.md`
- Plattform: `Plattform_Anforderungsdokument_v6_25.md`

Priorität:
- **P1 — Muss:** Modul widerspricht der Plattform oder dupliziert sie; ohne Fix inkonsistent.
- **P2 — Soll:** Architektonische Angleichung mit hohem Nutzen (Kopplung/Skalierung).
- **P3 — Konsistenz:** Klarstellungen, Nicht-Ziele, kleinere Verweise.

---

## P1 — Muss (Widerspruch / Duplikat zur Plattform)

### A1 — Observability: Kapitel 20.2 widerspricht der Plattform
- **Modulstelle:** 20.2 („Das System stellt keine eigenen Monitoring-Endpunkte bereit"; /health als „spätere Version").
- **Plattform:** 20.2.1 Health-Endpoint `/health` (Muss), 20.2.2 Health-Collector, 20.2.4 Admin-Statusfläche (Entscheidung 169).
- **Problem:** Direkter Widerspruch. Die Plattform schreibt /health + Health-Collector jetzt vor.
- **Vorschlag:** 20.2 umschreiben: Das Ticketing-Modul stellt **keine eigenen** Monitoring-Endpunkte, sondern liefert seine Checks (Mailbox-Erreichbarkeit IMAP/SMTP, `email_queue`-Fehlerstand, Cron-Aktualität von `fetch_mails`/`check_escalations`/`process_email_queue`) als **Health-Collector-Beiträge** an den Plattform-Health-Endpoint (Plattform 20.2.1/20.2.2). Die Betreiber-Überwachungstabelle bleibt als Empfehlung. „/health = spätere Version" entfernen.

### A2 — Scoped-Admin: binäres Admin-Modell vs. Administrationsbereiche
- **Modulstelle:** 2.1 (Administrator = „Voller Zugriff auf alle Admin-Funktionen"), 2.5, 12.9 (Admin-Bereiche).
- **Plattform:** 27.3.1 Core-Administrationsbereiche, Volladministrator/delegierter Administrator (Entscheidung 170).
- **Problem:** Modul kennt nur „Administrator = alles". Delegierte Admins (z. B. nur Benutzerverwaltung, ohne Mailbox-/Modulzugriff) sind nicht abbildbar.
- **Vorschlag:** In 2.1 die Administrator-Beschreibung auf „Volladministrator (alle Bereiche) bzw. delegierter Administrator (zugewiesene Teilmenge)" umstellen. In 12.9 die Admin-Bereiche den Core-Administrationsbereichen zuordnen (z. B. Benutzerverwaltung/Einladungen → Bereich „Benutzer- und Gruppenverwaltung"; Mailbox-/Queue-/Systemeinstellungen → „Core-Konfiguration" bzw. modul-eigener Bereich). Klarstellen, dass die Sichtbarkeit eines Admin-Bereichs vom zugewiesenen Administrationsbereich abhängt.

### A3 — DSGVO-Anonymisierung dupliziert statt referenziert
- **Modulstelle:** 17.2 (definiert Benutzer-Anonymisierung selbst).
- **Plattform:** 27.15.3 Anonymisierung (Recht auf Löschung), Entscheidung 160.
- **Problem:** Inhaltlich deckungsgleich, aber doppelt definiert → Divergenzrisiko.
- **Vorschlag:** 17.2 für **Benutzer-Accounts** auf die Plattform verweisen (irreversible Anonymisierung gemäß Plattform 27.15.3), statt das Verfahren erneut zu beschreiben. Die ticketing-**spezifische** Löschung von Ticketdaten (Hard-Delete von Tickets/Einträgen/Anhängen, DSGVO-Bereich, `purge_tickets`) bleibt im Modul (moduldefinierte Delete-Semantik gemäß Plattform 23.12.1).

---

## P2 — Soll (architektonische Angleichung)

### A4 — Benachrichtigungen event-getrieben über Plattform-Outbox
- **Modulstelle:** Kapitel 8 (Benachrichtigungen), `dashboard_events`, diverse „sendet Benachrichtigung"-Stellen (5.x, 7.x).
- **Plattform:** 26.9.2 transaktionaler Outbox, asynchrone Events, Entscheidung 168.
- **Problem:** Benachrichtigungen sind imperativ in jeder Funktion verdrahtet; kein zentrales Domänen-Event-Modell.
- **Vorschlag:** Ticketing-Domänenaktionen emittieren **Plattform-Events** (z. B. `ticket.created`, `ticket.assigned`, `ticket.status_changed`, `ticket.reply_received`, `ticket.escalated`, `ticket.followup_due`). Benachrichtigungen, `dashboard_events` und Digest werden als **Listener** über den transaktionalen Outbox abgeleitet (mindestens-einmal, idempotent). Reduziert Kopplung und vereinheitlicht die drei heute getrennten Auslöserpfade. Kapitel 8 entsprechend als „Listener auf Ticket-Events" umformulieren.

### A5 — `email_queue` mit Plattform-Outbox reconcilen
- **Modulstelle:** 3.11 (`email_queue`, Retry, Fehlerbenachrichtigung), CLI `process_email_queue`.
- **Plattform:** 26.9.2 Outbox/Worker, Dead-Letter; 20.2 Dead-Letter-Sichtbarkeit.
- **Problem:** `email_queue` ist faktisch ein zweiter Outbox neben dem Plattform-Outbox.
- **Vorschlag:** Bewusste Entscheidung dokumentieren: `email_queue` als **Spezialisierung** des Plattform-Jobsystems (SMTP-spezifische Zustell-/Retry-Semantik), nicht als Parallelwelt. Retry-/Dead-Letter-Begriffe an Plattform-Outbox angleichen; endgültig fehlgeschlagene E-Mails als Dead-Letter im Plattform-Health/Statusfläche sichtbar machen (statt nur Admin-Mail).

### A6 — Rechte-Granularität: Plattform-BREAD + Zusatzaktionen nutzen
- **Modulstelle:** 2.2–2.6 (Zugriff = Queue über Benutzergruppen; faktisch „Queue-Zugriff = volle Agent-Rechte").
- **Plattform:** 25.3/25.7 BREAD + Zusatzaktionen; Beispiel 25.15.1 (assign, change_status, reply, merge, hard_delete, restore).
- **Problem:** Modul kollabiert Rechte auf „Queue-Mitgliedschaft = alle Ticketaktionen". Read-only-Betrachter oder gestufte Agentenrollen (z. B. ohne merge/close) sind nicht ausdrückbar.
- **Vorschlag (phasierbar):** Ticketing-Ressourcen (`queue`, `ticket`, gespeicherte Filter, Reporting) explizit als BREAD-Ressourcen modellieren und die Zusatzaktionen (assign, change_status, reply, merge, hard_delete, restore) gruppenbezogen vergeben. Die heutige „Queue-Zugriff = alles"-Regel als Default/„Voll-Agent"-Profil beibehalten, aber feinere Stufen ermöglichen. Hinweis: größerer Eingriff — als eigene Ausbaustufe vorsehen.

---

## P3 — Konsistenz / Klarstellung

### A7 — SSO-Nicht-Ziel überholt
- **Modulstelle:** 1.3.3 („Kein LDAP/SSO"), 19.3 (LDAP/SSO als spätere Version).
- **Plattform:** 27.2.2 pluggable Authentifizierung (Resolver-Slot), Entscheidung 171.
- **Vorschlag:** Umformulieren: Authentifizierung (inkl. OIDC/SAML-SSO) ist eine **Plattformfähigkeit** (austauschbarer Auth-Resolver, Default lokal). Das Ticketing-Modul erbt sie und definiert sie nicht selbst; SSO daher aus Modul-Nicht-Zielen/„spätere Version" entfernen bzw. als „durch Plattform bereitgestellt" markieren.

### A8 — API-Auth: Verhältnis zum Plattform-Modell klären
- **Modulstelle:** 3.14.2 (queue-gebundener `ApiToken`, eigenes Rate-Limit, Gast-Level-Sichtbarkeit).
- **Plattform:** 27.16.3 benutzergebundene Token, Live-BREAD-Prüfung (Entscheidung 162).
- **Problem:** Zwei API-Auth-Schemata koexistieren.
- **Vorschlag:** Explizit dokumentieren, dass die externe Ticketing-REST-API bewusst ein **eigenes, gast-äquivalentes** Token-Schema ist (Queue-gebunden, keine internen Daten) und **kein** vollwertiger Plattform-API-Zugang. Abgrenzung zu den benutzergebundenen Plattform-Token (für interne API-Nutzung) klarstellen, damit die Koexistenz beabsichtigt und nicht widersprüchlich ist.

### A9 — 2FA-Nicht-Ziel als Auth-/Plattformthema einordnen
- **Modulstelle:** 19.6 (2FA als spätere Version).
- **Plattform:** Auth-Resolver (27.2.2).
- **Vorschlag:** 2FA als Bestandteil der Authentifizierung (Plattform-Auth-Resolver) kennzeichnen, nicht als Ticketing-Feature.

### A10 — Forward-Looking: Service-Contract für Integrationen
- **Modulstelle:** —
- **Plattform:** 26.3.4 Service-Contract / 29 (Beispiel Ticketing ↔ Wissensdatenbank).
- **Vorschlag (optional/zukunftsgerichtet):** Vorsehen, dass das Ticketing-Main-Modul Contracts/UI-Erweiterungspunkte für Integrations-Extension-Module bereitstellt (z. B. Einbringen strukturierter Wissensartikel-Treffer in die Ticketansicht). Noch nicht v1-Pflicht, aber Architektur-Hook benennen.

---

## Nicht plattformgetrieben, aber architektonisch empfohlen (separat)

Diese Punkte stammen aus der Architekturbewertung und sind **unabhängig** von der Plattform-Drift — der Vollständigkeit halber hier gelistet:

### R1 — Skalierungsrisiko Volltextsuche
- **Modulstelle:** 12.5.1 (DB-`LIKE '%term%'` über `ticket_comments`, öffentliche + interne Eintragstexte).
- **Problem:** Führender Wildcard ⇒ kein Indexzugriff ⇒ Full-Scan auf der größten Tabelle. NFR „10.000 Tickets < 2 s" (18) gilt für Pagination, nicht zwingend für die Eintrags-Volltextsuche.
- **Vorschlag:** Skalierungsrisiko in v1 explizit ausweisen; dedizierten Suchindex (19.1) früher einplanen oder den v1-Suchumfang (welche Felder volltext) bewusst begrenzen.

### R2 — `ticket_comments` als Hot-/Polymorph-Tabelle
- **Modulstelle:** 15.1 (`TicketComment`/`ticket_comments` hält alle Eintragstypen).
- **Problem:** Größte und meistgelesene Tabelle; Index-/Partitionierungsstrategie nur generisch adressiert (20.5).
- **Vorschlag:** Gezielte Strategie festschreiben (z. B. Partitionierung nach Zeitraum/Ticket, getrennte Indizes für `is_public` und Eintragstyp, Vermeidung von Volltext-LIKE auf dieser Tabelle).

---

## Bereits konsistent (kein Handlungsbedarf)

- Modulgrenzen über Resolver-Defaults (SLA-Kalender 24×7, Feiertage leer, CAPTCHA aus) — entspricht Plattform 23.6/26.
- Einheitliches Eintragsmodell, deactivate-not-delete, Matrix-Konfiguration, Audit/Timeline — entsprechen den Plattformprinzipien (1.5/1.6/1.8).
- Moduldefinierte Delete-Semantik für Tickets (Soft-/Hard-Delete) — entspricht Plattform 23.12.1.
- SLA als eigener Service — entspricht Plattform 1.8.

---

## Vorgeschlagene Reihenfolge der Einarbeitung

1. **P1** (A1, A2, A3) — Widersprüche/Duplikate zuerst auflösen.
2. **P2** (A4, A5, A6) — Event/Outbox, email_queue, Rechte-Granularität.
3. **P3** (A7, A8, A9, A10) — Klarstellungen/Verweise.
4. **R1, R2** — Skalierung (modul-intern, unabhängig).

Nach Einarbeitung: Modul-Dokument auf neue Version heben, Versionshistorie + ggf. eigenes Entscheidungsprotokoll des Moduls fortschreiben, Querverweise auf Plattform-Kapitel prüfen.

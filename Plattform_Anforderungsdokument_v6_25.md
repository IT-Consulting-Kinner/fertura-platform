# Plattform-Anforderungsdokument: Modulare Anwendungsplattform

Version 6.28

Stand: 03. Juni 2026

Status: In Überarbeitung

# 1. Einleitung

## 1.1 Zweck des Dokuments

Dieses Dokument beschreibt die Anforderungen an die modulare
Anwendungsplattform (nachfolgend "die Plattform"), die als technische
Basis für fachliche Anwendungsmodule dient. Die Plattform wird auf
Basis von CakePHP 5 und PostgreSQL entwickelt.

Die Plattform ist kein Fachsystem, sondern stellt die technische
Infrastruktur bereit, auf der Main-Module (z.B. Ticketing,
Wissensdatenbank, CRM) und Extension-Module betrieben, installiert,
aktualisiert, lizenziert und miteinander verbunden werden.

Die fachlichen Anforderungen einzelner Module werden in separaten
Modul-Anforderungsdokumenten beschrieben (z.B. "Anforderungsdokument:
Ticketing-Modul").

## 1.2 Zielgruppe

Dieses Dokument richtet sich an:

-   Auftraggeber und Stakeholder
-   Entwicklungsteam
-   Systemarchitekten
-   Qualitätssicherung

## 1.3 Technologiebasis

| **Komponente** | **Technologie** |
| --- | --- |
| Framework | CakePHP 5.x (neueste stabile Version) |
| Datenbank | PostgreSQL |
| Sprache | PHP 8.3+ |
| Mail-Empfang | IMAP (via Cronjob / CLI-Command) |
| Mail-Versand | SMTP (pro Mailbox konfigurierbar) |
| Frontend | Server-Side-Rendering (CakePHP Templates), Bootstrap 5 |
| Mehrsprachigkeit | CakePHP I18n, Standard: Englisch |
| Authentifizierung | CakePHP Authentication Plugin; Methode über Resolver-Slot austauschbar (lokal als Default, optional OIDC/SAML-SSO via Extension-Modul) |
| Autorisierung | CakePHP Authorization Plugin |
| CAPTCHA | Über Extension-Modul (z.B. Google reCAPTCHA v2), optional |

## 1.4 Konfigurationsprinzip

Grundsätzlich gilt: Sämtliche anwendungsspezifischen Konfigurationen
werden über die grafische Benutzeroberfläche (Admin-Bereich) vorgenommen
und in der Datenbank persistiert. Nur infrastrukturelle
Basiseinstellungen verbleiben in der Datei config/app.php bzw.
config/app_local.php:

| **config/app.php** | **Datenbank (GUI)** |
| --- | --- |
| Datenbankverbindung | Mailbox-Konfigurationen (IMAP/SMTP) |
| Log-Verzeichnis | Queue-Einstellungen |
| Debug-Modus | Eskalationsregeln, SLA-Kalender |
| Salt / Security Keys | Ticketnummern-Format |
| Session-Handling | Benachrichtigungseinstellungen und Templates |
| Cache-Konfiguration | Autoantworten, Signaturen |
| App.fullBaseUrl | Prioritäten, System-Mailbox |
| AES-256-GCM Encryption Key | Passwort-Policy, Session-Timeout, Rate-Limiting-Schwellen |
| Storage-Anbindung (Pfad / S3-Credentials) | Erlaubte Dateitypen, Max. Dateigröße |
|  | CAPTCHA-Konfiguration inkl. Site-Key / Secret-Key (modulverwaltet) |
|  | Gast-Session-Timeout (modulverwaltet) |

Modulspezifische Konfiguration verbleibt nicht in config/app.php.
Geheimnisse und Einstellungen von Modulen (z.B. CAPTCHA-Schlüssel des
Extension-Moduls Gastportal-CAPTCHA, Gast-Session-Timeout des
Ticketing-Gastzugangs) werden über den Konfigurationsspeicher des Core
verwaltet und – soweit es sich um Geheimnisse handelt – verschlüsselt
abgelegt (AES-256-GCM mit dem Core-Schlüssel aus config/app.php). In
config/app.php verbleiben ausschließlich infrastrukturelle
Basiseinstellungen.

Schlüsselrotation: Die Verschlüsselung schützt insbesondere gegen das
Offenlegen von Datenbankinhalten ohne die zugehörige Konfigurationsdatei
(z.B. ein entwendetes Datenbank-Backup). Eine routinemäßige automatische
Schlüsselrotation ist nicht vorgesehen. Für den Bedarfsfall – etwa eine
Schlüsselkompromittierung oder eine Compliance-Vorgabe des Betreibers –
stellt das System einen CLI-Command bereit, der alle gespeicherten
verschlüsselten Werte kontrolliert auf einen neuen Schlüssel umschlüsselt
(Re-Encryption, in einem Wartungsfenster ausgeführt). (Soll) Eine
periodische Rotation ist eine Betreiber- bzw. Compliance-Entscheidung
(Empfehlung); eine gleitende, unterbrechungsfreie Rotation über parallel
geführte Schlüssel mit Key-ID ist einer späteren Version vorbehalten.

## 1.5 Matrix-Konfiguration

Einstellungen, die auf mehrere Elemente wirken können (z.B. freie Felder
auf Queues, Checklisten-Vorlagen auf Queues), werden im Admin-Bereich
über eine zweidimensionale Matrix konfiguriert. Die Zeilen
repräsentieren die zu konfigurierenden Objekte (z.B. Felder, Vorlagen),
die Spalten die Zielobjekte (z.B. Queues). Per Checkbox wird die
Zuordnung aktiviert oder deaktiviert. Dieses Prinzip wird angewendet
bei:

-   Freie Felder ↔ Queues (welche Felder in welcher Queue aktiv sind)

-   Checklisten-Vorlagen ↔ Queues (welche Vorlagen in welcher Queue
    automatisch angewendet werden)

-   Makros ↔ Queues (welche Aktionspakete in welcher Queue verfügbar
    sind)

-   Abschlussgründe ↔ Queues (welche Abschlussgründe in welcher Queue
    verfügbar sind)

-   Tickettypen ↔ Queues (welche Tickettypen in welcher Queue verfügbar
    sind)

-   Pflichtfeld-Regeln: Felder ↔ Queues mit Bedingungen (immer Pflicht,
    Pflicht bei Status, Pflicht bei Eintragstyp)

Die Matrix-Ansicht ermöglicht eine schnelle Übersicht und effiziente
Konfiguration, ohne jedes Element einzeln bearbeiten zu müssen.

## 1.6 Datenintegrität konfigurierbarer Werte

Für alle konfigurierbaren Werte im System gilt ein einheitliches
Integritätsprinzip:

-   Nie löschen, nur deaktivieren: Konfigurierbare Werte können geändert
    und deaktiviert bzw. aktiviert werden, aber niemals gelöscht werden.
    Deaktivierte Werte erscheinen nicht mehr in Dropdowns und
    Auswahllisten, bleiben aber in der Datenbank und in allen
    historischen Referenzen erhalten.

-   Audit-Log: Alle Änderungen an konfigurierbaren Werten werden im
    Audit-Log erfasst (was wurde geändert, alter Wert, neuer Wert, durch
    wen, Zeitstempel).

-   Eindeutigkeit: Es dürfen keine doppelten Werte existieren. Jeder
    konfigurierbare Wert hat einen Unique-Constraint auf seinen Namen
    (pro Entität).

Dieses Prinzip gilt für folgende Entitäten des Ticketing-Main-Moduls:
Prioritäten, Queue-Gruppen, Queues, Benutzergruppen, Eintragstypen
(benutzerdefiniert), Abschlussgründe, Tickettypen, Tags (vordefiniert),
Freie Felder, Checklisten-Vorlagen, Textbausteine, Makros und Benutzer.

Dasselbe Prinzip gilt für Konfigurationsentitäten von Extension-Modulen,
insbesondere: SLA-Kalender und Ausnahmetag-Listen (Extension-Modul
SLA-Kalender, Kapitel 23.15.2) sowie Feiertagsquellen (Extension-Modul
Feiertagskalender, Kapitel 23.15.3). Diese Entitäten existieren nur,
wenn das jeweilige Extension-Modul installiert ist.

Einzige Ausnahme: Noch nicht aktivierte Einladungs-Accounts (Status
"eingeladen") dürfen beim Widerruf vollständig gelöscht werden (siehe
Modul-Dokument Ticketing, Kapitel 2.8).

Für aktivierte Benutzer wird das Recht auf Löschung (Art. 17 DSGVO)
durch irreversible Anonymisierung umgesetzt, nicht durch physische
Löschung (siehe Kapitel 27.15.3).

Hinweis zur modularen Architektur (Kapitel 23): Das Prinzip
"deaktivieren statt löschen" gilt verbindlich für Core-
Konfigurationsobjekte (Kapitel 23.3.1), für Konfigurationsentitäten
des Ticketing-Main-Moduls und für Konfigurationsentitäten installierter
Extension-Module. Die Delete-Semantik für fachliche Anwendungsdaten
(z.B. Tickets, Wissensartikel) wird vom jeweiligen Modul selbst
definiert (Kapitel 23.12).

## 1.7 Anforderungsklassifikation

Dieses Dokument verwendet folgende Klassifikation für Anforderungen und
Empfehlungen. Die Kennzeichnung wird insbesondere in Kapitel 20
(Betrieb) und Kapitel 21 (Abnahmekriterien) explizit verwendet:

| **Stufe** | **Bedeutung** | **Für Umsetzung und Abnahme** |
| --- | --- | --- |
| **Muss** | Verbindliche Anforderung. Ohne Umsetzung ist das System nicht releasefähig. | Muss implementiert und getestet werden |
| **Soll** | Dringend empfohlene Anforderung. Abweichung nur mit dokumentierter Begründung. | Soll implementiert werden, Abweichung dokumentieren |
| **Empfehlung** | Betriebsempfehlung oder Best Practice. Umsetzung liegt beim Betreiber. | Nicht Teil der Softwareabnahme |
| **Spätere Version** | Explizit nicht in v1 enthalten. Vorgesehen für künftige Releases. | Nicht implementieren |

Sofern eine Anforderung in diesem Dokument nicht explizit klassifiziert
ist, gilt sie als **Muss-Anforderung**.

## 1.8 Architekturprinzipien

Die folgenden technischen Prinzipien gelten innerhalb der gesamten
Plattform und insbesondere innerhalb jedes Moduls. Sie ergänzen die
plattformweiten Architekturprinzipien der modularen Architektur
(Kapitel 23.14). Kapitel 1.8 beschreibt, wie Code innerhalb eines
Moduls strukturiert sein muss; Kapitel 23.14 beschreibt, wie Module
auf der Plattform zusammenwirken.

**Geschäftslogik nicht in Controllern.** Controller nehmen Requests
entgegen, validieren Eingaben und delegieren an Service-Klassen. Die
gesamte Fachlogik (Statusübergänge, SLA-Berechnung, Berechtigungen,
Eintragsverarbeitung) liegt in dedizierten Service-Klassen, nicht in
Controllern oder Models.

**Rechteprüfung zentral und wiederverwendbar.** Der Core stellt die
zentrale Berechtigungsinfrastruktur bereit (CakePHP Authorization
Plugin). Module definieren darauf ihre BREAD-Ressourcen und
gruppenbezogenen Zugriffsregeln (siehe Kapitel 23.11). Im
Ticketing-Modul ergibt sich der Zugriffspfad aus Benutzer →
Benutzergruppen → Queues. Dieselbe Policy wird für GUI-Requests,
API-Requests und CLI-Commands verwendet. Keine Duplizierung der
Zugriffslogik.

**Mail-Ingestion getrennt von Ticket-Service.** Der E-Mail-Abruf
(IMAP-Verbindung, Parsing, Klassifizierung) ist ein eigenständiger
Service, der sauber vom Ticket-Service (Erstellung, Threading,
Zuordnung) getrennt ist. Der Mail-Service liefert strukturierte Daten,
der Ticket-Service verarbeitet sie.

**SLA-Berechnung als eigener Service.** Geschäftsminuten-Berechnung,
Kalender-Auswertung, Soll-/Ist-Zeitermittlung und Eskalationsprüfung
sind in einem dedizierten SLA-Service gekapselt. Dieser Service wird
von Ticket-Erstellung, Queue-Wechsel, Prioritätswechsel und dem
Eskalations-Cronjob gleichermaßen verwendet.

**Eintragsmodell als zentrale Domänenabstraktion.** Alle Einträge
(E-Mails, Statuswechsel, Gast-Kommentare, System-Aktionen,
benutzerdefinierte Einträge) laufen über dasselbe Modell
(TicketComment + EntryType). Kein paralleles Speichermodell für
verschiedene Eintragstypen.

**Audit und Timeline als eigene Querschnittsfunktion.** Audit-Log und
Ticket-Timeline werden über Event-Listener oder Behavior-Hooks
automatisch befüllt, nicht durch manuellen Code an jeder Stelle. So
wird sichergestellt, dass keine Aktion undokumentiert bleibt.

**API und GUI greifen auf dieselbe Fachlogik zu.** Die REST-API und
die GUI-Controller verwenden dieselben Service-Klassen. Es gibt keine
separate Implementierung der Geschäftslogik für den API-Kontext. Die
Sichtbarkeitsfilterung (öffentlich vs. intern) ist eine Schicht über
denselben Daten.

**Konfigurationsänderungen validieren gegen bestehende Referenzen.**
Vor der Deaktivierung einer Entität (Priorität, Queue, Tickettyp etc.)
prüft das System automatisch, wie viele aktive Referenzen existieren
(offene Tickets, Eskalationsregeln, Pflichtfeld-Regeln). Das Ergebnis
wird dem Administrator angezeigt, bevor die Aktion bestätigt wird.

**Datenbankmigrationen sind rückwärtskompatibel.** Schemaänderungen
dürfen bestehende Daten nicht zerstören. Neue Pflichtfelder erhalten
Defaultwerte. Umbenennungen und das Entfernen von Strukturen erfolgen
nach dem expand/contract-Muster (neue Struktur anlegen, Daten
übernehmen, Altstruktur erst in einem späteren, getrennten Schritt
entfernen), nicht als In-Place-destruktive Änderung. Schema- und
Datenmigrationen laufen innerhalb einer Datenbanktransaktion und werden
bei einem Fehler atomar zurückgerollt (PostgreSQL unterstützt
transaktionales DDL). Jede Migration muss zusätzlich eine umkehrende
down-Operation mitliefern; der Update-Mechanismus sichert den Stand
ergänzend über einen Wiederherstellungspunkt ab (siehe Kapitel 28.14.2).

**Integrität wird in der Datenbank durchgesetzt, nicht nur in der
Anwendung.** Integritäts- und Zugriffsregeln, die sich in der Datenbank
ausdrücken lassen (Fremdschlüssel, partielle Unique-, Check- und
Exclusion-Constraints, Row-Level Security), werden dort erzwungen.
Anwendungslogik ergänzt sie, ersetzt sie aber nicht (siehe Kapitel 30).


# 23. Modulare Plattformarchitektur

## 23.1 Zielsetzung

Das System wird als modulare Plattformarchitektur aufgebaut. Ziel ist
es, den fachlichen Kern nicht fest im Core zu verankern, sondern
Fachlogik in installierbare Main-Module und Extension-Module
auszulagern. Der Core stellt ausschließlich die technische Plattform
bereit, auf der Module betrieben, installiert, aktualisiert, lizenziert
und miteinander verbunden werden.

Die modulare Architektur verfolgt folgende Ziele:

-   Klare Trennung zwischen technischer Plattform und fachlicher
    Funktionalität
-   Nachinstallierbarkeit kostenloser und kostenpflichtiger Module
-   Saubere Erweiterbarkeit über definierte Contracts
-   Kontrollierter Betrieb über Marketplace, Lizenzprüfung und
    Update-Mechanismus
-   Keine Datenlöschung bei Modul-Deaktivierung
-   Klare Abhängigkeits- und Kompatibilitätsprüfung

## 23.2 Grundbegriffe

Zur Vermeidung von Unschärfen werden folgende Begriffe verbindlich
verwendet:

| **Begriff** | **Bedeutung** |
| --- | --- |
| Core | Technische Plattform ohne fachliche Hauptlogik |
| Main-Modul | Fachliches Hauptmodul mit eigener Domäne und vollständiger Grundfunktion |
| Extension-Modul | Erweiterungsmodul, das genau ein Main-Modul erweitert |
| Contract | Formal definierte, versionierte Schnittstelle für Resolver, Collector oder Event |
| Resolver | Erweiterungspunkt, der genau ein Ergebnis liefert |
| Collector | Erweiterungspunkt, der mehrere Beiträge sammelt |
| Event | Ereignis, auf das mehrere Module reagieren können |
| Provider | Konkrete Implementierung eines Resolver-Contracts |
| Listener | Konkrete Implementierung eines Event-Contracts |
| Registry | Zentrale Übersicht über Contracts und registrierte Implementierungen |
| Marketplace | Zentrale Quelle für Modul-Pakete und Metadaten |

## 23.3 Core-Plattform

Der Core ist keine Fachanwendung, sondern die technische Plattform des
Systems. Er enthält ausschließlich plattformweite Funktionen und keine
fachmodulspezifische Domänenlogik.

Der Core enthält mindestens folgende Funktionsbereiche (das
plattformweite Benutzer-, Gruppen- und Rollenmodell ist in Kapitel 27
detailliert beschrieben):

-   Benutzerverwaltung
-   Authentifizierung
-   Gruppenverwaltung
-   Admin-Grundbereich
-   Modulverwaltung
-   Paketmanager
-   Marketplace-Anbindung
-   Lizenzprüfung
-   Signaturprüfung
-   Update-Manager
-   Contract-/Resolver-/Collector-/Event-Registry
-   Lifecycle-Manager für Module
-   Konfigurationsspeicher
-   Audit-Log und Logging
-   Migrationsrunner
-   Abhängigkeitsprüfung
-   Grafische Darstellung von Modulabhängigkeiten

### 23.3.1 Grundregel für den Core

Im Core gilt:

-   Volladministratoren sind im Core über alle Administrationsbereiche
    vollberechtigt; delegierte Administratoren über die ihnen
    zugewiesene Teilmenge (Kapitel 27.3.1). Der Zugriff auf fachliche
    Moduldaten (z.B. Tickets, Wissensartikel) ergibt sich unabhängig
    davon aus den Gruppenmitgliedschaften gemäß dem BREAD-Modell des
    jeweiligen Moduls (siehe Kapitel 25 und 27).
-   Der Core verwendet kein BREAD-Rechtesystem.
-   Konfigurationsobjekte im Core werden aktiviert und deaktiviert,
    nicht gelöscht.
-   Löschvorgänge im Core sind nur in expliziten Sonderfällen
    vorgesehen, insbesondere bei der Löschung eines Moduls (Kapitel
    24.14) und beim Widerruf eines noch nicht aktivierten
    Einladungs-Accounts (Status "eingeladen", siehe Kapitel 1.6).

Der Core enthält bewusst keine fachmodulspezifischen Spezialrechte.

## 23.4 Main-Module

Main-Module bilden die fachlichen Hauptdomänen des Systems. Sie stellen
eine vollständige Grundfunktion bereit und müssen ohne Extension-Module
lauffähig sein.

Beispiele für Main-Module sind:

-   Ticketing
-   Wissensdatenbank
-   CRM
-   Asset Management

Ein Main-Modul bringt mindestens mit:

-   Eigene Services
-   Eigene GUI
-   Eigene Adminseiten
-   Eigene Datenbanktabellen
-   Eigene API-Endpunkte
-   Eigene Contracts
-   Eigene BREAD-fähige Ressourcen
-   Eigene modulbezogene Zusatzaktionen
-   Eigene Migrationslogik
-   Optionale öffentliche Modul-Interfaces zur Nutzung durch andere
    Module (siehe Kapitel 29)

### 23.4.1 Grundregel für Main-Module

Ein Main-Modul muss vollständig lauffähig sein, auch wenn kein
Extension-Modul installiert oder aktiv ist. Extension-Module dürfen ein
Main-Modul erweitern, aber niemals dessen Grundlauffähigkeit erst
herstellen.

## 23.5 Extension-Module

Das System unterscheidet zwei Arten von Extension-Modulen:

### 23.5.1 Reguläre Extension-Module

Ein reguläres Extension-Modul erweitert genau ein Main-Modul. Es dient
der modularen Erweiterung fachlicher Funktionen dieses Main-Moduls,
ohne selbst eine eigenständige Hauptdomäne zu bilden.

Beispiele:

-   SLA-Kalender (erweitert Ticketing)
-   Feiertagskalender (erweitert Ticketing)
-   Gastportal-CAPTCHA (erweitert Ticketing)
-   Advanced Reporting (erweitert Ticketing)
-   Webhooks (erweitert Ticketing)

### 23.5.2 Integrations-Extension-Module

Ein Integrations-Extension-Modul darf mehrere Main-Module über deren
öffentliche Modul-Interfaces (Kapitel 29) und Contracts (Kapitel 26)
fachlich verbinden. Es kapselt modulübergreifende Integrationslogik
und speichert Beziehungen zwischen Main-Modulen in eigenen Tabellen
(siehe Kapitel 29.9 und 29.10).

Beispiele:

-   Ticket-Wissensdatenbank (verbindet Ticketing + Wissensdatenbank)
-   CRM-Ticketing-Bridge (verbindet CRM + Ticketing)

### 23.5.3 Gemeinsame Fähigkeiten

Beide Arten von Extension-Modulen können mitbringen:

-   Eigene Services
-   Eigene GUI-Erweiterungen
-   Eigene Adminseiten
-   Eigene Tabellen
-   Eigene Migrationslogik
-   Registrierungen für Resolver, Collector oder Events
-   Optionale gruppenbezogene Berechtigungen auf eigene Ressourcen

### 23.5.4 Grundregel für Extension-Module

Ein Extension-Modul darf keine eigene Rechte-Domain im Core aufbauen.

Ein Extension-Modul darf:

-   Eigene Ressourcen definieren
-   Gruppen diesen Ressourcen zuordnen
-   Auf diese Ressourcen BREAD und modulbezogene Zusatzaktionen
    anwenden

Ein Extension-Modul darf nicht:

-   Das Core-Rechtesystem verändern
-   Eigene globale Rollenlogik einführen
-   Eine alternative Policy-Engine im Core etablieren

## 23.6 Erweiterungsmechanismen

Der Begriff "Hook" wird in der Architektur nicht als unscharfer
Sammelbegriff verwendet. Stattdessen wird zwischen Resolvern,
Collectors und Events unterschieden. Dies sind die drei
Erweiterungsmechanismen, bei denen externe Module einen vom Owner-Modul
definierten Punkt besetzen. Daneben existiert als vierter Contract-Typ
der Service (Request/Response), bei dem umgekehrt das Owner-Modul eine
Schnittstelle bereitstellt, die andere Module konsumieren; er ist die
Grundlage öffentlicher Modul-Interfaces (Kapitel 29). Alle vier Typen
werden in Kapitel 26 detailliert spezifiziert (einheitliches
Capability-Modell, Contract-Aufbau, Interface-Spezifikation,
Versionierung, Registrierung, Fehlerverhalten, Auditierung).

### 23.6.1 Resolver

Resolver liefern genau ein Ergebnis.

Beispiele:

-   Berechnung einer SLA-Frist
-   Bestimmung eines CAPTCHA-Providers
-   Ermittlung einer Feiertagsmenge
-   Auflösung einer Zusatzkonfiguration

Regeln für Resolver:

-   Jeder Resolver besitzt einen klar definierten Contract.
-   Jeder Resolver besitzt ein vom definierenden Modul festgelegtes
    Default-Verhalten.
-   Ein Resolver darf nie verpflichtend besetzt sein.
-   Ein Prozess muss auch ohne aktiven Provider korrekt laufen.
-   Pro Resolver-Slot darf genau ein aktiver Provider registriert sein.
-   Wenn ein anderes Modul denselben Resolver-Slot belegen soll, muss
    der bisherige Provider deaktiviert werden.

Default-Verhalten: Das definierende Modul legt pro Resolver fest, wie
das Default-Verhalten aussieht. Mögliche Defaults sind insbesondere:
ein konkreter Wert (z.B. 24×7-SLA), NULL, eine leere Liste oder ein
leeres Ergebnisobjekt. Das Default-Verhalten ist Bestandteil des
Contracts und muss dort ausdrücklich dokumentiert sein.

Architekturregel: Ein Prozess darf niemals darauf angewiesen sein, dass
ein externer Provider aktiv ist. Wenn ein Prozess ohne aktiven Provider
nicht lauffähig ist, ist er falsch geschnitten.

### 23.6.2 Collector

Collector sammeln mehrere Beiträge.

Beispiele:

-   Zusätzliche UI-Blöcke
-   Zusätzliche Dashboard-Widgets
-   Zusätzliche Exportspalten
-   Zusätzliche Datenquellen

Regeln für Collector:

-   Mehrere Module dürfen Beiträge registrieren.
-   Deaktivierte Module werden nicht berücksichtigt.
-   Ohne registrierte Beiträge liefert der Collector das definierte
    Leerergebnis, in der Regel eine leere Liste oder leere Sammlung.

### 23.6.3 Events

Events signalisieren, dass etwas passiert ist.

Beispiele:

-   Ticket wurde erstellt
-   Ticket wurde geschlossen
-   Modul wurde aktiviert
-   E-Mail wurde versendet

Regeln für Events:

-   Mehrere Listener sind erlaubt.
-   Deaktivierte Module werden ignoriert.
-   Events werden asynchron über einen transaktionalen Outbox zugestellt
    (Kapitel 26.9.2); der auslösende Prozess wartet nicht auf die
    Listener und ist von deren Erfolg unabhängig.

## 23.7 Contracts

Jeder Resolver, Collector oder Event basiert auf einem formalen,
versionierten Contract. Die vollständige Spezifikation von
Contract-Aufbau, Interface-Anforderungen und Versionierung ist in
Kapitel 26.4-26.6 beschrieben.

Ein Contract definiert mindestens:

-   Name
-   Typ: Resolver, Collector oder Event
-   Version
-   Owner-Modul
-   Beschreibung
-   Input-Spezifikation
-   Output-Spezifikation
-   Default-Semantik
-   Zulässige Provideranzahl
-   Fehlerverhalten

### 23.7.1 Grundregel

Für jeden Contract muss klar definiert sein: was übergeben wird, was
erwartet wird, wie das Default-Verhalten aussieht und was passiert,
wenn kein Provider aktiv ist. Damit ist jeder Contract eine
dokumentierte und versionierte Schnittstelle.

## 23.8 Registry im Admin-Bereich

Der Core stellt im Admin-Bereich eine zentrale Registry für Contracts
und ihre Registrierungen bereit.

### 23.8.1 Sicht pro Contract

Für jeden Contract werden mindestens angezeigt: Name, Typ, Version,
Owner-Modul, Beschreibung, Interface- oder Spezifikationsname,
Input-Spezifikation, Output-Spezifikation, Default-Semantik, aktive
Registrierungen und Status.

### 23.8.2 Sicht pro Provider, Listener oder Collector-Beitrag

Für jede Registrierung werden mindestens angezeigt: Modulname,
Modulversion, Ziel-Contract, Status (aktiv/inaktiv), Lizenzstatus
und Kompatibilitätsstatus.

### 23.8.3 Optionale Diagnosefunktionen

Ein Modul kann zusätzlich eine eigene Diagnose- oder Testoberfläche
bereitstellen. Diese ist nicht Teil des Core, kann aber aus der
Registry heraus verlinkt werden.

## 23.9 Marketplace und Lizenzierung

Es gibt einen zentralen Marketplace, über den Modul-Pakete und
Metadaten bereitgestellt werden. Module können kostenlos oder
kostenpflichtig sein. Die detaillierte Marketplace-Kommunikation,
Signaturprüfung und Update-Mechanismen sind in Kapitel 28 beschrieben.

### 23.9.1 Kostenpflichtige Module

Ein kostenpflichtiges Modul benötigt einen modulbezogenen
Lizenzschlüssel. Eigenschaften: auf genau dieses Modul begrenzt,
optional zeitlich befristet, Aktivierung nur bei gültiger Lizenz, bei
Ablauf wird das Modul deaktiviert.

### 23.9.2 Verhalten bei Lizenzablauf

Bei Ablauf einer Modul-Lizenz gilt:

-   Es erfolgt keine Datenlöschung.
-   Resolver fallen auf ihr Default-Verhalten zurück.
-   Collector- und Event-Beiträge des Moduls werden nicht mehr
    berücksichtigt.
-   Der Administrator sieht den Status des Moduls klar im System.

### 23.9.3 Kuratierter Marktplatz und Modulprüfung

Der Marktplatz ist kuratiert. Module und Herausgeber werden vor Aufnahme
durch den Marktplatz-Betreiber geprüft (Review/Vetting).
Herausgeberschlüssel bzw. -zertifikate (Kapitel 24.9.2) werden
ausschließlich an geprüfte Herausgeber ausgegeben. Damit ist
sichergestellt, dass jeder über den kuratierten Marktplatz installierbare
Code aus einer geprüften Quelle stammt.

Diese Kuratierung ist die Grundlage dafür, dass Module im selben
Laufzeitkontext wie der Core ausgeführt werden dürfen (Kapitel 23.16): Da
die Plattform technisch keine Sandbox bereitstellt, wird Vertrauen durch
Prüfung und Signatur vor der Ausführung etabliert.

#### 23.9.3.1 Betreiber-zugelassene Modulquellen und Härtung

Standardbetrieb (empfohlen): Es werden ausschließlich kuratierte,
geprüfte Module aus dem zentralen Marktplatz oder eigene, vom Betreiber
selbst verantwortete signierte Module zugelassen (Kapitel 28.4.1).

Betreiber können zusätzlich ungeprüfte Drittanbieter-Module zulassen.
Dies geschieht ausdrücklich auf eigenes Risiko und erfordert eine
bewusste Konfigurationsentscheidung. Für diesen Fall gelten folgende
Empfehlungen (Empfehlung):

-   Vorabprüfung des Modulcodes durch den Betreiber (Code-Review).
-   Betrieb der Anwendung unter einem Datenbank-Account mit minimalen
    Rechten (Least Privilege).
-   Begrenzung von Ressourcen (Speicher, Laufzeit, Dateisystemzugriff)
    auf Betriebssystem- oder Webserver-Ebene.
-   Bewusstsein, dass In-Process-Module nicht voneinander isoliert sind.
    Für eine echte technische Isolationsgrenze kann ein solches Modul auf
    den Modus `out_of_process` gesetzt werden (eigener Prozess, bereinigte
    Umgebung, eigene eingeschränkte DB-Rolle; Kapitel 23.16.2).

Die Plattform kennzeichnet ungeprüfte, nicht kuratierte Module im
Admin-Bereich deutlich als solche.

#### 23.9.3.2 Grundregel

Im Standardbetrieb sind nur geprüfte Module zugelassen – kuratierte
Marktplatz-Module oder betreiber-verantwortete signierte Module. Das
Zulassen ungeprüfter Module ist eine bewusste Betreiberentscheidung auf
eigenes Risiko; die Plattform stellt dafür keine technische Sandbox
bereit und weist solche Module sichtbar aus.

## 23.10 Modul-Lifecycle

Jedes Modul durchläuft einen einheitlichen Lebenszyklus. Die hier
beschriebenen Phasen definieren das fachliche Verhalten. Die
detaillierten technischen Schrittfolgen, Prüfketten und das
Manifest-Format sind in Kapitel 24 spezifiziert.

### 23.10.1 Installieren

Beim Installieren werden mindestens folgende Schritte ausgeführt:
Paket aus dem Marketplace laden, Signatur prüfen, Manifest lesen,
Kompatibilität prüfen, Abhängigkeiten prüfen, Dateien entpacken,
Migrationen ausführen, Registry-Einträge anlegen.

### 23.10.2 Aktivieren

Beim Aktivieren werden mindestens folgende Schritte ausgeführt:
Lizenz prüfen (falls erforderlich), Resolver-, Collector- und
Event-Registrierungen validieren, Konflikte prüfen, GUI, Services
und Prozesse aktiv schalten.

### 23.10.3 Deaktivieren

Beim Deaktivieren gilt: Modulverhalten wird abgeschaltet, Registry-
Einträge werden als inaktiv markiert, Resolver fallen auf Default
zurück, Collector und Events berücksichtigen das Modul nicht mehr.
Es werden keine Daten gelöscht.

### 23.10.4 Aktualisieren

Beim Aktualisieren werden mindestens folgende Schritte ausgeführt:
Neue Version laden, Signatur prüfen, Kompatibilität prüfen,
Migrationsvorschau anzeigen, Update ausführen, Registry neu
validieren.

### 23.10.5 Löschen

Die Löschung eines Moduls erfolgt nur explizit und mit folgenden
Regeln: Abhängigkeitsprüfung ist verpflichtend, deutliche Warnung vor
der Ausführung, Datenlöschung erfolgt nur in diesem Vorgang, niemals
bei Deaktivierung.

## 23.11 Berechtigungssystem für Anwendungsmodule

BREAD gilt ausschließlich für Anwendungsmodule, nicht für den Core.
Die hier beschriebenen Grundregeln werden in Kapitel 25 detailliert
ausgeführt (Ressourcenmodell, Gruppenzuordnung, Rechteaggregation,
Zusatzaktionen, Admin-Darstellung, Laufzeit-Prüfung).

### 23.11.1 BREAD

-   Browse
-   Read
-   Add
-   Edit
-   Delete

Diese Rechte gelten auf moduldefinierte Ressourcen. Beispiele: Queue,
Ticket, Wissensartikel, SLA-Kalender, Feiertagsliste,
Reporting-Definition.

### 23.11.2 Gruppenmodell

-   Mehreren Gruppen kann dieselbe Ressource zugeordnet werden.
-   Ein Benutzer kann Mitglied in mehreren Gruppen sein.
-   Die effektiven Rechte eines Benutzers ergeben sich aus der
    Vereinigung aller Gruppenrechte.

### 23.11.3 Grundregeln

Es gibt: keine Deny-Regeln, keine Prioritäten, keine Konfliktlogik
zwischen Gruppen.

## 23.12 Delete-Semantik

Im Core-Adminbereich gibt es kein generisches Delete. Dort gilt
ausschließlich: Activate / Deactivate.

### 23.12.1 Anwendungsmodule

Die fachliche Bedeutung von Delete wird vom jeweiligen Modul selbst
für seine Anwendungsdaten definiert. Beispiele: Ticket (Soft-Delete
oder Hard-Delete), Wissensartikel (Soft-Delete oder Hard-Delete),
andere Fachobjekte (moduldefiniert). Die Unterscheidung zwischen
Soft-Delete und Hard-Delete bleibt Sache des jeweiligen Moduls.

## 23.13 Abhängigkeiten

Jedes Extension-Modul muss deklarieren: welches Main-Modul es
erweitert, welche Mindestversion dieses Main-Moduls benötigt wird und
welche Contract-Versionen unterstützt werden.

### 23.13.1 Darstellung

Der Core zeigt Abhängigkeiten grafisch an, insbesondere: Main-Modul,
Extension-Module, Contract-Beziehungen, Konflikte und blockierende
Deinstallationen. Dadurch bleibt das System administrativ beherrschbar.

## 23.14 Architekturprinzipien

Für die modulare Plattformarchitektur gelten folgende verbindliche
Leitregeln:

-   Der Core ist Plattform, nicht Fachanwendung.
-   Main-Module müssen ohne Extensions lauffähig sein.
-   Extension-Module erweitern, ersetzen aber nicht die
    Grundlauffähigkeit. Reguläre Extension-Module erweitern genau
    ein Main-Modul. Integrations-Extension-Module dürfen mehrere
    Main-Module über öffentliche Interfaces verbinden (Kapitel 29).
-   Jeder Resolver besitzt einen definierten Default.
-   Ein Prozess darf niemals einen aktiven Provider zwingend
    voraussetzen.
-   Pro Resolver-Slot darf nur ein aktiver Provider existieren.
-   Collector und Events ignorieren deaktivierte Module automatisch.
-   Eine Moduldeaktivierung löscht niemals Daten.
-   Eine Modullöschung ist ein eigener, expliziter Vorgang.
-   Admin-Rechte im Core werden nicht über BREAD modelliert.
-   BREAD gilt nur für Anwendungsmodule.
-   Effektive Rechte ergeben sich aus der Vereinigung der
    Gruppenrechte.
-   Lifecycle-verändernde Operationen sind serialisiert; höchstens eine
    ist gleichzeitig aktiv (siehe Kapitel 24.18).
-   Integrität und Zugriffsschutz werden auch in der Datenbank
    durchgesetzt (Constraint-First, RLS für scoped Modultabellen, siehe
    Kapitel 30).

## 23.15 Beispiel: Ticketing

### 23.15.1 Main-Modul Ticketing

Das Main-Modul Ticketing bringt mit: Tickets, Queues, Einträge,
Ticket-GUI, Ticket-Admin, Ticket-API, Ticket-BREAD-Ressourcen,
Resolver-, Collector- und Event-Contracts.

### 23.15.2 Extension-Modul SLA-Kalender

Das Extension-Modul SLA-Kalender bringt mit: Kalenderverwaltung,
Admin-UI, Tabellen, Resolver-Provider für SLA-Berechnung. Default
ohne Modul: 24×7-SLA.

### 23.15.3 Extension-Modul Feiertagskalender

Das Extension-Modul Feiertagskalender bringt mit:
Feiertagsverwaltung, Admin-UI, Tabellen, Resolver oder Collector für
Feiertagsdaten. Default ohne Modul: leere Feiertagsmenge.

### 23.15.4 Extension-Modul Gastportal-CAPTCHA

Das Extension-Modul Gastportal-CAPTCHA bringt mit: CAPTCHA im
Gastportal, Admin-UI, Resolver-Provider für CAPTCHA-Validierung.
Default ohne Modul: kein CAPTCHA.

## 23.16 Sicherheits- und Vertrauensmodell

Module werden als vertrauenswürdiger Code im selben Laufzeitkontext wie
der Core ausgeführt. Die Plattform betreibt keine technische Sandbox,
die Modulcode auf Prozess-, Speicher- oder Dateisystemebene isoliert.
Ein installiertes und aktiviertes Modul hat grundsätzlich denselben
technischen Zugriff auf Datenbank, Dateisystem und interne Dienste wie
der Core.

Daraus folgt die Einordnung der Schutzmechanismen:

-   BREAD-Berechtigungen (Kapitel 25 und 27) und die Capability-Bindung
    für Contracts und Interfaces (Kapitel 26.13.2 und 29.8.3) sind
    Berechtigungs- und Disziplin-Mechanismen. Sie strukturieren den
    erlaubten Zugriff und machen Fehlnutzung erkennbar und auditierbar.
    Sie sind keine technische Barriere gegen bewusst bösartigen
    Modulcode.
-   Die maßgebliche Sicherheitsgrenze der Plattform ist die Signatur-
    und Vertrauenskette (Kapitel 24.9.2): Nur signierter, auf einen
    aktiven Vertrauensanker zurückführbarer und nicht widerrufener Code
    darf installiert und ausgeführt werden. Vertrauen wird vor der
    Ausführung etabliert, nicht zur Laufzeit erzwungen.

### 23.16.1 Grundregel

Die Plattform schützt nicht vor dem von ihr ausgeführten Modulcode,
sondern stellt über Signatur, Vertrauensanker und Widerruf sicher,
welcher Code überhaupt ausgeführt wird. Im Standardbetrieb dürfen nur
kuratierte oder betreiber-verantwortete Module installiert werden; das
Zulassen ungeprüfter Module ist eine bewusste Betreiberentscheidung auf
eigenes Risiko (Kapitel 23.9.3).

### 23.16.2 Optionale Out-of-Process-Isolation (technische Grenze)

Über die Vertrauenskette hinaus kann ein Modul je Installation auf den
Isolationsmodus **`out_of_process`** gesetzt werden (Standard:
`in_process`). In diesem Modus stellt die Plattform eine **echte
technische Isolationsgrenze** bereit:

-   **Eigener Prozess.** Der Modulcode läuft nicht im Core-Prozess,
    sondern in einem vom Core verwalteten **Subprozess** (Managed
    Subprocess). Nur der Modul-Namespace wird dort geladen.
-   **Bereinigte Umgebung.** Der Modulprozess startet mit einer
    **gesäuberten Umgebung** ohne die privilegierten Core-Geheimnisse —
    insbesondere ohne die Superuser-`DATABASE_URL` und ohne das
    Backup-Verschlüsselungspasswort. Der Modulcode kann diese damit nicht
    aus der Prozessumgebung lesen.
-   **Eigene, eingeschränkte Datenbankrolle (automatisch).** Der Core legt
    je isoliertem Modul **automatisch** eine **eigene DB-Rolle**
    (`mod_<key>`, `LOGIN`, `NOBYPASSRLS`) mit einem zufälligen, **AES-256-
    GCM-verschlüsselt** abgelegten Passwort an (für die Modul-Rolle selbst
    unlesbar). Die Rolle hat Rechte **nur auf das eigene Modul-Schema** und
    EXECUTE auf wenige Core-Hilfsfunktionen; ein Zugriff auf Core-Tabellen
    (z. B. `core.users`) ist technisch unterbunden, nicht nur per Disziplin.
-   **Modul-Migrationen ohne Superuser-Rechte.** Die Schema-Migrationen
    eines isolierten Moduls laufen über eine **als die eingeschränkte
    Login-Rolle authentifizierte Verbindung** (nicht per `SET ROLE` auf einer
    Superuser-Sitzung) — eine bösartige Migration kann sich daher **nicht**
    per `RESET ROLE`/`SET ROLE` wieder Superuser verschaffen und den Core
    beschädigen. Damit die Zeilen-Sicherheit (RLS) auch für die modul-eigenen
    Tabellen greift, erzwingt der Core anschließend `FORCE ROW LEVEL
    SECURITY`. Gilt für Installation **und** Update.
-   **Verwalteter Lebenszyklus.** Beim Aktivieren startet der Core den
    isolierten Host automatisch, beim Deaktivieren/Deinstallieren stoppt er
    ihn (und entfernt die DB-Rolle). Ein Supervisor im Hintergrundprozess
    **überwacht** die Hosts und startet abgestürzte neu (Selbstheilung).
-   **Authentifizierter Aufruf über RPC (Pro-Aufruf-Capability-Token).** Der
    Core ruft die Erweiterungspunkte des Moduls über eine schmale
    Inter-Prozess-Schnittstelle (Unix-Domain-Socket, JSON-Zeilen) auf. Jede
    Anfrage trägt ein **aufruf-gebundenes** Token: Das pro Host erzeugte
    Geheimnis dient ausschließlich als **HMAC-Schlüssel** und reist **nie** über
    den Socket; mitgeschickt wird ein MAC über die **gesamte kanonisierte
    Anfrage** (Operation, Klasse/Methode, Argumente, RLS-Kontext) plus Nonce und
    Ablauf. Damit ist der Aufruf **integritätsgeschützt** (Nutzlast/Kontext nicht
    manipulierbar, z. B. keine `bypass`-Eskalation), **zeitlich begrenzt** und
    **einmalig** (der Host weist abgelaufene und wiederholte Nonces ab) — ein am
    Socket abgefangenes Token lässt sich weder wiederverwenden noch auf einen
    anderen Aufruf ummünzen. Das Geheimnis liegt ausschließlich in einer
    **0600-Datei** und wird dem Host als **Pfad** (nicht als Wert) übergeben — es
    landet damit weder in der Prozess-Umgebung noch in der Kommandozeile. Fehlt
    das Geheimnis, verweigert der Host den Start (**fail-closed**, keine
    unauthentifizierte Bedienung). Ein- und Ausgabe sind dieselben
    serialisierbaren Contract-Strukturen wie beim In-Process-Aufruf (Kapitel
    29.8); der Aufrufpfad (`CapabilityHandle`) bleibt für das nutzende Modul
    unverändert. Die Grenze ist damit aufwärtskompatibel zu einer späteren
    Container- oder Host-getrennten Ausführung. **Geltung (ehrlich):** Das Token
    authentifiziert die **Prozessgrenze** (Core→Host) und schützt vor anderen
    Socket-Clients sowie vor Manipulation/Replay; es beschränkt **nicht** den im
    Host laufenden Modulcode selbst (der das Geheimnis kennt). Die eigentliche
    Sandbox bleiben DB-Rolle, bereinigte Umgebung und die optionale OS-Isolation.

Der Modus ist **opt-in** und ergänzt die Vertrauenskette, ersetzt sie
nicht: Signatur/Anker/Widerruf bleiben die maßgebliche Zulassungsgrenze
(23.16.1); die Out-of-Process-Isolation begrenzt zusätzlich, was bereits
zugelassener Modulcode zur Laufzeit technisch erreichen kann.

**Geltungsbereich (Phase 3, vollständig).** Isolierte Module dürfen **alle
gängigen Erweiterungspunkte** anbieten: **Service-Contracts, Collector-Beiträge**
(Health, Anonymisierung, periodische Aufgaben) **, Event-Listener, Daten-Resolver
und periodische Aufgaben** (`core.collector.scheduled`) — diese laufen über RPC
im Host. Der **RLS-Zeilenkontext** (`app.current_user_id`/`-group_ids`/`-bypass`)
wird dabei über die RPC-Grenze mitgereicht und im Host transaktionslokal gesetzt,
sodass Modul-Beiträge benutzer-/gruppen-scoped arbeiten. Beitragsklassen nutzen
im Host eine CakePHP-`default`-Connection auf die Modul-Rolle (Search-Path auf
das Modul-Schema). **Periodische Aufgaben:** Fälligkeits-Prüfung (Heartbeat) und
der Mehrinstanz-Advisory-Lock bleiben im Core; nur die Ausführung (`run()`)
reist über die RPC-Grenze (Systemkontext, RLS-Bypass). **Resolver** werden über
das Capability-Handle aufgerufen und nach der **Isolation des bereitstellenden
Moduls** geroutet. **Einzige Ausnahme:** der **Auth-Provider-Slot**
(`core.auth.provider`) bleibt bei Isolation **abgelehnt** — er ist config-artig
(der Resolver liefert ein In-Process-Authenticator-Objekt, das nicht über RPC
reichbar ist) und ist daher per Konstruktion in-process.

**Optionale OS-Härtung ohne Core-Änderung (Launcher-Prefix).** Für zwei dieser
Ausbaustufen — **eigener OS-Benutzer** und **Dateisystem-/Kernel-Sandbox** —
stellt der Core einen **konfigurierbaren Einsprungpunkt** bereit: Das Setting
`core.module.host.launcher` definiert ein Befehls-Prefix, das der Supervisor in
der bereits bereinigten `env -i`-Umgebung **vor `php`** setzt und das den
Host-Prozess wrappt/exec't. Damit kann der **Betreiber** je nach Plattform
isolieren, ohne Code zu ändern — z. B. `setpriv --reuid=… --regid=… --clear-groups --`
(eigener Benutzer; erfordert die nötigen OS-Rechte), `bwrap --unshare-all
--ro-bind / / --proc /proc --dev /dev --die-with-parent` (FS-/Kernel-Sandbox via
Namespaces + seccomp) oder `firejail`. Die Prozessverwaltung des Supervisors
(Erkennen/Stoppen) ist **wrapper-tolerant**: Erkennung läuft über den Socket,
das Stoppen findet den **tatsächlichen** Host-Prozess über die Kommandozeile
(`/proc`, Match auf Host-Skript **und** Modul-Key) — auch hinter einem forkenden
Launcher, sodass kein verwaister Host zurückbleibt. **Anforderung an den
Launcher:** er muss `php` per `exec` ersetzen oder SIGTERM weiterreichen und mit
dem Elternprozess sterben (z. B. `setpriv … --`, `bwrap … --die-with-parent`).
**Sicherheit:** Wer dieses Setting setzen darf, kann Code als Worker-Benutzer
ausführen — auf dieselbe Vertrauensstufe wie Shell-Zugriff beschränken. Default
ist leer (kein Prefix). **Bewusst spätere Ausbaustufen** (ehrlich benannt): die OS-Härtung als
**vom Core verwaltete, automatische** Eigenschaft (statt Betreiber-Konfiguration)
inkl. eigenem Container je Modul. (Die zuvor hier genannten **Capability-Tokens
je Aufruf** sind umgesetzt — siehe „Authentifizierter Aufruf über RPC" oben.)
**Hinweis Transaktionsgrenze:** Ein
Out-of-Process-Beitrag committet in **seiner** Sitzung; die Core-Operation
(z. B. Anonymisierung) und der Modul-Beitrag bilden daher **keine** verteilte
Transaktion — bei der irreversiblen Anonymisierung ist „über-bereinigt" jedoch
unkritisch.

# 24. Modul-Manifest, Paketstruktur und Installations-/Updatefluss

## 24.1 Zielsetzung

Jedes Modul muss in einer standardisierten Paketstruktur ausgeliefert
werden und ein verbindliches Manifest enthalten. Dadurch wird
sichergestellt, dass Module eindeutig identifizierbar, prüfbar,
installierbar, aktualisierbar und verwaltbar sind.

Das Manifest dient insbesondere folgenden Zwecken:

-   Eindeutige Identifikation des Moduls
-   Versions- und Kompatibilitätsprüfung
-   Abhängigkeitsprüfung
-   Registrierung von Contracts, Providern, Collectors und Events
-   Steuerung von Installation, Aktivierung und Update
-   Lizenz- und Signaturprüfung
-   Klare Trennung zwischen Main-Modulen und Extension-Modulen

## 24.2 Paketformat

Ein Modul wird als eigenständiges Paket ausgeliefert. Das Paket enthält
alle für das Modul erforderlichen Bestandteile:

-   Manifest
-   Quellcode
-   Migrationsdateien
-   Optionale Templates
-   Optionale statische Assets
-   Optionale Sprachdateien
-   Optionale Dokumentations- oder Diagnosedateien

Das konkrete Archivformat des Pakets wird systemweit festgelegt.
Empfohlen wird ein signiertes ZIP-Paket.

### 24.2.1 Grundregel

Ein Modul darf ausschließlich über das definierte Paketformat
installiert werden. Manuelle Dateikopien in Modulverzeichnisse sind
nicht vorgesehen.

## 24.3 Paketstruktur

Jedes Modul muss einer standardisierten internen Verzeichnisstruktur
folgen.

Empfohlene Struktur:

| **Verzeichnis/Datei** | **Beschreibung** |
| --- | --- |
| manifest.json | Verbindliches Modul-Manifest |
| src/ | PHP-Quellcode des Moduls |
| migrations/ | Modulbezogene Datenbankmigrationen |
| templates/ | Template-Dateien für UI und Adminoberflächen |
| assets/ | Statische Assets wie CSS, JavaScript oder Bilder |
| locales/ | Sprachdateien des Moduls |
| docs/ | Optionale technische Dokumentation |
| diagnostics/ | Optionale Diagnose- und Testdefinitionen des Moduls |

### 24.3.1 Grundregel

Die interne Paketstruktur muss so aufgebaut sein, dass der Core alle
relevanten Bestandteile automatisiert erkennen und verarbeiten kann.
Freie, nicht deklarierte Spezialstrukturen sind nicht zulässig.

## 24.4 Modul-Manifest

Jedes Modul muss ein verbindliches Manifest mitführen. Das Manifest
beschreibt Identität, Typ, Kompatibilität, Abhängigkeiten und
technische Registrierungen des Moduls.

Das Manifest ist verpflichtend. Ohne gültiges Manifest darf ein Modul
weder installiert noch aktiviert werden.

### 24.4.1 Pflichtfelder des Manifests

| **Feld** | **Beschreibung** |
| --- | --- |
| id | Eindeutige technische Modul-ID |
| name | Anzeigename des Moduls |
| version | Modulversion |
| type | Typ des Moduls: main oder extension |
| edition | Vertriebsart: free oder commercial |
| description | Kurzbeschreibung des Moduls |
| entrypoint | Einstiegsklasse des Moduls |
| core_compatibility | Unterstützter Versionsbereich des Core |
| signature | Signatur des Pakets |
| publisher | Herausgeber des Moduls |

### 24.4.2 Zusätzliche Pflichtfelder für Extension-Module

| **Feld** | **Beschreibung** |
| --- | --- |
| extends_main_module | Technische ID des Main-Moduls, das erweitert wird |
| main_module_compatibility | Unterstützter Versionsbereich des Main-Moduls |

### 24.4.3 Optionale Manifestfelder

| **Feld** | **Beschreibung** |
| --- | --- |
| requires_license | Gibt an, ob zur Aktivierung eine Lizenz erforderlich ist |
| license_scope | Beschreibung des Lizenzumfangs |
| dependencies | Weitere Modulabhängigkeiten |
| contracts_provided | Vom Modul definierte Contracts, einschließlich Service-Contracts (öffentliche Modul-Interfaces, Kapitel 29) |
| contracts_used | Vom Modul genutzte Contracts anderer Module, einschließlich konsumierter Service-Contracts (bei Main-Modulen: leer, siehe 24.7.3) |
| resolvers_registered | Vom Modul registrierte Resolver-Provider |
| collectors_registered | Vom Modul registrierte Collector-Beiträge |
| events_registered | Vom Modul registrierte Event-Listener |
| ui_extensions | Vom Modul registrierte UI-Erweiterungen |
| permissions | Vom Modul definierte BREAD-Ressourcen und Zusatzaktionen |
| integration_relations | Deklarierte Integrationsbeziehungen zu anderen Main-Modulen (nur bei Integrations-Extension-Modulen) |
| diagnostics_url | Verweis auf modulinterne Diagnoseoberfläche |
| changelog | Versionshinweise |
| deprecation | Hinweise auf veraltete Bestandteile |

## 24.5 Typisierung von Modulen

Jedes Modul ist genau einem Modultyp zugeordnet.

### 24.5.1 Main-Modul

Ein Main-Modul:

-   Definiert eine fachliche Hauptdomäne
-   Ist ohne Extension-Module lauffähig
-   Darf eigene Contracts definieren
-   Darf eigene BREAD-fähige Ressourcen definieren

### 24.5.2 Extension-Modul

Ein reguläres Extension-Modul:

-   Erweitert genau ein Main-Modul
-   Darf die Grundlauffähigkeit des Main-Moduls nicht voraussetzen
-   Darf eigene Ressourcen und Erweiterungen mitbringen
-   Darf Contracts des Main-Moduls oder eines anderen Extension-Moduls
    nutzen, sofern diese kompatibel und öffentlich definiert sind

Ein Integrations-Extension-Modul (Kapitel 23.5.2):

-   Darf mehrere Main-Module über deren öffentliche Interfaces
    verbinden
-   Muss genutzte öffentliche Interfaces (Service-Contracts) im Manifest
    deklarieren (contracts_used)
-   Speichert Integrationsbeziehungen in eigenen Tabellen

## 24.6 Deklaration von Contracts im Manifest

Wenn ein Modul eigene Contracts definiert, müssen diese im Manifest
beschrieben werden.

Für jeden Contract sind mindestens zu deklarieren (konsistent mit
Kapitel 23.7):

-   Name
-   Typ: Resolver, Collector oder Event
-   Version
-   Owner-Modul (wird automatisch aus der Modul-ID abgeleitet)
-   Beschreibung
-   Input-Spezifikation
-   Output-Spezifikation
-   Default-Semantik
-   Zulässige Provideranzahl
-   Fehlerverhalten

### 24.6.1 Grundregel

Nur Contracts, die im Manifest ausdrücklich deklariert sind, dürfen von
anderen Modulen verwendet werden. Nicht deklarierte interne
Erweiterungspunkte gelten als nicht öffentlich und sind nicht für andere
Module freigegeben.

## 24.7 Deklaration von Registrierungen im Manifest

Wenn ein Modul Resolver-Provider, Collector-Beiträge, Event-Listener
oder UI-Erweiterungen registriert, müssen diese im Manifest deklariert
sein.

Für jede Registrierung sind mindestens anzugeben:

-   Ziel-Contract oder Ziel-Erweiterungspunkt
-   Unterstützte Contract-Version
-   Implementierende Klasse
-   Registrierungsart
-   Priorität, sofern anwendbar

### 24.7.1 Grundregel

Registrierungen dürfen nur auf existierende und kompatible Contracts
erfolgen. Die Aktivierung eines Moduls ist zu blockieren, wenn eine
Registrierung auf einen nicht vorhandenen oder inkompatiblen Contract
verweist.

### 24.7.2 Deklaration angebotener und genutzter Contracts und Interfaces

Jedes Modul muss im Manifest explizit deklarieren:

-   Welche Contracts es bereitstellt (contracts_provided),
    einschließlich angebotener Service-Contracts (öffentliche
    Modul-Interfaces)
-   Welche Contracts es nutzt (contracts_used), einschließlich
    konsumierter Service-Contracts

Für jede Nutzung sind mindestens anzugeben: Zielname, Zielversion,
Art der Nutzung und verwendende Modulkomponente.

### 24.7.3 Regel nach Modultyp

Für Main-Module gilt:

-   contracts_used muss leer sein (Main-Module nutzen keine Contracts
    anderer Module, einschließlich Service-Contracts)

Für Extension-Module gilt:

-   contracts_used ist zulässig (einschließlich konsumierter
    Service-Contracts)

Der Paketmanager muss diese Regeln bei Installation, Aktivierung und
Update validieren und Verstöße blockieren.

## 24.8 Lizenzinformationen

Kostenpflichtige Module müssen Lizenzinformationen im Manifest
deklarieren.

Diese umfassen mindestens:

-   Lizenzpflicht ja/nein
-   Modulbezogene Lizenz-ID
-   Unterstützte Lizenzform (z.B. dauerhaft oder befristet)
-   Verhalten bei fehlender oder abgelaufener Lizenz

### 24.8.1 Verhalten bei fehlender oder ungültiger Lizenz

Ein kostenpflichtiges Modul darf ohne gültige Lizenz nicht aktiviert
werden.

Ist eine Lizenz abgelaufen oder wird ungültig, gilt:

-   Das Modul wird deaktiviert
-   Es erfolgt keine Datenlöschung
-   Resolver fallen auf Default-Verhalten zurück
-   Collector- und Event-Beiträge des Moduls werden nicht mehr
    berücksichtigt

## 24.9 Signaturprüfung

Jedes Modulpaket muss signiert sein. Die Signaturprüfung ist
verpflichtender Bestandteil von Installation und Update.

### 24.9.1 Regeln für Signaturen

-   Unsignierte Pakete dürfen nicht installiert werden.
-   Pakete mit ungültiger Signatur dürfen nicht installiert oder
    aktualisiert werden.
-   Die Signaturprüfung erfolgt vor dem Entpacken des Pakets.
-   Der Herausgeber des Moduls muss im Manifest angegeben sein.

### 24.9.2 Vertrauensanker und Schlüsselverwaltung

**Vertrauensanker.** Die Plattform liefert mit der Core-Auslieferung
einen oder mehrere vertrauenswürdige Wurzelschlüssel (Trust Anchor) des
Marketplace-Betreibers aus. Jede Paketsignatur wird gegen diese Wurzel
geprüft. Herausgeber von Drittanbieter-Modulen besitzen
Herausgeberschlüssel, deren Zertifikate über eine Signaturkette auf
einen Wurzelschlüssel zurückführbar sein müssen. Ein Paket gilt nur dann
als gültig signiert, wenn die Signaturkette lückenlos und gültig bis zu
einem aktiven Wurzelschlüssel reicht. Der im Manifest angegebene
Herausgeber (publisher) muss mit dem signierenden Herausgeberschlüssel
übereinstimmen.

**Schlüsselrotation.** Wurzel- und Herausgeberschlüssel besitzen eine
Gültigkeitsdauer (`valid_from`/`valid_to`). Dieses **Gültigkeitsfenster wird an
allen Verifikationspfaden durchgesetzt** (Paketinstallation, Vertrauenskette,
Lizenzprüfung): Ein Anker außerhalb seines Fensters wird abgewiesen. Eine
**gleitende Rotation** ist möglich — der neue Anker gilt ab sofort, der alte
läuft nach einem Überlappungsfenster aus, sodass beide während der Überlappung
akzeptiert werden (kein Ausfall). Aktualisierte Vertrauensanker bezieht der Core
über einen signierten Marketplace-Kanal (analog zum Metadatenabruf, Kapitel
28.5). Ein Schlüsselwechsel macht bereits installierte Module nicht
ungültig; maßgeblich ist die Gültigkeit der Signaturkette zum Zeitpunkt
der Installation oder des Updates.

**Widerruf (Revocation).** Der Marketplace stellt eine signierte
Sperrliste widerrufener Wurzel- und Herausgeberschlüssel bereit. Vor
jeder Installation und jedem Update prüft die Plattform diese Sperrliste.
Ist der signierende Schlüssel widerrufen, werden Installation und Update
blockiert.

**Sperrliste bei eingeschränkter Erreichbarkeit.** Ist die Sperrliste
nicht abrufbar, verwendet die Plattform die zuletzt zwischengespeicherte
Sperrliste und zeigt deren Alter an. Überschreitet das Alter eine
konfigurierte Schwelle, wird vor Installation oder Update gewarnt.

**Bereits installierte Module.** Wird der Signaturschlüssel eines
bereits installierten Moduls nachträglich widerrufen, wird das Modul im
Admin-Bereich deutlich als "Signatur widerrufen" gekennzeichnet und der
Administrator gewarnt. Eine automatische Deaktivierung erfolgt nicht,
und es erfolgt keine Datenlöschung.

### 24.9.3 Grundregel

Vertrauen entsteht ausschließlich über eine gültige, nicht widerrufene
Signaturkette bis zu einem aktiven Vertrauensanker. Die Prüfung von
Signaturkette und Sperrliste ist verpflichtender Bestandteil von
Installation und Update.

## 24.10 Installationsfluss

Die Installation eines Moduls erfolgt ausschließlich kontrolliert über
den Paketmanager.

Der Installationsfluss umfasst mindestens folgende Schritte:

1.  Auswahl des Moduls im Marketplace oder Bereitstellung des Pakets
2.  Laden des Paket-Metadatensatzes
3.  Signaturprüfung
4.  Manifest-Prüfung
5.  Core-Kompatibilitätsprüfung
6.  Main-Modul-Kompatibilitätsprüfung (falls Extension-Modul)
7.  Abhängigkeitsprüfung
8.  Konfliktprüfung für Resolver-Slots
9.  Lizenzprüfung (falls erforderlich)
10. Entpacken des Pakets
11. Registrierung des Moduls im System
12. Ausführung der Modulmigrationen
13. Aufbau der Registry-Einträge
14. Modul in Status "installiert, aber noch nicht aktiv" oder direkte
    Aktivierung, je nach Konfiguration

### 24.10.1 Grundregel

Die Installation darf nur erfolgen, wenn alle Prüfungen erfolgreich
abgeschlossen wurden. Teilinstallationen ohne konsistenten Abschluss
sind zu vermeiden.

## 24.11 Aktivierungsfluss

Nach erfolgreicher Installation kann ein Modul aktiviert werden.

Der Aktivierungsfluss umfasst mindestens:

1.  Prüfung des aktuellen Installationsstatus
2.  Erneute Lizenzprüfung (falls erforderlich)
3.  Validierung der deklarierten Registrierungen
4.  Resolver-Konfliktprüfung
5.  Aktivierung der Services
6.  Aktivierung von GUI- und Admin-Komponenten
7.  Aktivierung von API-Endpunkten
8.  Aktivierung der Registry-Einträge
9.  Protokollierung im Audit-Log

### 24.11.1 Resolver-Konfliktregel

Wenn ein Resolver-Slot bereits durch ein anderes aktives Modul belegt
ist, darf das neue Modul nicht parallel aktiviert werden. Das bestehende
Modul muss zuerst deaktiviert werden.

## 24.12 Deaktivierungsfluss

Ein Modul kann deaktiviert werden, ohne gelöscht zu werden.

Der Deaktivierungsfluss umfasst mindestens:

1.  Markierung des Moduls als inaktiv
2.  Deaktivierung aller Resolver-, Collector-, Event- und
    UI-Registrierungen
3.  Rückfall auf Default-Verhalten bei Resolvern
4.  Ausblenden der GUI-Erweiterungen des Moduls
5.  Beibehaltung aller Moduldaten
6.  Protokollierung im Audit-Log

### 24.12.1 Grundregel

Bei der Deaktivierung eines Moduls dürfen keine Daten gelöscht werden.

## 24.13 Updatefluss

Der Update-Mechanismus gilt für den Core und für Module. Ein Update
bezieht sich ausschließlich auf die Anwendung selbst, nicht auf die
darunterliegende Basisinfrastruktur wie PHP, PostgreSQL oder das
Betriebssystem. Kapitel 28 spezifiziert die Update-Mechanismen im
Detail (Core-Update, Modul-Update, Sicherheitsupdates, Wartungsmodus,
Marketplace-Kommunikation, atomarer Abschluss).

Der Updatefluss umfasst mindestens (generalisiertes Muster; die
konkreten Schrittfolgen für Core-Update und Modul-Update sind in
Kapitel 28.8 und 28.9 spezifiziert):

1.  Abruf verfügbarer Updates aus dem Marketplace
2.  Vergleich von installierter und verfügbarer Version
3.  Anzeige von Changelog und Kompatibilitätsinformationen
4.  Signaturprüfung des Update-Pakets
5.  Manifest- und Abhängigkeitsprüfung
6.  Contract-Versions- und Resolver-Konfliktprüfung
7.  Lizenzprüfung (falls erforderlich)
8.  Migrationsvorschau
9.  Aktivierung eines Wartungsmodus (sofern erforderlich)
10. Einspielen des Updates
11. Ausführung von Migrationen
12. Neuvalidierung aller Registrierungen
13. Protokollierung im Audit-Log
14. Beenden des Wartungsmodus

### 24.13.1 Grundregel

Updates dürfen nur dann durchgeführt werden, wenn die Zielversion mit
dem Core, dem Main-Modul und den registrierten Contracts kompatibel ist.

## 24.14 Löschfluss für Module

Die Löschung eines Moduls ist ein eigener, expliziter Vorgang und
strikt von der Deaktivierung zu unterscheiden.

Der Löschfluss umfasst mindestens:

1.  Auswahl des Moduls zur Löschung
2.  Anzeige aller Abhängigkeiten
3.  Anzeige aller betroffenen Datenbereiche
4.  Deutliche Warnung vor dem Vorgang
5.  Bestätigung durch den Administrator
6.  Deaktivierung des Moduls
7.  Entfernung der Registrierungen
8.  Optionaler Löschschritt für modulspezifische Daten (sofern
    vorgesehen)
9.  Entfernung der Paketdateien
10. Protokollierung im Audit-Log

### 24.14.1 Grundregel

Eine Modullöschung darf nur erfolgen, wenn keine blockierenden
Abhängigkeiten mehr bestehen oder diese bewusst aufgelöst wurden.

## 24.15 Kompatibilitätsprüfung

Vor Installation, Aktivierung und Update sind mindestens folgende
Kompatibilitäten zu prüfen:

-   Core-Version gegen Modulversion
-   Main-Modul-Version gegen Extension-Modul-Version
-   Contract-Version gegen registrierte Provider, Collector,
    Listener oder Service-Konsumenten
-   Lizenzstatus
-   Resolver-Slot-Konflikte
-   Deklarierte Modulabhängigkeiten

Die formale Kompatibilitätsregel (Versionsschema MAJOR.MINOR.PATCH und
Matching-Verfahren) ist in Kapitel 26.6.4 definiert.

### 24.15.1 Ergebnisdarstellung

Das Ergebnis der Kompatibilitätsprüfung ist dem Administrator
verständlich anzuzeigen. Es muss erkennbar sein: welche Bedingung
erfüllt ist, welche nicht erfüllt ist, welche Abhängigkeit die
Aktivierung blockiert und welches andere Modul einen Resolver-Slot
belegt.

## 24.16 Auditierbarkeit

Alle relevanten Modulvorgänge sind im Audit-Log zu protokollieren.

Dies umfasst mindestens:

-   Installation
-   Aktivierung
-   Deaktivierung
-   Update
-   Löschung
-   Lizenzfehler
-   Resolver-Konflikte
-   Fehlgeschlagene Signaturprüfungen
-   Widerrufene oder gesperrte Signaturschlüssel

### 24.16.1 Referenzrobustheit der Audit-Einträge

Audit-Einträge werden selbsterklärend gespeichert: Sie enthalten die zum
Zeitpunkt des Vorgangs gültigen Bezeichner (Modul-ID, Modulname,
Version, betroffene Objektkennung) als textuelle Kopie, nicht
ausschließlich als Fremdschlüssel auf lebende Datensätze. Wird ein Modul
später gelöscht (Kapitel 24.14) und werden seine Daten entfernt, bleiben
die zugehörigen Audit-Einträge vollständig lesbar und weisen das Modul
nachvollziehbar als entfernt aus. Das Audit-Log wird durch eine
Modullöschung nicht verändert oder bereinigt.

#### 24.16.1.1 Grundregel

Die Nachvollziehbarkeit eines Audit-Eintrags darf nicht davon abhängen,
dass das auslösende Modul oder das betroffene Objekt noch existiert.

## 24.17 Beispielhafte Modulmanifest-Inhalte

Ein Modulmanifest muss den Inhalt nicht in genau dieser Form abbilden,
aber fachlich mindestens die beschriebenen Informationen enthalten.

Beispielhafte Inhalte eines Main-Moduls: Modul-ID, Name, Version,
Typ = Main-Modul, Core-Kompatibilität, Entry Point, definierte
Contracts, BREAD-Ressourcen, Zusatzaktionen, Migrationshinweis,
Signatur.

Beispielhafte Inhalte eines Extension-Moduls: Modul-ID, Name, Version,
Typ = Extension-Modul, erweitert Main-Modul X, Main-Modul-
Kompatibilität, registrierte Resolver/Collector/Events, Lizenzpflicht
ja/nein, Signatur, Migrationshinweis.

## 24.18 Nebenläufigkeit von Lifecycle-Operationen

Lifecycle-verändernde Operationen (Installation, Aktivierung,
Deaktivierung, Update, Löschung von Core oder Modulen) verändern
Registry-, Migrations- und Paketzustand und dürfen niemals nebenläufig
zueinander laufen. Die Plattform serialisiert sie über einen exklusiven
Lifecycle-Lock, der als PostgreSQL-Advisory-Lock realisiert wird und
damit auch über mehrere Anwendungsknoten an derselben Datenbank hinweg
wirkt (mehrknotenfähig, siehe Kapitel 30.7).

-   Solange eine Lifecycle-Operation läuft, wird eine konkurrierende
    Lifecycle-Operation abgewiesen, mit klarem Hinweis auf die laufende
    Operation.
-   Der Lock wird auch bei Abbruch oder Fehler kontrolliert freigegeben,
    sodass keine dauerhafte Blockade entsteht.
-   Reguläre fachliche Nutzung der Module ist davon nicht betroffen; der
    Lock gilt nur für lifecycle-verändernde Vorgänge und ergänzt den
    optionalen Wartungsmodus aus Kapitel 28.11.

### 24.18.1 Grundregel

Zu jedem Zeitpunkt darf höchstens eine lifecycle-verändernde Operation
aktiv sein. Damit kann kein nebenläufig erzeugter inkonsistenter Zustand
entstehen (konsistent mit Kapitel 28.13).

# 25. BREAD, Ressourcenmodell und Gruppenzuordnung

## 25.1 Zielsetzung

Für Anwendungsmodule wird ein generisches Berechtigungsmodell auf Basis
von BREAD eingeführt. Ziel ist es, Ressourcen eines Main-Moduls oder
Extension-Moduls gruppenbasiert abzusichern, ohne das Rechtesystem des
Core zu erweitern oder zu überladen.

Hinweis zur Abgrenzung: Dieses Kapitel beschreibt das BREAD-Modell und
Ressourcenmodell aus Modulsicht. Das plattformweite Identitätsmodell
(Benutzer, Gruppen, Rollen, Lifecycle) ist in Kapitel 27 beschrieben.

Das Berechtigungsmodell dient insbesondere folgenden Zielen:

-   Einheitliche Absicherung fachlicher Ressourcen
-   Zuordnung mehrerer Gruppen zu derselben Ressource
-   Wiederverwendung derselben Gruppen über mehrere Module hinweg
-   Klare Trennung zwischen Core-Administration und Modulberechtigungen
-   Vererbung und Erweiterung im Verhältnis Main-Modul zu
    Extension-Modul
-   Einfache und nachvollziehbare Rechteaggregation

## 25.2 Geltungsbereich

Das in diesem Kapitel beschriebene Berechtigungsmodell gilt
ausschließlich für Anwendungsmodule.

Es gilt nicht für:

-   Den Core
-   Den Core-Adminbereich
-   Die technische Modulverwaltung
-   Die Marketplace- und Lizenzverwaltung
-   Die Core-Konfiguration

### 25.2.1 Grundregel

Im Core gilt weiterhin: Core-Administrationszugriff ergibt sich aus den
zugewiesenen Administrationsbereichen (Volladministrator = alle, Kapitel
27.3.1), es gibt kein BREAD für Core-Funktionen, Core-Konfigurationsobjekte
werden aktiviert und deaktiviert, nicht über BREAD verwaltet.

## 25.3 BREAD-Grundmodell

Für alle durch Module definierten Ressourcen können folgende
Standardrechte verwendet werden:

| **Kürzel** | **Bedeutung** |
| --- | --- |
| B | Browse |
| R | Read |
| A | Add |
| E | Edit |
| D | Delete |

### 25.3.1 Semantik der Standardrechte

Die Bedeutung der BREAD-Rechte ist modulübergreifend einheitlich
definiert.

**Browse:** Das Recht, Objekte einer Ressource in Listen, Übersichten
oder Suchergebnissen zu sehen. Beispiele: Tickets in einer Ticketliste
sehen, Wissensartikel in einer Übersicht sehen, SLA-Kalender in einer
Administrationsliste eines Moduls sehen.

**Read:** Das Recht, die Detailansicht eines einzelnen Objekts zu
sehen. Beispiele: ein Ticket öffnen, einen Wissensartikel im Detail
lesen, einen Feiertagskalender im Detail einsehen.

**Add:** Das Recht, neue Objekte einer Ressource anzulegen. Beispiele:
ein neues Ticket erstellen, einen neuen Wissensartikel anlegen, einen
neuen SLA-Kalender anlegen.

**Edit:** Das Recht, bestehende Objekte einer Ressource zu ändern.
Beispiele: Ticketdaten ändern, einen Wissensartikel bearbeiten, einen
SLA-Kalender ändern.

**Delete:** Das Recht, eine vom Modul definierte Löschaktion auf
Anwendungsdaten auszuführen. Die fachliche Bedeutung von Delete wird
vom jeweiligen Modul selbst bestimmt. Dazu können insbesondere gehören:
Soft-Delete, Hard-Delete oder modulspezifische Löschlogik.

### 25.3.2 Grundregel

Die konkrete fachliche Semantik von Delete auf Anwendungsdaten ist vom
jeweiligen Modul explizit zu definieren und zu dokumentieren.

## 25.4 Ressourcenmodell

Jedes Main-Modul und jedes Extension-Modul kann eigene Ressourcen
definieren. Eine Ressource ist ein fachlich berechtigbares Objekt oder
eine berechtigbare Objektklasse.

Beispiele für Ressourcen: Queue, Ticket, Wissensartikel, SLA-Kalender,
Feiertagsliste, Reporting-Definition, Artikelkategorie, API-Endpunkt
eines Moduls.

### 25.4.1 Anforderungen an Ressourcen

Eine Ressource muss mindestens eindeutig identifizierbar sein durch:

-   Modul-ID
-   Ressourcenname
-   Ressourcentyp
-   Optional ein konkretes Objekt oder eine Objektklasse

### 25.4.2 Ressourcentypen

| **Typ** | **Beschreibung** |
| --- | --- |
| Objektklasse | Rechte gelten für alle Objekte dieser Art |
| Einzelobjekt | Rechte gelten für ein bestimmtes Objekt |
| Bereichsressource | Rechte gelten für einen fachlichen Bereich ohne konkrete Einzelobjekte |

Beispiele: ticketing.ticket als Objektklasse, ticketing.queue:Support
als Einzelobjekt, reporting.dashboard als Bereichsressource.

## 25.5 Gruppenzuordnung

Ressourcen werden Gruppen zugeordnet. Eine Gruppe kann für eine
Ressource bestimmte BREAD-Rechte und moduldefinierte Zusatzaktionen
erhalten.

Dabei gilt:

-   Mehrere Gruppen können derselben Ressource zugeordnet werden
-   Dieselbe Gruppe kann mehreren Ressourcen in verschiedenen Modulen
    zugeordnet werden
-   Ein Benutzer kann Mitglied in mehreren Gruppen sein

### 25.5.1 Grundregel

Die Gruppenzuordnung ist generisch und nicht auf Queues beschränkt.
Jedes Modul entscheidet selbst, welche seiner Ressourcen gruppenfähig
sind.

## 25.6 Rechteaggregation

Die effektiven Rechte eines Benutzers ergeben sich aus der Vereinigung
aller Rechte, die ihm über seine Gruppenmitgliedschaften auf eine
Ressource zugewiesen sind.

### 25.6.1 Regeln für die Aggregation

-   Rechte werden additiv vereinigt
-   Es gibt keine Deny-Regeln
-   Es gibt keine Prioritäten zwischen Gruppen
-   Es gibt keine Konfliktlogik zwischen Gruppen
-   Ein vorhandenes Recht wird durch eine weitere Gruppe nicht
    eingeschränkt

### 25.6.2 Beispiel

Wenn Gruppe A auf Ressource ticketing.ticket die Rechte B und R besitzt
und Gruppe B auf dieselbe Ressource die Rechte E und D, dann erhält ein
Benutzer, der Mitglied beider Gruppen ist, effektiv die Rechte B, R, E
und D.

### 25.6.3 Ausschlüsse durch Ressourcen-Schnitt

Da das Modell keine Deny-Regeln kennt (Kapitel 25.6.1), werden
Ausschlüsse nicht durch ein Entzugsrecht ausgedrückt, sondern durch den
Schnitt der Ressourcen. Soll eine Teilmenge von Objekten nur für
bestimmte Gruppen sichtbar sein, wird diese Teilmenge als eigene
Ressource modelliert und nur den berechtigten Gruppen zugeordnet. Für
zeilenbezogene Sichtbarkeit und als Defense-in-Depth setzt die Plattform
zusätzlich Row-Level Security ein (Kapitel 30.3); RLS ist zugleich der
designierte Mechanismus für künftiges feingranulares Row-Scoping.

Beispiel: Sollen sensible Tickets nicht für alle Mitglieder einer Queue
sichtbar sein, werden sie in eine eigene Queue (eigene Ressource)
geführt, die nur den berechtigten Gruppen zugeordnet ist. Ein generelles
"darf X nicht sehen" über eine Deny-Regel ist nicht vorgesehen.

#### 25.6.3.1 Grundregel

Ausschlüsse werden über die Modellierung von Ressourcen gelöst
(Ressourcen-Schnitt), nicht über Deny-Regeln. Die additive
Rechteaggregation (Kapitel 25.6) bleibt davon unberührt.

## 25.7 Zusatzaktionen

Neben den BREAD-Grundrechten darf ein Modul zusätzliche,
fachspezifische Aktionen definieren. Beispiele: assign, change_status,
reply, merge, hard_delete, restore, test_connection, publish, archive.

### 25.7.1 Grundregel

Zusatzaktionen sind moduldefiniert und gelten nur innerhalb des
jeweiligen Moduls oder der jeweiligen Ressource. Sie erweitern das
BREAD-Modell, verändern aber nicht die Grundlogik des Core.

### 25.7.2 Deklaration

Ein Modul muss im Manifest oder in seiner Ressourcenbeschreibung
deklarieren: welche Zusatzaktionen existieren, auf welche Ressourcen
sie anwendbar sind und ob sie optional oder verpflichtend prüfbar sind.

## 25.8 Main-Module und Berechtigungen

Ein Main-Modul definiert seine Ressourcen und legt fest, welche davon
gruppenfähig sind.

Ein Main-Modul kann insbesondere: Ressourcen definieren, BREAD-Rechte
auf diese Ressourcen anwenden, Zusatzaktionen definieren und
Gruppenzuordnungen für diese Ressourcen ermöglichen.

### 25.8.1 Grundregel

Ein Main-Modul ist vollständig selbst für die Definition seiner
Ressourcen und deren BREAD-Relevanz verantwortlich.

## 25.9 Extension-Module und Berechtigungen

Ein Extension-Modul darf eigene Ressourcen definieren und diese Gruppen
zuordnen. Diese Gruppen können dieselben Gruppen sein wie beim
Main-Modul, aber auch andere. Ein Extension-Modul kann damit eigene
berechtigbare Objekte einführen, ohne eine eigene Rechte-Domain im
Core aufzubauen.

### 25.9.1 Zulässige Fähigkeiten eines Extension-Moduls

Ein Extension-Modul darf:

-   Eigene Ressourcen definieren
-   Eigene BREAD-Rechte auf diese Ressourcen anwenden
-   Eigene Zusatzaktionen definieren
-   Gruppen auf diese Ressourcen mappen

Ein Extension-Modul darf nicht:

-   Das Core-Rechtesystem verändern
-   Neue globale Rollenmodelle definieren
-   Das Aggregationsmodell ändern
-   Eine alternative Rechteauflösung einführen

### 25.9.2 Verhältnis zum Main-Modul

Ein Extension-Modul erweitert fachlich genau ein Main-Modul. Es kann
dabei: dieselben Gruppen wie das Main-Modul verwenden, andere Gruppen
für seine eigenen Ressourcen zulassen, die Rechteprüfung jedoch nur
innerhalb des vom Core vorgegebenen BREAD- und Zusatzaktionsmodells
durchführen.

## 25.10 Rechtevererbung im Verhältnis Main-Modul zu Extension-Modul

Es gibt keine automatische fachliche Rechtevererbung im Sinne einer
impliziten Freischaltung aller Extension-Ressourcen durch das
Main-Modul.

Stattdessen gilt:

-   Das Main-Modul definiert seine eigenen Ressourcen
-   Das Extension-Modul definiert seine eigenen Ressourcen
-   Gruppen können auf Ressourcen des Main-Moduls und des
    Extension-Moduls jeweils separat berechtigt werden

### 25.10.1 Praktische Nutzung

In der Praxis können dabei dieselben Gruppen verwendet werden wie im
Main-Modul. Dies ist jedoch eine Konfigurationsentscheidung des
jeweiligen Moduls und keine implizite technische Vererbung.

### 25.10.2 Grundregel

Ein Extension-Modul darf keine unsichtbare oder stillschweigende
Rechteausweitung erzeugen. Wenn seine Ressourcen abgesichert werden
sollen, müssen diese Ressourcen explizit gruppenbezogen konfiguriert
werden.

## 25.11 Gruppenfähige und nicht gruppenfähige Ressourcen

Ein Modul darf selbst entscheiden, welche Ressourcen gruppenfähig sind
und welche nicht.

### 25.11.1 Gruppenfähige Ressourcen

Für gruppenfähige Ressourcen gelten: sie können Gruppen zugeordnet
werden, sie können mit BREAD und Zusatzaktionen abgesichert werden und
sie erscheinen in der Gruppen- und Rechteverwaltung des Moduls.

### 25.11.2 Nicht gruppenfähige Ressourcen

Nicht gruppenfähige Ressourcen werden nicht in die gruppenbasierte
Berechtigungslogik aufgenommen. Ihre Zugriffslogik ist dann vom Modul
selbst zu definieren, muss aber weiterhin mit dem Core-Modell
kompatibel bleiben.

### 25.11.3 Grundregel

Ein Modul darf gruppenunfähige Ressourcen nur dann definieren, wenn
deren Zugriffsverhalten klar dokumentiert und fachlich begründet ist.

## 25.12 Darstellung im Admin-Bereich

Module mit gruppenfähigen Ressourcen müssen eine geeignete
Administrationsoberfläche bereitstellen, in der Gruppen diesen
Ressourcen zugeordnet werden können.

Die Darstellung muss mindestens ermöglichen:

-   Auswahl der Ressource
-   Auswahl einer oder mehrerer Gruppen
-   Zuweisung von BREAD-Rechten
-   Zuweisung von Zusatzaktionen
-   Einsicht in bestehende Zuordnungen

### 25.12.1 Zulässige Darstellungsformen

Abhängig vom Modul sind insbesondere zulässig: Matrixansicht,
Detailformular pro Ressource, Detailformular pro Gruppe, hierarchische
Zuordnungsansicht.

### 25.12.2 Grundregel

Die Darstellung der Rechtezuordnung ist Sache des Moduls. Der Core
schreibt nur das Berechtigungsmodell vor, nicht die konkrete
Bedienoberfläche.

## 25.13 Rechteprüfung zur Laufzeit

Die Rechteprüfung auf Ressourcen eines Moduls muss zur Laufzeit
serverseitig erfolgen.

### 25.13.1 Grundregel

Ein Modul darf Rechte niemals ausschließlich im Frontend prüfen.
Sichtbarkeit in der GUI und tatsächliche serverseitige Berechtigung
müssen übereinstimmen, aber die serverseitige Prüfung ist maßgeblich.

### 25.13.2 Prüfobjekt

Die Rechteprüfung basiert auf: Benutzer, Gruppenmitgliedschaften,
Ressource und angefragtem Recht oder angefragter Zusatzaktion.

## 25.14 Auditierbarkeit

Änderungen an gruppenbezogenen Berechtigungen eines Moduls sind im
Audit-Log zu protokollieren.

Dies umfasst mindestens:

-   Hinzufügen einer Gruppe zu einer Ressource
-   Entfernen einer Gruppe von einer Ressource
-   Änderung von BREAD-Rechten
-   Änderung von Zusatzaktionen

## 25.15 Beispiel: Ticketing

### 25.15.1 Ressourcen des Main-Moduls Ticketing

Beispiele für gruppenfähige Ressourcen im Main-Modul Ticketing: Queue,
Ticket, gespeicherte Filter, Reporting-Definitionen des Ticketmoduls.

Beispielhafte Zusatzaktionen: assign, change_status, reply, merge,
hard_delete, restore.

### 25.15.2 Ressourcen des Extension-Moduls SLA-Kalender

Beispiele für gruppenfähige Ressourcen: SLA-Kalender,
Ausnahmetag-Liste, Geschäftszeitfenster.

Beispielhafte Zusatzaktionen: assign_calendar, calculate_preview.

### 25.15.3 Ressourcen des Extension-Moduls Feiertagskalender

Beispiele für gruppenfähige Ressourcen: Feiertagsquelle,
Feiertagsdefinition, Regionenzuordnung.

Beispielhafte Zusatzaktionen: import_holidays, refresh_holidays.

## 25.16 Architekturprinzipien für das Berechtigungsmodell

Für das Berechtigungsmodell gelten folgende verbindliche Leitregeln:

-   BREAD gilt ausschließlich für Anwendungsmodule.
-   Der Core verwendet kein BREAD-Rechtesystem.
-   Rechte werden über Gruppen vergeben.
-   Ein Benutzer kann Mitglied in mehreren Gruppen sein.
-   Effektive Rechte ergeben sich aus der Vereinigung aller
    Gruppenrechte.
-   Es gibt keine Deny-Regeln und keine Prioritäten zwischen Gruppen.
-   Module definieren selbst ihre Ressourcen und Zusatzaktionen.
-   Extension-Module dürfen eigene Ressourcen definieren, aber keine
    eigene Rechte-Domain im Core aufbauen.
-   Rechteprüfungen müssen immer serverseitig erfolgen.
-   Änderungen an Berechtigungen sind auditierbar.

# 26. Contracts, Resolver, Collector und Events

## 26.1 Zielsetzung

Zur kontrollierten Erweiterbarkeit des Systems werden
modulübergreifende Erweiterungspunkte nicht als unscharfe "Hooks",
sondern als formale, versionierte Contracts definiert. Dadurch wird
sichergestellt, dass Erweiterungen nachvollziehbar, validierbar,
kompatibilitätsprüfbar und zur Laufzeit beherrschbar bleiben.

Die Contract-Architektur verfolgt insbesondere folgende Ziele:

-   Klare Trennung zwischen definierendem Modul und erweiterndem Modul
-   Formale Definition von Ein- und Ausgaben
-   Kontrollierte Erweiterung über Resolver, Collector und Events
-   Garantierte Lauffähigkeit auch ohne aktive Provider
-   Eindeutige Registrierung und Sichtbarkeit im Admin-Bereich
-   Sichere Update- und Kompatibilitätsprüfung

## 26.2 Grundbegriffe

Zur Vermeidung von Unschärfen gelten folgende Begriffe verbindlich:

| **Begriff** | **Bedeutung** |
| --- | --- |
| Contract | Formal definierte, versionierte Schnittstelle |
| Resolver | Contract-Typ, der genau ein Ergebnis liefert |
| Collector | Contract-Typ, der mehrere Beiträge sammelt |
| Event | Contract-Typ, der ein Ereignis beschreibt |
| Service-Contract (Request/Response) | Contract-Typ, bei dem das Owner-Modul eine aufrufbare Schnittstelle mit Rückgabewert bereitstellt; Grundlage öffentlicher Modul-Interfaces (Kapitel 29) |
| Provider | Konkrete Implementierung eines Resolver-Contracts |
| Collector-Beitrag | Konkrete Implementierung eines Collector-Contracts |
| Listener | Konkrete Implementierung eines Event-Contracts |
| Service-Nutzer | Modul, das einen Service-Contract (öffentliches Modul-Interface) konsumiert |
| Default-Verhalten | Verhalten eines Contracts, wenn kein aktiver Provider registriert ist |
| Slot | Eindeutiger registrierbarer Erweiterungspunkt, insbesondere bei Resolvern |

## 26.3 Contract-Typen

Das System kennt vier technische Contract-Typen. Allen liegt ein
einheitliches Capability-Modell zugrunde: Eine Capability hat eine
Richtung (welche Seite die Implementierung bereitstellt) und eine
Kardinalität (ein Ergebnis, mehrere Beiträge oder fire-and-forget ohne
Rückgabewert).

| **Richtung \ Kardinalität** | **Ein Ergebnis** | **Mehrere Beiträge** | **Fire-and-forget** |
| --- | --- | --- | --- |
| Externe stellen bereit (Owner konsumiert) | Resolver | Collector | — |
| Owner stellt bereit (Externe konsumieren) | Request/Response (Service) | — | Event |

Resolver, Collector und Event sind die Erweiterungsmechanismen, bei
denen das Owner-Modul einen Punkt definiert und externe Module ihn
besetzen. Der Request/Response-Typ kehrt die Richtung um: Das
Owner-Modul stellt die Implementierung bereit, andere Module rufen sie
auf. Öffentliche Modul-Interfaces (Kapitel 29) sind die Anwendung dieses
vierten Typs für die modulübergreifende Integration.

### 26.3.1 Resolver

Resolver liefern genau ein Ergebnis.

Beispiele: Berechnung einer SLA-Frist, Bestimmung eines
CAPTCHA-Providers, Ermittlung einer Feiertagsmenge, Ableitung einer
Zusatzkonfiguration.

Eigenschaften von Resolvern:

-   Genau ein Contract
-   Genau ein Ergebnis
-   Genau ein aktiver Provider pro Resolver-Slot
-   Verpflichtendes Default-Verhalten
-   Grundlauffähigkeit auch ohne aktiven Provider

### 26.3.2 Collector

Collector sammeln mehrere Beiträge.

Beispiele: Zusätzliche UI-Blöcke, zusätzliche Exportspalten,
zusätzliche Dashboard-Widgets, ergänzende Datenquellen.

Eigenschaften von Collectors:

-   Mehrere Beiträge erlaubt
-   Deaktivierte Module werden ignoriert
-   Definiertes Leerergebnis bei fehlenden Beiträgen

### 26.3.3 Events

Events signalisieren, dass etwas passiert ist.

Beispiele: Ticket wurde erstellt, Ticket wurde geschlossen, Modul
wurde aktiviert, E-Mail wurde versendet.

Eigenschaften von Events:

-   Mehrere Listener erlaubt
-   Deaktivierte Module werden ignoriert
-   Asynchrone Zustellung über einen transaktionalen Outbox (Kapitel
    26.9.2); fire-and-forget ohne Rückgabewert
-   Mindestens-einmal-Zustellung; Listener müssen idempotent sein
-   Listener dürfen Nebenwirkungen auslösen, aber keine Pflichtlogik
    herstellen

### 26.3.4 Request/Response (Service)

Bei einem Service-Contract stellt das Owner-Modul selbst die
Implementierung bereit; andere Module konsumieren sie über einen Aufruf
mit Rückgabewert.

Beispiele: Lese- oder Suchzugriff auf ein Wissensdatenbank-Modul,
Abfrage eines Stammdatensatzes aus einem CRM-Modul.

Eigenschaften von Service-Contracts:

-   Anbieter ist das Owner-Modul (im Gegensatz zu Resolver und
    Collector, bei denen externe Module bereitstellen).
-   Request/Response mit definiertem Rückgabewert (im Gegensatz zum
    Event, das fire-and-forget ohne Rückgabewert ist).
-   Mehrere Konsumenten sind erlaubt (Standard); das Owner-Modul kann
    die Nutzung exklusiv beschränken (Kapitel 29.8.1).
-   Öffentliche Modul-Interfaces (Kapitel 29) sind die Anwendung dieses
    Contract-Typs für die modulübergreifende Integration.

## 26.4 Aufbau eines Contracts

Jeder Contract muss formal beschrieben und versioniert sein. Ein
Contract ist keine lose Konvention, sondern ein technischer Vertrag.

| **Feld** | **Beschreibung** |
| --- | --- |
| Name | Eindeutiger technischer Contract-Name |
| Typ | Resolver, Collector oder Event |
| Version | Contract-Version |
| Owner-Modul | Modul, das den Contract definiert |
| Beschreibung | Fachliche und technische Kurzbeschreibung |
| Input-Spezifikation | Definition der Eingabestruktur |
| Output-Spezifikation | Definition der Ausgabestruktur |
| Default-Semantik | Verhalten ohne aktiven Provider |
| Provider-Regel | Anzahl zulässiger Registrierungen |
| Fehlerverhalten | Regel bei Laufzeitfehlern |

### 26.4.1 Grundregel

Ein Contract ist nur dann gültig, wenn Input, Output und
Default-Verhalten eindeutig und maschinenlesbar beschrieben sind.

## 26.5 Interface-Spezifikation

Für jeden Contract muss klar definiert sein: was übergeben wird, was
erwartet wird, in welcher Form das Ergebnis zurückgegeben wird und wie
das Default-Verhalten aussieht.

### 26.5.1 Formale Anforderung

Jeder Contract muss eine formale Interface-Spezifikation besitzen.
Diese kann technisch in Form von Interface-Klassen, DTOs oder einer
gleichwertigen maschinenlesbaren Spezifikation umgesetzt werden.

### 26.5.2 Anforderungen an Input und Output

Input und Output eines Contracts müssen typisiert und dokumentiert
sein. Die Übergabe freier, unstrukturierter Daten ohne definierte
Spezifikation ist nicht zulässig.

Der Contract muss mindestens festlegen:

-   Name und Typ jedes Eingabefeldes
-   Pflicht-/Optional-Status der Eingabefelder
-   Name und Typ jedes Ausgabefeldes
-   Bedeutung von NULL, sofern zulässig
-   Bedeutung einer leeren Liste oder eines leeren Ergebnisobjekts,
    sofern zulässig

### 26.5.3 Default-Verhalten als Teil des Contracts

Das Default-Verhalten ist verpflichtender Bestandteil jedes Resolver-
und Collector-Contracts. Es wird vom definierenden Modul festgelegt.

Mögliche Default-Verhalten sind insbesondere: konkreter Wert, NULL,
leere Liste, leeres Ergebnisobjekt oder anderes fachlich neutrales
Ergebnis.

### 26.5.4 Grundregel

Ein Prozess darf niemals darauf angewiesen sein, dass ein externer
Provider aktiv ist. Wenn ein Prozess ohne aktiven Provider nicht
korrekt läuft, ist der Prozess fachlich oder technisch falsch
geschnitten.

## 26.6 Versionierung von Contracts

Jeder Contract ist unabhängig vom Modul selbst versioniert.

### 26.6.1 Ziel der Versionierung

Die Versionierung dient dazu: Änderungen an Input oder Output
kontrolliert zu machen, Kompatibilitätsprüfungen bei Modulinstallation
und Update zu ermöglichen und Breaking Changes eindeutig zu
kennzeichnen.

### 26.6.2 Grundregel

Eine Registrierung auf einen Contract ist nur zulässig, wenn die
deklarierte Contract-Version kompatibel ist.

### 26.6.3 Änderungsklassen

| **Änderungsart** | **Bedeutung** |
| --- | --- |
| Patch | Keine fachliche Änderung, nur Korrekturen oder Dokumentationsanpassungen |
| Minor | Abwärtskompatible Erweiterung |
| Major | Nicht abwärtskompatible Änderung |

## 26.6.4 Versionsschema und Kompatibilitätsregel

Alle Versionen (Core, Module, Contracts, öffentliche Modul-Interfaces)
folgen dem Schema MAJOR.MINOR.PATCH (Semantic Versioning), konsistent
mit den Änderungsklassen aus Kapitel 26.6.3.

**Deklaration geforderter Versionen.** Eine geforderte Version wird als
exakte Version oder als Versionsbereich angegeben. Zulässige
Schreibweisen:

-   Exakt: 2.3.1
-   Bereich mit Vergleichsoperatoren: >=2.1.0 <3.0.0

Kurzformen (z.B. Caret- oder Tilde-Notation) sind nicht zulässig.
Bereiche müssen immer mit expliziten Vergleichsoperatoren angegeben
werden.

**Kompatibilitätsregel für Contracts und öffentliche Modul-Interfaces.**
Eine Registrierung oder Nutzung, die Version A.B.C fordert, ist mit
einem Anbieter der Version X.Y.Z genau dann kompatibel, wenn:

-   X = A (gleiche Major-Version) und
-   der Anbieter mindestens so neu ist wie gefordert: Y > B oder
    (Y = B und Z >= C).

Begründung über die Änderungsklassen (Kapitel 26.6.3): Eine
Major-Erhöhung ist nicht abwärtskompatibel und bricht die
Kompatibilität. Eine Minor-Erhöhung ist abwärtskompatibel – ein gegen
eine ältere Minor entwickelter Nutzer läuft gegen eine neuere Minor
weiter. Ein Patch ändert die Schnittstelle fachlich nicht.

**Kompatibilitätsregel für Core- und Main-Modul-Versionen.**
core_compatibility und main_module_compatibility werden als
Versionsbereich angegeben. Installation, Aktivierung und Update sind nur
zulässig, wenn die tatsächliche Core- bzw. Main-Modul-Version innerhalb
des deklarierten Bereichs liegt.

### 26.6.4.1 Grundregel

Kompatibilität ist immer eine maschinell auswertbare Bereichsprüfung.
"Kompatibel" bedeutet ausschließlich: gleiche Major-Version und
Anbieterversion größer oder gleich der geforderten Version innerhalb
dieser Major.

## 26.7 Resolver-Slots

Jeder Resolver wird über einen Resolver-Slot adressiert. Ein
Resolver-Slot ist ein eindeutiger, registrierbarer Erweiterungspunkt.

### 26.7.1 Regeln für Resolver-Slots

-   Pro Resolver-Slot darf genau ein aktiver Provider existieren
-   Ein zweiter Provider für denselben Slot darf nicht parallel
    aktiviert werden
-   Wenn ein anderes Modul denselben Slot belegen soll, muss das
    bisherige Modul deaktiviert werden
-   Ohne aktiven Provider greift das definierte Default-Verhalten
-   Die Slot-Exklusivität wird zusätzlich durch ein partielles
    Unique-Constraint in der Datenbank erzwungen (Kapitel 30.2)

### 26.7.2 Grundregel

Resolver-Slots sind exklusiv.

## 26.8 Verhalten von Collectors

### 26.8.1 Regeln für Collectors

-   Mehrere Beiträge sind zulässig
-   Deaktivierte Module werden ignoriert
-   Ein Collector liefert ohne aktive Beiträge das definierte
    Leerergebnis
-   Die Reihenfolge der Beiträge muss definiert oder für den Contract
    als unerheblich markiert sein

### 26.8.2 UI-Beiträge: View-Models und Ausgabekodierung

UI-Beiträge eines Moduls (Collector-Beiträge vom Typ UI sowie
registrierte ui_extensions, Kapitel 24.4.3) werden in Templates des Core
oder anderer Module eingebettet.

UI-Beiträge werden als strukturierte View-Models bzw. deklarative
UI-Deskriptoren bereitgestellt (Daten plus Verweis auf eine vom Core
bereitgestellte Slot- oder Komponentendefinition). Module liefern damit
Daten und Struktur, nicht fertiges Markup; die Markup-Erzeugung liegt
beim Core. Dadurch entfällt modulübergreifendes Roh-HTML als Regelfall,
die XSS-Fläche wird konstruktiv vermieden und das Erscheinungsbild
(Bootstrap) bleibt über alle Module konsistent.

Für die sichere Darstellung gilt darüber hinaus:

-   Dynamische Werte werden vom Core kontextkorrekt kodiert
    (HTML-Escaping über das Templating- und Auto-Escaping-System).
-   Die Absicherung obliegt der einbettenden Seite (dem Empfänger): Der
    Core-Renderer setzt das Auto-Escaping als Default durch und behandelt
    von Modulen gelieferte Werte grundsätzlich als nicht
    vertrauenswürdig. So bleibt die Ausgabe auch dann sicher, wenn ein
    beitragendes Modul fehlerhaft oder bösartig ist.
-   Roh-HTML ist nicht das Beitragsformat. Liefert ein Modul in einem
    begründeten Sonderfall dennoch Markup, ist dies nur über ein
    explizites, dokumentiertes Opt-out möglich; das Auto-Escaping des
    Core und dessen Durchsetzungspflicht bleiben unberührt.

Empfehlung (Betreiber): Eine restriktive Content-Security-Policy (CSP)
als zusätzliche Verteidigungsschicht gegen XSS wird empfohlen.

### 26.8.2.1 Grundregel

UI-Beiträge werden als strukturierte View-Models bereitgestellt; das
Markup erzeugt der Core. Die Ausgabesicherheit wird durch die
einbettende Core-Renderschicht durchgesetzt, nicht durch das beitragende
Modul. Roh-HTML ist kein Regelbeitrag, sondern eine explizite,
dokumentierte und abgeratene Ausnahme.

## 26.9 Verhalten von Events

### 26.9.1 Regeln für Events

-   Mehrere Listener sind zulässig
-   Deaktivierte Module werden ignoriert
-   Listener dürfen Nebenwirkungen auslösen
-   Listener dürfen die Grundfunktion des auslösenden Prozesses nicht
    zur Pflicht machen

### 26.9.2 Transaktionaler Outbox und asynchrone Zustellung

Events werden nicht synchron im auslösenden Request an die Listener
zugestellt, sondern über einen transaktionalen Outbox asynchron
verarbeitet.

-   **Transaktionaler Outbox.** Das Auslösen eines Events schreibt einen
    Event-Datensatz innerhalb derselben Datenbanktransaktion wie die
    auslösende fachliche Änderung in eine Outbox-Tabelle. Entweder werden
    fachliche Änderung und Event gemeinsam committet, oder keines von
    beiden. Dadurch entstehen weder verlorene noch grundlose Events.
-   **Asynchrone Verarbeitung.** Ein Worker (CLI-Command per Cronjob,
    analog zu process_email_queue) liest den Outbox und stellt die Events
    an die registrierten Listener zu. Der auslösende Prozess endet, ohne
    auf die Listener zu warten. Zur latenzarmen Zustellung wird der Worker
    zusätzlich über PostgreSQL LISTEN/NOTIFY benachrichtigt; der
    periodische Cron-Lauf bleibt Fallback (Kapitel 30.6).
-   **Mindestens-einmal-Zustellung.** Ein Event kann einem Listener mehr
    als einmal zugestellt werden. Listener müssen idempotent sein.
-   **Isolierte Fehler und Retry.** Der Fehler eines Listeners blockiert
    weder die übrigen Listener noch den auslösenden Prozess.
    Fehlgeschlagene Zustellungen werden mit Backoff wiederholt; nach
    Ausschöpfung der Versuche gehen sie in einen Fehler- bzw.
    Dead-Letter-Zustand über. Der Admin-Bereich zeigt die Dead-Letter-Events
    nicht nur an, sondern erlaubt pro Event ein **erneutes Einstellen** (Retry)
    oder ein **Verwerfen** (sowie „alle wiedereinstellen"); diese Aktionen werden
    auditiert.

### 26.9.3 Grundregel

Ein Event dient der asynchronen Reaktion auf einen Prozess, nicht der
Herstellung seiner Grundlauffähigkeit. Die Entkopplung über den Outbox
ist strukturell garantiert, nicht nur eine Disziplinregel. Wer eine
synchrone Antwort im Request benötigt, verwendet einen Resolver oder
Service-Contract, kein Event.

## 26.10 Registrierung von Contracts

Contracts müssen durch das definierende Modul ausdrücklich registriert
werden. Die Registrierung umfasst mindestens: Contract-Name,
Contract-Typ, Version, Owner-Modul, Input-Spezifikation,
Output-Spezifikation, Default-Semantik und Provider-Regel.

### 26.10.1 Grundregel

Nur registrierte Contracts gelten als öffentlich nutzbar. Nicht
registrierte interne Erweiterungspunkte eines Moduls sind nicht für
andere Module freigegeben.

## 26.11 Registrierung von Providern, Collector-Beiträgen und Listenern

Ein Modul, das einen Contract nutzt, muss seine Registrierung
ausdrücklich deklarieren. Die Registrierung umfasst mindestens:
Ziel-Contract, unterstützte Contract-Version, implementierende Klasse,
Registrierungsart und Priorität (sofern relevant).

### 26.11.1 Grundregel

Registrierungen dürfen nur auf existierende und kompatible Contracts
erfolgen. Die Aktivierung eines Moduls ist zu blockieren, wenn die
Registrierung ungültig ist.

## 26.12 Registry im Admin-Bereich

Der Core stellt im Admin-Bereich eine zentrale Registry für Contracts
und Registrierungen bereit. Sie umfasst alle vier Contract-Typen,
einschließlich der Service-Contracts. Öffentliche Modul-Interfaces
erscheinen darin als Service-Contracts; die Interface-Registry (Kapitel
29.12) ist die auf diesen Typ gefilterte Sicht.

### 26.12.1 Sicht pro Contract

Für jeden Contract werden mindestens angezeigt: Name, Typ, Version,
Owner-Modul, Beschreibung, Interface- oder Spezifikationsname,
Input-Spezifikation, Output-Spezifikation, Default-Semantik, Status
und aktive Registrierungen.

### 26.12.2 Sicht pro Registrierung

Für jede Registrierung werden mindestens angezeigt: Modulname,
Modulversion, Ziel-Contract, Registrierungsart, Status (aktiv/inaktiv),
Lizenzstatus und Kompatibilitätsstatus.

### 26.12.3 Diagnose- und Testoberflächen

Ein Modul kann für eigene Contracts oder Registrierungen eine Diagnose-
oder Testoberfläche bereitstellen. Diese ist nicht Teil des Core, kann
aber aus der Registry heraus verlinkt werden.

## 26.13 Aktivierung und Validierung

Vor der Aktivierung eines Moduls mit Contracts oder Registrierungen
müssen mindestens folgende Prüfungen erfolgreich sein:

-   Contract existiert
-   Contract-Version ist kompatibel
-   Resolver-Slot ist frei (sofern Resolver)
-   Lizenz ist gültig (falls erforderlich)
-   Signatur ist gültig
-   Modul- und Core-Kompatibilität ist gegeben

### 26.13.1 Grundregel

Eine ungültige Registrierung blockiert die Aktivierung des Moduls.

### 26.13.2 Registrierte Nutzung zur Laufzeit (Capability-Bindung)

Ein Modul greift auf einen Contract ausschließlich über ein
Capability-Handle zu, das ihm der Core bei der Aktivierung für jede im
Manifest deklarierte und validierte Nutzung übergibt. Das Handle ist an
das nutzende Modul, den Ziel-Contract und die kompatible Contract-Version
gebunden.

Daraus folgt:

-   Ein Modul kann nur Contracts aufrufen, für die es ein Handle besitzt.
-   Es gibt keinen global adressierbaren Zugriff auf die Registry oder
    auf interne Provider-Implementierungen anderer Module.
-   Der Core stellt Handles nur für aktive, kompatible und – falls
    erforderlich – lizenzierte Registrierungen aus.
-   Wird eine Registrierung inaktiv (Deaktivierung, Lizenzablauf,
    Inkompatibilität), wird das zugehörige Handle ungültig.

Die Zugriffskontrolle wirkt damit durch Konstruktion (nur vergebene
Handles sind aufrufbar), nicht durch nachträgliche Prüfung des
Aufrufers. Ein Aufruf ohne gültiges Handle liefert das definierte
Default- bzw. Abweisungsverhalten (Kapitel 26.13.3).

Die Capability-Bindung ist eine Zugriffskontrolle auf Vertragsebene und
ersetzt keine technische Sandbox; zur Einordnung siehe Kapitel 23.16.

### 26.13.3 Verhalten bei Abweisung

Das aufrufende Modul ist verpflichtet, einen abgewiesenen
Contract-Aufruf fachlich kontrolliert zu behandeln. Die konkrete
Reaktion ist moduldefiniert und kann insbesondere bestehen aus:

-   Rückfall auf ein Default-Verhalten
-   Rückgabe eines leeren Ergebnisses
-   Ausblenden einer Funktion
-   Anzeige eines fachlichen Fehlers
-   Protokollierung und kontrollierter Abbruch des konkreten
    Teilprozesses

Die Plattform lehnt den nicht registrierten Aufruf technisch ab. Wie
das fachlich verarbeitet wird, entscheidet das aufrufende Modul.

## 26.14 Verhalten bei Deaktivierung eines Moduls

Wird ein Modul deaktiviert, gelten für Contracts und Registrierungen
folgende Regeln:

-   Resolver-Provider des Moduls werden inaktiv
-   Collector-Beiträge des Moduls werden nicht mehr berücksichtigt
-   Event-Listener des Moduls werden nicht mehr ausgeführt
-   Das Default-Verhalten der betroffenen Resolver und Collector greift
    automatisch
-   Es erfolgt keine Datenlöschung

## 26.15 Verhalten bei Lizenzablauf

Läuft die Lizenz eines kostenpflichtigen Moduls ab, wird das Modul
deaktiviert. Für Contracts und Registrierungen gilt dann dasselbe
Verhalten wie bei einer manuellen Deaktivierung:

-   Resolver fallen auf ihr Default-Verhalten zurück
-   Collector-Beiträge werden ignoriert
-   Event-Listener werden ignoriert
-   Moduldaten bleiben erhalten

## 26.16 Fehlerverhalten zur Laufzeit

Jeder Contract muss ein definiertes Fehlerverhalten besitzen.

### 26.16.1 Resolver

Bei Resolvern gilt: Ist kein Provider aktiv, greift das
Default-Verhalten. Ist ein aktiver Provider fehlerhaft, muss das
definierte Fehlerverhalten des Contracts greifen. Das Fehlerverhalten
darf die Grundlauffähigkeit des Main-Moduls nicht brechen.

### 26.16.2 Collector

Bei Collectors gilt: Fehlerhafte Beiträge eines Moduls dürfen die
übrigen Beiträge nicht blockieren. Deaktivierte oder fehlerhafte Module
werden ignoriert, sofern der Contract dies nicht anders definiert.

### 26.16.3 Events

Bei Events gilt: Da die Zustellung asynchron über den Outbox erfolgt
(Kapitel 26.9.2), kann ein fehlerhafter Listener den auslösenden
Hauptprozess strukturell nicht beeinträchtigen. Fehlgeschlagene
Zustellungen werden mit Backoff wiederholt, nach Ausschöpfung der
Versuche in einen sichtbaren Fehler- bzw. Dead-Letter-Zustand überführt
und protokolliert.

### 26.16.4 Grundregel

Laufzeitfehler in Erweiterungen sind zu protokollieren und müssen
administrativ nachvollziehbar sein.

## 26.17 Auditierbarkeit

Folgende Contract-bezogenen Vorgänge sind im Audit-Log zu
protokollieren:

-   Registrierung eines Contracts
-   Registrierung eines Providers, Collector-Beitrags oder Listeners
-   Aktivierung und Deaktivierung entsprechender Registrierungen
-   Resolver-Konflikte
-   Fehlgeschlagene Validierungen
-   Lizenzbedingte Deaktivierungen
-   Inkompatible Contract-Versionen

## 26.18 Beispielhafte Anwendung

### 26.18.1 Resolver im Main-Modul Ticketing

Das Main-Modul Ticketing definiert einen Resolver zur Berechnung einer
SLA-Frist. Der Contract legt fest: Input (Ticketdaten, Queue, Priorität,
Erstellungszeitpunkt), Output (berechneter Zeitpunkt), Default
(24×7-SLA). Ist kein aktiver Provider registriert, verwendet Ticketing
die 24×7-Berechnung.

### 26.18.2 Resolver im Extension-Modul Feiertagskalender

Ein Extension-Modul zur Feiertagsberechnung definiert einen Resolver
oder Collector zur Ermittlung relevanter Feiertage. Der Contract legt
fest: Input (Zeitraum, Region, Kalenderkontext), Output
(Feiertagsmenge), Default (leere Feiertagsmenge). Ist kein aktiver
Provider registriert, werden keine Feiertage berücksichtigt.

### 26.18.3 Resolver im Extension-Modul Gastportal-CAPTCHA

Ein Extension-Modul für CAPTCHA im Gastportal definiert einen Resolver
zur Validierung eines CAPTCHA-Kontexts. Der Contract legt fest: Input
(Request-Kontext, CAPTCHA-Daten), Output (Validierungsergebnis),
Default (kein CAPTCHA aktiv). Ist kein aktiver Provider registriert,
arbeitet das Gastportal ohne CAPTCHA.

## 26.19 Architekturprinzipien

Für Contracts, Resolver, Collector und Events gelten folgende
verbindliche Leitregeln:

-   Verträge werden nicht informell, sondern formal und versioniert
    definiert.
-   Jeder Resolver besitzt ein verpflichtendes Default-Verhalten.
-   Ein Prozess darf niemals von einem aktiven Provider abhängig sein.
-   Pro Resolver-Slot darf nur ein aktiver Provider existieren.
-   Collector und Events dürfen mehrere Registrierungen haben.
-   Contracts müssen im Admin-Bereich sichtbar und nachvollziehbar
    sein.
-   Registrierungen müssen vor Aktivierung validiert werden.
-   Fehlerhafte oder deaktivierte Erweiterungen dürfen die
    Grundlauffähigkeit des Main-Moduls nicht zerstören.
-   Lizenzablauf führt zu Deaktivierung, nicht zu Datenlöschung.
-   Der Zugriff auf Contracts und öffentliche Interfaces erfolgt über
    gebundene Capability-Handles; einen globalen, frei adressierbaren
    Zugriff auf Registry oder fremde interne Dienste gibt es nicht
    (Capability-Bindung, Kapitel 26.13.2).
-   Alle relevanten Vorgänge sind auditierbar.

# 27. Benutzer, Gruppen, Rollen und Berechtigungsmodell der Plattform

## 27.1 Zielsetzung

Die Plattform benötigt ein einheitliches Identitäts- und
Berechtigungsmodell, das den Core einfach hält und gleichzeitig
Main-Modulen sowie Extension-Modulen erlaubt, ihre fachlichen
Ressourcen kontrolliert abzusichern.

Hinweis zur Abgrenzung: Kapitel 25 beschreibt das BREAD-Modell und
Ressourcenmodell aus Sicht der Module (wie Module Ressourcen definieren
und absichern). Dieses Kapitel beschreibt das plattformweite
Identitäts- und Berechtigungsmodell aus Sicht des Core (wie Benutzer,
Gruppen und Rollen verwaltet werden und wie die beiden Ebenen
zusammenwirken).

Das Berechtigungsmodell verfolgt insbesondere folgende Ziele:

-   Klare Trennung zwischen Core-Administration und
    Anwendungsberechtigungen
-   Zentrale Verwaltung von Benutzern und Gruppen
-   Einfache, nachvollziehbare Rechteaggregation
-   Wiederverwendbarkeit derselben Gruppen über mehrere Module hinweg
-   Keine modulübergreifende Aufweichung des Core-Rechtesystems
-   Stabile Grundlage für Main-Module und Extension-Module

## 27.2 Benutzer

Ein Benutzer ist eine im Core verwaltete Identität, die sich an der
Plattform anmelden kann und anschließend entsprechend ihrer Rolle und
Gruppenzugehörigkeiten Zugriff auf Core-Funktionen und
Modul-Funktionen erhält.

| **Eigenschaft** | **Beschreibung** |
| --- | --- |
| Benutzer-ID | Eindeutige technische Identifikation |
| Benutzername | Eindeutiger Anmeldename |
| E-Mail-Adresse | Eindeutige E-Mail-Adresse |
| Vorname | Anzeigename, optional oder verpflichtend gemäß Systemdefinition |
| Nachname | Anzeigename, optional oder verpflichtend gemäß Systemdefinition |
| Status | z.B. aktiv, deaktiviert, eingeladen |
| Administrationsbereiche | Zugewiesene Core-Administrationsbereiche (keine = Nicht-Administrator, alle = Volladministrator) |
| Gruppenmitgliedschaften | Zugeordnete Gruppen |
| Sprache | Optionale Benutzer-Sprache |
| Zeitzone | Optionale Benutzer-Zeitzone |
| Profildaten | Weitere vom Core definierte Benutzerinformationen |

### 27.2.1 Grundregel

Benutzer werden ausschließlich durch den Core verwaltet. Main-Module
und Extension-Module dürfen keine eigene, vom Core unabhängige
Benutzerbasis aufbauen.

### 27.2.2 Authentifizierung (pluggable)

Die Authentifizierungsmethode ist über einen Resolver-Slot des Core
austauschbar. Der Default-Provider ist die lokale Benutzer- und
Passwort-Authentifizierung; der Core ist damit ohne weiteres Modul voll
authentifizierungsfähig.

Ein Extension-Modul kann einen alternativen Authentifizierungs-Provider
registrieren (z.B. OIDC oder SAML gegen einen externen
Identity-Provider). Es gelten die Resolver-Regeln (Kapitel 26.7): genau
ein aktiver Provider pro Slot; ohne aktiven Provider greift der lokale
Default.

Benutzer bleiben Core-verwaltete Identitäten (Kapitel 27.2). Bei
externer Authentifizierung kann die Identität per
Just-in-Time-Provisioning angelegt oder mit einem bestehenden
Core-Benutzer verknüpft werden. Die Autorisierung (Administrationsbereiche,
Gruppen, BREAD) bleibt unabhängig von der Authentifizierungsmethode.

## 27.3 Rollen und Administrationsbereiche im Core

Der Core kennt ein bewusst einfaches, rollen- bzw. bereichsbasiertes
Modell zur Steuerung des Zugriffs auf plattformweite Funktionen. Es
verwendet kein BREAD und keine Gruppen.

### 27.3.1 Administratoren und Administrationsbereiche

Die Core-Administration ist in eine feste Menge von
Administrationsbereichen gegliedert:

-   Benutzer- und Gruppenverwaltung
-   Modul- und Lifecycle-Verwaltung (Installation, Aktivierung,
    Deaktivierung, Update, Löschung)
-   Marketplace und Lizenzverwaltung
-   Registry und Contracts
-   Update-Manager (inkl. Signatur- und Kompatibilitätsprüfung)
-   Core-Konfiguration
-   Sprachverwaltung (Sprachpakete für Core, Module und Extensions; Import,
    Feld-Editor, Review, Löschen)

Einem Benutzer werden ein oder mehrere Administrationsbereiche
zugewiesen:

-   Ein Volladministrator hält alle Administrationsbereiche und besitzt
    damit vollen Zugriff auf den Core-Adminbereich.
-   Ein delegierter Administrator hält eine Teilmenge der Bereiche (z.B.
    nur Benutzer- und Gruppenverwaltung) und sieht bzw. bedient
    ausschließlich die zugewiesenen Bereiche.

Die Zuweisung ist rollen- bzw. bereichsbasiert; sie ist kein BREAD und
keine Gruppenzuordnung. Innerhalb eines zugewiesenen Bereichs ist der
Zugriff vollständig (keine feinere Aufteilung pro Objekt). Änderungen an
Administrationsbereichen sind auditierbar.

### 27.3.2 Nicht-Administrator

Nicht-Administratoren besitzen keinen Zugriff auf plattformweite
Core-Administrationsfunktionen. Ihr Zugriff auf fachliche Funktionen
ergibt sich ausschließlich aus: installierten Main-Modulen,
installierten Extension-Modulen, ihren Gruppenmitgliedschaften und den
daraus aggregierten Rechten auf moduldefinierte Ressourcen.

### 27.3.3 Gäste (modulspezifisches Zugriffskonzept)

Der Core kennt kein Gast-Konzept. Gäste (nicht authentifizierte
Zugriffe über Ticketnummer + E-Mail-Adresse, siehe Modul-Dokument Ticketing, Kapitel 9) sind ein
modulspezifisches Zugriffskonzept des Ticketing-Main-Moduls. Sie sind
keine Core-Benutzer, haben keine Administrationsbereiche, keine
Gruppenmitgliedschaften und keinen Zugriff auf Core-Funktionen. Das
Ticketing-Modul implementiert den Gastzugang als eigenen
Authentifizierungsmechanismus außerhalb des Core-Identitätsmodells.

### 27.3.4 Grundregel

Im Core gilt ein bewusst einfaches Modell: Der Zugriff auf
Core-Administrationsfunktionen ergibt sich aus den dem Benutzer
zugewiesenen Administrationsbereichen (Volladministrator = alle
Bereiche), nicht über BREAD und nicht über Gruppen. Feinere
Rechteauflösungen innerhalb fachlicher Bereiche erfolgen ausschließlich
in den Anwendungsmodulen. Modulspezifische Zugriffskonzepte wie der
Gastzugang liegen in der Verantwortung des jeweiligen Moduls.

## 27.4 Gruppen

Gruppen sind vom Core verwaltete Berechtigungscontainer. Sie dienen
dazu, Benutzern in Main-Modulen und Extension-Modulen Rechte auf
Ressourcen zuzuweisen.

| **Eigenschaft** | **Beschreibung** |
| --- | --- |
| Gruppen-ID | Eindeutige technische Identifikation |
| Name | Eindeutiger Gruppenname |
| Beschreibung | Optionale Beschreibung |
| Status | Aktiv oder deaktiviert |
| Mitglieder | Zugeordnete Benutzer |
| Ressourcenzuordnungen | Zuordnungen zu Modul-Ressourcen mit Rechten |

### 27.4.1 Zweck von Gruppen

Gruppen dienen dazu: Benutzer fachlichen Zugriffsbereichen zuzuordnen,
Rechte auf Ressourcen gebündelt zu vergeben, dieselbe
Berechtigungslogik über mehrere Module hinweg wiederzuverwenden und
Rechteänderungen zentral und nachvollziehbar zu verwalten.

### 27.4.2 Grundregel

Gruppen sind zentrale Core-Objekte. Main-Module und Extension-Module
dürfen eigene Ressourcen und Rechtezuordnungen definieren, aber keine
vom Core unabhängige Gruppenverwaltung etablieren.

## 27.5 Gruppenmitgliedschaften

Ein Benutzer kann Mitglied in mehreren Gruppen sein. Eine Gruppe kann
mehrere Benutzer enthalten.

### 27.5.1 Regeln

-   Mehrfachmitgliedschaften sind zulässig.
-   Gruppenmitgliedschaften werden im Core verwaltet.
-   Deaktivierte Gruppen dürfen keine aktiven Rechte mehr vermitteln.
-   Deaktivierte Benutzer dürfen unabhängig von ihren Gruppen keine
    aktiven Modulzugriffe erhalten.

### 27.5.2 Grundregel

Die effektiven Modulrechte eines Benutzers ergeben sich aus der
Vereinigung der Rechte aller aktiven Gruppen, in denen der Benutzer
Mitglied ist.

## 27.6 Plattformweites Berechtigungsmodell

Die Plattform trennt Berechtigungen in zwei Ebenen:

**Ebene 1: Core-Berechtigungen.** Diese ergeben sich ausschließlich
aus den dem Benutzer zugewiesenen Core-Administrationsbereichen
(Volladministrator = alle Bereiche, Kapitel 27.3.1).

**Ebene 2: Modulberechtigungen.** Diese ergeben sich ausschließlich
aus: Gruppenmitgliedschaften, Ressourcenzuordnungen, BREAD-Rechten
und moduldefinierten Zusatzaktionen.

### 27.6.1 Grundregel

Der Core entscheidet über Core-Funktionen. Module entscheiden über ihre
fachlichen Ressourcen, aber immer innerhalb des vom Core vorgegebenen
Gruppen- und Aggregationsmodells.

## 27.7 BREAD im Verhältnis zum Core

BREAD gilt ausschließlich für Anwendungsmodule. Der Core selbst nutzt
für seine eigenen Verwaltungsfunktionen kein BREAD. Die vollständige
Spezifikation des BREAD-Modells ist in Kapitel 25 beschrieben.

### 27.7.1 Konsequenz

Begriffe wie Browse, Read, Add, Edit und Delete gelten nur für
Ressourcen von Main-Modulen und Extension-Modulen, nicht für die
Core-Administration.

### 27.7.2 Grundregel

Die Berechtigungsprüfung auf Core-Funktionen erfolgt bereichsbasiert
(über die zugewiesenen Administrationsbereiche), nicht über Gruppen und
nicht über BREAD.

## 27.8 Ressourcenzuordnung

Main-Module und Extension-Module dürfen Ressourcen definieren, die
Gruppen zugeordnet werden können (siehe Kapitel 25.4 für
Ressourcentypen und -modell).

Jede Ressourcenzuordnung muss mindestens folgende Informationen
enthalten:

| **Eigenschaft** | **Beschreibung** |
| --- | --- |
| Modul-ID | Das zugehörige Modul |
| Ressourcentyp | Die fachliche Art der Ressource |
| Ressourcenkennung | Objektklasse, Bereich oder Einzelobjekt |
| Gruppe | Zugeordnete Gruppe |
| Rechte | BREAD-Rechte und ggf. Zusatzaktionen |

### 27.8.1 Grundregel

Die Ressourcenzuordnung erfolgt immer explizit. Es gibt keine
stillschweigende oder implizite Rechtevergabe durch bloße
Modulinstallation.

## 27.9 Rechteaggregation

Die Plattform verwendet ein additives Vereinigungsmodell. Die
detaillierten Regeln und ein Beispiel sind in Kapitel 25.6 beschrieben.

### 27.9.1 Grundregel

Die Plattform verwendet ausschließlich positive Rechteaggregation:
Rechte werden additiv vereinigt, es gibt keine Deny-Regeln, keine
Prioritäten und keine Konfliktlogik zwischen Gruppen.

## 27.10 Zusatzaktionen

Zusätzlich zu BREAD dürfen Module fachliche Zusatzaktionen definieren.
Die vollständige Spezifikation (Deklaration, Beispiele,
Ressourcenbindung) ist in Kapitel 25.7 beschrieben.

### 27.10.1 Grundregel

Zusatzaktionen erweitern das BREAD-Modell innerhalb eines Moduls,
werden wie BREAD-Rechte gruppenbezogen vergeben und dürfen das
Core-Rechtesystem nicht erweitern oder verändern.

## 27.11 Main-Module und Berechtigungen

Ein Main-Modul ist für die Definition seiner fachlichen Ressourcen und
deren Rechtefähigkeit vollständig selbst verantwortlich. Die
zulässigen Fähigkeiten sind in Kapitel 25.8 beschrieben.

### 27.11.1 Grundregel

Ein Main-Modul darf seine eigene Fachdomäne absichern, darf dabei aber
das Core-Modell von Benutzer, Gruppe, Aggregation und Rollen nicht
verändern.

## 27.12 Extension-Module und Berechtigungen

Ein Extension-Modul darf eigene Ressourcen definieren und diese Gruppen
zuordnen (siehe Kapitel 25.9 für zulässige Fähigkeiten und Details).

Aus Core-Perspektive darf ein Extension-Modul nicht:

-   Eigene globale Rollenmodelle definieren
-   Das Core-Rechtesystem verändern
-   Eine alternative Gruppenlogik einführen
-   Die Rechteaggregation verändern

### 27.12.1 Grundregel

Ein Extension-Modul darf eigene berechtigbare Ressourcen einführen,
aber keine eigene Rechte-Domain im Core erzeugen.

## 27.13 Verhältnis zwischen Main-Modul und Extension-Modul

Es gibt keine automatische technische Rechtevererbung zwischen
Main-Modul und Extension-Modul (siehe Kapitel 25.10 für Details).
Gruppen können auf Ressourcen beider Module jeweils separat berechtigt
werden. In der Praxis können dieselben Gruppen verwendet werden – das
ist eine Konfigurationsentscheidung, keine implizite Vererbung.

### 27.13.1 Grundregel

Ein Extension-Modul darf keine stillschweigende Rechteausweitung
verursachen.

## 27.14 Aktivierung und Deaktivierung von Gruppen

Gruppen können aktiviert oder deaktiviert werden.

### 27.14.1 Verhalten bei Deaktivierung

Wird eine Gruppe deaktiviert: bleiben ihre Zuordnungen technisch
erhalten, vermittelt sie keine aktiven Rechte mehr und werden ihre
Rechte bei der Aggregation nicht mehr berücksichtigt.

### 27.14.2 Verhalten bei Reaktivierung

Wird eine Gruppe reaktiviert: werden ihre bestehenden Zuordnungen
wieder wirksam und ihre Rechte wieder in die Aggregation einbezogen.

### 27.14.3 Grundregel

Die Deaktivierung einer Gruppe löscht keine Ressourcenzuordnungen,
sondern setzt deren Wirkung temporär außer Kraft.

## 27.15 Aktivierung und Deaktivierung von Benutzern

Benutzer können aktiviert oder deaktiviert werden.

### 27.15.1 Verhalten bei Deaktivierung

Wird ein Benutzer deaktiviert: kann er sich nicht mehr anmelden,
erhält er keine Rechte mehr aus Gruppenmitgliedschaften, bleiben seine
Gruppenmitgliedschaften technisch erhalten und bleiben historische
Referenzen auf den Benutzer erhalten.

### 27.15.2 Verhalten bei Reaktivierung

Wird ein Benutzer reaktiviert: werden seine bestehenden
Gruppenmitgliedschaften wieder wirksam und erhält er wieder die
aggregierten Rechte seiner Gruppen.

### 27.15.3 Anonymisierung (Recht auf Löschung)

Aktivierte Benutzer, die bereits Fachdaten oder Referenzen erzeugt haben
(Tickets, Einträge, Zuweisungen, Audit-Log), werden nicht physisch
gelöscht, sondern auf Antrag irreversibel anonymisiert. Dies setzt das
Recht auf Löschung (Art. 17 DSGVO) um, ohne die Datenintegrität oder
historische Nachvollziehbarkeit zu zerstören.

**Verfahren.** Bei der Anonymisierung werden die personenbezogenen
Identitätsfelder des Benutzers (insbesondere Benutzername, E-Mail-Adresse,
Vor- und Nachname, Profildaten) durch einen nicht rückführbaren
Platzhalter ersetzt (z.B. "Gelöschter Benutzer #<technische ID>"). Die
technische Benutzer-ID und alle historischen Referenzen auf diese ID
bleiben erhalten.

**Irreversibilität.** Die Anonymisierung ist irreversibel. Es wird kein
Schlüssel und keine Zuordnungstabelle vorgehalten, die eine
Re-Identifikation erlaubt. Eine bloße Pseudonymisierung (über einen
separat gehaltenen Schlüssel umkehrbar) erfüllt das Löschrecht nicht und
ist für diesen Zweck nicht zulässig.

**Audit-Log.** Das Audit-Log behält die protokollierten Vorgänge als
Nachweis (konsistent mit Kapitel 20.6), ersetzt aber den Personenbezug
durch die anonymisierte Referenz. Das Log wird nicht gelöscht.

**Abgrenzung Einladungs-Accounts.** Noch nicht aktivierte
Einladungs-Accounts (Status "eingeladen") haben keine Fachdaten oder
Referenzen erzeugt und dürfen beim Widerruf physisch gelöscht werden
(Kapitel 1.6 und 23.3.1). Für sie ist keine Anonymisierung erforderlich.

**Personenbezogene Daten in Freitext (Modul-Teilnahme).** Personenbezogene
Daten, die in Modul-Inhalten enthalten sind (z.B. Freitext in
Ticket-Texten, Signaturen oder Kommentaren), kennt der Core nicht — nur
das jeweilige Modul kennt sein Datenmodell. Der Core stellt dafür einen
**Teilnahme-Hook** bereit: den Collector-Contract
`core.collector.anonymize`. Beim irreversiblen Anonymisieren eines
Benutzers ruft der Core **in derselben Transaktion** jedes Modul auf, das
einen Beitrag (`App\Service\Privacy\AnonymizeContributorInterface`)
registriert hat, damit es seine eigenen personenbezogenen Daten zu dem
Benutzer bereinigt. Das ist eine **atomare All-or-Nothing**-Operation
(scheitert ein Beitrag, scheitert die gesamte Anonymisierung). Die
**Orchestrierung ist Core-Funktion**; die fachliche Bereinigung liegt beim
jeweiligen Modul.

#### 27.15.3.1 Grundregel

Aktivierte Benutzer werden nicht gelöscht, sondern irreversibel
anonymisiert. Die technische ID und historische Referenzen bleiben
erhalten; der Personenbezug wird vollständig und unumkehrbar entfernt.
Die Anonymisierung der Identitätsfelder (Core) ist eine Muss-Anforderung;
die Bereinigung personenbezogener Freitextinhalte erfolgt über den
Core-Hook `core.collector.anonymize`, an dem die Module ihre eigenen Daten
mit-bereinigen (Core stellt die Orchestrierung, das Modul die fachliche
Bereinigung).

## 27.16 Rechteprüfung zur Laufzeit

Die Rechteprüfung erfolgt immer serverseitig.

### 27.16.1 Prüfgrundlage

Die Rechteprüfung basiert auf: Benutzer, Benutzerstatus,
Core-Administrationsbereichen, Gruppenmitgliedschaften, Ressource und
angefragtem BREAD-Recht
oder angefragter Zusatzaktion.

### 27.16.2 Grundregel

Ein Modul darf Rechte niemals ausschließlich im Frontend prüfen.
Sichtbarkeit in der GUI und tatsächliche serverseitige Berechtigung
müssen übereinstimmen, aber die serverseitige Prüfung ist maßgeblich.

### 27.16.3 API-Authentifizierung und Anmeldeschutz

**API-Authentifizierung.** Zugriffe auf die REST-API werden
serverseitig authentifiziert. Jeder API-Aufruf ist an eine
Core-Identität (Benutzer) gebunden; es gibt keinen anonymen API-Zugriff
auf geschützte Ressourcen. Modulspezifische Ausnahmen wie der
Ticketing-Gastzugang bleiben in der Verantwortung des jeweiligen Moduls
(Kapitel 27.3.3). Die Authentifizierung erfolgt über an den Benutzer
gebundene, serverseitig widerrufbare Zugangstoken. Das konkrete
Token-Verfahren ist nicht vorgeschrieben.

**Rechteprüfung.** Für API-Aufrufe gilt dasselbe Berechtigungsmodell wie
für die GUI. Ein Zugangstoken trägt **keine eigenen, erweiternden Rechte**:
Die effektiven Rechte werden bei jedem Aufruf live gegen die aktuell
hinterlegten BREAD-Rechte und Zusatzaktionen des Benutzers geprüft
(Kapitel 25 und 27); eine Rechteänderung (z.B. Gruppenentzug) wirkt damit
unmittelbar auch auf bestehende Token. Ein Token kann jedoch **Scopes**
tragen, die den Zugriff **zusätzlich einschränken** (Least Privilege /
Defense-in-Depth) — ein Scope kann nie mehr gewähren als die Live-Rechte
des Benutzers, sondern nur weniger. Es existiert keine im Token
eingefrorene oder erweiternde Rechteprüfung für den API-Kontext.

**Token-Lebenszyklus.** Zugangstoken können erstellt, eingesehen (ohne
erneute Anzeige des Geheimnisses) und widerrufen werden. Ein
deaktivierter oder anonymisierter Benutzer verliert sofort die
Gültigkeit seiner Token. (Soll)

**Anmeldeschutz.** Wiederholte fehlgeschlagene Anmelde- und
Token-Authentifizierungsversuche werden serverseitig begrenzt
(Rate-Limiting bzw. temporäre Sperre). Die Schwellenwerte sind im
Admin-Bereich konfigurierbar (Datenbank, analog zur Passwort-Policy,
Kapitel 1.4) und besitzen einen sicheren Vorgabewert, der auch ohne
Konfiguration greift. (Soll)

### 27.16.3.1 Grundregel

API und GUI teilen sich Identität und Rechtemodell. Die API führt kein
eigenes, abweichendes Authentifizierungs- oder Berechtigungsmodell ein.
Der Authentifizierungsgrundsatz und das gemeinsame Rechtemodell sind
Muss-Anforderungen; konkreter Token-Lebenszyklus und
Anmeldeschutz-Schwellen sind Soll-Anforderungen.

## 27.17 Administrationsoberflächen für Modulrechte

Jedes Modul mit gruppenfähigen Ressourcen muss eine geeignete
Administrationsoberfläche bereitstellen (siehe Kapitel 25.12 für
Anforderungen und zulässige Darstellungsformen).

### 27.17.1 Grundregel

Die Darstellung der Rechtezuordnung ist Sache des jeweiligen Moduls.
Der Core schreibt das Modell vor, nicht die konkrete Bedienoberfläche.

## 27.18 Auditierbarkeit

Änderungen an Benutzer-, Gruppen- und Ressourcenberechtigungen sind im
Audit-Log zu protokollieren. Dies umfasst mindestens:

-   Anlegen, Ändern, Aktivieren und Deaktivieren einer Gruppe
-   Hinzufügen oder Entfernen eines Benutzers aus einer Gruppe
-   Hinzufügen oder Entfernen einer Gruppe von einer Ressource
-   Änderung von BREAD-Rechten
-   Änderung von Zusatzaktionen
-   Aktivierung oder Deaktivierung eines Benutzers
-   Anonymisierung eines Benutzers (Recht auf Löschung)

## 27.19 Beispielhafte Anwendung

Die beispielhafte Anwendung des Berechtigungsmodells für das
Ticketing-Main-Modul und die Extension-Module SLA-Kalender und
Feiertagskalender (gruppenfähige Ressourcen und Zusatzaktionen) ist
in Kapitel 25.15 beschrieben.

## 27.20 Architekturprinzipien

Für Benutzer, Gruppen, Rollen und das Berechtigungsmodell gelten
folgende verbindliche Leitregeln:

-   Benutzer werden ausschließlich im Core verwaltet.
-   Gruppen werden ausschließlich im Core verwaltet.
-   Der Core verwendet für Core-Funktionen kein BREAD.
-   Core-Administrationszugriff ergibt sich aus zugewiesenen
    Administrationsbereichen (Volladministrator = alle); kein BREAD,
    keine Gruppen.
-   Die Authentifizierungsmethode ist über einen Resolver-Slot
    austauschbar (Default: lokal; optional OIDC/SAML-SSO).
-   BREAD gilt ausschließlich für Anwendungsmodule.
-   Rechte werden über Gruppen vergeben.
-   Ein Benutzer kann Mitglied in mehreren Gruppen sein.
-   Effektive Rechte ergeben sich aus der Vereinigung aller
    Gruppenrechte.
-   Es gibt keine Deny-Regeln und keine Prioritäten zwischen Gruppen.
-   Main-Module definieren ihre Ressourcen selbst.
-   Extension-Module dürfen eigene Ressourcen definieren, aber keine
    eigene Rechte-Domain im Core etablieren.
-   Rechteprüfungen müssen immer serverseitig erfolgen.
-   Änderungen an Berechtigungen sind auditierbar.

# 28. Core-Update, Modul-Update, Signaturprüfung und Marketplace-Kommunikation

## 28.1 Zielsetzung

Die Plattform muss in der Lage sein, den Core sowie installierte
Module kontrolliert, nachvollziehbar und sicher zu aktualisieren.
Gleichzeitig muss sichergestellt werden, dass ausschließlich
vertrauenswürdige Pakete aus einer definierten Quelle verarbeitet
werden.

Die Update- und Marketplace-Architektur verfolgt insbesondere
folgende Ziele:

-   Sichere Verteilung von Core- und Modul-Updates
-   Signaturprüfung aller Pakete vor Installation oder Update
-   Zentrale Marketplace-Anbindung
-   Kontrollierte Lizenzprüfung
-   Kompatibilitätsprüfung vor jeder Änderung
-   Keine stillen oder unkontrollierten Selbstupdates
-   Klare Trennung zwischen Plattform-Update und Infrastruktur-Update

## 28.2 Geltungsbereich

Der in diesem Kapitel beschriebene Update-Mechanismus gilt
ausschließlich für: den Core, Main-Module und Extension-Module.

Er gilt ausdrücklich nicht für: PHP, PostgreSQL, Webserver,
Betriebssystem, Composer-Abhängigkeiten außerhalb der von der
Plattform ausgelieferten Anwendung und sonstige
Infrastrukturkomponenten.

### 28.2.1 Grundregel

Die Plattform aktualisiert ausschließlich sich selbst und ihre Module.
Die darunterliegende Basisinfrastruktur bleibt in der Verantwortung
des Betreibers (siehe auch Kapitel 20.7 Betriebsgrenzen).

## 28.3 Update-Arten

| **Update-Art** | **Beschreibung** |
| --- | --- |
| Core-Update | Aktualisierung der Plattform selbst |
| Main-Modul-Update | Aktualisierung eines Main-Moduls |
| Extension-Modul-Update | Aktualisierung eines Extension-Moduls |
| Sicherheitsupdate | Besonders gekennzeichnetes Update mit sicherheitsrelevanter Bedeutung |
| Kompatibilitätsupdate | Update zur Herstellung oder Wiederherstellung von Versionskompatibilität |

## 28.4 Marketplace

Die Plattform verwendet einen zentralen Marketplace als autoritative
Quelle für Module und Updates (siehe Kapitel 23.9 für
Lizenzierungsmodell).

Der Marketplace stellt mindestens folgende Informationen bereit:
Verfügbare Core-Versionen, verfügbare Modul-Versionen,
Paket-Metadaten, Changelogs, Kompatibilitätsinformationen,
Lizenzinformationen, Paket-Signaturen und Download-Referenzen.

### 28.4.1 Grundregel

Pakete und Updates dürfen ausschließlich aus dem definierten
Marketplace oder aus explizit zugelassenen, gleichwertig signierten
Paketquellen verarbeitet werden.

## 28.5 Marketplace-Kommunikation

Die Plattform kommuniziert kontrolliert mit dem Marketplace, um
verfügbare Versionen, Lizenzen und Metadaten abzurufen.

### 28.5.1 Abrufbare Informationen

Die Plattform darf insbesondere folgende Informationen abrufen:
Verfügbare Core-Updates, verfügbare Modul-Updates, Modul-Metadaten,
Lizenzstatus für kostenpflichtige Module, Signaturinformationen,
Sperrlisten widerrufener Schlüssel, aktualisierte Vertrauensanker,
Changelog-Informationen und Kompatibilitätsinformationen.

### 28.5.2 Mindestanforderungen

Die Marketplace-Kommunikation muss mindestens sicherstellen:

-   Verschlüsselte Übertragung
-   Verifikation der Herkunft
-   Prüfbarkeit der Antwortdaten
-   Trennung von Metadatenabruf und Paketinstallation

### 28.5.3 Grundregel

Der bloße Abruf von Marketplace-Metadaten darf keine Änderungen am
installierten System bewirken.

## 28.6 Signaturprüfung

Jedes Core- und Modulpaket muss signiert sein. Die Signaturprüfung
ist verpflichtender Bestandteil jeder Installation und jedes Updates
(konsistent mit Kapitel 24.9; Vertrauensanker, Schlüsselrotation und
Widerruf siehe Kapitel 24.9.2).

### 28.6.1 Regeln für die Signaturprüfung

-   Unsignierte Pakete dürfen nicht installiert oder aktualisiert
    werden.
-   Pakete mit ungültiger Signatur dürfen nicht installiert oder
    aktualisiert werden.
-   Die Signaturprüfung erfolgt vor dem Entpacken des Pakets.
-   Das Ergebnis der Signaturprüfung ist zu protokollieren.
-   Der Herausgeber des Pakets muss erkennbar sein.

### 28.6.2 Grundregel

Ohne erfolgreiche Signaturprüfung darf keine Installation und kein
Update durchgeführt werden.

## 28.7 Lizenzprüfung

Die Lizenzprüfung ist offline-first. Maßgeblich ist eine signierte
Lizenzdatei (Lizenz-Token), die gegen den Vertrauensanker (Kapitel
24.9.2) geprüft wird. Aktivierung, Update und laufender Betrieb
erfordern keinen Serverkontakt. Eine Online-Verbindung zum Marktplatz
oder Lizenzserver ist optional und dient ausschließlich dem Abruf
aktualisierter Sperrlisten (Widerruf, Kapitel 24.9.2) und der optionalen
Lizenz-Erneuerung.

Kostenpflichtige Module müssen vor Aktivierung und vor Update eine
gültige Lizenzdatei besitzen (konsistent mit Kapitel 24.8).

### 28.7.1 Prüfgegenstände

Mindestens zu prüfen sind: Signaturgültigkeit der Lizenzdatei gegen den
Vertrauensanker (Kapitel 24.9.2), Modulbezug der Lizenz, zeitliche
Gültigkeit (Gültigkeitszeitraum in der Lizenzdatei), formale Gültigkeit
und Kompatibilität von Lizenz und Zielmodul. Alle diese Prüfungen
erfolgen ohne Serverkontakt anhand der signierten Lizenzdatei.

### 28.7.2 Verhalten bei ungültiger Lizenz

Ist eine Lizenz ungültig, abgelaufen oder nicht vorhanden: darf das
Modul nicht aktiviert werden, darf ein kostenpflichtiges Update nicht
aktiviert werden, bleibt das Modul deaktiviert oder wird deaktiviert
und es erfolgt keine Datenlöschung.

Diese Prüfung erfolgt anhand der signierten Lizenzdatei ohne
Serverkontakt. Das Verhalten im laufenden Betrieb, beim Lizenzablauf und
beim optionalen Online-Enforcement regelt Kapitel 28.7.3.

### 28.7.3 Offline-first-Lizenzierung

Maßgeblich für Aktivierung und laufenden Betrieb ist die signierte
Lizenzdatei. Sie enthält Modulbezug, Gültigkeitszeitraum, ein optionales
Online-Enforcement und ein Karenzfenster; alle diese Angaben sind
Bestandteil der Signatur und damit nicht durch den Betreiber
manipulierbar.

-   Ein Modul ist aktiv, solange seine Lizenzdatei innerhalb ihres
    Gültigkeitszeitraums liegt und nicht widerrufen ist (Sperrliste,
    Kapitel 24.9.2). Hierfür ist kein Serverkontakt erforderlich.
-   Erreicht der Gültigkeitszeitraum sein Ende und liegt keine erneuerte
    Lizenzdatei vor, wird das Modul deaktiviert (konsistent mit Kapitel
    23.9.2 und 28.15). Es erfolgt keine Datenlöschung.
-   Eine erneuerte Lizenzdatei kann jederzeit online abgerufen oder
    offline eingespielt werden.

#### 28.7.3.1 Optionales Online-Enforcement

Eine Lizenz kann ein verpflichtendes periodisches Online-Enforcement
deklarieren (z.B. für Miet- oder Abonnementmodelle). Ist es aktiv, gilt:

-   Das Modul benötigt innerhalb eines in der Lizenz definierten
    Intervalls eine erfolgreiche Online-Bestätigung.
-   Gelingt die Bestätigung innerhalb des Intervalls nicht (Server nicht
    erreichbar), bleibt das Modul innerhalb des in der Lizenz
    definierten Karenzfensters aktiv. Fehlt die Angabe, ist das
    Karenzfenster null.
-   Bestätigt der Server eine abgelaufene oder ungültige Lizenz, wird das
    Modul unmittelbar deaktiviert; es gibt in diesem Fall kein
    Karenzfenster.
-   Läuft das Karenzfenster ab, ohne dass eine Bestätigung gelang, wird
    das Modul deaktiviert.

Ist kein Online-Enforcement deklariert (Standard, offline-first), findet
keine verpflichtende Online-Prüfung statt; maßgeblich bleibt allein die
signierte Lizenzdatei.

#### 28.7.3.2 Grundregel

Offline-first: Die signierte Lizenzdatei ist die Autorität; Aktivierung
und Betrieb erfordern keinen Serverkontakt. Online-Enforcement ist eine
optionale, in der Lizenz deklarierte Ausnahme. Ein Karenzfenster
überbrückt ausschließlich die Nichterreichbarkeit einer geforderten
Online-Bestätigung, niemals eine bestätigt abgelaufene Lizenz.

### 28.7.4 Grundregel

Lizenzprüfung blockiert Aktivierung, nicht jedoch die Datenhaltung.

## 28.8 Core-Update

Ein Core-Update aktualisiert die Plattform selbst. Es kann funktionale
Änderungen, Sicherheitsfixes, Registry-Änderungen, Lifecycle-Änderungen
und Kompatibilitätsanpassungen enthalten.

### 28.8.1 Ablauf eines Core-Updates

1.  Abruf verfügbarer Core-Versionen
2.  Anzeige der Zielversion, des Changelogs und der
    Kompatibilitätsinformationen
3.  Signaturprüfung des Update-Pakets
4.  Prüfung der Kompatibilität mit installierten Main-Modulen und
    Extension-Modulen
5.  Anzeige möglicher Konflikte
6.  Migrationsvorschau
7.  Optionale Aktivierung eines Wartungsmodus
8.  Erstellung des Wiederherstellungspunkts (vollständiger DB-Dump),
    sofern das Update Migrationen enthält (siehe Kapitel 28.14.2)
9.  Einspielen des Update-Pakets
10. Ausführung der Core-Migrationen
11. Neuvalidierung aller Modulregistrierungen
12. Protokollierung im Audit-Log
13. Beenden des Wartungsmodus

### 28.8.2 Grundregel

Ein Core-Update darf nur dann ausgeführt werden, wenn die installierten
Module mit der Zielversion kompatibel sind oder die
Inkompatibilitäten vorab eindeutig angezeigt und bestätigt wurden.

## 28.9 Modul-Update

Ein Modul-Update aktualisiert ein Main-Modul oder ein Extension-Modul.

### 28.9.1 Ablauf eines Modul-Updates

1.  Abruf verfügbarer Modulversionen
2.  Anzeige von Changelog und Kompatibilitätsinformationen
3.  Signaturprüfung des Update-Pakets
4.  Prüfung der Kompatibilität mit dem Core
5.  Prüfung der Kompatibilität mit dem Main-Modul (falls
    Extension-Modul)
6.  Prüfung registrierter Contracts und Contract-Versionen
7.  Resolver-Konfliktprüfung
8.  Lizenzprüfung (falls kostenpflichtig)
9.  Migrationsvorschau
10. Optionale Aktivierung eines Wartungsmodus
11. Erstellung des Wiederherstellungspunkts (vollständiger DB-Dump),
    sofern das Update Migrationen enthält (siehe Kapitel 28.14.2)
12. Einspielen des Update-Pakets
13. Ausführung der Modulmigrationen
14. Neuvalidierung der Registrierungen
15. Protokollierung im Audit-Log
16. Beenden des Wartungsmodus

### 28.9.2 Grundregel

Ein Modul-Update darf nur dann ausgeführt werden, wenn die Zielversion
mit dem Core, dem Main-Modul und allen genutzten Contracts kompatibel
ist.

## 28.10 Sicherheitsupdates

Sicherheitsupdates sind besonders gekennzeichnete Updates für Core
oder Module.

### 28.10.1 Anforderungen

Für Sicherheitsupdates muss das System zusätzlich unterstützen:
Eindeutige Kennzeichnung im Update-Dialog, Hervorhebung der
Dringlichkeit, Anzeige ob das Update Core oder Modul betrifft und
Protokollierung der Installation im Audit-Log.

### 28.10.2 Grundregel

Ein Sicherheitsupdate folgt denselben Prüfregeln wie ein normales
Update, darf aber in der Darstellung besonders hervorgehoben werden.

## 28.11 Wartungsmodus

Vor einem Core- oder Modul-Update kann ein Wartungsmodus aktiviert
werden.

### 28.11.1 Wirkung des Wartungsmodus

Während des Wartungsmodus gilt: Keine reguläre fachliche Nutzung der
Plattform, keine Aktivierung neuer Prozesse, keine Paket- oder
Moduländerungen parallel und klarer Hinweis für Benutzer und
Administratoren.

### 28.11.2 Grundregel

Der Wartungsmodus ist nicht zwingend für jedes Update vorgeschrieben,
muss aber vom Update-Mechanismus unterstützt werden.

## 28.12 Kompatibilitätsprüfung

Vor jeder Installation, Aktivierung und jedem Update muss eine
Kompatibilitätsprüfung durchgeführt werden (konsistent mit Kapitel
24.15; formale Versions- und Matching-Regel siehe Kapitel 26.6.4).

### 28.12.1 Zu prüfende Kompatibilitäten

-   Core-Version gegen Paketversion
-   Main-Modul-Version gegen Extension-Modul-Version
-   Contract-Version gegen registrierte Provider, Collector,
    Listener oder Service-Konsumenten
-   Resolver-Slot-Konflikte
-   Deklarierte Modulabhängigkeiten
-   Lizenzstatus
-   Signaturstatus
-   Auswirkungen auf aktive Integrationsbeziehungen von
    Integrations-Extension-Modulen (Service-Contract-Versionen sind
    durch die Contract-Versionsprüfung abgedeckt)

### 28.12.2 Ergebnisdarstellung

Das Ergebnis der Kompatibilitätsprüfung muss dem Administrator
verständlich angezeigt werden. Erkennbar sein muss insbesondere:
Welche Bedingung erfüllt ist, welche nicht erfüllt ist, welche
Abhängigkeit eine Aktivierung oder ein Update blockiert, welches
Modul einen Resolver-Slot belegt, welche Contract-Versionen betroffen
sind und welche öffentlichen Modul-Interfaces oder
Integrationsbeziehungen betroffen sind.

## 28.13 Verhalten bei Inkompatibilitäten

Wird eine Inkompatibilität festgestellt:

-   Installation oder Update wird blockiert, wenn die
    Inkompatibilität technisch oder fachlich kritisch ist
-   Das System zeigt die betroffenen Module, Contracts oder Slots
    eindeutig an
-   Es erfolgt keine Teilaktivierung eines inkonsistenten Zustands

### 28.13.1 Grundregel

Die Plattform darf keine Installation, Aktivierung oder Aktualisierung
in einen bewusst inkonsistenten Zustand hinein durchführen.

## 28.14 Teilupdates und atomarer Abschluss

Ein Update soll nur dann als erfolgreich gelten, wenn alle notwendigen
Schritte vollständig und konsistent abgeschlossen wurden.

### 28.14.1 Anforderungen

-   Unvollständige Updates dürfen nicht als erfolgreich markiert werden
-   Migrationsfehler müssen den Vorgang als fehlgeschlagen markieren
-   Registrierungsfehler müssen den Vorgang als fehlgeschlagen markieren
-   Der vorherige Zustand muss soweit wie möglich erhalten bleiben oder
    klar als fehlgeschlagen gekennzeichnet werden

### 28.14.2 Wiederherstellungspunkt und Rollback

**Reversible Migrationen.** Jede Schema- oder Datenmigration (Core wie
Modul) muss eine umkehrende down-Operation mitliefern. Migrationen laufen
innerhalb einer Datenbanktransaktion; PostgreSQL unterstützt
transaktionales DDL, sodass Schemaänderungen bei einem Fehler atomar
zurückgerollt werden. Destruktive
Schemaänderungen (Entfernen oder Umbenennen von Spalten oder Tabellen)
erfolgen ausschließlich nach dem expand/contract-Muster: zunächst
additive Änderung (neue Struktur anlegen, Daten übernehmen), erst in
einem späteren, getrennten Schritt Entfernen der Altstruktur.
In-Place-destruktive Änderungen sind unzulässig.

**Wiederherstellungspunkt.** Vor dem Einspielen eines Updates, das
Migrationen enthält, erstellt der Update-Mechanismus verpflichtend einen
Wiederherstellungspunkt in Form eines vollständigen Datenbank-Dumps
(pg_dump). Die
erfolgreiche Erstellung ist Voraussetzung für die Fortsetzung; gelingt
sie nicht (z.B. fehlender Speicherplatz oder fehlende Rechte), wird das
Update vor dem Einspielen abgebrochen. Diese Regel gilt einheitlich für
Core- und Modul-Updates. Es gibt keine Unterscheidung nach Update-Art,
Modultyp oder Risikoeinschätzung; einziger Anknüpfungspunkt ist, ob das
Update Migrationen enthält. Diese Regel greift auch **außerhalb** des
Update-Managers: Werden Migrationen beim **Containerstart** angewendet (neues
Core-Image), zieht der Start-Vorgang ebenfalls zuerst automatisch einen
Wiederherstellungspunkt, sofern Migrationen ausstehen — der Betreiber muss nicht
daran denken. Stehen keine Migrationen aus, entsteht kein unnötiger Dump.

**Rollback bei Fehlschlag.** Schlägt eine Migration fehl, wird die
laufende Migrationstransaktion atomar zurückgerollt (transaktionales
DDL). Erstreckt sich ein Update über mehrere Transaktionen oder sind
bereits committete Schritte betroffen, rollt der Mechanismus die
ausgeführten Migrationen über ihre down-Operationen zurück; ist auch das
nicht vollständig möglich, wird der Stand aus dem Wiederherstellungspunkt
zurückgespielt. Der Vorgang wird als fehlgeschlagen markiert (konsistent
mit Kapitel 28.14.1).

**Grundregel.** Kein migrationsbehaftetes Update ohne zuvor erfolgreich
erstellten Wiederherstellungspunkt. Der Wiederherstellungspunkt ist ein
verpflichtender, systemseitig erzwungener Bestandteil des
Update-Vorgangs und nicht von der Backup-Strategie des Betreibers
(Kapitel 20.1) abhängig.

### 28.14.3 Grundregel

Ein Update ist nur dann abgeschlossen, wenn Paketstand,
Migrationsstand und Registrierungsstand konsistent sind.

## 28.15 Verhalten bei Modul-Deaktivierung durch Lizenzablauf

Wird ein Modul durch Lizenzablauf deaktiviert, muss das System
denselben kontrollierten Zustand herstellen wie bei einer manuellen
Deaktivierung (konsistent mit Kapitel 26.15):

-   Das Modul wird als deaktiviert markiert
-   Resolver-Provider des Moduls werden nicht mehr berücksichtigt
-   Collector-Beiträge werden nicht mehr berücksichtigt
-   Event-Listener werden nicht mehr berücksichtigt
-   GUI-Erweiterungen des Moduls werden ausgeblendet
-   Moduldaten bleiben erhalten
-   Der Status ist für den Administrator sichtbar

### 28.15.1 Grundregel

Lizenzablauf darf zu Deaktivierung, aber niemals zu stiller
Datenlöschung führen.

## 28.16 Update-Historie

Die Plattform muss für Core und Module eine nachvollziehbare
Update-Historie führen.

Diese muss mindestens enthalten: Betroffene Komponente, alte Version,
neue Version, Zeitstempel, ausführender Administrator, Ergebnis und
ggf. Fehlerhinweis.

### 28.16.1 Grundregel

Jede Installation, Aktivierung, Deaktivierung, Aktualisierung oder
Löschung von Core- oder Modulpaketen ist auditierbar.

## 28.17 Administrationsoberfläche für Updates

Die Plattform muss eine geeignete Update-Oberfläche im Admin-Bereich
bereitstellen.

Diese muss mindestens ermöglichen: Anzeige installierter Core- und
Modulversionen, Anzeige verfügbarer Updates, Anzeige von Changelogs,
Anzeige von Lizenzstatus, Anzeige von Kompatibilitätsprüfungen, Start
von Installations- und Updatevorgängen und Einsicht in
Update-Historie.

### 28.17.1 Grundregel

Die Update-Oberfläche muss den Administrator vor jeder
auswirkungsrelevanten Aktion transparent über Version,
Kompatibilität, Lizenzstatus und Folgen informieren.

## 28.18 Architekturprinzipien

Für Core-Update, Modul-Update, Signaturprüfung und
Marketplace-Kommunikation gelten folgende verbindliche Leitregeln:

-   Die Plattform aktualisiert ausschließlich sich selbst und ihre
    Module.
-   Infrastrukturkomponenten wie PHP, PostgreSQL oder Betriebssystem werden
    nicht durch die Plattform aktualisiert.
-   Jedes Paket muss signiert sein.
-   Ohne gültige Signatur darf keine Installation und kein Update
    erfolgen.
-   Ohne erfolgreiche Kompatibilitätsprüfung darf keine Aktivierung
    und kein Update erfolgen.
-   Lizenzprüfung blockiert Aktivierung, nicht die Datenhaltung.
-   Lizenzablauf führt zu Deaktivierung, nicht zu Datenlöschung.
-   Updates dürfen nicht in einen bewusst inkonsistenten Zustand
    führen.
-   Core- und Modulupdates müssen auditierbar sein.
-   Der Marketplace ist die autoritative Quelle für Pakete und
    Metadaten.

# 29. Öffentliche Modul-Interfaces und modulübergreifende Integrationen

## 29.1 Zielsetzung

Öffentliche Modul-Interfaces sind die Service-Ausprägung der Contracts
(Request/Response, Kapitel 26.3.4): formal beschriebene, versionierte
Schnittstellen, über die Main-Module gezielt von anderen Modulen
angesprochen werden können.
Ziel ist es, modulübergreifende Integrationen zu ermöglichen, ohne den
Core fachlich zu öffnen oder direkte, nicht kontrollierte Kopplungen
zwischen Modulen zuzulassen.

Die Architektur der öffentlichen Modul-Interfaces verfolgt
insbesondere folgende Ziele:

-   Formale Bereitstellung fachlicher oder technischer Schnittstellen
    durch Main-Module
-   Kontrollierte Nutzung dieser Schnittstellen durch andere Module
-   Klare Trennung zwischen Interface-Anbieter und Interface-Nutzer
-   Versionierte und prüfbare Integrationsbeziehungen
-   Transparenz für Administratoren über angebotene und genutzte
    Schnittstellen
-   Vermeidung direkter, nicht deklarierter Modulkopplungen
-   Kapselung modulübergreifender Beziehungen in
    Integrations-Extension-Modulen

## 29.2 Grundbegriffe

| **Begriff** | **Bedeutung** |
| --- | --- |
| Öffentliches Modul-Interface | Anwendungsfall eines Service-Contracts (Request/Response, Kapitel 26.3.4): eine von einem Main-Modul bereitgestellte, von anderen Modulen konsumierbare Schnittstelle |
| Interface-Anbieter | Modul, das ein öffentliches Modul-Interface definiert und bereitstellt |
| Interface-Nutzer | Modul, das ein öffentliches Modul-Interface konsumiert |
| Integrationsbeziehung | Deklarierte Nutzung eines öffentlichen Modul-Interfaces durch ein anderes Modul |
| Integrations-Extension-Modul | Extension-Modul, das zwei oder mehr Main-Module über deren öffentliche Interfaces fachlich verbindet |
| Interface-Registry | Zentrale Übersicht über angebotene und genutzte öffentliche Modul-Interfaces |

## 29.3 Einordnung in das Contract-Modell

Ein öffentliches Modul-Interface ist ein Contract vom Typ
Request/Response (Service, Kapitel 26.3.4): Das anbietende Main-Modul ist
der Anbieter (Owner), andere Module sind die Konsumenten. Es ist keine
zweite, parallele Architektur neben den Contracts, sondern eine ihrer
vier Ausprägungen.

### 29.3.1 Verhältnis zu Resolver, Collector und Event

Resolver und Collector kehren die Richtung um: Dort definiert das
Owner-Modul einen Erweiterungspunkt, den externe Module mit einer
Implementierung bzw. Beiträgen besetzen. Bei einem Service-Contract
stellt umgekehrt das Owner-Modul die Implementierung bereit, und externe
Module rufen sie auf. Ein Event ist – wie ein Service – ein vom Owner
bereitgestellter Punkt, jedoch fire-and-forget ohne Rückgabewert; ein
Service liefert eine Antwort.

### 29.3.2 Grundregel

Ein öffentliches Modul-Interface ist kein unspezifischer Hook und keine
interne Service-Methode, sondern ein explizit deklarierter,
versionierter Service-Contract. Für Aufbau, Spezifikation und
Versionierung gelten die Contract-Regeln aus Kapitel 26.4 bis 26.6.

## 29.4 Zielmodell

Main-Module werden als fachliche Tower betrachtet. Jedes Main-Modul
kann eigene öffentliche Modul-Interfaces bereitstellen. Andere Module
können diese Interfaces nutzen, sofern sie kompatibel und ausdrücklich
freigegeben sind.

Modulübergreifende Integrationen sollen bevorzugt in
Integrations-Extension-Modulen gekapselt werden.

Typisches Beispiel: Main-Modul Ticketing + Main-Modul
Wissensdatenbank + Integrations-Extension-Modul
Ticket-Wissensdatenbank. Das Integrations-Extension-Modul konsumiert
dabei ein öffentliches Modul-Interface der Wissensdatenbank und einen
Contract oder Erweiterungspunkt des Ticketing-Moduls. Die Beziehung
zwischen Ticket und Wissensobjekt wird nicht in einem der beiden
Main-Module gespeichert, sondern in einer eigenen Datenhaltung des
Integrations-Extension-Moduls geführt.

## 29.5 Anforderungen an öffentliche Modul-Interfaces

Ein öffentliches Modul-Interface ist ein Service-Contract und folgt dem
Contract-Aufbau (Kapitel 26.4). Jedes öffentliche Modul-Interface muss
formal beschrieben und versioniert sein; die folgende Feldliste
konkretisiert die Contract-Felder für den Service-Fall.

| **Feld** | **Beschreibung** |
| --- | --- |
| Name | Eindeutiger technischer Name des Interfaces |
| Anbieter-Modul | Modul, das das Interface bereitstellt |
| Anbieter-Modul-Version | Version des anbietenden Moduls |
| Interface-Version | Version des Interfaces |
| Beschreibung | Fachliche und technische Beschreibung |
| Input-Spezifikation | Definition der erwarteten Eingabestruktur |
| Output-Spezifikation | Definition der Rückgabestruktur |
| Fehlerverhalten | Verhalten bei Fehlern |
| Verfügbarkeitsregel | Wann das Interface nutzbar ist |
| Mehrfachnutzung erlaubt | Ob mehrere Module dieses Interface parallel nutzen dürfen |

### 29.5.1 Grundregel

Ein öffentliches Modul-Interface ist nur dann gültig, wenn
Eingabeparameter, Antwortobjekt und Version eindeutig dokumentiert und
maschinenlesbar beschrieben sind.

## 29.6 Interface-Spezifikation

Jedes öffentliche Modul-Interface muss eine formale Spezifikation
besitzen.

### 29.6.1 Anforderungen an die Eingabe

Die Spezifikation muss mindestens festlegen: Name und Typ jedes
Parameters, Pflicht- oder Optional-Status, Bedeutung jedes Parameters
und zulässige Wertebereiche (sofern erforderlich).

### 29.6.2 Anforderungen an die Ausgabe

Die Spezifikation muss mindestens festlegen: Name und Typ jedes
Rückgabefeldes, fachliche Bedeutung der Rückgabewerte, Bedeutung von
NULL (sofern zulässig) und Bedeutung leerer Listen oder leerer
Ergebnisobjekte (sofern zulässig).

### 29.6.3 Grundregel

Die Plattform klassifiziert öffentliche Modul-Interfaces nicht
fachlich nach Arten. Die fachliche Bedeutung und Semantik eines
Interfaces wird ausschließlich durch das anbietende Modul definiert.

## 29.7 Versionierung öffentlicher Modul-Interfaces

Jedes öffentliche Modul-Interface ist unabhängig von der Modulversion
selbst versioniert.

### 29.7.1 Änderungsklassen

Als Service-Contract folgt die Versionierung vollständig den
Contract-Regeln (Kapitel 26.6 und 26.6.4):

| **Änderungsart** | **Bedeutung** |
| --- | --- |
| Patch | Keine fachliche Änderung, nur Korrekturen oder Dokumentationsanpassungen |
| Minor | Abwärtskompatible Erweiterung |
| Major | Nicht abwärtskompatible Änderung |

### 29.7.2 Grundregel

Ein Modul darf ein öffentliches Interface nur dann nutzen, wenn die
deklarierte Interface-Version kompatibel ist.

## 29.8 Nutzung öffentlicher Modul-Interfaces

Ein Modul, das ein öffentliches Interface eines anderen Moduls nutzen
will, muss diese Nutzung ausdrücklich deklarieren. Die Deklaration
umfasst mindestens: Ziel-Interface, unterstützte Interface-Version,
nutzendes Modul, implementierende Integrationslogik und Status der
Nutzung.

### 29.8.1 Mehrfachnutzung

Der Standard für öffentliche Modul-Interfaces ist: Mehrfachnutzung
erlaubt. Mehrere Module dürfen dasselbe Interface gleichzeitig nutzen.

Das anbietende Modul kann die Mehrfachnutzung einschränken, indem es
im Interface-Feld "Mehrfachnutzung erlaubt" den Wert "nein" setzt.
In diesem Fall darf nur ein nutzendes Modul gleichzeitig aktiv sein.
Differenziertere Einschränkungen (z.B. "nur lesend mehrfach,
schreibend exklusiv") werden über separate Interfaces mit
unterschiedlichen Mehrfachnutzungs-Regeln abgebildet, nicht über
Sonderlogik innerhalb eines Interfaces.

Für Auswirkungen der Mehrfachnutzung auf Last und Konsistenz ist das
anbietende Modul verantwortlich. Das anbietende Modul muss
sicherstellen, dass parallele Nutzung durch mehrere Module keine
inkonsistenten Zustände erzeugt.

### 29.8.2 Grundregel

Öffentliche Modul-Interfaces sind grundsätzlich mehrfach nutzbar, es
sei denn, das anbietende Modul schränkt dies ausdrücklich ein. Die
Einschränkung wird im Manifest und in der Interface-Registry sichtbar
gemacht.

### 29.8.3 Registrierte Nutzung zur Laufzeit (Capability-Bindung)

Ein Modul nutzt ein öffentliches Modul-Interface ausschließlich über ein
Capability-Handle, das ihm der Core bei der Aktivierung für jede im
Manifest deklarierte und validierte Nutzung übergibt. Das Handle ist an
das nutzende Modul, das Ziel-Interface und die kompatible
Interface-Version gebunden.

Daraus folgt:

-   Ein Modul kann nur Interfaces nutzen, für die es ein Handle besitzt.
-   Es gibt keinen global adressierbaren Zugriff auf interne Dienste des
    anbietenden Moduls.
-   Der Core stellt Handles nur für aktive, kompatible und zulässige
    Nutzungen aus (einschließlich der Mehrfachnutzungs-Regel, Kapitel
    29.8.1).
-   Wird die Nutzung inaktiv (Deaktivierung des nutzenden oder
    anbietenden Moduls, Inkompatibilität), wird das zugehörige Handle
    ungültig.

Die Zugriffskontrolle wirkt durch Konstruktion (nur vergebene Handles
sind nutzbar), nicht durch nachträgliche Prüfung des Aufrufers. Eine
Nutzung ohne gültiges Handle liefert das definierte Abweisungsverhalten
(Kapitel 29.8.4).

Die Capability-Bindung ist eine Zugriffskontrolle auf Interface-Ebene und
ersetzt keine technische Sandbox; zur Einordnung siehe Kapitel 23.16.

### 29.8.4 Verhalten bei Abweisung

Das aufrufende Modul ist verpflichtet, einen abgewiesenen
Interface-Aufruf fachlich kontrolliert zu behandeln. Die konkrete
Reaktion ist moduldefiniert und kann insbesondere bestehen aus:

-   Nutzung eines eigenen Default-Verhaltens
-   Rückgabe eines leeren Ergebnisses
-   Ausblenden einer Integrationsfunktion
-   Anzeige eines fachlichen Fehlers
-   Protokollierung und kontrollierter Abbruch des konkreten
    Teilprozesses

Die Plattform lehnt den nicht registrierten Aufruf technisch ab. Wie
das fachlich verarbeitet wird, entscheidet das aufrufende Modul.

## 29.9 Integrations-Extension-Module

Ein Integrations-Extension-Modul ist ein Extension-Modul, das mehrere
Main-Module über deren öffentliche Interfaces und Contracts fachlich
verbindet.

### 29.9.1 Eigenschaften

Ein Integrations-Extension-Modul kann: öffentliche Interfaces mehrerer
Main-Module konsumieren, Daten zwischen diesen Modulen fachlich
transformieren, strukturierte Antworten an Contracts oder
UI-Erweiterungspunkte anderer Module liefern, eigene Services und
Adminlogik mitbringen und eigene Tabellen für Verknüpfungen,
Beziehungen oder Integrationszustände besitzen.

### 29.9.2 Grundregel

Modulübergreifende Integrationen sollen bevorzugt in dedizierten
Integrations-Extension-Modulen gekapselt werden, nicht durch direkte,
harte Kopplung zwischen Main-Modulen.

## 29.10 Datenhaltung von Integrationsbeziehungen

Beziehungen zwischen zwei oder mehr Main-Modulen sollen nicht durch
direkte Fremdschlüssel oder fachliche Fremdreferenzen in den
Main-Modulen selbst hergestellt werden, sofern diese Beziehung
ausschließlich durch eine modulare Integration entsteht.

### 29.10.1 Grundregel

Wenn eine Beziehung fachlich nur durch ein Integrations-Extension-Modul
existiert, ist diese Beziehung in der Datenhaltung des
Integrations-Extension-Moduls zu speichern.

### 29.10.2 Beispiel

Eine Verknüpfung zwischen Ticket und Wissensartikel wird nicht als
Feld im Ticket-Modul gespeichert. Stattdessen hält das
Integrations-Extension-Modul Ticket-Wissensdatenbank eine eigene
Tabelle für diese Verknüpfung.

### 29.10.3 Ziel

Dadurch bleiben: Main-Module unabhängig, Integrationen optional,
Deaktivierung oder Löschung eines Integrationsmoduls kontrollierbar
und Main-Module frei von fachlichen Fremdreferenzen auf andere
Main-Module.

## 29.11 Regeln für modulübergreifende Integrationen

-   Main-Module dürfen öffentliche Interfaces anbieten.
-   Andere Module dürfen ausschließlich deklarierte öffentliche
    Interfaces konsumieren.
-   Direkte Nutzung interner, nicht freigegebener Modulklassen oder
    Services ist unzulässig.
-   Integrationslogik zwischen Main-Modulen soll in
    Integrations-Extension-Modulen gekapselt werden.
-   Die Nutzung eines zusätzlichen Main-Moduls darf keine stille,
    unkontrollierte Aktivierung neuer Integrationen erzeugen.
-   Beziehungen zwischen Main-Modulen, die nur durch eine Integration
    entstehen, sollen in den Tabellen des Integrations-Extension-Moduls
    gespeichert werden.

### 29.11.1 Sichtbare Aktivierung

Wenn ein Integrations-Extension-Modul zusätzliche Integrationen
aktivieren kann, weil ein weiteres kompatibles Main-Modul installiert
wurde, muss diese neue Integrationsbeziehung im System sichtbar und
nachvollziehbar werden.

### 29.11.2 Grundregel

Es darf keine unsichtbare oder implizite modulübergreifende Kopplung
entstehen.

## 29.12 Interface-Registry im Admin-Bereich

Öffentliche Modul-Interfaces erscheinen als Service-Contracts in der
zentralen Contract-Registry (Kapitel 26.12). Die Interface-Registry ist
die auf Service-Contracts gefilterte Sicht dieser Registry; sie zeigt
die folgenden interface-spezifischen Angaben.

### 29.12.1 Sicht pro angebotenem Interface

Für jedes öffentliche Modul-Interface werden mindestens angezeigt:
Name, Anbieter-Modul, Anbieter-Modul-Version, Interface-Version,
Beschreibung, erwartete Parameter, Antwortobjekt, Fehlerverhalten,
Verfügbarkeitsstatus, Mehrfachnutzung erlaubt (ja/nein) und Anzahl
der aktiven Nutzer.

### 29.12.2 Sicht pro nutzendem Modul

Für jede aktive Nutzung werden mindestens angezeigt: Nutzendes Modul,
Modulversion, Ziel-Interface, genutzte Interface-Version, Status
(aktiv/inaktiv) und Kompatibilitätsstatus.

### 29.12.3 Grundregel

Die Nutzung öffentlicher Modul-Interfaces muss für Administratoren
ebenso transparent sein wie Contracts, Resolver und Registrierungen.

## 29.13 Kompatibilitätsprüfung

Vor Aktivierung oder Update eines Moduls, das öffentliche Interfaces
anbietet oder nutzt, sind mindestens folgende Prüfungen
durchzuführen: Existiert das Ziel-Interface, ist die Zielversion
kompatibel, ist das anbietende Modul aktiv, ist die Mehrfachnutzung
zulässig, bestehen Konflikte mit vorhandenen Integrationen und ist die
deklarierte Nutzung mit der Zielversion noch gültig.

### 29.13.1 Grundregel

Eine inkompatible Nutzung eines öffentlichen Modul-Interfaces blockiert
die Aktivierung oder das Update des betreffenden Moduls.

## 29.14 Verhalten bei Deaktivierung eines anbietenden Moduls

Wird ein Modul deaktiviert, das öffentliche Interfaces bereitstellt:
Das Interface steht nicht mehr zur Verfügung, nutzende Module dürfen
dadurch nicht unkontrolliert fehlschlagen, betroffene Integrationen
werden als inaktiv markiert und alle daraus resultierenden Fehler-
oder Fallback-Zustände müssen nachvollziehbar sein.

### 29.14.1 Grundregel

Die Deaktivierung eines anbietenden Moduls darf nicht zur Zerstörung
der Grundlauffähigkeit eines anderen Main-Moduls führen.

## 29.15 Verhalten bei Deaktivierung eines nutzenden Moduls

Wird ein Modul deaktiviert, das öffentliche Interfaces anderer Module
nutzt: Die Nutzung des Interfaces endet, die Integrationsbeziehung
wird als inaktiv markiert, das anbietende Modul bleibt unverändert
lauffähig und es erfolgt keine Änderung an der Datenhaltung des
anbietenden Moduls.

## 29.16 Auditierbarkeit

Folgende Vorgänge sind im Audit-Log zu protokollieren: Definition
eines öffentlichen Interfaces, Aktivierung oder Deaktivierung eines
anbietenden Interfaces, Registrierung einer Interface-Nutzung,
Aktivierung oder Deaktivierung einer Integrationsbeziehung,
Inkompatibilitäten bei Aktivierung oder Update und Wechsel der
genutzten Interface-Version.

## 29.17 Beispiel: Ticketing und Wissensdatenbank

### 29.17.1 Main-Modul Wissensdatenbank

Das Main-Modul Wissensdatenbank kann ein öffentliches Modul-Interface
bereitstellen. Es legt selbst fest: welche Such- oder Lesekontexte
unterstützt werden, welche Parameter erwartet werden, welches
Antwortobjekt zurückgegeben wird und wie Fehler behandelt werden.

### 29.17.2 Main-Modul Ticketing

Das Main-Modul Ticketing enthält keine direkte fachliche
Fremdreferenz auf Wissensobjekte. Es bietet stattdessen Contracts
oder UI-Erweiterungspunkte an, über die strukturierte
Integrationsinformationen in die Ticketansicht eingebracht werden
können.

### 29.17.3 Integrations-Extension-Modul Ticket-Wissensdatenbank

Das Integrations-Extension-Modul kann: das öffentliche Modul-Interface
der Wissensdatenbank nutzen, Contracts oder UI-Erweiterungspunkte des
Ticketing-Moduls nutzen, eine eigene Tabelle zur Speicherung der
Verknüpfung zwischen Ticket und Wissensobjekt besitzen und
strukturierte Informationen in die Ticket-UI einbringen.

### 29.17.4 Grundregel

Die fachliche Brücke zwischen Ticketing und Wissensdatenbank lebt im
Integrations-Extension-Modul, nicht im Core und nicht als harte
Direktkopplung der beiden Main-Module.

## 29.18 Architekturprinzipien

Für öffentliche Modul-Interfaces und modulübergreifende Integrationen
gelten folgende verbindliche Leitregeln:

-   Main-Module dürfen öffentliche Interfaces anbieten.
-   Öffentliche Interfaces müssen formal beschrieben und versioniert
    sein.
-   Das anbietende Modul definiert selbst Semantik, Parameter und
    Antwortobjekt des Interfaces.
-   Andere Module dürfen nur deklarierte öffentliche Interfaces
    konsumieren.
-   Interne, nicht freigegebene Services eines Moduls dürfen nicht
    direkt von anderen Modulen genutzt werden.
-   Öffentliche Modul-Interfaces müssen im Admin-Bereich transparent
    sichtbar sein.
-   Mehrfachnutzung öffentlicher Interfaces ist zulässig, sofern das
    anbietende Modul dies erlaubt.
-   Modulübergreifende Integrationen sollen bevorzugt in
    Integrations-Extension-Modulen gekapselt werden.
-   Beziehungen zwischen Main-Modulen, die nur durch eine Integration
    entstehen, sollen in den Tabellen des Integrations-Extension-Moduls
    gespeichert werden.
-   Die Deaktivierung eines anbietenden Moduls darf nicht die
    Grundlauffähigkeit anderer Main-Module zerstören.
-   Es darf keine unsichtbare oder implizite modulübergreifende
    Kopplung entstehen.
-   Alle relevanten Integrationsbeziehungen sind auditierbar.


# 30. Datenbankfundament (PostgreSQL)

## 30.1 Zielsetzung

Die Plattform nutzt PostgreSQL nicht nur als Persistenz, sondern als
aktiven Bestandteil der Architektur: Integrität, Nebenläufigkeit,
Zugriffsschutz und asynchrone Verarbeitung werden – wo sinnvoll – in der
Datenbank durchgesetzt, nicht allein in der Anwendung. Leitgedanke ist
Defense-in-Depth: Die Anwendung bleibt die primäre Schicht, die Datenbank
ist das verlässliche Sicherheitsnetz.

### 30.1.1 Grundregel (Constraint-First)

Integritäts- und Zugriffsregeln, die sich in der Datenbank ausdrücken
lassen, werden dort erzwungen (Fremdschlüssel, Unique-/Partial-Unique-,
Check- und Exclusion-Constraints, Row-Level Security). Anwendungslogik
ergänzt diese Regeln, ersetzt sie aber nicht.

## 30.2 Integrität über Datenbank-Constraints

-   **Fremdschlüssel** sichern referenzielle Integrität.
-   **Partielle Unique-Constraints** erzwingen "genau ein aktiver
    X"-Regeln direkt in der Datenbank, insbesondere: genau ein aktiver
    Provider pro Resolver-Slot (Kapitel 26.7, `UNIQUE (slot) WHERE
    active`); genau ein als Standard markierter Wert pro
    Konfigurationsentität; eindeutige Zuordnungen mit Aktiv-Bedingung
    (z.B. eine aktive Eingangs-Mailbox pro Queue im Ticketing-Modul).
-   **Check-Constraints** sichern Wertebereiche und Statusinvarianten.
-   **Exclusion-Constraints (GiST)** erzwingen Überlappungsfreiheit von
    Bereichen (z.B. nicht überlappende Geschäftszeit-Fenster).

### 30.2.1 Grundregel

"Genau ein aktiver"-Invarianten und Überlappungsfreiheit werden über
partielle Unique- bzw. Exclusion-Constraints in der Datenbank
durchgesetzt, nicht nur über Anwendungsprüfungen.

## 30.3 Row-Level Security (verpflichtend für scoped Modultabellen)

Module, deren Daten gruppen- oder bereichsbezogen geschützt sind (z.B.
queue-bezogene Tickets), müssen ihre betroffenen Tabellen mit
Row-Level-Security-Policies (RLS) absichern. RLS ist ein verpflichtendes
Defense-in-Depth-Netz unter dem BREAD-Modell (Kapitel 25): Die Anwendung
prüft Rechte weiterhin primär; die DB-Policy stellt zusätzlich sicher,
dass keine Query Zeilen außerhalb des erlaubten Scopes liefert – auch
nicht bei einem fehlenden Filter in GUI, API, CLI, Reporting oder Export.

### 30.3.1 Anforderungen

-   Jede gruppen-/bereichs-scoped Modultabelle aktiviert RLS
    (`ENABLE ROW LEVEL SECURITY`, `FORCE ROW LEVEL SECURITY`).
-   Das Modul liefert seine RLS-Policies als Bestandteil seiner
    Migrationen mit (analog zu Tabellen und Indizes).
-   Der Core **erzwingt** dies bei der Installation: Deklariert ein Modul
    scoped (`is_scoped`) Ressourcen, muss sein Schema nach den Migrationen
    mindestens eine RLS-aktivierte Tabelle **mit Policy** enthalten — sonst
    wird die Installation abgebrochen (Rückbau).
-   Der Zugriffskontext (aktueller Benutzer und seine effektiven Gruppen)
    wird pro Transaktion über eine Session-Variable gesetzt
    (`SET LOCAL`), kompatibel mit Connection-Pooling.
-   Die Policy-Prädikate sind indexnah formuliert (z.B. über eine
    Hilfsfunktion oder eine zwischengespeicherte Mitgliedschafts-Sicht).

### 30.3.2 Definierte Bypass-Pfade

Legitime Vollzugriffe (Wartung, Migrationen, bestimmte Hintergrund-Jobs,
DSGVO-/Hard-Delete-Vorgänge) erfolgen über eine ausdrücklich definierte
Rolle bzw. einen `BYPASSRLS`-Pfad. Solche Zugriffe sind dokumentiert und
auditierbar; ein unkontrolliertes Umgehen der Policies ist unzulässig.

### 30.3.3 Verhältnis zu BREAD und Row-Scoping

RLS ersetzt nicht das BREAD-Modell: BREAD regelt Aktionsrechte
(Browse/Read/Add/Edit/Delete und Zusatzaktionen), RLS regelt
Zeilen-Sichtbarkeit. RLS ist zugleich der designierte Mechanismus für
künftiges feingranulares Row-Scoping (z.B. "nur eigene
Queues/Organisation", vgl. Kapitel 25.6.3) und für eine etwaige spätere
Mandantenfähigkeit.

### 30.3.4 Grundregel

Gruppen-/bereichs-scoped Modultabellen sind über RLS abgesichert. Die
DB-Policy ist ein verpflichtendes Sicherheitsnetz unter der
serverseitigen Rechteprüfung, kein Ersatz dafür.

## 30.4 Transaktionale Migrationen

PostgreSQL unterstützt transaktionales DDL. Schema- und Datenmigrationen
laufen in einer Transaktion und werden bei Fehlern atomar zurückgerollt
(Kapitel 1.8 und 28.14.2). Der Wiederherstellungspunkt (pg_dump) bleibt
ergänzendes Sicherheitsnetz.

## 30.5 JSONB für semi-strukturierte Daten

Semi-strukturierte, schemaschwache Daten werden in JSONB-Spalten
gespeichert und – wo abgefragt – über GIN-Indizes erschlossen.
Anwendungsfälle: Audit-Log (alter/neuer Wert und Änderungsdetails als
Payload, Kapitel 1.6), Event-Outbox (Event-Payload, Kapitel 26.9.2),
Contract-/Registry-/Manifest-Metadaten (Kapitel 23.8, 26.10),
Konfigurationsspeicher (strukturierte Konfigurationswerte).

Strikt relationale, häufig gefilterte Fachdaten bleiben in
normalisierten Spalten; JSONB ersetzt nicht das relationale Modell,
sondern ergänzt es für offene/variable Strukturen.

## 30.6 Asynchrone Verarbeitung: Outbox mit LISTEN/NOTIFY

Der transaktionale Outbox (Kapitel 26.9.2) wird um PostgreSQL
LISTEN/NOTIFY ergänzt: Nach dem Commit eines Outbox-Eintrags
benachrichtigt die Datenbank den Worker (`NOTIFY`), der das Event
latenzarm verarbeitet. Der periodische Cron-Lauf bleibt als Fallback
bestehen (Robustheit bei verpasster Benachrichtigung oder
Worker-Neustart). Die Zustellgarantie (mindestens einmal, idempotente
Listener) bleibt unverändert.

## 30.7 Nebenläufigkeit: Advisory Locks

Der exklusive Lifecycle-Lock (Kapitel 24.18) wird über einen
PostgreSQL-Advisory-Lock realisiert. Dadurch wirkt die Serialisierung
lifecycle-verändernder Operationen nicht nur prozesslokal, sondern über
alle Anwendungsknoten hinweg, die dieselbe Datenbank nutzen. Das
beseitigt die Single-Instance-Annahme für diesen Mechanismus und macht
ihn mehrknotenfähig.

### 30.7.1 Instanzübergreifender Session-Speicher (HA-Voraussetzung)

Für einen **Mehrinstanz-Betrieb der Web-Schicht** (Hochverfügbarkeit)
muss der Session-Zustand zwischen den Instanzen geteilt sein; eine
datei-basierte Ablage ist knotenlokal und damit ungeeignet. Die
Plattform unterstützt daher einen **DB-gestützten Session-Speicher**
(`core.sessions`, CakePHP `DatabaseSession`), aktivierbar über
`SESSION_DEFAULTS=database`. Damit sehen alle Web-Instanzen denselben
Anmeldezustand; zusätzlich überleben Sessions einen Container-Recreate
(kein Zwangs-Logout beim Deploy). Zusammen mit dem mehrknotenfähigen
Advisory-Lock (30.7), dem `SKIP LOCKED`-Outbox und der Advisory-Lock-
Serialisierung periodischer Aufgaben (20.3) sind damit die DB-seitigen
Voraussetzungen für einen Mehrinstanz-Betrieb erfüllt. Der
**Standardbetrieb bleibt Einzelinstanz** (Default `php` bzw. im
Referenz-Compose `database`); HA ist ein bewusster Betreiber-Schritt, der
zusätzlich geteilte persistente Volumes (Sprachpaket-/Modul-Stores) und
einen vorgelagerten Lastverteiler erfordert (Infrastruktur).

## 30.8 Partitionierung großer Tabellen

Kontinuierlich wachsende Tabellen werden über deklarative
Zeitbereichs-Partitionierung beherrschbar gehalten, insbesondere das
Audit-Log (Kapitel 20.6) und der Event-Outbox. Alte Partitionen können
effizient archiviert oder (wo zulässig) abgetrennt werden. Module mit
sehr großen Tabellen (z.B. Ticketing-Einträge) können dasselbe Muster
anwenden.

## 30.9 Architekturprinzipien

Für das Datenbankfundament gelten folgende verbindliche Leitregeln:

-   Integrität wird in der Datenbank durchgesetzt, nicht nur in der
    Anwendung (Constraint-First, Defense-in-Depth).
-   "Genau ein aktiver"-Invarianten über partielle Unique-Constraints.
-   Überlappungsfreiheit über Exclusion-Constraints.
-   Gruppen-/bereichs-scoped Modultabellen sind über RLS abgesichert
    (verpflichtend), mit definierten Bypass-Pfaden.
-   Migrationen sind transaktional und atomar rückrollbar.
-   Semi-strukturierte Daten in JSONB, relationale Fachdaten
    normalisiert.
-   Asynchrone Verarbeitung über transaktionalen Outbox + LISTEN/NOTIFY,
    Cron als Fallback.
-   Knotenübergreifende Serialisierung über Advisory Locks.
-   Große, wachsende Tabellen werden partitioniert.

# 20. Betrieb und Betreiberperspektive

Hinweis: Die Abschnitte 20.3 (Cronjob-Überwachung) und 20.4
(E-Mail-Betriebsüberwachung) beziehen sich auf das Ticketing-
Main-Modul und sind im Modul-Anforderungsdokument Ticketing
detailliert beschrieben. Alle anderen Abschnitte gelten
plattformweit.

(Hosting, Administration, Überwachung). Die Anforderungsklassifikation
(Kapitel 1.7) wird hier explizit angewendet, um Muss-Anforderungen an
die Software klar von Betriebsempfehlungen an den Betreiber zu trennen.

## 20.1 Backup und Wiederherstellung

Backup und Wiederherstellung erfolgen auf **zwei Ebenen** mit klar getrennter
Zuständigkeit.

### 20.1.1 Infrastruktur-Backup/-Restore (Empfehlung, Systemadministrator)

Die Sicherung der **Infrastruktur** liegt **außerhalb von Fertura** in der
Verantwortung des Betreibers bzw. Systemadministrators: Host- und
Volume-Snapshots, PostgreSQL-Streaming-Replikation / Point-in-Time-Recovery
(PITR), die **Off-Site-Ablage** der Sicherungen sowie deren **Scheduling** und
**Aufbewahrungsdauer**. Hierfür sind die Standard-PostgreSQL- und
Storage-Verfahren anwendbar.

### 20.1.2 Daten-Backup/-Restore (Systemfunktion, Fertura Core)

Die **konsistente Sicherung und Wiederherstellung der Anwendungsdaten** ist eine
**Systemfunktion** des Core. Eine Sicherung umfasst beide Datenbereiche zum
selben Zeitpunkt:

-   **Datenbank (PostgreSQL):** Tickets, Einträge, Konfigurationen, Benutzer,
    Audit-Log u. a. — vollständiger Dump (`pg_dump`, custom-format).

-   **Datei-Storage:** die persistenten Stores (Sprachpakete, Marketplace-Daten,
    installierter Modulcode; Anhänge/Inline-Bilder, sofern lokal konfiguriert —
    Speicherpfad in config/app.php).

Die Erstellung ist **konsistent** (Datenbank und Datei-Storage werden unter einem
Lifecycle-Lock zum selben Zeitpunkt gesichert) und **prüfbar** (Prüfsummen je
Artefakt; ein Probe-Restore in eine Wegwerf-Datenbank weist die
Wiederherstellbarkeit nach, ohne die Produktion zu berühren). Der Core stellt
dafür CLI- **und** GUI-Funktionen bereit: Erstellen, Auflisten, Prüfen,
Probe-Restore und Löschen sowie eine **destruktive Wiederherstellung** der
Anwendungsdaten (ausdrücklich zu bestätigen, CLI).

Jede Sicherung ist **ein ZIP-Archiv** mit UTC-Zeitstempel im Namen (gezielt
identifizierbar). Weitere Eigenschaften:

-   **Verifikation vor Abschluss:** Integritätsprüfung immer, optional zusätzlich
    ein Probe-Restore; nur verifizierte Sicherungen gelten als gültig.
-   **Verschlüsselung (optional, Segregation of Duty):** AES-256 des
    Archivinhalts über ein Secret-Passwort — ohne Passwort nichts lesbar. Für
    **Desaster-Recovery** muss das Passwort **out-of-band** bereitgestellt
    werden (Umgebungsvariable bzw. Secret-Datei), **nicht** über ein DB-Setting:
    Letzteres läge im Dump und wäre auf einem frischen System nicht verfügbar.
-   **Planung & Aufbewahrung:** konfigurierbarer Zeitplan (Frequenz) + Retention
    nach Anzahl **und** Alter; konfigurierbarer Ablageort (Linux-/Windows-Pfad).
-   **Protokoll:** unveränderliches (append-only) Operationsprotokoll über
    Backups und Restores (inkl. Download/Export) in der GUI.
-   **Robustheit/Betrieb:** Pre-Flight-Speicherprüfung, E-Mail-Alarm bei
    Fehlschlag, Download des Archivs aus der GUI, Health-Subsystem `backup`.

Hinweis: Die Backup-Strategie ist damit eine Core-Systemfunktion (nicht mehr nur
Empfehlung); Off-Site-Ablage/Scheduling der erzeugten Archive bleibt
Infrastruktur-Aufgabe (20.1.1).

Die so erzeugten Sicherungen werden auf einem persistenten Volume abgelegt; ihre
Off-Site-Ablage, das Scheduling und die Aufbewahrung sind Teil des
Infrastruktur-Backups (20.1.1).

## 20.2 System-Health und Monitoring (Muss: Health-Endpoint und Statusflächen / Empfehlung: Betreiber-Alerting)

Der Core stellt Observability als Systemfunktion bereit. Das externe
Alerting und der Betrieb von Dashboards bleiben Betreibersache
(Empfehlung).

### 20.2.1 Health-Endpoint (Muss)

Der Core stellt einen Health-Endpoint HTTP GET /health bereit, der den
Status der Subsysteme als JSON liefert. Aggregiert werden mindestens:
Datenbank, Datei-Storage, Aktualität der CLI-Worker (inkl.
Outbox-Worker, Kapitel 26.9.2), Registry- und Modulzustand,
Dead-Letter-Zähler, Lizenzstatus, Lokalisierung (Sprachpakete: fehlende
Englisch-Basis, Versionsfehler) und Daten-Backup (fehlendes/fehlgeschlagenes/
überfälliges Backup bei aktivem Zeitplan).

Zugriffsschutz: Ein minimaler Liveness-Check (nur "up"/"down" ohne
Detail) ist ohne Authentifizierung erreichbar; der detaillierte
Subsystem-Status ist token- bzw. authentifizierungsgeschützt, damit
keine internen Zustände unautorisiert offengelegt werden.

### 20.2.2 Modul-Beiträge zur Health (Health-Collector)

Module steuern eigene Health-Checks über einen vom Core definierten
Health-Collector-Contract bei (Kapitel 26.3.2). Der Core aggregiert
diese Beiträge in den Health-Endpoint. So wird z.B. die
Mailbox-Erreichbarkeit des Ticketing-Moduls als Collector-Beitrag
modelliert, nicht im Core verdrahtet. Ohne registrierte Beiträge bleibt
das aggregierte Ergebnis leer (Collector-Leerergebnis).

### 20.2.3 Strukturierte Logs (Soll)

Anwendungs- und Betriebslogs werden in maschinenlesbarer, strukturierter
Form geschrieben (z.B. JSON-Felder für Zeitstempel, Ebene, Komponente,
Modul, Korrelations-ID), um Auswertung und SIEM-Integration zu
erleichtern (Kapitel 20.7).

### 20.2.4 Admin-Statusfläche (Soll)

Der Admin-Bereich zeigt den Betriebszustand: Modul-Lifecycle-Zustände,
Registry-Gesundheit, Outbox- und Dead-Letter-Stand, Lizenzstatus sowie
die Aktualität der CLI-Worker (festigt das Cron-Status-Widget aus
Kapitel 20.3).

### 20.2.5 Betreiberseitige Überwachung (Empfehlung)

Folgende Betriebszustände sollten vom Betreiber – z.B. über den
Health-Endpoint oder eigene Prüfungen – überwacht werden. Das Alerting
selbst ist Betreibersache:

| **Prüfpunkt** | **Methode** | **Empfohlenes Intervall** |
| --- | --- | --- |
| Mailbox-Erreichbarkeit | IMAP-Verbindungstest pro aktiver Mailbox (Admin-GUI oder automatisiert) | Alle 15 Minuten |
| E-Mail-Versand-Queue | Anzahl fehlgeschlagener E-Mails in email_queue (Status: fehlgeschlagen) | Alle 5 Minuten |
| Cronjob-Ausführung | Prüfung, ob CLI-Commands (fetch_mails, check_escalations, process_email_queue) regelmäßig laufen | Alle 10 Minuten |
| Datenbank-Verbindung | Standard-PostgreSQL-Health-Check | Alle 1 Minute |
| Datei-Storage | Schreib-/Lesezugriff auf konfigurierten Speicherpfad oder S3-Bucket | Alle 15 Minuten |
| Festplattenauslastung | Verfügbarer Speicherplatz für Datenbank, Logs und Anhänge | Alle 30 Minuten |
| Anwendungs-Logs | Überwachung auf PHP-Fehler, CakePHP-Exceptions und kritische Warnungen | Kontinuierlich |

Das externe Alerting erfolgt über die betreiberseitige Infrastruktur
(z.B. Nagios, Zabbix, Uptime-Monitoring), bevorzugt gegen den
Health-Endpoint (20.2.1).

## 20.3 Cronjob-Überwachung (Muss: Protokollierung / Soll: Dashboard-Widget)

Die CLI-Commands (siehe Modul-Dokument Ticketing, Kapitel 14) sind für den Betrieb essenziell.
Fällt ein Cronjob aus, hat das direkte Auswirkungen:

| **Command** | **Auswirkung bei Ausfall** |
| --- | --- |
| fetch_mails | Keine neuen Tickets aus E-Mails, keine Zuordnung zu bestehenden Tickets |
| check_escalations | SLA-Verletzungen werden nicht erkannt, keine Eskalationsbenachrichtigungen |
| process_email_queue | Ausgehende E-Mails (Antworten, Autoantworten, Benachrichtigungen) werden nicht zugestellt |
| send_digest | Digest-Benachrichtigungen bleiben aus |
| purge_tickets | Abgelaufene Tickets werden nicht automatisch gelöscht (DSGVO-Risiko) |
| process_scheduled_changes | Geplante Statuswechsel werden nicht ausgeführt |
| send_followup_reminders | Wiedervorlage-Erinnerungen bleiben aus |

Protokollierung (Muss): Jeder CLI-Command protokolliert seinen letzten
erfolgreichen Lauf mit Zeitstempel (Heartbeat).

Dashboard-Widget (Soll): Ein Admin-Dashboard-Widget zeigt den Status
aller Commands an (letzte Ausführung, Dauer, Ergebnis). Bei
Überschreitung des erwarteten Intervalls um mehr als das Doppelte wird
ein visueller Warnhinweis im Admin-Dashboard angezeigt.

**Andock-Punkt für periodische Modul-Aufgaben.** Der Core bietet einen
Collector (`core.collector.scheduled`), über den Module periodische Aufgaben
(z.B. `fetch_mails`, `check_escalations`) deklarieren. Der Core-Worker führt sie
im angegebenen Intervall **fehlerisoliert** aus, protokolliert je Aufgabe einen
Heartbeat (erscheint damit in der Worker-Aktualität inkl. Überfälligkeitswarnung)
und hält den Fehler eines Jobs vom Rest fern. Die fachliche Logik bleibt im
Modul; der Core stellt nur die Ausführungs- und Überwachungsinfrastruktur bereit
(Alternative zu einem separaten Modul-Cron).

**Mehrere Worker-Instanzen.** Der Hintergrundprozess (Outbox-Verarbeitung +
Scheduler) darf mehrfach betrieben werden. Die Event-Verarbeitung ist über
`FOR UPDATE SKIP LOCKED` kollisionsfrei (Kapitel 26.9.2); die periodischen
Aufgaben werden je Aufgabe über einen **PostgreSQL-Advisory-Lock** serialisiert,
sodass auch bei mehreren Worker-Instanzen **keine** Aufgabe doppelt läuft (kein
doppeltes geplantes Backup o.ä.). Einzelinstanz bleibt der Standard; mehrere
Instanzen dienen Durchsatz/Resilienz des Async-Tiers (unabhängig von der
HA-Frage des Web-Tiers).

## 20.4 E-Mail-Betriebsüberwachung (Muss: Logging und Statusanzeige / Empfehlung: aktives Monitoring)

Da E-Mail der primäre Kanal ist (siehe Kapitel 1.7.1), erfordert
der Mailbox-Betrieb besondere Aufmerksamkeit:

-   **Fehlgeschlagene E-Mails:** Die email_queue-Tabelle zeigt den
    Zustellstatus aller ausgehenden E-Mails. E-Mails mit Status
    "fehlgeschlagen" nach Ausschöpfung aller Retries lösen eine
    Admin-Benachrichtigung aus (siehe Modul-Dokument Ticketing, Kapitel 3.11).

-   **Verworfene E-Mails:** E-Mails, die bei der Klassifizierung
    verworfen werden (OOF, Kalendereinladungen, DSN, Blacklist),
    werden im Applikationslog protokolliert (siehe Modul-Dokument Ticketing, Kapitel 3.4/3.5).
    Administratoren sollten dieses Log regelmäßig prüfen.

-   **Mailbox-Verbindungsprobleme:** Bei wiederholtem
    Verbindungsfehlschlag einer Mailbox (IMAP oder SMTP) wird ein
    Logeintrag geschrieben. Der Admin-Bereich zeigt den letzten
    erfolgreichen Verbindungstest pro Mailbox an.

-   **Rate-Limiting-Status:** Die aktuelle Autoantwort-Rate pro
    Absenderadresse ist im Admin-Bereich einsehbar, um
    Rate-Limiting-Auslösungen nachzuvollziehen.

## 20.5 Datenvolumen und Performance (Muss: Indizes / Empfehlung: Betreibermaßnahmen)

Das System ist für folgende Größenordnungen ausgelegt (siehe auch
Modul-Dokument Ticketing, Kapitel 18, Nicht-funktionale Anforderungen):

-   Bis zu 10.000 offene Tickets mit Ladezeiten unter 2 Sekunden
    (paginiert)
-   Beliebig viele geschlossene Tickets (abhängig von
    Aufbewahrungsdauer und Hardware)
-   Beliebig viele Queues und Benutzer

Datenbank-Indizes (Muss): Das Datenmodell setzt Indizes auf alle
Fremdschlüssel und häufig gefilterte Felder (Status, Queue, Priorität,
Erstellungsdatum). Diese Indizes sind Teil des Datenbankschemas und
werden bei der Installation automatisch angelegt.

Betreibermaßnahmen bei wachsendem Datenvolumen (Empfehlung):

-   **Aufbewahrungsdauer nutzen:** Pro Queue eine sinnvolle
    Aufbewahrungsdauer konfigurieren (Modul-Dokument Ticketing, Kapitel 4.1). Der Command
    purge_tickets löscht abgelaufene Tickets automatisch.

-   **Zusätzliche Indizes:** Bei Bedarf können Indizes auf freie
    Felder oder Tags gesetzt werden.

-   **Anhang-Storage:** Bei hohem Anhang-Aufkommen wird S3-
    kompatibler Storage empfohlen (konfigurierbar in config/app.php).

## 20.6 Audit-Log-Betrieb (Muss: keine Löschung im System / Empfehlung: Archivierung)

Das Audit-Log wächst kontinuierlich und wird nie automatisch
bereinigt. Bei lang laufenden Installationen können folgende
Maßnahmen notwendig werden:

-   Regelmäßiger Export älterer Audit-Log-Einträge in ein Archiv
-   Deklarative Datenbankpartitionierung nach Zeitraum (PostgreSQL,
    z.B. monatliche Partitionen; siehe Kapitel 30.8)
-   Gezielte Indizierung für häufige Abfragen (nach Benutzer,
    Zeitraum, Entitätstyp)

Das Audit-Log selbst darf nicht im System gelöscht oder verändert
werden. Archivierung erfolgt außerhalb des Systems. Einzige zulässige
Änderung ist die irreversible Anonymisierung des Personenbezugs im
Rahmen des Rechts auf Löschung (Kapitel 27.15.3): Die protokollierten
Vorgänge bleiben unverändert erhalten, lediglich personenbezogene
Klardaten werden durch eine anonymisierte Referenz ersetzt.

Audit-Einträge sind referenzrobust gespeichert und bleiben auch nach
Löschung des auslösenden Moduls oder betroffenen Objekts vollständig
lesbar (Kapitel 24.16.1).

## 20.7 Explizite Betriebsgrenzen

Folgende Funktionen sind bewusst **nicht Teil des Systems** und liegen
in der Verantwortung des Betreibers oder externer Werkzeuge:

| **Thema** | **Systemleistung** | **Betreiberverantwortung** |
| --- | --- | --- |
| Backup/Restore | Konsistentes Datenmodell (DB + Storage) | Backup-Strategie, Scheduling, Aufbewahrung |
| Infrastruktur-Monitoring | Health-Endpoint (/health), strukturierte Logs, Admin-Statusflächen (Kapitel 20.2) | Externes Alerting, Dashboards, Uptime-Monitoring (Nagios, Zabbix etc.) |
| Hochverfügbarkeit | Standard-Webserver-Setup (Apache/Nginx + PHP-FPM) | Redundanz, Load-Balancing, Failover |
| Queue-Worker | CLI-Commands per Cronjob | Cronjob-Konfiguration und -Überwachung |
| E-Mail-Infrastruktur | IMAP-Abruf und SMTP-Versand | Mailserver-Betrieb, DNS (MX, SPF, DKIM), TLS-Zertifikate |
| Sicherheitsupdates | CakePHP und PHP-Abhängigkeiten | Betriebssystem, Webserver, PostgreSQL, PHP-Runtime |
| Log-Rotation | Schreiben in konfiguriertes Log-Verzeichnis | Log-Rotation, Archivierung, Speicherplatz |
| SIEM/Security-Audit | Audit-Log mit allen relevanten Aktionen | Integration in SIEM-Systeme |
| SSL/TLS-Terminierung | Keine (Anwendung liefert HTTP) | HTTPS-Terminierung über Reverse-Proxy |


## 20.8 Deployment- und Distributionsmodell

Der Core wird als eigenständiges Container-Image bereitgestellt (PHP-FPM
+ Core-Code). Die Laufzeitumgebung wird aus getrennten Diensten
komponiert; einzelne Anliegen laufen in eigenen Containern.

### 20.8.1 Trennung von Core und Datenbank

PostgreSQL ist nicht Bestandteil des Core-Images, sondern ein
eigenständiger Dienst (eigener Container oder extern/Managed). Das ist
konsistent mit der Update-Scope-Grenze (Kapitel 28.2): Ein Core-Update
ersetzt nicht die Datenbank. Daten-Lebenszyklus, Backup/PITR (Kapitel
20.1, 28.14.2) und Skalierung/HA (mehrere Core-Knoten an einer DB,
Advisory-Lock Kapitel 30.7) bleiben dadurch sauber trennbar.

### 20.8.2 Sofort lauffähig per Compose

Für den schnellen Start wird eine Container-Compose-Konfiguration
bereitgestellt, die Core, Reverse Proxy, PostgreSQL und den Worker als
getrennte Dienste verdrahtet. Damit ist der Core nach dem Download mit
einem Befehl lauffähig ("clone & up"), ohne mehrere Anliegen in einem
Container zu vermengen.

### 20.8.3 Dev/Demo versus Produktion

-   Dev/Eval: Compose mit Wegwerf-PostgreSQL und Mail-Catcher. Ein
    optionales All-in-One-Image ist ausschließlich für Demo-/
    Evaluationszwecke zulässig und ausdrücklich als nicht
    produktionstauglich zu kennzeichnen.
-   Produktion: Core-Image + extern verwaltetes PostgreSQL + Reverse
    Proxy + skalierbarer Worker; Backup, Monitoring und DB-Betrieb gemäß
    Kapitel 20.

### 20.8.4 Grundregel

Der Core wird getrennt von der Datenbank ausgeliefert. PostgreSQL wird
nie in das produktive Core-Image eingebettet (Ausnahme: klar
gekennzeichnete Demo-Images). Die Laufzeitumgebung wird aus getrennten
Diensten komponiert.

### 20.8.5 Optionale Subsysteme abschaltbar (Feature-Flags)

Der Plattform-Core deckt bewusst eine breite, aber legitime Fläche ab
(Identität, Module, Registry, Eventing, Observability, Updates, Backup,
i18n). Damit je Installation **nur das Nötige** läuft (kleinere Angriffs-
und Wartungsfläche), lassen sich **optionale Subsysteme pro Deployment
über Umgebungsvariablen abschalten** (`FEATURE_<NAME>=false`):

-   **`FEATURE_API`** — die externe API v1 (`/api/v1`, Bearer-Token). Aus:
    keine `/api`-Routen, kein API-Auth-Middleware geladen.
-   **`FEATURE_MARKETPLACE`** — der Marketplace-Client (ausgehende Sync-
    Aufrufe). Aus: kein Sync über CLI/GUI; die **Lizenzverwaltung bleibt**
    verfügbar (Lizenzdateien werden offline eingespielt).
-   **`FEATURE_BACKUP_SCHEDULER`** — automatische (geplante) Backups. Aus:
    kein Scheduler-Lauf; **manuelles** Backup/Restore bleibt verfügbar.

Die Flags sind bewusst **env-basiert** (harter Betreiber-Schalter, nicht
über eine kompromittierte Admin-Sitzung reaktivierbar); Standard ist
**alle aktiv** (kompatibel). Der Zustand wird unter `/health` (`features`)
ausgewiesen. Kernfunktionen (Identität, Datenmodell, Lifecycle, RLS,
Observability-Grundlage) sind nicht abschaltbar.

# 31. Mehrsprachigkeit und Lokalisierung

Der Core, Module und Extensions sind mehrsprachig. Die Anzeigesprache ist
umschaltbar; Sprachpakete sind unabhängig von der Komponenten-Auslieferung
nachladbar und verwaltbar. Grundlage ist die I18n-Funktionalität des Frameworks
(CakePHP I18n).

## 31.1 Grundsatz

-   **Basissprache Englisch.** Englisch ist die kanonische Sprache; fehlt eine
    Übersetzung in der gewählten Sprache, wird **flach** auf Englisch
    zurückgefallen (kein regionaler Zwischen-Fallback wie `de_AT→de`).
-   **Symbolische Schlüssel.** Übersetzbare Texte werden über stabile
    symbolische Schlüssel (`<bereich>.<sache>.<variante>`) referenziert, nicht
    über den Klartext. Das hält die Schlüssel versions- und editierstabil.
-   **Domain je Komponente.** Jede Komponente nutzt eine eigene Übersetzungs-
    Domain (Core: `default`; Module/Extensions: ihr technischer Schlüssel).
-   **Locale-Format** `ll_CC` (z.B. `en_US`, `de_DE`).

## 31.2 Mitlieferung von Sprachdateien

Jede Komponente bringt für ihre installierte Version **mindestens Englisch** mit.
Sprachdateien liegen als GNU-`.po`-Dateien im Paket (`locales/<locale>/<domain>.po`)
und werden im Manifest deklariert (unterstützte Locales + Domain). Bei der
Installation übernimmt der Core die Paket-Sprachdateien in den verwalteten
Sprachspeicher; ihr Herkunfts-/Signaturstatus wird dabei festgehalten (31.5).

## 31.3 Verwalteter Sprachspeicher (Managed Locale Store)

Der Katalog-Inhalt liegt in Dateien (PO) in einem persistenten Speicher; die
Datenbank hält nur **Verwaltungs-Metadaten** je Sprachdatei (Komponente,
Version, Locale, Domain, Status, Prüfsumme). Das Schreiben ist **ausfallsicher**:
Es wird zunächst in eine temporäre Datei geschrieben und dann atomar umbenannt
(kein Lösch-Fenster); ein abgebrochener Schreibvorgang wird zur Laufzeit erkannt
und bereinigt bzw. geheilt.

## 31.4 Versionierung und Versions-Gate

Sprachdateien folgen der Version der zugehörigen Komponente (keine eigene
Pack-Versionierung; ein Fehler wird durch Überschreiben derselben Version
korrigiert). Bei der Auflösung je (Komponente, Locale) gegen die **aktive**
Komponentenversion gilt:

| Pack-Version vs. aktive Version | Verhalten | Status |
| --- | --- | --- |
| identisch | genutzt | sauber |
| gleiche Major, abweichende Minor/Patch | genutzt (höchste passende) | **Hinweis** |
| abweichende Major / keine passende | nicht genutzt → Englisch | **Fehler** |

Major gilt als Bruchgrenze (Schlüsseländerungen). **Wählbar** sind nur die
Sprachen, für die der **Core** eine nutzbare Datei besitzt; der Status wird
berechnet, nicht gespeichert.

## 31.5 Sprachverwaltung (Administrationsbereich)

Die Verwaltung der Sprachpakete ist ein eigener fester Administrationsbereich
(Kapitel 27.3.1). Sie zeigt aktive Komponenten mit Version und darunter
gruppiert deren Sprachdateien (inkl. berechnetem Status). Funktionen:

-   **Import** zusätzlicher Sprachpakete (unabhängig vom Komponentenpaket) mit
    Vorschau vor der Übernahme.
-   **Status-Trio** je Sprachdatei: `signed` (Herkunft, **nur beim Import**
    geprüft), `reviewed`, `edited`. Ein Admin-Edit setzt `edited=ja` und
    `reviewed=ja` (Admin-Edit = Review); die Signatur wird durch Editieren
    **nicht** invalidiert. Ein **Review** kann ohne Edit erfolgen.
-   **Feld-basierter, verlustfreier Editor:** Es wird nur der Übersetzungstext
    bearbeitet; Schlüssel, Kontext, Plurale und Kommentare bleiben erhalten. Das
    Speichern nutzt das ausfallsichere Schreiben (31.3). Nur Administratoren
    editieren.
-   **Löschregeln:** Bei aktiver Komponente kann alles außer **Englisch**
    gelöscht werden; bei inaktiver Komponente alles (inkl. Englisch). Eine
    Deinstallation **behält** Sprachdateien.

## 31.6 Anzeigesprache zur Laufzeit

Die Sprache wird pro Request bestimmt. Präzedenz (jeweils nur für **aktivierte**
Sprachen): expliziter Wechsel (`?lang`) → Session-Wahl → Benutzer-Präferenz
(`user.locale`) → `Accept-Language` → System-Default. Der Umschalter steht in
der GUI bereit; für angemeldete Benutzer wird die Wahl persistent (`user.locale`)
gespeichert, anonym/öffentlich nur in der Session. Zur Auswahl stehen nur
Sprachen, für die der Core eine nutzbare Datei hat (31.4), eingeschränkt auf die
vom Betreiber **aktivierten** Sprachen (Einstellungen `locale.default` /
`locale.enabled`).

## 31.7 Audit und Health

Import, Edit, Review und Löschen werden **auditiert**. Das Health-Subsystem
`localization` (Kapitel 20.2.1) meldet fehlende Englisch-Basis aktiver
Komponenten, Versionsfehler und verwaiste temporäre Schreibdateien.

## 31.8 Grundregel

Texte werden ausschließlich über symbolische Schlüssel mit Englisch als Basis
geführt; Sprachpakete sind versioniert an die Komponente gebunden, prüfbar
(Status) und über die Sprachverwaltung unabhängig pflegbar — ohne die
Komponente neu auszuliefern.

## Anhang A: Versionshistorie

| **Version** | **Datum** | **Änderung** |
| --- | --- | --- |
| 5.2 | 01.04.2026 | Kapitel 23 Modulare Plattformarchitektur: Core-Plattform, Main-Module, Extension-Module, Resolver/Collector/Event-Mechanismen, Contracts, Registry, Marketplace, Lizenzierung, Modul-Lifecycle, BREAD-Berechtigungssystem, Delete-Semantik, Abhängigkeiten, 12 Architekturprinzipien, Beispiel Ticketing mit 3 Extensions. Konsistenzanpassungen in Kapitel 1.3, 1.4, 1.6, 1.10, 2 (Rollen), 7.7 (SLA-Kalender), 9.2/9.3 (CAPTCHA), 12.9, 15.1 (Datenmodell), 16.3, 21.4 für modularen Kontext |
| 5.3 | 01.04.2026 | Kapitel 24 Modul-Manifest, Paketstruktur und Installations-/Updatefluss: Paketformat und -struktur, verbindliches Manifest mit Pflicht-/Optionalfeldern, Typisierung (Main/Extension), Contract- und Registrierungsdeklaration, Lizenzinformationen, Signaturprüfung, Installations-/Aktivierungs-/Deaktivierungs-/Update-/Löschfluss mit konkreten Schrittfolgen, Kompatibilitätsprüfung, Auditierbarkeit, beispielhafte Manifestinhalte |
| 5.4 | 01.04.2026 | Kapitel 25 BREAD, Ressourcenmodell und Gruppenzuordnung: BREAD-Grundmodell mit Semantik der 5 Standardrechte, Ressourcenmodell (3 Ressourcentypen), generische Gruppenzuordnung, additive Rechteaggregation ohne Deny/Prioritäten, Zusatzaktionen, Main-/Extension-Berechtigungen, keine implizite Rechtevererbung, gruppenfähige vs. nicht gruppenfähige Ressourcen, Admin-Darstellung, serverseitige Laufzeit-Rechteprüfung, Auditierbarkeit, 10 Architekturprinzipien, Beispiele Ticketing/SLA-Kalender/Feiertagskalender |
| 5.5 | 01.04.2026 | Kapitel 26 Contracts, Resolver, Collector und Events: Formale Contract-Typen (Resolver/Collector/Event), Contract-Aufbau mit 10 Pflichtfeldern, Interface-Spezifikation (typisiert, maschinenlesbar), Contract-Versionierung (Patch/Minor/Major), Resolver-Slots (exklusiv), Collector-/Event-Verhalten, Registrierung von Contracts und Providern, Registry im Admin-Bereich, Aktivierungs-Validierung, Deaktivierungs-/Lizenzablauf-Verhalten, Fehlerverhalten zur Laufzeit (Resolver/Collector/Events), Auditierbarkeit, 3 beispielhafte Anwendungen (SLA, Feiertag, CAPTCHA), 10 Architekturprinzipien |
| 5.6 | 02.04.2026 | Kapitel 27 Benutzer, Gruppen, Rollen und Berechtigungsmodell der Plattform: Core-Identitätsmodell (Benutzer mit 11 Eigenschaften, Gruppen mit 6 Eigenschaften), Zwei-Ebenen-Berechtigungsmodell (Core-Rolle vs. Modulberechtigungen), Gruppen-Lifecycle (Aktivierung/Deaktivierung ohne Datenverlust), Benutzer-Lifecycle (Deaktivierung mit Erhalt historischer Referenzen), Ressourcenzuordnung (5 Pflichtfelder), Rechteaggregation (additiv, keine Deny), serverseitige Laufzeit-Prüfung, Auditierbarkeit (6 Vorgangstypen), 12 Architekturprinzipien. Querverweise auf Kapitel 25 für BREAD-Details, auf Kapitel 23.3 für Core-Funktionsbereiche |
| 5.7 | 02.04.2026 | Kapitel 28 Core-Update, Modul-Update, Signaturprüfung und Marketplace-Kommunikation: 5 Update-Arten, Marketplace als autoritative Quelle, Marketplace-Kommunikation (verschlüsselt, herkunftsverifiziert), Signaturprüfung (vor Entpacken), Lizenzprüfung (blockiert Aktivierung nicht Daten), Core-Update (12 Schritte), Modul-Update (15 Schritte), Sicherheitsupdates, Wartungsmodus, Kompatibilitätsprüfung (7 Prüfpunkte), Inkompatibilitäts-Verhalten, atomarer Abschluss, Lizenzablauf-Deaktivierung, Update-Historie (7 Felder), Admin-Oberfläche, 10 Architekturprinzipien |
| 5.8 | 02.04.2026 | Widerspruchsprüfung und 7 Konsistenz-Fixes: (1) Admin-Ticketzugriff: 23.3.1 präzisiert (Core-Vollzugriff ≠ automatischer Modul-Datenzugriff), 12.1 Export an 2.5 angeglichen. (2) Queue FK SlaCalendar: als Extension-Modul-Migration gekennzeichnet, nicht als Main-Modul-Schema. (3) Release-Kriterien 22.2: SLA-Kalender als bedingte Kriterien (nur bei installiertem Extension-Modul). (4) Benutzergruppen/Gruppen: Terminologie-Bridge in 2.3 (Benutzergruppen = Core-Gruppen mit Queue-Zuordnung als Ticketing-spezifische Ressourcenzuordnung). (5) Update-Schritte: 24.13, 28.8 und 28.9 reconciliert (Migrationsvorschau, Contract-Prüfung durchgängig). (6) 1.6 Entitätenliste: Extension-Modul-Entitäten (SLA-Kalender, Ausnahmetag-Listen) von Ticketing-Main-Modul-Entitäten getrennt. (7) Gast-Rolle: 27.3.3 ergänzt (Gast = modulspezifisches Zugriffskonzept, keine Core-Rolle) |
| 5.9 | 03.04.2026 | Kapitel 29 Öffentliche Modul-Interfaces und modulübergreifende Integrationen: Abgrenzung zu Contracts (Kap. 26), Grundbegriffe (6 Definitionen), Zielmodell (Main-Module als fachliche Tower), Interface-Anforderungen (10 Pflichtfelder), formale Interface-Spezifikation (Input/Output), Versionierung (Patch/Minor/Major konsistent mit 26.6.3), Nutzungsdeklaration, Integrations-Extension-Module, Datenhaltung (Integrationsbeziehungen im Extension-Modul nicht im Main-Modul), 6 Integrationsregeln, Interface-Registry im Admin-Bereich (11+6 Felder), Kompatibilitätsprüfung (6 Prüfpunkte), Deaktivierungsverhalten (Anbieter/Nutzer), Auditierbarkeit (6 Vorgangstypen), Beispiel Ticketing+Wissensdatenbank, 12 Architekturprinzipien |
| 6.0 | 03.04.2026 | Architektur-Review und Konsolidierung: (1) Kapitel 23.5 restrukturiert: zwei Extension-Modul-Typen (regulär = genau ein Main-Modul, Integration = mehrere Main-Module über öffentliche Interfaces). 23.14 Architekturprinzipien aktualisiert. (2) Kapitel 24.4.3 Manifest um public_interfaces_provided, public_interfaces_used, integration_relations ergänzt. 24.5.2 um Integrations-Extension-Modul-Typ erweitert. (3) Kapitel 28.12.1 Kompatibilitätsprüfung um öffentliche Interface-Versionen und Integrationsbeziehungen ergänzt. (4) Kapitel 29.3.4 explizite Abgrenzungsregel Contracts vs. öffentliche Modul-Interfaces ergänzt ("ergänzen, nicht ersetzen"). (5) Kapitel 29.8.1 Mehrfachnutzung präzisiert (Standard=erlaubt, Einschränkung explizit, Konsistenz beim Anbieter). (6) Kapitel 27 redaktionell entflechtet: 27.9-27.13, 27.17 und 27.19 von Duplikaten bereinigt und durch Verweise auf Kapitel 25 ersetzt (~80 Zeilen reduziert, keine Informationsverluste) |
| 6.1 | 03.04.2026 | Zugriffsschutz für Contracts und öffentliche Modul-Interfaces: (1) Kapitel 24.4.3 um used_contracts ergänzt. 24.7.2 Deklaration angebotener/genutzter Contracts und Interfaces mit Pflichtangaben. 24.7.3 Regel nach Modultyp (Main-Module: used_contracts und used_public_interfaces müssen leer sein; Extension-Module: beides zulässig). (2) Kapitel 26.13.2 Registrierte Nutzung zur Laufzeit: Laufzeit-Guard prüft aufrufendes Modul, Ziel-Contract, Registrierung, Aktivstatus und Version. 26.13.3 Verhalten bei Abweisung: aufrufendes Modul verpflichtet zur kontrollierten fachlichen Behandlung. (3) Kapitel 29.8.3 Registrierte Nutzung zur Laufzeit für öffentliche Interfaces analog zu 26.13.2. 29.8.4 Verhalten bei Abweisung analog zu 26.13.3 |
| 6.2 | 03.06.2026 | Kapitel 26.6.4 Versionsschema und Kompatibilitätsregel ergänzt: Semantic Versioning (MAJOR.MINOR.PATCH) als verbindliches Schema, Deklaration geforderter Versionen als exakte Version oder expliziter Bereich (>=x <y, keine Kurzformen), formale Matching-Regel für Contracts und öffentliche Interfaces (gleiche Major-Version, Anbieterversion ≥ geforderte Version), Bereichsprüfung für Core- und Main-Modul-Versionen. Querverweise aus 24.15 und 28.12 auf 26.6.4 ergänzt. Entscheidung 154 ergänzt |
| 6.3 | 03.06.2026 | Kapitel 28.14 um Wiederherstellungspunkt und Rollback erweitert (28.14.2, bisherige Grundregel zu 28.14.3): reversible down-Migrationen verpflichtend, vor jedem migrationsbehafteten Update verpflichtender Wiederherstellungspunkt (vollständiger DB-Dump, systemseitig erzwungen, einheitlich für Core- und Modul-Updates ohne Sonderfälle), Rollback per down-Operationen mit Wiederherstellungspunkt als Fallback, expand/contract für destruktive Schemaänderungen. Update-Abläufe 28.8.1 und 28.9.1 um Schritt "Wiederherstellungspunkt erstellen" ergänzt. Kapitel 1.8 (rückwärtskompatible Migrationen) um expand/contract und down-Pflicht geschärft. Entscheidung 155 ergänzt |
| 6.4 | 03.06.2026 | Kapitel 24.9.2 Vertrauensanker und Schlüsselverwaltung ergänzt (24.9.3 Grundregel): zweistufiges Trust-Modell (Marketplace-Wurzel signiert Herausgeber-Zertifikate, Signaturkette bis zu aktivem Vertrauensanker), Schlüsselrotation über signierten Marketplace-Kanal, Widerruf über signierte Sperrliste mit verpflichtender Prüfung vor Installation/Update, gecachte Sperrliste mit Alters-Warnung bei Nichterreichbarkeit, Warnkennzeichnung statt Deaktivierung bei nachträglich widerrufenen Schlüsseln installierter Module. Kapitel 28.5.1 um Sperrlisten und Vertrauensanker, 28.6 um Querverweis, 24.16 um widerrufene Schlüssel ergänzt. Entscheidung 156 ergänzt |
| 6.5 | 03.06.2026 | Vereinheitlichung der Manifest-Feldnamen auf das Schema <objekt>_<verb>: used_contracts → contracts_used (Kapitel 24.4.3, 24.7.2, 24.7.3); Dublette provided_contracts → contracts_provided (24.7.2); Dublette used_public_interfaces → public_interfaces_used (24.7.3). Historische Changelog-Einträge (6.0/6.1) bleiben unverändert und verwenden noch die alten Bezeichner |
| 6.6 | 03.06.2026 | Kapitel 23.16 Sicherheits- und Vertrauensmodell ergänzt (23.16.1 Grundregel): Klarstellung, dass Module im selben Laufzeitkontext ohne technische Sandbox laufen; Einordnung von BREAD und Laufzeit-Guard als Berechtigungs-/Disziplin-Mechanismen vs. Signatur-/Vertrauenskette (24.9.2) als maßgebliche Sicherheitsgrenze. Klarstellende Notizen an 26.13.2 und 29.8.3 ergänzt. Entscheidung 157 ergänzt |
| 6.7 | 03.06.2026 | Kapitel 28.7.3 Offline-Verhalten und Karenzfenster ergänzt (bisherige Grundregel zu 28.7.4): Unterscheidung Lizenzserver erreichbar (Antwort maßgeblich, bestätigter Ablauf → sofortige Deaktivierung) vs. nicht erreichbar (in der Lizenz definiertes, signiertes Karenzfenster ab Stichtag; fehlt die Angabe = null). Zwischengespeichertes signiertes Lizenz-Token, manipulationssicher. 28.7.2 um Abgrenzung Online-Prüfung vs. laufender Betrieb ergänzt. Entscheidung 158 ergänzt |
| 6.8 | 03.06.2026 | Kapitel 1.4 Konfigurations-Leck bereinigt: reCAPTCHA Site-/Secret-Key und Gast-Session-Timeout aus der config/app.php-Spalte entfernt und als modulverwaltete, verschlüsselte DB-Konfiguration gekennzeichnet; klarstellender Absatz ergänzt (Modul-Geheimnisse im Konfigurationsspeicher, AES-256-GCM, nur Infrastruktur in app.php). Entscheidung 159 ergänzt |
| 6.9 | 03.06.2026 | Kapitel 23.3.1 präzisiert: Core-Löschsonderfälle um den Widerruf eines noch nicht aktivierten Einladungs-Accounts (Status "eingeladen", Kapitel 1.6) ergänzt, konsistent zur bestehenden Ausnahme. Keine neue Entscheidung (Präzisierung bestehender Regel) |
| 6.10 | 03.06.2026 | Kapitel 27.15.3 Anonymisierung (Recht auf Löschung) ergänzt (27.15.3.1 Grundregel): aktivierte Benutzer werden statt physischer Löschung irreversibel anonymisiert (technische ID und historische Referenzen bleiben, Personenbezug wird unumkehrbar entfernt); Pseudonymisierung als Löschersatz ausgeschlossen; Audit-Log behält Vorgänge mit anonymisierter Referenz; Freitext-PII als Spätere Version abgegrenzt. Querverweise in 1.6 und 20.6 ergänzt, 27.18 um Anonymisierung als auditpflichtigen Vorgang erweitert. Identitäts-Anonymisierung = Muss (v1). Entscheidung 160 ergänzt |
| 6.11 | 03.06.2026 | Kapitel 26.8.2 UI-Beiträge und Ausgabekodierung ergänzt (26.8.2.1 Grundregel): Ausgabesicherheit von Modul-UI-Beiträgen (Collector-UI, ui_extensions) wird durch die einbettende Core-Renderschicht durchgesetzt (Empfänger-Prinzip, Modulwerte nicht vertrauenswürdig), Auto-Escaping als Default, Roh-HTML nur per explizitem dokumentiertem Opt-out, CSP als Betreiber-Empfehlung. Entscheidung 161 ergänzt |
| 6.12 | 03.06.2026 | Kapitel 27.16.3 API-Authentifizierung und Anmeldeschutz ergänzt (27.16.3.1 Grundregel): API-Aufrufe serverseitig authentifiziert und an Core-Identität gebunden, mechanismus-offene widerrufbare Zugangstoken ohne eigene Scopes, Rechte je Aufruf live gegen BREAD geprüft (kein im Token eingefrorenes Recht), Token-Invalidierung bei Deaktivierung/Anonymisierung, Anmeldeschutz per Rate-Limiting mit DB/GUI-konfigurierbaren Schwellen und sicherem Vorgabewert. Kapitel 1.4 DB-Spalte um Rate-Limiting-Schwellen ergänzt. Auth-Grundsatz/Rechtemodell = Muss, Token-Lebenszyklus/Schwellen = Soll. Entscheidung 162 ergänzt |
| 6.13 | 03.06.2026 | Kapitel 24.16.1 Referenzrobustheit der Audit-Einträge ergänzt (24.16.1.1 Grundregel): Audit-Einträge selbsterklärend mit textueller Kopie der Bezeichner statt reiner Fremdschlüssel; bleiben nach Modullöschung/Datenentfernung vollständig lesbar (Modul als entfernt ausgewiesen); Log wird durch Modullöschung nicht verändert. Querverweis aus 20.6 ergänzt. Entscheidung 163 ergänzt |
| 6.14 | 03.06.2026 | Kapitel 1.4 um Schlüsselrotation ergänzt: Verschlüsselung schützt v.a. gegen DB-Leak ohne Config-Datei; keine routinemäßige automatische Rotation in v1; CLI-Re-Encryption-Command für Bedarfsfall (Kompromittierung/Compliance) im Wartungsfenster = Soll; periodische Rotation = Betreiber-/Compliance-Empfehlung; gleitende Zero-Downtime-Rotation mit Key-ID = Spätere Version. Entscheidung 164 ergänzt |
| 6.15 | 03.06.2026 | Kapitel 24.18 Nebenläufigkeit von Lifecycle-Operationen ergänzt (24.18.1 Grundregel): lifecycle-verändernde Operationen (Install/Aktivierung/Deaktivierung/Update/Löschung) serialisiert über exklusiven Lifecycle-Lock pro Plattforminstanz, konkurrierende Operation wird mit klarem Hinweis abgewiesen, kontrollierte Lock-Freigabe bei Abbruch/Fehler, reguläre Modulnutzung unbetroffen. Leitregel in 23.14 ergänzt. Entscheidung 165 ergänzt |
| 6.16 | 03.06.2026 | Architektur-Review Punkt 0 (Trust-Modell): Kapitel 23.9.3 Kuratierter Marktplatz und Modulprüfung ergänzt (23.9.3.1 Härtung, 23.9.3.2 Grundregel): kuratierter Marktplatz mit Herausgeber-Vetting als Standard und Rechtfertigung der In-Process-Ausführung; Hybrid-Pfad für betreiber-zugelassene ungeprüfte Module auf eigenes Risiko mit empfohlenen Härtungsmaßnahmen und sichtbarer Kennzeichnung; ehrlicher Hinweis, dass In-Process-Module nicht isolierbar sind (echte Isolation = Out-of-Process = Spätere Version). Querverweis in 23.16.1 ergänzt. Entscheidung 166 ergänzt |
| 6.17 | 03.06.2026 | Architektur-Review Punkt 1 (Capability-Modell): Laufzeit-Guard durch Capability-Bindung ersetzt. Kapitel 26.13.2 und 29.8.3 umgestellt — Module greifen auf Contracts/Interfaces nur über bei Aktivierung vergebene, gebundene Capability-Handles zu (kein globaler Registry-/Service-Zugriff); Zugriffskontrolle wirkt durch Konstruktion statt durch nachträgliche Aufruferprüfung; Handle wird bei Inaktivität (Deaktivierung/Lizenzablauf/Inkompatibilität) ungültig. Architekturprinzip in 26.19 ergänzt, Querverweis/Begriff in 23.16 angepasst. Entscheidungen 151 und 157 auf Capability-Bindung aktualisiert (Terminologie "Laufzeit-Guard" ersetzt; historische Changelog-Einträge unverändert) |
| 6.18 | 03.06.2026 | Architektur-Review Punkt 2 (Verschmelzung Contracts + öffentliche Interfaces): einheitliches Capability-Modell (Richtung × Kardinalität) und vierter Contract-Typ Request/Response (Service) in Kap. 26.2/26.3 ergänzt; öffentliche Modul-Interfaces als Service-Contracts eingeordnet (Kap. 29.3 neu gefasst, 29.1/29.2/29.5/29.7.1/29.12 angepasst). Manifest vereinheitlicht: public_interfaces_provided/used entfernt und in contracts_provided/used gefaltet (24.4.3, 24.5.2, 24.7.2, 24.7.3). Contract-Registry deckt alle vier Typen (26.12), Interface-Registry als gefilterte Sicht (29.12). Kompatibilitätsprüfung 28.12.1 entdoppelt. Entscheidungen 110 und 144 aktualisiert, Entscheidung 167 ergänzt |
| 6.19 | 03.06.2026 | Konsistenz-Durchlauf nach Punkt 2: Übersicht der Erweiterungsmechanismen (Kapitel 23.6) um den vierten Contract-Typ Service/Request-Response ergänzt, damit Überblick (Kap. 23) und Detailspezifikation (Kap. 26, vier Typen) übereinstimmen. Keine neue Entscheidung (Angleichung bestehender Übersicht) |
| 6.20 | 03.06.2026 | Architektur-Review Punkt 3 (Events asynchron): Events von synchronen In-Process-Listenern auf transaktionalen Outbox mit asynchroner Worker-Verarbeitung umgestellt. Neuer Abschnitt 26.9.2 (transaktionaler Outbox, mindestens-einmal-Zustellung, Idempotenz-Pflicht der Listener, isolierte Fehler, Retry/Dead-Letter); bisherige Grundregel zu 26.9.3. Übersicht 23.6.3, Eigenschaften 26.3.3 und Fehlerverhalten 26.16.3 angepasst. Trennung zu Resolver/Service geschärft (synchrone Antwort → Resolver/Service). Entscheidung 168 ergänzt |
| 6.21 | 03.06.2026 | Architektur-Review Punkt 4 (Modul-UI als View-Models): Kapitel 26.8.2 geschärft — UI-Beiträge werden als strukturierte View-Models/deklarative Deskriptoren bereitgestellt, Markup-Erzeugung beim Core (modulübergreifendes Roh-HTML entfällt als Regelfall, konsistentes Styling); Empfänger-Escaping aus Punkt 7c bleibt für die Wertedarstellung, Roh-HTML als abgeratene Opt-out-Ausnahme. Grundregel 26.8.2.1 aktualisiert. Entscheidung 161 entsprechend aktualisiert (View-Models als primäres Beitragsformat) |
| 6.22 | 03.06.2026 | Architektur-Review Punkt 5 (Offline-first-Lizenzierung): Kapitel 28.7 von Online-Prüfung mit Offline-Fallback auf Offline-first umgestellt — maßgeblich ist die signierte Lizenzdatei (Gültigkeitszeitraum, Karenzfenster, optionales Online-Enforcement); Aktivierung/Update/Betrieb ohne Serverkontakt, online nur für Sperrlisten und optionale Erneuerung. 28.7.3 neu gefasst (Offline-first), neues 28.7.3.1 Optionales Online-Enforcement (Miet-/Abo-Modelle) und 28.7.3.2 Grundregel; 28.7/28.7.1/28.7.2 angepasst. Entscheidung 158 entsprechend aktualisiert |
| 6.23 | 03.06.2026 | Architektur-Review Punkt 6 (Observability als Core-Funktion): Kapitel 20.2 umgestellt von "System stellt keine Monitoring-Endpunkte bereit" auf Core-Funktion — Health-Endpoint /health (Muss, minimaler öffentlicher Liveness + authgeschützter Detailstatus, 20.2.1), Modul-Health über Health-Collector-Contract (20.2.2), strukturierte Logs (Soll, 20.2.3), Admin-Statusfläche (Soll, 20.2.4); betreiberseitige Überwachung als Empfehlung (20.2.5). 20.7 Betriebsgrenzen-Zeile Infrastruktur-Monitoring angepasst. Entscheidung 169 ergänzt |
| 6.24 | 03.06.2026 | Architektur-Review Punkt 7 (Pluggable SSO + Admin-Zwischenstufe): (A) Authentifizierung als Resolver-Slot (Default lokal, optional OIDC/SAML via Extension) — neues Kapitel 27.2.2, Tech-Tabelle 1.3 angepasst, Entscheidung 171. (B) Scoped-Admin-Modell: Core-Administrationsbereiche mit Volladministrator (alle) und delegiertem Administrator (Teilmenge) statt binärem Admin — Kapitel 27.3 neu gefasst (27.3.1 Administrationsbereiche), Ripple in 23.3.1, 25.2.1, 27.2 (Benutzer-Eigenschaft), 27.6, 27.7.2, 27.16.1, 27.20; Entscheidungen 108/122/134/135 aktualisiert, Entscheidung 170 ergänzt |
| 6.25 | 03.06.2026 | Architektur-Review Punkt 8 (Ausschluss-Muster): Kapitel 25.6.3 ergänzt (25.6.3.1 Grundregel) — Ausschlüsse werden über Ressourcen-Schnitt modelliert (sensible Teilmenge als eigene Ressource, nur berechtigten Gruppen zugeordnet), nicht über Deny-Regeln; additives Aggregationsmodell unverändert. ABAC-Erweiterung bewusst nicht aufgenommen (Variante b). Entscheidung 172 ergänzt |
| 6.26 | 03.06.2026 | Datenbank von MySQL/InnoDB auf PostgreSQL umgestellt: Technologiebasis (1.1, 1.3), Update-Scope und Betriebsgrenzen (24.13, 28.2, 28.18, 20.7), Backup (20.1: pg_dump / PITR), Health-Check (20.2.5), Entscheidungen 121/138 angepasst. Migrations-Atomarität über transaktionales DDL geschärft (1.8, 28.14.2: Rollback primär per Transaktion, Wiederherstellungspunkt als pg_dump-Fallback); Entscheidung 155 aktualisiert. Entscheidung 173 (DB-Wahl PostgreSQL) ergänzt |
| 6.27 | 03.06.2026 | PostgreSQL-Leverage (P2–P10): neues Kapitel 30 Datenbankfundament — Constraint-First/DB-Integrität (partielle Unique-/Check-/Exclusion-Constraints), verpflichtende Row-Level Security für scoped Modultabellen (Defense-in-Depth + Row-Scoping-Hook), JSONB, Outbox + LISTEN/NOTIFY, Advisory-Lock-Lifecycle (mehrknotenfähig), deklarative Partitionierung. Bestandsschärfungen: 1.8 (Constraint-First-Prinzip), 23.14 (Leitregel), 26.7.1 (partielles Unique für Resolver-Slot), 26.9.2 (LISTEN/NOTIFY), 24.18 (Advisory Lock), 25.6.3 (RLS-Verweis), 20.6 (deklarative Partitionierung). Entscheidungen 174–179 ergänzt. P8/P9 folgen im Ticketing-Modul |
| 6.28 | 04.06.2026 | Kapitel 20.8 Deployment- und Distributionsmodell ergänzt: Core als eigenständiges Container-Image getrennt von PostgreSQL (eigener Dienst), Sofort-Start per docker compose (Core/Web/DB/Worker/Mail), All-in-One nur für Demo/Eval. Konsistent mit Update-Scope (28.2), Backup (20.1), HA/Advisory-Lock (30.7). Entscheidung 180 ergänzt |
| 6.29 | 07.06.2026 | Kapitel 20.1 Backup/Wiederherstellung in zwei Ebenen mit getrennter Zuständigkeit neu gefasst: **20.1.1 Infrastruktur-Backup/-Restore** (Empfehlung, Systemadministrator: Host-/Volume-Snapshots, PITR/Replikation, Off-Site, Scheduling, Aufbewahrung — außerhalb Fertura) und **20.1.2 Daten-Backup/-Restore als Systemfunktion des Core** (konsistente Sicherung von DB + persistenten Datei-Stores unter Lifecycle-Lock, prüfbar via Prüfsummen + Probe-Restore in Wegwerf-DB; CLI+GUI für Erstellen/Auflisten/Prüfen/Probe-Restore/Löschen; destruktive Daten-Wiederherstellung per CLI). Ersetzt die frühere Aussage „keine Systemfunktion". Entscheidung 181 ergänzt |
| 6.30 | 07.06.2026 | Doku-Software-Abgleich nach Umsetzung: (a) **7. Administrationsbereich „Sprachverwaltung"** in 27.3.1 + Entscheidung 170 ergänzt (zuvor 6); (b) **API-Token tragen Scopes** (zusätzliche Einschränkung, nie erweiternd) in 27.16.3 + Entscheidung 162 korrigiert (zuvor „keine eigenen Scopes"); (c) **20.1.2 um den realen Backup-Funktionsumfang erweitert** (ZIP+Zeitstempel, Verifikation-vor-Abschluss, optionale AES-256-Verschlüsselung, Zeitplan/Retention nach Anzahl+Alter, append-only-Protokoll, Pre-Flight, Mail-Alarm, Download, Health-Subsystem); (d) **30.3.1**: Core **erzwingt** RLS für `is_scoped`-Module bei der Installation (Abbruch sonst). Hinweis: Mehrsprachigkeit/Locale-Verwaltung ist als eigener Subsystem implementiert, im Anforderungsdokument bislang nur als Technologie-Zeile geführt (eigene Kapitel-Ausarbeitung offen). |
| 6.31 | 07.06.2026 | Doku-Software-Abgleich (Fortsetzung): (a) **Neues Kapitel 31 „Mehrsprachigkeit und Lokalisierung"** ausgearbeitet (Grundsatz/symbolische Schlüssel, Mitlieferung, Managed Locale Store mit ausfallsicherem Schreiben, Versions-Gate, Sprachverwaltungs-Admin-Bereich mit Status-Trio + verlustfreiem Editor, Laufzeit-Sprachwahl, Audit/Health). (b) Bestehende Kapitel um umgesetzte Mechanismen ergänzt: **20.2.1** Health-Subsysteme `localization` + `backup`; **20.3** Andock-Punkt für periodische Modul-Aufgaben (`core.collector.scheduled`); **24.9.2** Durchsetzung des Anker-Gültigkeitsfensters + gleitende Rotation; **26.9.2** Dead-Letter-Retry/Verwerfen-GUI; **28.14.2** automatischer Wiederherstellungspunkt auch bei Boot-Migrationen. |
| 6.32 | 08.06.2026 | (a) **Mehrere Worker-Instanzen** explizit unterstützt (20.3): periodische Aufgaben werden je Aufgabe über einen PostgreSQL-Advisory-Lock serialisiert (kein Doppellauf bei >1 Worker); Outbox bleibt über SKIP LOCKED kollisionsfrei. Einzelinstanz = Standard. (b) **Backup-Verschlüsselung DR-tauglich** (20.1.2): Passwort aus Env/Secret (`BACKUP_PASSWORD_FILE`/`BACKUP_PASSWORD`) mit Vorrang vor dem DB-Setting — out-of-band, damit ein verschlüsseltes Backup nicht über das im Dump enthaltene Passwort entschlüsselt werden müsste (Henne-Ei). |
| 6.71 | 10.06.2026 | **SAML-Replay-Schutz cookie-unabhängig**: Der zuvor (6.70) als „unter `SameSite` ggf. wirkungslos" dokumentierte Punkt ist jetzt robust gelöst. Beim Login-Start wird ein zufälliger `RelayState` (vom IdP unverändert zurückgespiegelt, also **ohne** Browser-Cookie verfügbar) serverseitig an die ausgestellte SAML-AuthnRequest-ID **und** den Provider gebunden (neue Tabelle `saml_auth_requests`). Beim Rücksprung (ACS) wird er **einmalig** atomar eingelöst und die signierte IdP-Antwort an genau diese Anfrage gebunden — unbekannt/abgelaufen/bereits verbraucht ⇒ Ablehnung. Die Provider-Auswahl kommt aus dem serverseitigen Store (autoritativ), nicht aus dem angreiferseitig beeinflussbaren RelayState; die session-cookie-basierte Bindung aus 6.69 entfällt. Verifiziert: 2 Tests (Einmal-Einlösung + Ablauf), Migration auf Dev-/Test-DB; volle Suite **199→201 grün** + Runtime-Smoke. |
| 6.70 | 10.06.2026 | **Zweites vollständiges Feature-Peer-Review (6 unabhängige Prüfer) + Remediation**: Behoben — **(a)** Backup-Verschlüsselung **fail-closed**: ohne libzip-AES-256 wird ein gesetztes Backup-Passwort nicht mehr stillschweigend ignoriert (sonst Klartext-Archiv trotz „verschlüsselt"-Kennzeichnung), sondern der Lauf abgebrochen. **(b)** Lokaler Login: aktive Drosselung verweigert die Anmeldung jetzt **wirklich** (auch bei korrektem Passwort, vorher nur die Fehlermeldung unterdrückt) + **Session-ID-Erneuerung** nach Login/SSO (Session-Fixation-Schutz). **(c)** CSV/XLSX-Formel-Injection: Entwertung greift auch bei Auslösern **nach führendem Whitespace/Zeilenumbruch**. **(d)** SSE-Stream-Zähler: read-modify-write des Datei-Cache-Fallbacks jetzt per Dateisperre **atomar** (kein Aussperren/kein Limit-Bypass durch verlorene Updates). **(e)** SSO: passwortlose **offene Einladungen** (`invited`) werden nicht mehr allein über eine unverifizierte IdP-E-Mail beansprucht. **(f)** SAML-ACS lehnt einen Provider-/Sitzungs-Konflikt hart ab. **(g)** Egress: Pinning erzwingt zusätzlich die IPv4-Familie (kein IPv6-Ausweichen bei Dual-Stack) und bricht ohne `curl`-Erweiterung ab. Bewusst dokumentiert/zurückgestellt: SAML-`InResponseTo` ist unter `SameSite=Lax` ggf. wirkungslos (robustes Fix braucht IdP-Test), Rate-Limit-Proxy-Modell, `EmbeddingService`-Locale-Latenz, Outbox-at-least-once. Verifiziert: volle Suite **196→199 grün** + Runtime-Smoke. |
| 6.69 | 10.06.2026 | **Letzte drei dokumentierten Peer-Review-Restpunkte geschlossen**: **(a) SAML-Replay:** `InResponseTo`-Bindung — der SP merkt die AuthnRequest-ID in der Session und gibt sie beim ACS an `processResponse()` mit; onelogin prüft die IdP-Antwort dagegen, die ID wird einmalig verbraucht (kein Replay), plus `rejectUnsolicitedResponsesWithInResponseTo=true` gegen untergeschobene IdP-initiierte Antworten. **(b) Egress-Antwortgröße:** Begrenzung jetzt **während** des Transfers über den Curl-Fortschritts-Callback (`CURLOPT_XFERINFOFUNCTION`) — bricht ab, sobald das Limit überschritten wird, auch ohne `Content-Length` (kein Speicher-DoS durch unbegrenztes Puffern; Stream-Adapter fällt auf die nachgelagerte Begrenzung zurück). **(c) AI-Endpoint-Override:** je Provider als validiertes, auditiertes Setting (`ai.{openai,xai,anthropic,google}.endpoint`) im Katalog; das Gateway erzwingt **https** (sonst liefe der Bearer-Schlüssel im Klartext) und nutzt weiter den SSRF-gehärteten Egress. Verifiziert per Test (SAML-Security-Flag, AI-Endpoint http→Ablehnung/https→genutzt). Volle Suite **193→196 grün**. |
| 6.68 | 09.06.2026 | **Restliche Peer-Review-Härtungen (Egress-DNS-Pinning + SSE-Stream-Cap)**: Die beiden im Review ehrlich offen gelassenen Härtungen umgesetzt. **(a) DNS-Rebinding-Schutz:** Der `EgressClient` löst den Host selbst auf, validiert die IP und **pinnt die Verbindung** auf genau diese (`CURLOPT_RESOLVE`) — geprüfte IP == verbundene IP, kein TOCTOU mehr (greift mit dem Curl-Adapter; IP-Literale/Allowlist/`allow_private` ohne Pinning). **(b) SSE-Stream-Limit:** Pro Benutzer max. gleichzeitige `/events/stream`-Verbindungen (`sse.max_streams_per_user`, Default 3) über einen Cache-Zähler (am Stream-Ende dekrementiert, sonst TTL-Selbstheilung); Überschreitung → `429`. Gegen FPM-/DB-Slot-Erschöpfung. Verifiziert per Test (`pinTarget` für Hostname/Privat/Literal/Override; Cache-`decrement`). Volle Suite **189→193 grün**. |
| 6.67 | 09.06.2026 | **Peer-Review-Härtung des Programms (Sicherheits-/Korrektheits-Fixes)**: Vier unabhängige adversariale Prüfungen (Security der externen Flächen, Korrektheit Daten/Automation, Infra/Tooling, Doku-Sync) — die gültigen Befunde wurden behoben. **CRITICAL:** (1) **SSO-Account-Takeover** geschlossen — eine per IdP behauptete E-Mail wird **nicht mehr** automatisch in ein bestehendes Konto mit lokalem Passwort eingeloggt (nur passwortlose/SSO-Konten verknüpfbar; OIDC `email_verified=false` wird abgelehnt). (2) **Tabellen-Formel-Injection** in CSV/XLSX-Exporten neutralisiert (führende `= + - @` etc. werden entwertet). (3) **Embedding-Dimensions-Prüfung** (1536) verhindert stillen Bruch der semantischen Suche bei abweichenden Modellen. **HIGH:** (4) Egress folgt **keinen Redirects** mehr (Redirect-SSRF), (5) OIDC prüft **`azp`** bei Mehrfach-Audience (Token-Substitution), (6) Automations-Bedingungen vergleichen **strikt** (kein Typ-Juggling), (7) Workflow-Übergang ist ein **atomarer Compare-And-Swap** (kein Doppel-Übergang bei Nebenläufigkeit), (8) Automations-/Workflow-Aktionen via persistiertem `derived_done`-Flag **at-least-once** (kein Verlust bei Absturz+Reclaim), (9) Modul-API-Pfade werden **gequotet** + Linter-Grammatik (kein Regex-Injection/ReDoS). **MEDIUM/LOW:** In-App-Insert isoliert, Modul-Fehlermeldungen nicht mehr nach außen, Cache-Schlüssel kollisionsfrei gehasht, Off-Site-Download räumt Teildatei auf. Verifiziert per neuen Tests (SSO-Refusal, azp, Formel-Injection, Dimension). Volle Suite **184→189 grün**. MODULE_DEVELOPMENT um die neuen Erweiterungspunkte ergänzt. |
| 6.66 | 09.06.2026 | **Admin-GUI „Integrationen & Automatisierung" (zurückgestellter GUI-Ausbau)**: Neuer Admin-Bereich unter Core-Konfiguration (`/admin/integrations`, scoped `core_config`): Übersicht + sichere Betriebsaktionen für **Webhooks** (Subscriptions + letzte Zustellungen, aktivieren/deaktivieren/löschen, Zustellung erneut), **SSO-Provider**, **Automations-Regeln** und **Workflows** (aktivieren/deaktivieren/löschen). Anlage/Konfiguration bleibt CLI/API (formularlastig); die GUI deckt Monitoring + Lebenszyklus ab. i18n-Schlüssel (en/de) ergänzt. Begleitend gehärtet: `LocaleMiddleware` behandelt die Benutzer-Locale robust für Entity- **und** Array-Identitäten. Verifiziert per Controller-Integrationstest. Volle Suite 183→184 grün. |
| 6.65 | 09.06.2026 | **Zurückgestellte Themen, Teil 1 (Programm-Nachlauf)**: **(a) Multi-Tenancy — Entscheidung:** Die Plattform bleibt **eine Organisation pro Installation** (RLS-Owner-Scoping nach Nutzer/Gruppe). Echte Mandantenfähigkeit (schema-/db-per-tenant bzw. First-Class-`tenant_id`) wird **nicht** umgesetzt, da das Anforderungsdokument keinen Mehr-Kunden-SaaS-Betrieb auf einer Instanz fordert; Neubewertung nur bei Produktausrichtung „Multi-Customer-SaaS". **(b) SAML-SP-Signierung:** Mit hinterlegtem SP-Zertifikat (Konfig) + SP-Privatschlüssel (verschlüsseltes Secret) werden **AuthnRequests signiert** (`authnRequestsSigned`, RSA-SHA256, `wantAssertionsSigned`); CLI `sso add-saml --sp-cert-file/--sp-key-file`. **(c) Automatisches Off-Site-Backup:** Der Backup-Scheduler lädt das frische Archiv bei `backup.offsite.enabled` automatisch ins Objekt-Storage (P14); WAL-PITR bleibt Betreiber-Runbook. **(d) Workflow-State-Machines (P12-Ausbau):** zustandsbehaftete Abläufe (`workflow_definitions`/`workflow_instances`): je Geschäftsobjekt eine Instanz, Transitionen auf Events mit Bedingung + Aktionen (gemeinsamer `ActionExecutor` mit den ECA-Regeln); Auswertung beim Event-Dispatch; CLI `workflow`. Verifiziert per Test (SAML-Signierung; Workflow-Übergänge/from-State-/Bedingungs-Gating/Wildcard). Volle Suite 177→183 grün. |
| 6.64 | 09.06.2026 | **Modul-SDK: Linter + Scaffolding + Katalog (Programm, P16; Abschluss Tier 1–3)**: Entwickler-Werkzeuge für das Modul-Ökosystem. **`ManifestLinter`** (CLI `module_lint`): statische Manifest-Prüfung ohne DB (Pflichtfelder, id/Namespace-Format, Form der Registrierungs-Sektionen, api_routes-Methoden/Pfade, contracts_provided-Typen) mit Fehlern + Hinweisen. **`ModuleScaffolder`** (CLI `module_scaffold`): erzeugt ein lauffähiges Modul-Gerüst (Manifest, Beispiel-API-Endpunkt `ApiEndpointInterface`, Migration, README), das den Linter sauber besteht. **CLI `module_contracts`**: Katalog aller Core-Erweiterungspunkte (Contracts aus der Registry) inkl. zu implementierendem Interface — spiegelt die nun reiche Oberfläche (Webhooks, SSO, API-Routen, Notifications, Suche, AI, Automatisierung). Verifiziert per Test (Linter-Regeln; Scaffold besteht Linter) + CLI-Smoke. Damit ist das Programm „Wettbewerbsfähigkeit Core" (Tier 1–3, P01–P16) vollständig. |
| 6.63 | 09.06.2026 | **Zero-Downtime-Bausteine (Programm, P15)**: **Readiness-Probe** `GET /health/ready` (getrennt von der Liveness): 200 = bereit für Verkehr, 503 = entleeren — im Wartungsmodus (während eines Updates) wird die Instanz not-ready, sodass ein Load-Balancer/Orchestrator sie bei **rolling/blue-green**-Deployments ohne 503-Antworten an echte Nutzer drainen kann. **Migrations-Sicherheits-Check** (`MigrationSafetyChecker` + CLI `migration_check`): findet destruktive/abwärts-inkompatible Muster in den `up()`-Pfaden (DROP TABLE/COLUMN, RENAME, ALTER TYPE, NOT NULL ohne DEFAULT, TRUNCATE) und empfiehlt **Expand/Contract**; advisory, `down()`-Rollbacks ausgenommen. Verifiziert per Test (Readiness ready/maintenance; Checker-Erkennung/Ignorieren von down()). |
| 6.62 | 09.06.2026 | **Off-Site-Backup (Programm, P14)**: `OffsiteBackupService` lädt die (bereits AES-verschlüsselten) Backup-Archive über den Objekt-Storage (P03) an ein **externes Ziel** (S3-kompatibel) und holt sie für ein Disaster-Recovery zurück (Upload/List/Download/Delete; CLI `backup_offsite`; Setting `backup.offsite.enabled`). Ergänzt das Core-Backup (Kap. 20.1.2) um Geo-Redundanz. **PITR** (WAL-Archivierung ins Objekt-Storage) als Betreiber-Runbook beschrieben. Verifiziert per Test (Upload/List/Download-Roundtrip gegen lokalen Adapter). |
| 6.61 | 09.06.2026 | **Reporting/Export-Primitiv (Programm, P13)**: `ExportService` erzeugt tabellarische Reports als **CSV** (nativ), **XLSX** (PhpSpreadsheet) und **PDF** (dompdf, HTML-Tabelle) und legt sie optional über den Objekt-Storage (P03, lokal/S3) ab (`store()` → `reports/…`, mit Audit). Modulen als gemeinsame Export-Funktion verfügbar. Image um die `gd`-Extension ergänzt (PhpSpreadsheet-Voraussetzung). Verifiziert per Test (CSV-Inhalt, XLSX-ZIP-Signatur, PDF-Header, Ablage im Storage). |
| 6.60 | 09.06.2026 | **Automations-/Workflow-Engine (Programm, P12)**: Deklarative **Event-Condition-Action**-Regeln auf der Event-Outbox (`core.automation_rules`). Pro Regel ein Event-Muster (Contract, exakt/`*`/`prefix.*`), eine Bedingung als JSON-Ausdruck über die Event-Nutzlast (`all`/`any`/`not` + Blätter mit Operatoren eq/ne/gt/lt/gte/lte/contains/in/exists, Feldpfade per Punktnotation) und Aktionen (`notify` mit `{{pfad}}`-Interpolation, `event` zum Publizieren weiterer Outbox-Events → löst Listener/Webhooks aus). Der Worker wertet passende, aktive Regeln **beim ersten Dispatch** aus (keine Doppel-Aktionen bei Retries); Regel-/Aktionsfehler sind isoliert. Verwaltung über CLI `automation`. Verifiziert per Test (Evaluator-Operatoren/Verschachtelung; Engine: Matching/Bedingung/Notify-Aktion). State-Machines als spätere Ausbaustufe (ECA deckt die gängige Automatisierung ab). |
| 6.59 | 09.06.2026 | **AI/LLM-Primitive (Programm, P11)**: Provider-agnostisches **LLM-Gateway** (`AiGateway`) für **OpenAI, Anthropic/Claude, xAI/Grok, Google/Gemini** — einheitliche `complete()`/`embed()`-Schnittstelle, Netzzugriff über den gehärteten Egress (P01), **API-Schlüssel out-of-band** über `*_API_KEY`-Env (nie in der DB), Provider/Modell über `core.ai.*`-Settings; ohne Konfiguration deaktiviert (klare Ausnahme). **Embedding-Store** (`core.embeddings`, `pgvector`) + **semantische Suche** (`EmbeddingService::semantic`, Cosine/HNSW), sichtbarkeits-gefiltert über den Eigentümer; speist die Suche (P10). Capability-Contracts `core.ai.complete`/`core.ai.embed` für Module. DB-Image auf `pgvector/pgvector:pg17` umgestellt (datenkompatibel zu postgres:17). Verifiziert per Test (Request-/Antwort-Mapping aller vier Provider gegen Egress-Stub; Gateway-Deaktivierung; pgvector-Index + semantische Suche inkl. Owner-Scoping). |
| 6.58 | 09.06.2026 | **Volltext-Suche (Programm, P10)**: Zentrale Such-Capability über `core.search_index` (Postgres `tsvector` als **generierte Spalte**, gewichtet Titel>Body, Konfiguration `simple`=sprachneutral, GIN-Index). `SearchService` (`index`/`remove`/`removeSource`/`search`/`reindexAll`): Treffer nach `ts_rank` sortiert und **sichtbarkeits-gefiltert** über den Eigentümer (nur eigene + öffentliche Dokumente; System/Admin sieht alles). Module pflegen den Index inkrementell und liefern über den Collector-Contract `core.collector.search` ein `reindex()`. API `GET /api/v1/search?q=…&limit=…` (Scope `me:read`, in OpenAPI). Verifiziert per Test (Ranking, websearch-Syntax, Owner-Scoping, Upsert/Remove) + Live-Smoke. |
| 6.57 | 09.06.2026 | **Benachrichtigungs-Framework (Programm, P09)**: Ein Aufruf, mehrere Kanäle — **In-App** (Tabelle `core.notifications` + per SSE/P08 live), **E-Mail** (Core-MailService), **Modul-Kanäle** über den neuen Collector-Contract `core.collector.notification_channel` (`NotificationChannelInterface`, in-process/RPC) — plus ein Outbox-Event `core.notification.created`, sodass Webhook-Abos (P05) externe Empfänger erreichen. Kanäle je (Benutzer, Typ) über `core.notification_prefs` steuerbar (Standardkanal ab-, Zusatzkanal anschaltbar). Kanal-Fehler sind isoliert (eine fehlgeschlagene E-Mail verliert nicht die In-App-Benachrichtigung). API für den Token-Inhaber: `GET /api/v1/notifications`, `POST /api/v1/notifications/{id}/read`, `…/read-all` (Scope `me:read`, in OpenAPI). Verifiziert per Test (In-App-Speichern/-Lesen, Präferenz-Abschaltung, E-Mail-Kanal, Modul-Kanal) + Live-Smoke (Benachrichtigung erscheint über die API). Nebenbei behoben: boolescher SQL-Parameter `false` wurde als `''` gebunden (PostgreSQL-Boolean-Fehler) — jetzt `'true'`/`'false'` in setActive/setPref. |
| 6.56 | 09.06.2026 | **Echtzeit-Stream (Programm, P08; SSE)**: `GET /events/stream` liefert dem angemeldeten Benutzer Server-Sent-Events über seinen `LISTEN/NOTIFY`-Kanal (`RealtimeService::publish` → `pg_notify`), Basis für In-App-Benachrichtigungen (P09) — ohne zusätzlichen Broker (gleiche Strömung wie der Outbox-Worker). Bewusst **zeitlich begrenzt** (≈30 s/Verbindung) mit Heartbeats; der Browser (`EventSource`) verbindet automatisch neu, sodass kein FPM-Worker dauerhaft gebunden wird. Der Stream läuft als `CallbackStream` bei der Antwort-Ausgabe, also außerhalb der Request-Transaktion (RLS); eigene LISTEN-Verbindung (App-Rolle). Verifiziert per Test (identifier-sichere Kanalnamen, Publish) + Live (Cross-Session `publish→LISTEN` liefert die Nutzlast; `/events/stream` ohne Login → 401). |
| 6.55 | 09.06.2026 | **API-Reife (Programm, P07; Kap. 29)**: Drei habitus-konsistente Bausteine. **(1) Rate-Limiting** — `ApiRateLimitMiddleware` (Fixed-Window/Minute je API-Token bzw. IP über den Cache/P02; Redis-fähig, atomar bzw. read-modify-write-Fallback bei FileEngine; `429`+`Retry-After`+`X-RateLimit-*`), steuerbar über `core.api.rate_limit.*`, fail-open bei nicht verfügbarem Cache. **(2) OpenAPI 3.1** — `GET /api/v1/openapi.json` aus dem **tatsächlichen** Bestand generiert (Core-Endpunkte + Modul-Routen, `OpenApiGenerator`), Single Source of Truth statt Handpflege. **(3) Modul-Endpunkt-Registrierung** — neuer Core-Contract `core.api.route`: Module deklarieren Endpunkte im Manifest (`api_routes`: method/path/class, `ApiEndpointInterface`); der Core routet `/api/v1/m/<key>/<pfad>` über die `ContributionRuntime` (in-process oder out_of_process per RPC, Kap. 23.16.2) mit Bearer-Token/Scope/RLS. Verifiziert per Test (Pfad-Matching, OpenAPI-Gerüst, Rate-Limit-429) + Live-Smoke (`/openapi.json` 200 + `X-RateLimit`-Header mit Token, 401 ohne). |
| 6.54 | 09.06.2026 | **OIDC- + SAML-SSO als First-Party (Programm, P06; Kap. 27.2.2)**: Externe Identitätsföderation ist nicht mehr „optional via Extension", sondern Core-seitig vorhanden — **parallel** zur lokalen Anmeldung (Break-Glass bleibt). **OIDC**: Authorization-Code-Flow mit **PKCE**, Discovery + **JWKS-Signaturprüfung** des ID-Tokens (`web-token/jwt-library`), Claim-Prüfung (iss/aud/exp/nonce); Netzzugriffe über den gehärteten Egress (P01), Discovery/JWKS gecacht (P02). **SAML 2.0**: SP-initiierter Login + ACS mit signaturgeprüfter Assertion (`onelogin/php-saml`). Neue Tabellen `core.sso_providers` (Konfig als JSONB, Client-Secret AES-verschlüsselt) und `core.identity_links` (externe Identität ↔ Core-Benutzer). **Just-in-Time-Provisioning/Account-Linking**: Zuordnung über Link → E-Mail → Anlage (Status `active`, ohne Passwort); Identitäten/Autorisierung bleiben Core-verwaltet. Login-Seite zeigt Buttons aktiver Provider; Verwaltung über CLI `sso` (add-oidc/add-saml/list/on/off/rm). SAML-ACS ist von der CSRF-Prüfung ausgenommen (Echtheit über die signierte Assertion). Verifiziert per Test (ID-Token-Signatur/Claims gegen lokal erzeugtes Schlüsselpaar; Provisioning/Linking/Secret-Verschlüsselung; SAML-Settings/Attribute) + Live-Smoke (Provider anlegen → Button rendert). Admin-GUI als spätere Ergänzung. |
| 6.53 | 09.06.2026 | **Outbound-Webhooks (Programm, P05)**: Plattform-Events extern über HTTP zustellen — auf dem gehärteten Egress (P01, SSRF-Schutz) und nach dem Outbox-Muster. Neue Tabellen `core.webhook_subscriptions` (URL/Event-Filter/HMAC-Geheimnis, „deaktivieren statt löschen") und `core.webhook_deliveries` (pro Subscription+Event eine Zustell-Aufgabe; UNIQUE(subscription_id,event_id) = **idempotentes** Einreihen). Der Worker reiht beim Event-Dispatch passende Subscriptions ein und stellt fällige Zustellungen zu: **HMAC-signiert** (`X-Fertura-Signature: sha256=<hmac>` über `"<timestamp>.<body>"`, replay-fest), mit Retry/exponentiellem Backoff und **Dead-Letter** (nie still verworfen). Verwaltung über CLI `webhook` (list/add/rm/on/off/deliver/deliveries/retry). SSRF-Schutz des Egress gilt auch für Webhook-Ziele (private URLs nur per Allowlist). Admin-GUI als spätere Ergänzung. Verifiziert per Test (Signatur, Filter-Matching, Idempotenz, Zustellung inkl. Header, Retry→Dead-Letter). |
| 6.52 | 09.06.2026 | **Metrics + Tracing (Programm, P04)**: Prometheus-Endpoint `GET /metrics` (Textformat 0.0.4) — geschützt wie der Health-Detailpfad (Session ODER `core.health_token`). Exportiert **gemeinsam geteilten DB-Zustand** als Gauges (`fertura_up`, `fertura_worker_heartbeat_age_seconds`, `fertura_outbox_events`, `fertura_modules`) — bewusst zustandsbasiert statt prozesslokaler Zähler, wodurch das PHP-FPM-Aggregationsproblem entfällt; gelesen über die privilegierte Verbindung, jede Teil-Abfrage fehlerisoliert. **Tracing:** W3C-`traceparent` wird in der `LogContextMiddleware` fortgeführt/erzeugt (`trace_id`/`span_id` in jeder Logzeile), der Egress-Client (P01) propagiert `traceparent` an ausgehende Aufrufe. Begleitend gehärtet: der `CacheStore` (P02) unterdrückt umgebungsbedingte FileEngine-Warnungen (graceful degradation, kann eine Anfrage nicht mehr stören), und das Cache-Verzeichnis wird im Entrypoint für den Laufzeit-Nutzer (www-data) beschreibbar gemacht. End-to-End über `/metrics` verifiziert. |
| 6.51 | 09.06.2026 | **Objekt-Storage-Abstraktion (Programm, P03)**: Einheitliche Storage-API `App\Service\Storage\StorageManager` auf Basis von Flysystem 3 — **lokal** (Default) oder **S3-kompatibel** (AWS/MinIO, leichter `async-aws`-Adapter statt AWS-SDK). Write/Read/Stream/Exists/Size/List/Delete mit Übersetzung in `StorageException`. Treiber/Wurzel über `core.storage.driver`/`core.storage.path`; S3-Zugangsdaten **out-of-band** über `STORAGE_S3_*`-Env (nie in der DB). Fundament für Off-Site-Backup (P14) und Reporting/Export (P13). Verifiziert per Test gegen den lokalen Adapter (kein Netz). |
| 6.50 | 09.06.2026 | **Cache-Abstraktion + Settings-Cache (Programm, P02)**: Engine-konfigurierbarer Anwendungs-Cache (`_app_`/`_app_settings_`, via `CACHE_APP_URL` auf File/APCu/Redis umstellbar) plus ausfallsicherer Helfer `App\Service\Cache\CacheStore` (graceful degradation: bei nicht verfügbarem Cache wird der Aufruf zur Nicht-Operation, die Quelle bleibt maßgeblich). Die heiß gelesenen Konfigurationswerte (`SettingsManager::get`) werden nun gecacht und bei `set()` **gezielt invalidiert**; **Geheimnisse werden NIE gecacht** (kein Klartext im Datei-Cache, immer DB/AES). Verifiziert per Unit-Test (Cache-Roundtrip/remember; Settings-Invalidierung; Geheimnis-Bypass). |
| 6.49 | 09.06.2026 | **HTTP-Egress-Primitiv (Wettbewerbs-Programm Tier 1, P01)**: Gemeinsamer, gehärteter Ausgang für alle nach außen gerichteten Aufrufe (künftig Webhooks, OIDC, AI-Gateway, Marketplace) auf Basis von `Cake\Http\Client` — neue `App\Service\Http\EgressClient`. Kernschutz **SSRF**: ohne ausdrückliche Freigabe werden Ziele in privaten/reservierten Netzen (Loopback, RFC1918, Link-Local inkl. Cloud-Metadaten `169.254.169.254`) blockiert; nur `http`/`https`; Timeout, Antwortgrößen-Limit, fester User-Agent. Steuerbar über `core.http.egress.*` (enabled/timeout/max_response_bytes/allow_private/allowlist/user_agent). Verifiziert per Unit-Test (Schema-/Privat-/Metadaten-Block, Allowlist, Override, Antwort-Mapping/-Begrenzung). Erstes Fundament-Primitiv des Programms „Wettbewerbsfähigkeit Core" (Tier 1–3, siehe PROGRAM_TIER123.md). |
| 6.48 | 09.06.2026 | **Review-Härtung der Out-of-Process-Isolation (Kap. 23.16.2; Korrektheits-/Sicherheits-Härtung, keine Spezifikationsänderung)**: Ein vollständiger Review (3 unabhängige Prüfungen) bestätigte Funktion und Doku-Sync; behoben wurden die gefundenen Restpunkte. **(a)** **Verwaiste Hosts vermeiden:** Das Stoppen findet den **tatsächlichen** Host-Prozess über `/proc` (Kommandozeile: Host-Skript **und** Modul-Key) statt sich auf die eingefangene Start-PID zu verlassen — ein **forkender Launcher** (z. B. `firejail`) kann so keinen verwaisten Host mit belegtem Socket/DB-Rolle mehr hinterlassen. **(b)** **Geheimnis out-of-band:** Der HMAC-Schlüssel wird dem Host nur noch als **Dateipfad** (0600) statt als Umgebungs-/Kommandozeilen-Wert übergeben — kein Klartext in `/proc/<pid>/environ`. **(c)** **Fail-closed:** Fehlt das Geheimnis, verweigert der Host den Start, statt unauthentifiziert zu bedienen. **(d)** **DoS-Schutz:** Die Anfragezeile ist vor dem Parsen auf 4 MiB begrenzt. **(e)** **Ehrliche Einordnung dokumentiert:** Das Pro-Aufruf-Token authentifiziert die Prozessgrenze (Core→Host), beschränkt aber nicht den Modulcode selbst (der das Geheimnis kennt); die eigentliche Sandbox bleiben DB-Rolle/bereinigte Umgebung/OS-Isolation. **(f)** **Launcher-Anforderung** (exec bzw. SIGTERM-Weiterreichung) und **Settings-Write-Vertrauensstufe** (shell-äquivalent) dokumentiert; stale Code-Docblock korrigiert. Volle Suite weiterhin 90 grün. |
| 6.47 | 09.06.2026 | **Pro-Aufruf-Capability-Token für die Out-of-Process-RPC (Kap. 23.16.2)**: Die zuvor rein kanal-basierte Authentifizierung (ein statisches, bei jedem Aufruf im Klartext mitgeschicktes Host-Token) wird **aufruf-gebunden**. Das pro Host erzeugte Geheimnis dient jetzt **nur noch als HMAC-Schlüssel** und reist **nie** über den Socket; jede Anfrage trägt stattdessen einen MAC über die **gesamte kanonisierte Anfrage** (Operation, Klasse/Methode, Argumente, RLS-Kontext) plus **Nonce** und **Ablauf**. Damit ist der Aufruf integritätsgeschützt (keine Manipulation von Nutzlast/Kontext, z. B. keine `bypass`-Eskalation), zeitlich begrenzt und einmalig (der Host weist abgelaufene **und** wiederholte Nonces ab) — ein am Socket abgefangenes Token ist weder wiederverwendbar noch auf einen anderen Aufruf ummünzbar. Dies war der letzte als „spätere Protokoll-Erweiterung" benannte offene Punkt der Isolation. Neue Klasse `RpcCapabilityToken` (symmetrisches mint/verify, deterministische Kanonisierung); `RemoteInvoker` signiert, `module-host.php` prüft + hält eine Nonce-Sperrliste. Verifiziert per Unit-Test (MAC-Roundtrip, Tamper-/Geheimnis-Bindung, Ablauf, stabile Kanonisierung) und E2E-Test (gültiger Aufruf ok; Replay derselben Nonce, falsches Geheimnis und manipulierte Nutzlast werden über den realen Socket abgewiesen). |
| 6.46 | 09.06.2026 | **Konfigurierbares Launcher-Prefix für isolierte Modul-Hosts (Kap. 23.16.2)**: Neues Setting `core.module.host.launcher` — ein Befehls-Prefix, das der Supervisor in der bereinigten `env -i`-Umgebung **vor `php`** setzt, um den Host-Prozess zusätzlich vom Betriebssystem zu isolieren, **ohne Core-Codeänderung**. Damit kann der Betreiber je nach Plattform die zuvor als „spätere Ausbaustufe" benannte OS-Härtung selbst aktivieren: **eigener OS-Benutzer** (`setpriv`/`sudo`/`runuser`, erfordert OS-Rechte) und **Dateisystem-/Kernel-Sandbox** (`bwrap`/`nsjail`/`firejail` — Namespaces + seccomp). Die Prozesserkennung des Supervisors (Starten/Stoppen/Selbstheilung) wurde **wrapper-tolerant** gemacht (Match auf Host-Skript **und** Modul-Key in der Kommandozeile statt auf ein exaktes argv-Token), bleibt aber PID-Recycling-sicher. Default leer = kein Prefix (unverändertes Verhalten). Capability-Tokens je Aufruf bleiben bewusst offen (Protokoll-Erweiterung, keine Infrastruktur). Verifiziert per E2E-Test (transparenter Wrapper-Launcher: Prefix läuft vor `php`, Argumente werden durchgereicht, Service-Aufruf + sauberes Stoppen des gewrappten Hosts). |
| 6.45 | 09.06.2026 | **Out-of-Process-Isolation Phase 3 abgeschlossen (Kap. 23.16.2)**: Auch **Daten-Resolver** und **periodische Aufgaben** (`core.collector.scheduled`) isolierter Module laufen jetzt über RPC im isolierten Host — damit decken alle gängigen Erweiterungspunkte (Service, Collector, Event, Resolver, Scheduled) die Isolation ab. Bei periodischen Aufgaben bleiben Fälligkeits-Prüfung (Heartbeat) und der Mehrinstanz-Advisory-Lock **im Core**; nur die Ausführung (`run()`) reist über die RPC-Grenze (Systemkontext mit RLS-Bypass). Resolver werden über das Capability-Handle aufgerufen und nach der **Isolation des bereitstellenden Moduls** geroutet. Einzige verbleibende Ausnahme: der **Auth-Provider-Slot** (`core.auth.provider`) — er ist config-artig (liefert ein In-Process-Authenticator-Objekt) und bleibt naturgemäß in-process. Nebenbei geschlossene latente Lücke: Der Core-Contract `core.collector.scheduled` war nie geseedet, sodass sich Module gar nicht für periodische Aufgaben registrieren konnten (Migration `CoreScheduledCollector`). |
| 6.44 | 09.06.2026 | **Out-of-Process-Isolation Phase 3 (Kap. 23.16.2 erweitert)**: Isolierte Module dürfen jetzt nicht nur Service-Contracts, sondern auch **Collector-Beiträge** (Health, Anonymisierung) und **Event-Listener** anbieten — diese laufen über RPC im isolierten Host. Der **RLS-Zeilenkontext** (`app.current_user_id`/`-group_ids`/`-bypass`) wird über die RPC-Grenze mitgereicht und im Host gesetzt; Beitragsklassen nutzen dort eine CakePHP-Connection auf die Modul-Rolle. **Resolver** und **periodische Aufgaben** (`core.collector.scheduled`) bleiben bei Isolation abgelehnt (noch nicht über RPC — keine stille In-Process-Ausführung). Transaktionsgrenze dokumentiert (Out-of-Process-Beitrag committet eigenständig). |
| 6.43 | 09.06.2026 | **Anonymisierungs-Hook für Module (Kap. 27.15.3 aktualisiert)**: Der Core stellt jetzt den Collector-Contract `core.collector.anonymize` bereit, über den Module beim irreversiblen Anonymisieren eines Benutzers **in derselben Transaktion** ihre eigenen personenbezogenen (Freitext-)Daten zu dem Benutzer mit-bereinigen (Interface `AnonymizeContributorInterface`). All-or-Nothing; für die Beiträge wird RLS-Bypass gesetzt und danach wiederhergestellt. Damit ist die zuvor als „spätere Version/Betreiberprozess" markierte Freitext-Bereinigung über Modulgrenzen **Core-seitig orchestriert** — die fachliche Bereinigung bleibt Modul-Sache. |
| 6.42 | 09.06.2026 | **Peer-Review-Härtung der Modul-Installation (Kap. 24)**: Bestand-Befund M7 behoben (Korrektheits-Härtung, keine Spezifikationsänderung). Der `install()`-Vorgang lässt sich nicht in eine DB-Transaktion kapseln (CREATE ROLE/Schema und das Kopieren des Pakets sind teils nicht-transaktional); zuvor räumte nur der RLS-Pflicht-Abbruch (E47) einen Teil der Artefakte ab — schlug ein **späterer** Schritt fehl (Schema-Grant an die App-Rolle, Sprachpaket-Import oder eine `RegistryException` beim Registrieren der Contracts), blieben Schema, Modulzeile, kopiertes Verzeichnis und — bei `out_of_process` — die provisionierte DB-Rolle `mod_<key>` zurück, sodass ein erneuter Install an „Modul bereits installiert" scheiterte. Jetzt umschließt ein zentraler manueller Rückbau **alle** Schritte ab Beginn der Seiteneffekte: bei jedem Fehlschlag wird der isolierte Host gestoppt, die DB-Rolle entfernt, das Schema gedroppt, Modulzeile/Registrierungen/Contracts/Ressourcen/Sprachpakete gelöscht und das kopierte Verzeichnis (samt Sprachpaket-Dateien) entfernt. Verifiziert per Integrationstest (erzwungener Fehlschlag nach der Schema-Erzeugung → nichts bleibt zurück, anschließender Install gelingt). |
| 6.41 | 09.06.2026 | **Peer-Review-Härtung der Daten-Wiederherstellung (Kap. 20.1.2)**: Zwei Bestand-Befunde im Backup-Subsystem behoben (Korrektheits-Härtung, keine Spezifikationsänderung). (a) **Restore-Erfolg wird jetzt geprüft**: Die destruktive Wiederherstellung wertet Exitcode **und** Ausgabe von `pg_restore` aus und schlägt bei echten Fehlern fehl — zuvor wurde beides ignoriert, sodass ein fehlgeschlagener Restore still durchlief, der Wartungsmodus (HTTP 503) wieder freigegeben und „erfolgreich" protokolliert wurde, obwohl eine halb-wiederhergestellte Datenbank online ging. Harmlose `--clean`-Notices („existiert nicht, übersprungen") bleiben bewusst toleriert (kein Fehlalarm). (b) **Konsistenz-Lock ist Pflicht**: Lässt sich der Lifecycle-Lock beim Sichern nicht erhalten (eine parallele Update-/Restore-/Backup-Operation hält ihn), bricht die Sicherung jetzt klar ab, statt still ohne Lock einen DB↔Storage-inkonsistenten Snapshot zu erzeugen. Verifiziert per Integrationstest (gehaltener Lock → Abbruch). |
| 6.40 | 08.06.2026 | **Peer-Review-Härtung der Out-of-Process-Isolation (Kap. 23.16.2 präzisiert)**: Ein interner Peer-Review deckte auf, dass die „Migrationen-als-Rolle"-Zusage über `RESET ROLE` umgehbar war (`SET LOCAL ROLE` auf einer Superuser-Sitzung) und Updates ohnehin als Superuser migrierten. Behoben: Modul-Migrationen laufen jetzt über eine **als Login-Rolle authentifizierte Verbindung** (Install + Update; `RESET ROLE` führt nicht mehr zu Superuser), der RPC-Socket ist durch ein **pro-Host-Token** abgesichert (nicht mehr anonym aufrufbar), Katalog-Bezeichner werden gequotet. Ehrlich benannte Restposten ergänzt: RLS-Zeilenkontext über RPC und Same-User-Trennung (eigener OS-Benutzer) sind spätere Phasen. Keine Spezifikationsänderung; Korrektheits-/Sicherheits-Härtung. |
| 6.39 | 08.06.2026 | **Upgrade-Pfad abgesichert (Kap. 24.13/28.14.2)**: Down-Reversibilität **aller** Core-Migrationen per Wegwerf-DB-Harness nachgewiesen (migrate → rollback -t 0); Modul-Update-Integrationstest für Migrationsvorschau, Wiederherstellungspunkt (nur bei ausstehenden Migrationen) und die Rollback-Kaskade. Begleitend **Bugfix**: die Update-Rollback-Kaskade rollte bei einem Fehlschlag fälschlich auch beim Install angewendete Migrationen zurück (Datenverlust) — jetzt nur noch die im fehlgeschlagenen Update neu angewendeten. Keine Spezifikationsänderung; Reifegrad-/Korrektheits-Härtung. |
| 6.38 | 08.06.2026 | **Deployment-Feature-Flags für optionale Subsysteme (Kap. 20.8.5 neu)**: Optionale Subsysteme lassen sich je Installation per Umgebungsvariable abschalten — `FEATURE_API` (externe API v1: keine `/api`-Routen + kein API-Middleware), `FEATURE_MARKETPLACE` (Marketplace-Client/-Sync; Lizenzverwaltung bleibt), `FEATURE_BACKUP_SCHEDULER` (automatische Backups; manuelles Backup bleibt). Bewusst env-basiert (harter Betreiber-Schalter), Standard alle aktiv; Zustand unter `/health` (`features`). Setzt den im internen Review benannten Hebel zur Reduktion der Angriffs-/Wartungsfläche um (Kern-Subsysteme bleiben nicht abschaltbar). |
| 6.37 | 08.06.2026 | **Instanzübergreifender Session-Speicher (HA-Voraussetzung, Kap. 30.7.1 neu)**: DB-gestützte Sessions (`core.sessions`, CakePHP `DatabaseSession`, eigene `SessionsTable`) als instanzübergreifender Speicher, aktivierbar über `SESSION_DEFAULTS=database` (Referenz-Compose: an). Schließt die zweite (von zwei) Voraussetzung für einen Mehrinstanz-Betrieb der Web-Schicht — die erste (mehrknotenfähiger Scheduler-Lock) war bereits erfüllt. Sessions überleben zudem Container-Recreates. Einzelinstanz bleibt Standard; HA ist ein bewusster Betreiber-Schritt (zusätzlich geteilte Volumes + Lastverteiler = Infrastruktur). |
| 6.36 | 08.06.2026 | **Out-of-Process-Isolation, Phase 2 — Finalisierung** (Kap. 23.16.2 erweitert): Die Isolationsgrenze ist jetzt **automatisch und selbstverwaltet**. Pro isoliertem Modul legt der Core automatisch eine **eigene, eingeschränkte DB-Rolle** (verschlüsseltes Passwort) an; die **Modul-Migrationen laufen unter dieser Rolle** statt als Superuser (schließt das Rest-Risiko „Install-Migration mit Superuser-Rechten"), mit anschließend **erzwungener RLS** (`FORCE ROW LEVEL SECURITY`). Ein **Supervisor** startet/stoppt/heilt die Hosts (Aktivieren startet, Deaktivieren/Löschen stoppt + entfernt die Rolle; der Worker überwacht periodisch). **Geltungsbereich bewusst eng:** isolierte Module dürfen nur Service-Contracts anbieten; nicht-RPC-fähige Erweiterungspunkte werden **abgelehnt** statt still in-process ausgeführt. CLI `module install --isolation`, `module isolate`, `module host`. Verifiziert per E2E-Integrationstest. |
| 6.35 | 08.06.2026 | **Betriebs-Härtung (drei Restposten)**: (a) **Restore-Cutover** (20.1.2/28.11): Die destruktive Daten-Wiederherstellung schaltet für ihre Dauer automatisch den **Wartungsmodus (HTTP 503)** über ein **datei-basiertes Flag**, das den DB-Restore übersteht (ein DB-Setting würde mitten im Vorgang überschrieben), und gibt ihn danach wieder frei. (b) **Wiederherstellungspunkte** (28.14.2) liegen auf dem **persistenten Backup-Volume** (`backups/recovery/`) statt im flüchtigen `tmp/` und werden nach Anzahl aufbewahrt (`RECOVERY_KEEP`). (c) Reversibilität: die down-Migration der Backup-Log-Härtung bereinigt vor dem Zurücksetzen die neu hinzugekommene `download`-Operation. Keine Spezifikationsänderung; Umsetzungs-/Reifegrad-Härtung. |
| 6.34 | 08.06.2026 | **Integrationstest-Abdeckung der kritischen Pfade** (Reifegrad, keine Spezifikationsänderung): DB-gestützte PHPUnit-Integrationstests gegen eine echte PostgreSQL-Test-DB für Modul-Lifecycle **inkl. RLS-Durchsetzung** (Kap. 24/30.3), Signatur-/Vertrauenskette (Kap. 24.9.2), Backup/Restore-Roundtrip mit Probe-Restore und AES-256 (Kap. 20.1.2), i18n-Versions-Gate (Kap. 31) und API-Token-/Bearer-Authentifizierung (Kap. 29). Suite auf 46 Tests erweitert; CI um PostgreSQL-17-Client + sodium ergänzt; `TESTING.md` neu. Schließt den im internen Architektur-Review benannten dominanten Reifegrad-Blocker (zuvor nur Smoke-Tests). |
| 6.33 | 08.06.2026 | **Opt-in Out-of-Process-Modulisolation, Phase 1** (neues Kapitel 23.16.2; 23.9.3.1 aktualisiert): Ein Modul kann je Installation auf `isolation = out_of_process` gesetzt werden und läuft dann als vom Core verwalteter Subprozess mit **bereinigter Umgebung** (kein Core-`DATABASE_URL`/`BACKUP_PASSWORD`) und **eigener, eingeschränkter DB-Rolle** (`mod_<key>`, nur eigenes Schema). Der Core ruft die Service-Contracts transparent über Unix-Domain-Socket-RPC auf (`CapabilityHandle` routet automatisch); Ein-/Ausgabe bleiben die serialisierbaren Contract-Strukturen aus 29.8. Erste echte technische Isolationsgrenze über die Vertrauenskette (23.16.1) hinaus; weitere Härtungsstufen (OS-Benutzer/Container, Capability-Tokens) als spätere Ausbaustufen. Verifiziert per Isolations-Harness. |

## Anhang B: Entscheidungsprotokoll

Die folgenden Entscheidungen wurden im Rahmen der Plattformarchitektur
getroffen.

| **Nr.** | **Thema** | **Entscheidung** |
| --- | --- | --- |
| 107 | Modulare Plattformarchitektur | Core als technische Plattform ohne Fachlogik. Fachliche Funktionalität in Main-Module und Extension-Module ausgelagert |
| 108 | Core-Grundregel | Core-Administration über Administrationsbereiche: Volladministrator = alle Bereiche, delegierter Administrator = Teilmenge (Entscheidung 170). Kein BREAD im Core. Konfigurationsobjekte nur aktivierbar/deaktivierbar |
| 109 | Main-Modul-Grundregel | Main-Module müssen ohne Extension-Module vollständig lauffähig sein. Extensions erweitern, ersetzen aber nicht die Grundlauffähigkeit |
| 110 | Erweiterungsmechanismen | Drei Erweiterungsmechanismen, bei denen externe Module einen vom Owner definierten Punkt besetzen: Resolver (genau ein Ergebnis), Collector (mehrere Beiträge), Event (Benachrichtigung). Ergänzt um den vierten Contract-Typ Request/Response (Service), bei dem das Owner-Modul bereitstellt (Entscheidung 167). Kein unscharfer "Hook"-Begriff |
| 111 | Resolver-Default | Jeder Resolver hat ein dokumentiertes Default-Verhalten. Ein Prozess darf nie einen aktiven Provider zwingend voraussetzen |
| 112 | BREAD-Berechtigungssystem | BREAD gilt nur für Anwendungsmodule, nicht für den Core. Keine Deny-Regeln, keine Prioritäten, keine Konfliktlogik |
| 113 | Delete-Semantik | Core: nur Activate/Deactivate. Anwendungsmodule definieren eigene Delete-Semantik (Soft-Delete/Hard-Delete) |
| 114 | Moduldeaktivierung | Deaktivierung löscht nie Daten. Resolver fallen auf Default zurück. Collector/Events ignorieren deaktivierte Module. Löschung ist separater expliziter Vorgang |
| 115 | Paketformat | Module werden als signierte Pakete ausgeliefert. Manuelle Dateikopien in Modulverzeichnisse sind nicht vorgesehen |
| 116 | Manifest-Pflicht | Ohne gültiges Manifest darf ein Modul weder installiert noch aktiviert werden. Manifest enthält Pflichtfelder für Identität, Typ, Kompatibilität und Signatur |
| 117 | Signaturprüfung | Verpflichtend bei Installation und Update. Unsignierte oder ungültig signierte Pakete werden abgelehnt. Prüfung erfolgt vor dem Entpacken |
| 118 | Installationsfluss | 14-Schritte-Prozess mit vollständiger Prüfkette (Signatur → Manifest → Kompatibilität → Abhängigkeiten → Konflikte → Lizenz) vor Entpacken |
| 119 | Resolver-Konfliktregel | Bei belegtem Resolver-Slot darf kein zweites Modul parallel aktiviert werden. Bestehendes Modul muss zuerst deaktiviert werden |
| 120 | Contract-Deklaration | Nur im Manifest deklarierte Contracts sind öffentlich und dürfen von anderen Modulen genutzt werden. Nicht deklarierte Erweiterungspunkte gelten als intern |
| 121 | Update-Scope | Updates beziehen sich ausschließlich auf die Anwendung (Core und Module), nicht auf Basisinfrastruktur (PHP, PostgreSQL, Betriebssystem) |
| 122 | BREAD-Geltungsbereich | BREAD gilt ausschließlich für Anwendungsmodule. Core verwendet kein BREAD. Core-Administration über Administrationsbereiche (siehe Entscheidung 170) |
| 123 | Ressourcenmodell | Drei Typen: Objektklasse, Einzelobjekt, Bereichsressource. Jede Ressource eindeutig durch Modul-ID + Ressourcenname + Typ identifiziert |
| 124 | Rechteaggregation | Rein additiv. Keine Deny-Regeln, keine Prioritäten, keine Konfliktlogik zwischen Gruppen. Vereinigung aller Gruppenrechte |
| 125 | Keine implizite Rechtevererbung | Extension-Module erben keine Rechte vom Main-Modul. Ressourcen müssen explizit gruppenbezogen konfiguriert werden. Keine stillschweigende Rechteausweitung |
| 126 | Zusatzaktionen | Module dürfen fachspezifische Aktionen über BREAD hinaus definieren (z.B. assign, merge, hard_delete). Müssen im Manifest deklariert werden |
| 127 | Contract-Formalität | Contracts sind keine losen Konventionen, sondern technische Verträge mit maschinenlesbarer Interface-Spezifikation (Input/Output typisiert, Default dokumentiert) |
| 128 | Contract-Versionierung | Unabhängig von Modulversion. Drei Änderungsklassen: Patch (Korrektur), Minor (abwärtskompatibel), Major (Breaking Change). Registrierung nur bei kompatibler Version |
| 129 | Resolver-Slot-Exklusivität | Genau ein aktiver Provider pro Slot. Zweiter Provider blockiert. Wechsel nur durch vorherige Deaktivierung des bisherigen Providers |
| 130 | Collector-Reihenfolge | Reihenfolge der Beiträge muss pro Contract definiert oder als unerheblich markiert sein |
| 131 | Fehlerverhalten Laufzeit | Fehlerhafte Provider/Listener dürfen Grundlauffähigkeit des Main-Moduls nicht brechen. Fehler werden protokolliert. Fehlerhafte Collector-Beiträge blockieren nicht die übrigen |
| 132 | Contract-Registry | Zentrale Admin-Sicht pro Contract (11 Felder) und pro Registrierung (7 Felder). Optionale Diagnoseoberflächen verlinkbar |
| 133 | Plattform-Identitätsmodell | Benutzer und Gruppen werden ausschließlich im Core verwaltet. Module dürfen keine eigene Benutzerbasis oder Gruppenverwaltung aufbauen |
| 134 | Core-Rollenmodell | Bewusst einfach und bereichsbasiert: Volladministrator (alle Administrationsbereiche) / delegierter Administrator (Teilmenge) / Nicht-Administrator (Zugriff nur über Modulberechtigungen). Kein BREAD für Core-Funktionen (siehe Entscheidung 170) |
| 135 | Zwei-Ebenen-Berechtigungen | Ebene 1: Core-Berechtigungen (bereichsbasiert über Administrationsbereiche). Ebene 2: Modulberechtigungen (gruppenbasiert über BREAD + Zusatzaktionen). Klare Trennung |
| 136 | Gruppen-Deaktivierung | Deaktivierung setzt Wirkung temporär außer Kraft, löscht keine Zuordnungen. Reaktivierung stellt alle Zuordnungen wieder her |
| 137 | Benutzer-Deaktivierung | Deaktivierter Benutzer: keine Anmeldung, keine Rechte, aber Gruppenmitgliedschaften und historische Referenzen bleiben erhalten. Reaktivierung stellt alles wieder her |
| 138 | Update-Scope Plattform | Plattform aktualisiert ausschließlich sich selbst (Core + Module). PHP, PostgreSQL, Webserver, Betriebssystem sind Betreiberverantwortung |
| 139 | Marketplace als autoritative Quelle | Pakete und Updates nur aus definiertem Marketplace oder gleichwertig signierten Paketquellen. Metadatenabruf bewirkt keine Systemänderung |
| 140 | Signaturprüfung vor Entpacken | Unsignierte oder ungültig signierte Pakete werden abgelehnt. Prüfung erfolgt vor dem Entpacken. Ergebnis wird protokolliert |
| 141 | Atomarer Update-Abschluss | Update gilt nur als erfolgreich wenn Paketstand, Migrationsstand und Registrierungsstand konsistent sind. Kein bewusst inkonsistenter Zustand |
| 142 | Core-Update Modulkompatibilität | Core-Update nur wenn installierte Module kompatibel sind oder Inkompatibilitäten vorab angezeigt und bestätigt wurden |
| 143 | Wartungsmodus | Nicht zwingend für jedes Update, aber vom Update-Mechanismus unterstützt. Während Wartung: keine fachliche Nutzung, keine parallelen Änderungen |
| 144 | Öffentliche Modul-Interfaces als Service-Contracts | Öffentliche Modul-Interfaces sind ein Contract-Typ (Request/Response/Service, Kap. 26.3.4): das Owner-Modul ist Anbieter, andere Module konsumieren. Keine parallele Architektur neben Contracts, sondern eine der vier Ausprägungen des einheitlichen Contract-Modells; gemeinsame Registry, Versionierung und Manifestfelder (siehe Entscheidung 167) |
| 145 | Integrations-Extension-Module | Modulübergreifende Integrationen bevorzugt in dedizierten Integrations-Extension-Modulen kapseln, nicht als Direktkopplung zwischen Main-Modulen |
| 146 | Datenhaltung Integrationsbeziehungen | Beziehungen zwischen Main-Modulen die nur durch Integration entstehen werden im Integrations-Extension-Modul gespeichert, nicht in den Main-Modulen selbst. Keine fachlichen Fremdreferenzen zwischen Main-Modulen |
| 147 | Interface-Nutzung deklarationspflichtig | Nutzung öffentlicher Interfaces muss ausdrücklich deklariert werden. Direkte Nutzung interner, nicht freigegebener Modulklassen oder Services ist unzulässig |
| 148 | Keine implizite Modulkopplung | Installation eines zusätzlichen Main-Moduls darf keine stille Aktivierung neuer Integrationen erzeugen. Neue Integrationsbeziehungen müssen sichtbar und nachvollziehbar werden |
| 149 | Deaktivierung Interface-Anbieter | Deaktivierung eines anbietenden Moduls darf Grundlauffähigkeit anderer Main-Module nicht zerstören. Betroffene Integrationen werden als inaktiv markiert |
| 150 | Zwei Extension-Modul-Typen | Reguläre Extension-Module erweitern genau ein Main-Modul. Integrations-Extension-Module dürfen mehrere Main-Module über öffentliche Interfaces verbinden. Beide Typen unterliegen denselben Core-Regeln |
| 151 | Capability-Bindung für Contracts und Interfaces | Contracts und öffentliche Modul-Interfaces sind zugriffsgeschützt. Module erhalten bei Aktivierung Capability-Handles nur für deklarierte, validierte Nutzungen und können nur aufrufen, wofür sie ein Handle besitzen; ein globaler Registry- oder Service-Zugriff besteht nicht. Zugriffskontrolle wirkt durch Konstruktion, nicht durch nachträgliche Aufruferprüfung (siehe Kapitel 26.13.2 und 29.8.3) |
| 152 | Kontrollierte Abweisungsbehandlung | Aufrufendes Modul ist verpflichtet, abgewiesene Aufrufe fachlich kontrolliert zu behandeln. Kein unkontrollierter Fehlerzustand. Konkrete Reaktion (Default, leeres Ergebnis, Fehler, Ausblenden) ist moduldefiniert |
| 153 | Main-Module nutzen keine fremden Contracts/Interfaces | Main-Module dürfen Contracts und öffentliche Interfaces bereitstellen, aber nicht von anderen Modulen konsumieren. Nur Extension-Module dürfen fremde Contracts und Interfaces nutzen |
| 154 | Versionsschema und Kompatibilitätsregel | Alle Versionen folgen Semantic Versioning (MAJOR.MINOR.PATCH). Geforderte Versionen werden als exakte Version oder als expliziter Bereich (>=x <y) deklariert; Kurzformen (Caret/Tilde) sind unzulässig. Kompatibel = gleiche Major-Version und Anbieterversion ≥ geforderte Version. Major-Wechsel bricht Kompatibilität (siehe Kapitel 26.6.4) |
| 155 | Migrations-Rollback und Wiederherstellungspunkt | Migrationen laufen in einer Datenbanktransaktion (PostgreSQL: transaktionales DDL → atomares Rollback bei Fehler). Jede Migration liefert zusätzlich eine umkehrende down-Operation. Vor jedem migrationsbehafteten Update wird ergänzend ein Wiederherstellungspunkt (vollständiger DB-Dump, pg_dump) erstellt; ohne ihn wird das Update blockiert. Einheitlich für Core- und Modul-Updates. Destruktive Schemaänderungen nur per expand/contract. Rollback primär per Transaktion, dann down-Operationen, ersatzweise Wiederherstellungspunkt (siehe Kapitel 28.14.2) |
| 156 | Signatur-Vertrauensanker und Widerruf | Zweistufiges Trust-Modell: Marketplace-Wurzelschlüssel (mit Core ausgeliefert) signiert Herausgeber-Zertifikate; gültige Signaturkette bis zu aktivem Vertrauensanker erforderlich. Schlüsselrotation über signierten Marketplace-Kanal, installierte Module bleiben gültig. Widerruf über signierte Sperrliste, vor Installation/Update verpflichtend geprüft (Block bei widerrufenem Schlüssel). Bei nicht erreichbarer Sperrliste gecachte Liste mit Alters-Warnung. Nachträglich widerrufene Schlüssel installierter Module: Warnkennzeichnung, keine automatische Deaktivierung, keine Datenlöschung (siehe Kapitel 24.9.2) |
| 157 | Sicherheits- und Vertrauensmodell | Module laufen als vertrauenswürdiger Code im selben Laufzeitkontext wie der Core; keine technische Sandbox. BREAD und Capability-Bindung sind Berechtigungs-/Disziplin-Mechanismen (auditierbar), keine Barriere gegen bösartigen Modulcode. Maßgebliche Sicherheitsgrenze ist die Signatur-/Vertrauenskette (24.9.2): Vertrauen wird vor Ausführung etabliert, nicht zur Laufzeit erzwungen (siehe Kapitel 23.16) |
| 158 | Offline-first-Lizenzierung mit optionalem Online-Enforcement | Maßgeblich ist die signierte Lizenzdatei (Modulbezug, Gültigkeitszeitraum, Karenzfenster, optionales Online-Enforcement; manipulationssicher). Aktivierung, Update und Betrieb erfordern keinen Serverkontakt; online nur für Sperrlisten und optionale Erneuerung. Ablauf des Gültigkeitszeitraums → Deaktivierung ohne Datenlöschung. Optionales, in der Lizenz deklariertes Online-Enforcement (Miet-/Abo-Modelle): erfordert periodische Online-Bestätigung; bei Nichterreichbarkeit greift das lizenzdefinierte Karenzfenster (fehlt = null), bei bestätigtem Ablauf sofortige Deaktivierung. Karenz überbrückt nur Nichterreichbarkeit, nie eine bestätigt abgelaufene Lizenz (siehe Kapitel 28.7.3) |
| 159 | Modul-Konfiguration und -Geheimnisse | Modulspezifische Konfiguration und Geheimnisse gehören nicht in config/app.php, sondern in den Konfigurationsspeicher des Core; Geheimnisse werden verschlüsselt abgelegt (AES-256-GCM mit Core-Schlüssel). In app.php verbleiben nur infrastrukturelle Basiseinstellungen. Betrifft u.a. CAPTCHA-Schlüssel (Gastportal-CAPTCHA) und Gast-Session-Timeout (Ticketing-Gastzugang) (siehe Kapitel 1.4) |
| 160 | DSGVO-Löschung durch Anonymisierung | Aktivierte Benutzer werden nicht physisch gelöscht, sondern auf Antrag irreversibel anonymisiert (Identitätsfelder durch nicht rückführbaren Platzhalter ersetzt, technische ID und historische Referenzen bleiben). Pseudonymisierung (umkehrbar) ist für diesen Zweck unzulässig. Audit-Log behält Vorgänge, ersetzt Personenbezug durch anonymisierte Referenz. Einladungs-Accounts (Status "eingeladen") werden weiterhin physisch gelöscht. Identitäts-Anonymisierung = Muss (v1); Freitext-Bereinigung = Spätere Version (siehe Kapitel 27.15.3) |
| 161 | Modul-UI: View-Models und Ausgabesicherheit | UI-Beiträge (Collector-UI, ui_extensions) werden als strukturierte View-Models bzw. deklarative Deskriptoren bereitgestellt; das Markup erzeugt der Core (kein modulübergreifendes Roh-HTML als Regelfall, konsistentes Bootstrap-Styling). Dynamische Werte werden vom Core kontextkorrekt kodiert; die Absicherung obliegt der einbettenden Core-Renderschicht (Empfänger), Modulwerte gelten als nicht vertrauenswürdig. Roh-HTML nur per explizitem, dokumentiertem Opt-out (abgeraten). CSP als Betreiber-Empfehlung (siehe Kapitel 26.8.2) |
| 162 | API-Authentifizierung und Anmeldeschutz | REST-API serverseitig authentifiziert, jeder Aufruf an eine Core-Identität gebunden (kein anonymer Zugriff außer modulverantworteten Ausnahmen). Mechanismus-offene, an den Benutzer gebundene, widerrufbare Zugangstoken; effektive Rechte werden je Aufruf live gegen die aktuellen BREAD-Rechte/Zusatzaktionen geprüft (Token-Scopes können zusätzlich einschränken, nie erweitern). Deaktivierter/anonymisierter Benutzer → Token sofort ungültig. Anmeldeschutz per Rate-Limiting/temporäre Sperre, Schwellen in DB/GUI konfigurierbar (analog Passwort-Policy) mit sicherem Vorgabewert. Auth-Grundsatz und gemeinsames Rechtemodell = Muss; Token-Lebenszyklus und Anmeldeschutz-Schwellen = Soll (siehe Kapitel 27.16.3) |
| 163 | Referenzrobustheit der Audit-Einträge | Audit-Einträge werden selbsterklärend gespeichert (Modul-ID, Name, Version, Objektkennung als textuelle Kopie, nicht nur als Fremdschlüssel). Nach Modullöschung und Datenentfernung bleiben Audit-Einträge vollständig lesbar und weisen das Modul als entfernt aus; das Log wird nicht bereinigt. Nachvollziehbarkeit hängt nicht von der Existenz des auslösenden Moduls oder betroffenen Objekts ab (siehe Kapitel 24.16.1) |
| 164 | Schlüsselrotation verschlüsselter Werte | Keine routinemäßige/automatische Key-Rotation in v1 (für on-prem mit kleinem, änderungsarmem Geheimnis-Bestand nicht gerechtfertigt). CLI-Re-Encryption-Command für den Bedarfsfall (Schlüsselkompromittierung/Compliance), Ausführung im Wartungsfenster = Soll. Periodische Rotation = Betreiber-/Compliance-Empfehlung. Gleitende, unterbrechungsfreie Rotation mit Key-ID = Spätere Version (siehe Kapitel 1.4) |
| 165 | Serialisierung von Lifecycle-Operationen | Installation, Aktivierung, Deaktivierung, Update und Löschung (Core/Module) laufen nie nebenläufig; exklusiver Lifecycle-Lock pro Plattforminstanz. Konkurrierende Operation wird mit klarem Hinweis abgewiesen; Lock wird bei Abbruch/Fehler kontrolliert freigegeben. Reguläre fachliche Modulnutzung unbetroffen. Höchstens eine lifecycle-verändernde Operation gleichzeitig (konsistent mit 28.13, siehe Kapitel 24.18) |
| 166 | Trust-Modell: kuratierter Marktplatz mit Härtungspfad (Hybrid) | Standardbetrieb lässt nur kuratierte, geprüfte Marktplatz-Module oder betreiber-verantwortete signierte Module zu; Herausgeberzertifikate nur an geprüfte Herausgeber. Kuratierung rechtfertigt die In-Process-Ausführung (keine Sandbox, Kapitel 23.16). Betreiber können ungeprüfte Module bewusst auf eigenes Risiko zulassen, mit empfohlenen Härtungsmaßnahmen (Code-Review, Least-Privilege-DB-Account, OS-Ressourcenlimits) und sichtbarer Kennzeichnung. In-Process-Module sind nicht voneinander isolierbar; echte Isolation erfordert Out-of-Process-Ausführung = Spätere Version (siehe Kapitel 23.9.3) |
| 167 | Einheitliches Capability-Modell, vier Contract-Typen | Resolver/Collector/Event und öffentliche Modul-Interfaces sind Ausprägungen eines einzigen Contract-Begriffs. Modell = Richtung (wer stellt bereit) × Kardinalität. Vierter Typ Request/Response (Service): Owner-Modul stellt bereit, andere konsumieren (Rückgabewert); öffentliche Modul-Interfaces (Kap. 29) sind dessen Integrations-Anwendung. Gemeinsame Registry (26.12), Versionierung (26.6) und Manifestfelder (contracts_provided/used); public_interfaces_* entfällt. Entscheidungen 110 und 144 entsprechend aktualisiert (siehe Kapitel 26.3.4) |
| 168 | Events asynchron über transaktionalen Outbox | Events werden nicht synchron zugestellt, sondern über einen transaktionalen Outbox (Event-Datensatz in derselben DB-Transaktion wie die fachliche Änderung) und einen Worker (CLI/Cron) asynchron verarbeitet. Mindestens-einmal-Zustellung, Listener müssen idempotent sein. Listener-Fehler sind strukturell vom Auslöser entkoppelt; Retry mit Backoff, danach sichtbarer Fehler-/Dead-Letter-Zustand. Synchrone Reaktion im Request → Resolver/Service, nicht Event (siehe Kapitel 26.9.2) |
| 169 | Observability als Core-Funktion | Der Core stellt einen Health-Endpoint (HTTP GET /health, Muss) bereit: minimaler öffentlicher Liveness + token-/authgeschützter Detailstatus (DB, Storage, Worker-Aktualität inkl. Outbox, Registry/Modulzustand, Dead-Letter, Lizenz). Modul-Health über Health-Collector-Contract aggregiert (z.B. Ticketing-Mailbox). Strukturierte Logs (Soll) und Admin-Statusfläche (Soll). Externes Alerting/Dashboards bleiben Betreibersache. 20.2 von "keine Monitoring-Endpunkte" auf Core-Funktion umgestellt (siehe Kapitel 20.2) |
| 170 | Core-Administrationsbereiche (scoped admin) | Core-Administration in eine feste Menge von Administrationsbereichen gegliedert (Benutzer-/Gruppenverwaltung, Modul-/Lifecycle, Marketplace/Lizenz, Registry/Contracts, Update-Manager, Core-Konfiguration, Sprachverwaltung). Volladministrator = alle Bereiche; delegierter Administrator = Teilmenge. Bereichs-/rollenbasiert, kein BREAD, keine Gruppen; innerhalb eines Bereichs voller Zugriff. Auditierbar (siehe Kapitel 27.3.1) |
| 171 | Pluggable Authentifizierung (Resolver-Slot) | Authentifizierungsmethode über Resolver-Slot austauschbar; Default = lokale Passwort-Authentifizierung. Extension-Modul kann OIDC/SAML-Provider registrieren (genau ein aktiver Provider, Resolver-Regeln Kap. 26.7). Benutzer bleiben Core-Identitäten (JIT-Provisioning/Verknüpfung möglich); Autorisierung unabhängig von der Authentifizierungsmethode (siehe Kapitel 27.2.2) |
| 172 | Ausschlüsse über Ressourcen-Schnitt | Da das BREAD-Modell keine Deny-Regeln kennt, werden Ausschlüsse nicht über Entzug, sondern durch Modellierung gelöst: sensible Teilmengen werden als eigene Ressource (z.B. eigene Queue) geführt und nur berechtigten Gruppen zugeordnet. Additive Aggregation (25.6) bleibt unverändert (siehe Kapitel 25.6.3) |
| 173 | Datenbank: PostgreSQL | Die Plattform verwendet PostgreSQL (statt MySQL/InnoDB). Vorteile: transaktionales DDL (atomare Migrationen, Entscheidung 155), JSONB, deklarative Partitionierung (Audit-Log) und Row-Level-Security (Option für künftiges Row-Scoping, vgl. 25.6.3). Backup via pg_dump / PITR. CakePHP-ORM ist DB-agnostisch; betroffene Doku-Stellen (1.1, 1.3, 20.1, 20.2, 20.7, 24.13, 28.2/28.18) angepasst (siehe Kapitel 1.3) |
| 174 | Constraint-First / DB-Integrität | Integritätsregeln werden in der Datenbank durchgesetzt (Defense-in-Depth): Fremdschlüssel, partielle Unique-Constraints für "genau ein aktiver X" (u.a. Resolver-Slot-Exklusivität 26.7), Check- und Exclusion-Constraints (Überlappungsfreiheit). Anwendungslogik ergänzt, ersetzt nicht (siehe Kapitel 30.1/30.2) |
| 175 | Row-Level Security verpflichtend | Gruppen-/bereichs-scoped Modultabellen müssen RLS-Policies führen (ENABLE/FORCE RLS) als verpflichtendes Defense-in-Depth-Netz unter BREAD. Zugriffskontext pro Transaktion via SET LOCAL (pooling-kompatibel); Policies Teil der Modulmigrationen; definierte BYPASSRLS-Pfade für Wartung/Jobs/DSGVO. RLS = designierter Mechanismus für künftiges Row-Scoping/Mandantenfähigkeit, kein BREAD-Ersatz (siehe Kapitel 30.3) |
| 176 | JSONB für semi-strukturierte Daten | Schemaschwache Daten in JSONB mit GIN-Indizes: Audit-Payloads, Outbox-Event-Payloads, Contract-/Registry-/Manifest-Metadaten, Konfigurationsspeicher. Relationale Fachdaten bleiben normalisiert (siehe Kapitel 30.5) |
| 177 | Outbox mit LISTEN/NOTIFY | Der transaktionale Event-Outbox (26.9.2) wird um PostgreSQL LISTEN/NOTIFY für latenzarme Zustellung ergänzt; Cron-Lauf bleibt Fallback. Zustellgarantie (mindestens einmal, idempotent) unverändert (siehe Kapitel 30.6) |
| 178 | Lifecycle-Lock via Advisory Lock | Der exklusive Lifecycle-Lock (24.18) wird als PostgreSQL-Advisory-Lock realisiert und wirkt knotenübergreifend (mehrknotenfähig), nicht nur prozesslokal (siehe Kapitel 30.7) |
| 179 | Partitionierung großer Tabellen | Kontinuierlich wachsende Tabellen (Audit-Log 20.6, Event-Outbox) werden über deklarative Zeitbereichs-Partitionierung beherrscht; alte Partitionen archivierbar/abtrennbar (siehe Kapitel 30.8) |
| 180 | Deployment-/Distributionsmodell | Der Core wird als eigenständiges Container-Image (PHP-FPM + Core) ausgeliefert; PostgreSQL ist kein Bestandteil des Images, sondern eigener Dienst (Container/Managed). Sofort-Start über Compose (Core/Web/DB/Worker/Mail, "clone & up"). All-in-One-Image nur für Demo/Eval, nicht produktionstauglich. Konsistent mit Update-Scope (28.2), Backup/PITR (20.1) und HA/Advisory-Lock (30.7) (siehe Kapitel 20.8) |

# Modul-Anforderungsdokument: Ticketing

Version 6.5

Stand: 15. Juni 2026

Status: In Überarbeitung

# 1. Einleitung

## 1.1 Zweck des Dokuments

Dieses Dokument beschreibt die funktionalen und nicht-funktionalen
Anforderungen an das Ticketing-Main-Modul (nachfolgend "das Modul"),
ein webbasiertes, E-Mail-zentriertes Ticketsystem für strukturierte
Serviceprozesse. Das Modul orientiert sich in seiner Funktionalität an
etablierten Lösungen wie Znuny/OTRS und deckt den Bereich
IT-Service-Management und E-Mail-basierte Anfragenbearbeitung ab.

Das Modul läuft auf der modularen Anwendungsplattform, deren
Architektur, Core-Funktionen, Berechtigungsmodell, Contract-System
und Update-Mechanismen im separaten Plattform-Anforderungsdokument
beschrieben sind.

Technologiebasis, Konfigurationsprinzip, Matrix-Konfiguration,
Datenintegrität, Anforderungsklassifikation und Architekturprinzipien
sind im Plattform-Anforderungsdokument (Kapitel 1) definiert und
gelten für dieses Modul verbindlich.

## 1.2 Zielgruppe

Dieses Dokument richtet sich an:

-   Auftraggeber und Stakeholder
-   Entwicklungsteam
-   Qualitätssicherung

## 1.3 Produktpositionierung und Leitprinzipien

### 1.3.1 Positionierung

Das System ist ein **selbst hostbares, E-Mail-zentriertes
Ticketsystem für strukturierte Serviceprozesse**. Es richtet sich an
Organisationen, die eingehende Anfragen professionell, nachvollziehbar
und SLA-gesteuert bearbeiten wollen, ohne auf eine externe
SaaS-Plattform angewiesen zu sein.

E-Mail ist der primäre Eingangskanal. Das System ist so konzipiert,
dass ein Ticket durch eine eingehende E-Mail entsteht und die gesamte
Kommunikation für den Anfragenden wie ein normaler E-Mail-Thread
aussieht. Die grafische Benutzeroberfläche (GUI) dient der internen
Bearbeitung und manuellen Ticketerstellung. Die REST-API ermöglicht
die Anbindung externer Systeme (z.B. Shopsysteme). GUI und API sind
gleichwertige Ergänzungen zum E-Mail-Kanal, nicht umgekehrt.

### 1.3.2 Leitprinzipien

Die folgenden Prinzipien gelten als verbindliche Leitlinien für
Architektur, Implementierung und Produktentscheidungen:

**E-Mail ist ein Erstkanal, kein nachträglicher Anbau.** Das gesamte
Threading-Modell, die Eintragsstruktur und die Benachrichtigungslogik
sind auf E-Mail als primären Kommunikationsweg ausgelegt. Jede
Funktion muss im E-Mail-Kontext sauber funktionieren.

**Öffentliche und interne Kommunikation bleiben strikt getrennt.**
Was der Anfragende (Gast, externer Empfänger, API-Konsument) sieht,
ist klar definiert und an keiner Stelle versehentlich erweiterbar.
Interne Einträge, Zuweisungen, Eskalationen und Metadaten sind nie
nach außen sichtbar.

**Konfiguration darf Historie nie zerstören.** Konfigurierbare Werte
werden deaktiviert, nie gelöscht. Historische Referenzen bleiben
intakt. Das Audit-Log ist unveränderlich. Jeder relevante Vorgang
ist nachvollziehbar.

**Rechteprüfung erfolgt immer serverseitig.** Queue-Zugriff,
Ticketberechtigung und API-Autorisierung werden auf Datenbank- und
Anwendungsebene durchgesetzt, nie nur im Frontend. Das Frontend zeigt
nur an, was der Benutzer auch tatsächlich darf.

**Externe Systeme sehen nie mehr als der Gast.** Die REST-API liefert
ausschließlich die Daten, die auch im Gastzugang sichtbar wären.
Kein API-Endpunkt gibt interne Informationen preis.

**Das System ist sofort nutzbar, nicht erst nach langer
Konfiguration.** Nach der Installation sind sinnvolle Standardwerte
aktiv (siehe Kapitel 1.4). Administratoren können das System
verfeinern, müssen es aber nicht erst grundlegend einrichten, bevor
der erste Ticket-Eingang funktioniert.

**Jede Aktion ist nachvollziehbar.** Statuswechsel, Queue-Wechsel,
Zuweisungen, Konfigurationsänderungen und Löschvorgänge werden im
Audit-Log oder in der Ticket-Timeline protokolliert. Es gibt keine
stillen Zustandsänderungen.

### 1.3.3 Bewusste Nicht-Ziele (v1)

Folgende Funktionen sind bewusst nicht Teil der ersten Version, um
den Produktkern nicht zu verwässern:

-   Kein Omnichannel (kein Chat, kein Telefon-Integration, kein
    Social-Media-Eingang)
-   Kein visueller Workflow-Designer oder Automationsengine
-   Keine KI-Funktionen (automatische Klassifizierung,
    Antwortvorschläge etc.)
-   Keine modul-eigene LDAP/SSO-Implementierung. Authentifizierung
    inkl. OIDC/SAML-SSO ist eine Plattformfähigkeit (austauschbarer
    Auth-Resolver, Default lokal; Plattform-Dokument, Kapitel 27.2.2) und
    wird vom Modul geerbt, nicht selbst definiert.
-   Kein Echtzeit-Collaboration (kein gleichzeitiges Bearbeiten
    desselben Eintrags)

Mandantenfähigkeit ist kein Nicht-Ziel mehr: Sie ist gemäß
Plattform-Entscheidung 185 verbindlich umgesetzt (siehe Kapitel 23
„Mandantenfähigkeit"). Queue-Gruppen bleiben Bereichstrennung innerhalb
eines Mandanten und sind orthogonal zur Mandantentrennung.

## 1.4 Standard-Konfiguration nach Installation

Nach der Erstinstallation und Ausführung des initialen Setup-Commands
(bin/cake create_admin) ist das System mit folgenden Standardwerten
betriebsbereit:

**Prioritäten:**

| Name | Farbe | Standard |
| --- | --- | --- |
| Niedrig | #28a745 (grün) | Nein |
| Normal | #007bff (blau) | Ja |
| Hoch | #fd7e14 (orange) | Nein |
| Kritisch | #dc3545 (rot) | Nein |

**Tickettypen:** Reklamation, Lieferproblem, Zahlungsproblem,
Stammdatenänderung (alle aktiv, änder- und erweiterbar)

**Abschlussgründe:** Kunde nicht erreichbar, Rückerstattung erfolgt,
Fehler nicht reproduzierbar, An Drittanbieter übergeben (alle aktiv)

**Eintragstypen (benutzerdefiniert):** Eingehender Anruf, Abgehender
Anruf, Interne Notiz (alle aktiv, erweiterbar)

**Benachrichtigungen:** Alle Benachrichtigungstypen für den
Administrator aktiviert, Versandmodus: sofort. Default-Templates in
Englisch und Deutsch vorausgefüllt.

**Sicherheit:**

| Einstellung | Default |
| --- | --- |
| Passwort-Mindestlänge | 8 Zeichen |
| Großbuchstaben erforderlich | Ja |
| Kleinbuchstaben erforderlich | Ja |
| Zahlen erforderlich | Ja |
| Sonderzeichen erforderlich | Ja |
| Session-Timeout | 30 Minuten |
| Gast-Session-Timeout | 15 Minuten |
| Einladungslink-Gültigkeit | 48 Stunden |
| Passwort-Reset-Token | 1 Stunde |
| Login-Fehlversuche bis Sperre | 5 |
| Login-Sperrdauer | 15 Minuten |

**Ticketnummern-Format:** TKT-{YYYY}-{NNNNNN} (z.B. TKT-2026-000001),
jährlicher Reset aktiv.

**Einträge pro Seite:** 25 (Systemdefault)

**SLA-Kalender:** Kein Kalender zugeordnet (24×7-Betrieb als
Standard). Kein SLA-Kalender vorkonfiguriert, da Geschäftszeiten
organisationsspezifisch sind.

**Eskalationsregeln:** Keine vorkonfiguriert (müssen pro Queue +
Priorität vom Administrator angelegt werden).

**Queues, Mailboxen, Benutzergruppen:** Keine vorkonfiguriert (die
erste Queue mit Mailbox-Anbindung wird vom Administrator eingerichtet).

Grundsatz: Das System ist nach Installation **administrativ sofort
startfähig**. Der Administrator kann sich anmelden, den Admin-Bereich
nutzen und Konfigurationen vornehmen. Für den **produktiven
E-Mail-Betrieb** sind mindestens eine Queue mit zugeordneter
Eingangs-Mailbox (für den Empfang eingehender E-Mails) und einer
Ausgangs-Mailbox (Pflicht pro Queue, für den E-Mail-Versand) zu
konfigurieren. Erst danach können eingehende E-Mails verarbeitet und
Tickets erstellt werden.

# 2. Rollen und Berechtigungen

Hinweis zur modularen Architektur: Der Core stellt Benutzerverwaltung,
Authentifizierung und Gruppenverwaltung bereit (Plattform-Dokument, Kapitel 23.3, detailliert
in Plattform-Dokument, Kapitel 27). Die in diesem Kapitel beschriebenen Rollen (Gast,
Benutzer, Administrator), Queue-Gruppen, Benutzergruppen und die daraus
resultierende Zugriffskontrolle sind Bestandteil des Ticketing-Main-
Moduls (Plattform-Dokument, Kapitel 23.15.1). Die Administrator-Rolle hat im Core
grundsätzlich Vollberechtigung (Plattform-Dokument, Kapitel 27.3.1). Das BREAD-Rechtesystem
(Plattform-Dokument, Kapitel 25) gilt für moduldefinierte Ressourcen.

## 2.1 Rollenübersicht

| **Rolle** | **Authentifizierung** | **Beschreibung** |
| --- | --- | --- |
| Gast | Ticketnummer + E-Mail + CAPTCHA (sofern Modul installiert) | Sieht alle eigenen Tickets in einer Übersichtsliste, kann Status und öffentliche Einträge einsehen und öffentliche Einträge hinzufügen (Eintragstyp: Gast-Kommentar). |
| Benutzer | Login (Benutzername + Passwort) | Sieht Tickets der Queues, die über seine Benutzergruppen erreichbar sind. Kann Tickets bearbeiten, Einträge hinzufügen und per E-Mail beantworten. Kann anderen Benutzern mit Zugriff auf die Queue Tickets zuweisen. |
| Administrator | Login (Benutzername + Passwort) | Zugriff auf Admin-Funktionen gemäß den im Core zugewiesenen Administrationsbereichen (Plattform-Dokument, Kapitel 27.3.1): ein Volladministrator hat Zugriff auf alle Admin-Funktionen (Systemkonfiguration, Mailboxen, Queue-Gruppen, Benutzerverwaltung, DSGVO etc.), ein delegierter Administrator nur auf die ihm zugewiesenen Bereiche. Kann sich zusätzlich Benutzergruppen zuordnen, um Tickets wie ein normaler Benutzer zu sehen und zu bearbeiten. |

## 2.2 Queue-Gruppen

Queues werden in Queue-Gruppen organisiert. Queue-Gruppen dienen der
logischen Bereichstrennung und steuern, zwischen welchen Queues Tickets
verschoben werden dürfen.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String (unique) | Eindeutiger Anzeigename der Queue-Gruppe |
| Beschreibung | Text (nullable) | Optionale Beschreibung |
| Aktiv | Boolean | Aktiviert/Deaktiviert (nie löschbar, siehe Plattform-Dokument, Kapitel 1.6) |

Regeln:

-   Jede Queue gehört zu genau einer Queue-Gruppe.

-   Ein Queue-Wechsel ist nur innerhalb derselben Queue-Gruppe zulässig.
    Das Queue-Wechsel-Dropdown zeigt ausschließlich Queues der gleichen
    Gruppe an.

-   Queue-Gruppen können geändert, aktiviert und deaktiviert werden,
    aber nie gelöscht.

-   Wird eine Queue-Gruppe deaktiviert, sind alle darin enthaltenen
    Queues ebenfalls nicht mehr aktiv.

## 2.3 Benutzergruppen

Hinweis zur Terminologie: "Benutzergruppen" in diesem Kapitel sind
dieselben Core-Objekte, die im Plattform-Dokument, Kapitel 27 als "Gruppen" definiert
werden. Im Kontext des Ticketing-Moduls wird der Begriff
"Benutzergruppe" verwendet, weil die Queue-Zuordnung die zentrale
Ressourcenzuordnung dieses Moduls darstellt (siehe Plattform-Dokument, Kapitel 25.5:
"Gruppenzuordnung ist generisch und nicht auf Queues beschränkt").

Benutzer werden nicht direkt auf Queues berechtigt, sondern indirekt
über Benutzergruppen. Eine Benutzergruppe bündelt den Zugriff auf eine
oder mehrere Queues, auch über verschiedene Queue-Gruppen hinweg.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String (unique) | Eindeutiger Anzeigename der Benutzergruppe |
| Beschreibung | Text (nullable) | Optionale Beschreibung |
| Zugeordnete Queues | m:n Relation | Queues, auf die Mitglieder dieser Gruppe Zugriff haben (auch aus verschiedenen Queue-Gruppen) |
| Aktiv | Boolean | Aktiviert/Deaktiviert (nie löschbar, siehe Plattform-Dokument, Kapitel 1.6) |

Regeln:

-   Ein Benutzer kann Mitglied mehrerer Benutzergruppen sein.

-   Der effektive Queue-Zugriff eines Benutzers ergibt sich aus der
    Vereinigung aller Queues seiner Benutzergruppen.

-   Benutzer dürfen nicht direkt auf Queues berechtigt werden --
    ausschließlich über Benutzergruppen.

-   Benutzergruppen können geändert, aktiviert und deaktiviert werden,
    aber nie gelöscht.

## 2.4 Queue-Zugriffskontrolle

Nach der Anmeldung sieht ein Benutzer ausschließlich Tickets der Queues,
die über seine Benutzergruppen erreichbar sind. Der Zugriff wird auf
Datenbankebene durchgesetzt. Die Berechtigungsprüfung erfolgt über:
Benutzer → Benutzergruppen → Queues. An allen Stellen, wo Benutzer einer
Queue referenziert werden (Ticketzuweisung, Benachrichtigungen,
automatische Zuweisung, \@Mention-Autovervollständigung,
Queue-Wechsel-Dialog), wird der effektive Queue-Zugriff über
Benutzergruppen aufgelöst.

### 2.4.1 Rechte-Granularität (BREAD und Zusatzaktionen)

Die queue-basierte Zugriffskontrolle ist die fachliche Ausprägung des
plattformweiten BREAD-Modells (Plattform-Dokument, Kapitel 25). Die
zentrale gruppenfähige Ressource des Ticketing-Moduls ist die Queue;
weitere gruppenfähige Ressourcen sind Tickets, gespeicherte Filter und
Reporting-Definitionen.

Standardprofil (v1): Eine Benutzergruppe mit Zugriff auf eine Queue
erhält das volle Agentenprofil auf deren Tickets (Browse, Read, Add,
Edit sowie die Zusatzaktionen assign, change_status, reply, merge). Die
Zusatzaktionen hard_delete und restore sowie die DSGVO-Funktionen
bleiben den entsprechenden Administrationsbereichen vorbehalten (siehe
Kapitel 2.1).

Feinere Stufen (vorgesehen): Da BREAD-Rechte und Zusatzaktionen
gruppenbezogen vergeben werden, lassen sich differenziertere Rollen
abbilden, ohne das additive Aggregationsmodell zu verändern – z.B. reine
Lese-Gruppen (Browse/Read) oder Agenten ohne merge/close. Diese feinere
Vergabe ist eine eigene Ausbaustufe; das Standardprofil bleibt der
Default. Es gelten ausschließlich additive Rechte ohne Deny-Regeln;
Ausschlüsse werden über Ressourcen-Schnitt modelliert (Plattform-Dokument,
Kapitel 25.6.3), z.B. sensible Tickets in einer eigenen Queue, die nur
berechtigten Gruppen zugeordnet ist.

## 2.5 Administrator-Dashboard-Verhalten

Administratoren haben Zugriff auf die Admin-Funktionen ihrer
zugewiesenen Administrationsbereiche (Plattform-Dokument, Kapitel
27.3.1; ein Volladministrator auf alle). Für das Dashboard gilt:

-   Ist ein Administrator mindestens einer Benutzergruppe zugeordnet,
    sieht er im Dashboard die Ticket-Listen und Statistiken der Queues
    seiner Gruppen -- wie ein normaler Benutzer. Er kann Tickets
    bearbeiten, Einträge hinzufügen und zuweisen.

-   Ist ein Administrator keiner Benutzergruppe zugeordnet, sieht er im
    Dashboard keine Ticket-Listen und keine Statistiken. Er hat nur
    Zugriff auf die Admin-Funktionen.

-   Administratoren können sich selbst Benutzergruppen zuordnen und
    wieder entfernen.

## 2.6 Ticketzuweisung

Ein Benutzer darf ein Ticket einem anderen Benutzer zuweisen, sofern der
Zielbenutzer über mindestens eine seiner Benutzergruppen Zugriff auf die
aktuelle Queue des Tickets hat. Bei der Zuweisung wechselt der Status
automatisch auf "zugewiesen". Ein Pflichtkommentar ist erforderlich.

## 2.7 Benutzererstellung per Einladung

Benutzer können ausschließlich durch Administratoren per Einladung
erstellt werden. Es gibt kein öffentliches Registrierungsformular und
keinen Self-Service zur Account-Erstellung. Der Einladungsprozess läuft
wie folgt ab:

1.  Der Administrator gibt die E-Mail-Adresse des zukünftigen Benutzers
    ein. Das System prüft die Eindeutigkeit der E-Mail-Adresse (eine
    E-Mail darf systemweit nur einmal existieren).

2.  Ein leerer Account mit der E-Mail-Adresse wird angelegt (Status:
    "eingeladen"). Der Account hat noch keinen Benutzernamen und kein
    Passwort.

3.  Eine Einladungs-E-Mail mit einem zeitlich begrenzten
    Registrierungslink wird über die System-Mailbox versendet. Die
    Gültigkeitsdauer des Links ist konfigurierbar (Systemeinstellung,
    Default: 48 Stunden).

4.  Der eingeladene Benutzer klickt auf den Link und gelangt zu einer
    Profilseite, auf der er folgende Daten angibt: Vorname, Nachname,
    Benutzername und Passwort (gemäß der konfigurierten
    Passwort-Policy).

5.  Nach dem Ausfüllen wird der Account aktiviert (Status: "aktiv").
    Eine weitere Bestätigung per E-Mail entfällt.

Der Administrator kann dem Benutzer bereits bei der Einladung eine Rolle
(Benutzer/Administrator) und Benutzergruppen-Zuordnungen zuweisen. Diese
werden beim leeren Account hinterlegt und gelten sofort nach
Aktivierung.

### 2.7.1 Einladungs-Dashboard

Im Admin-Bereich steht ein Einladungs-Dashboard zur Verfügung, das alle
Einladungen mit ihrem Status anzeigt:

| **Status** | **Beschreibung** | **Verfügbare Aktionen** |
| --- | --- | --- |
| Ausstehend | Einladung versendet, Benutzer hat Profil noch nicht ausgefüllt | Widerrufen (löscht temporären Account), Erneut versenden (neuer Link, Timer zurückgesetzt) |
| Abgelaufen | Gültigkeitsdauer des Links überschritten | Erneut versenden (neuer Link), Widerrufen |
| Abgeschlossen | Benutzer hat Profil ausgefüllt, Account ist aktiv | Aus Einladungsliste entfernen (Account bleibt bestehen) |
| Widerrufen | Einladung wurde vom Admin zurückgezogen | Aus Einladungsliste entfernen |

Sichtbare Informationen pro Einladung: E-Mail-Adresse, Einladungsdatum,
Status, Ablaufdatum des Links, Datum der Aktivierung (bei
abgeschlossenen Einladungen).

## 2.8 Benutzer-Lebenszyklus

Benutzer können nie gelöscht werden, nur deaktiviert:

Ausnahme: Noch nicht aktivierte Einladungs-Accounts (Status
"eingeladen") dürfen beim Widerruf der Einladung vollständig gelöscht
werden, da sie noch keine Referenzen im System besitzen (keine
Einträge, Zuweisungen oder Timeline-Einträge). Diese Ausnahme gilt
ausschließlich für Accounts, bei denen der eingeladene Benutzer sein
Profil noch nicht ausgefüllt hat.

-   Deaktivierte Benutzer können sich nicht mehr anmelden.

-   Deaktivierte Benutzer bleiben in der Datenbank, um die
    Referenzintegrität zu gewährleisten (Einträge, Zuweisungshistorie,
    Timeline-Einträge, Audit-Log).

-   In Dropdowns und Auswahllisten werden deaktivierte Benutzer nicht
    angezeigt.

-   In der Tickethistorie und bei bestehenden Zuweisungen bleibt der
    Name des deaktivierten Benutzers sichtbar (z.B. "Max Mustermann
    (deaktiviert)").

-   Administratoren können deaktivierte Benutzer jederzeit reaktivieren.

-   Wird ein deaktivierter Benutzer reaktiviert, behält er alle
    bisherigen Benutzergruppen-Zuordnungen und Einstellungen.

# 3. Mailbox-Management

## 3.1 Mailbox-Typen

| **Typ** | **Zweck** | **Anzahl** |
| --- | --- | --- |
| Queue-Mailbox | Wird Queues als Eingangs-Mailbox (E-Mail-Empfang, max. 1 pro Queue, optional) und/oder als Ausgangs-Mailbox (E-Mail-Versand, Pflicht pro Queue, mehrfach nutzbar) zugeordnet. Eine Mailbox kann für eine Queue als Eingang und gleichzeitig für dieselbe oder andere Queues als Ausgang dienen. Siehe Kapitel 4.1 für Details. | Mehrere |
| System-Mailbox | Versand von Systembenachrichtigungen (neue Tickets, Zuweisungen, Eskalationen etc.). Wird global konfiguriert. Wird nicht für den Empfang eingehender E-Mails genutzt. | Maximal 1 |

## 3.2 Konfigurationsparameter pro Mailbox

| **Parameter** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String | Anzeigename der Mailbox |
| E-Mail-Adresse | String | Absender-/Empfängeradresse |
| IMAP-Host | String | Hostname des IMAP-Servers |
| IMAP-Port | Integer | Standard: 993 |
| IMAP-Verschlüsselung | Enum | SSL / TLS / Keine |
| IMAP-Benutzername | String | Login für IMAP |
| IMAP-Passwort | String (AES-256-GCM) | Passwort für IMAP (verschlüsselt in DB, nie im Klartext anzeigbar) |
| SMTP-Host | String | Hostname des SMTP-Servers |
| SMTP-Port | Integer | Standard: 587 |
| SMTP-Verschlüsselung | Enum | TLS / SSL / STARTTLS / Keine |
| SMTP-Benutzername | String | Login für SMTP |
| SMTP-Passwort | String (AES-256-GCM) | Passwort für SMTP (verschlüsselt in DB, nie im Klartext anzeigbar) |
| Typ | Enum | queue / system |
| Aktiv | Boolean | Mailbox aktiviert/deaktiviert |

Hinweis: Passwortfelder werden beim Bearbeiten einer Mailbox immer leer
angezeigt. Ein Hinweis "Leer lassen, um das aktuelle Passwort
beizubehalten" wird angezeigt. Ein visueller Indikator zeigt an, ob ein
Passwort hinterlegt ist (grünes Häkchen) oder nicht (rotes Warnsymbol).
Die Verschlüsselung erfolgt mit AES-256-GCM unter Verwendung eines
dedizierten Encryption-Keys aus config/app.php.

## 3.3 Mail-Abruf

Der Abruf eingehender E-Mails erfolgt per CakePHP CLI-Command (bin/cake
fetch_mails), welches über einen Cronjob in konfigurierbaren Intervallen
ausgeführt wird. Das Intervall ist pro Queue im Admin-Interface
einstellbar.

## 3.4 E-Mail-Klassifizierung

Eingehende E-Mails werden vor der Verarbeitung klassifiziert. Nur
reguläre E-Mails (inkl. Antworten) werden verarbeitet. Folgende Typen
werden erkannt und verworfen:

| **Typ** | **Erkennungsmerkmale** | **Aktion** |
| --- | --- | --- |
| Out-of-Office / Auto-Replies | Header: Auto-Submitted: auto-replied, X-Auto-Response-Suppress: OOF/All, Precedence: bulk/junk/auto_reply, Return-Path: \<\>, X-MS-Exchange-Organization-AutoReply: true | Verwerfen + Logging |
| Kalendereinladungen | Content-Type: text/calendar oder application/ics, Attachments mit .ics-Endung, method=REQUEST/REPLY/CANCEL | Verwerfen + Logging |
| Delivery Status Notifications | Content-Type: multipart/report mit report-type=delivery-status | Verwerfen + Logging |
| Reguläre E-Mails | Kein Auto-Submitted Header oder Wert "no", Standard Content-Types | Verarbeiten |

Verworfene E-Mails werden im Logfile protokolliert, damit der Admin
nachvollziehen kann, was gefiltert wurde.

## 3.5 Spam- und Duplikat-Schutz

Das System bietet folgenden Schutz gegen Spam und Duplikate:

-   Duplikat-Erkennung: Eingehende E-Mails werden anhand ihrer
    Message-ID geprüft. Bereits verarbeitete Message-IDs werden nicht
    erneut verarbeitet.

-   Absender-Blacklist: Im Admin-Bereich können einzelne E-Mail-Adressen
    und Domains gesperrt werden. E-Mails von geblockten Absendern werden
    verworfen.

-   Spam-Aktion im Ticket: Benutzer können ein Ticket als Spam
    markieren. Dies bewirkt gleichzeitig: sofortiger Statuswechsel zu
    "abgebrochen" (Sonder-Transition aus jedem Status),
    Absender-E-Mail wird automatisch auf die Blacklist gesetzt,
    Pflichtkommentar wird mit "Marked as spam" vorausgefüllt
    (editierbar).

-   Rate-Limiting: Global konfigurierbar (Admin-GUI). Maximal X
    Autoantworten pro Absenderadresse innerhalb von Y Minuten.

## 3.6 E-Mail-Threading (Zuordnung zu bestehenden Tickets)

Eingehende E-Mails werden nach folgendem Verfahren bestehenden Tickets
zugeordnet:

1.  Zuerst werden die E-Mail-Header In-Reply-To und References geprüft.
    Findet sich eine gespeicherte Message-ID, wird die E-Mail dem
    entsprechenden Ticket zugeordnet.

2.  Falls kein Header-Match gefunden wird, wird der Betreff nach dem
    konfigurierten Ticketnummern-Muster durchsucht (Fallback).

3.  Wird kein bestehendes Ticket gefunden, wird ein neues Ticket
    erstellt.

## 3.7 Antwort auf geschlossenes Ticket

Wenn eine eingehende E-Mail einem bereits geschlossenen oder
abgebrochenen Ticket zugeordnet wird, geschieht Folgendes:

-   Das Ticket wird automatisch wiedereröffnet.

-   Der Status wird auf "pausiert" gesetzt.

-   Der letzte Bearbeiter bleibt zugewiesen.

-   Die SLA-Zeiten laufen weiter (keine Neuberechnung). Die bisherige
    Pausenzeit bleibt bestehen.

-   Die E-Mail wird als neuer Eintrag (Eintragstyp: Eingehende E-Mail)
    angehängt.

-   Eine Benachrichtigung wird an den zugewiesenen Benutzer gesendet.

Hinweis: Dies ist eine Sonder-Transition
(closed_success/closed_failure/cancelled → paused), die ausschließlich
vom System bei eingehender E-Mail ausgelöst wird -- nicht manuell über
die UI.

## 3.8 Original-E-Mail-Speicherung

Bei eingehenden E-Mails wird zusätzlich zum extrahierten Eintragstext
die vollständige Original-E-Mail (RFC 822 Quelltext) gespeichert. Ebenso
wird bei jeder ausgehenden E-Mail aus einem Ticket der vollständige
Quelltext gespeichert. Dies ermöglicht:

-   Korrekte E-Mail-Thread-Bildung: Bei einer Antwort aus dem Ticket
    heraus wird die Antwort auf die letzte E-Mail (ein- oder ausgehend)
    aufgesetzt. Die Header In-Reply-To, References und Message-ID-Kette
    werden korrekt gesetzt.

-   Zitierter Text: Im Antwort-Dialog wird der Inhalt der letzten E-Mail
    als editierbares Zitat angezeigt. Der Benutzer kann das Zitat vor
    dem Absenden kürzen oder bearbeiten.

-   Linearer Thread: Die Antwort setzt immer auf die letzte E-Mail auf
    (egal ob ein- oder ausgehend). Dadurch bleibt die Konversation ein
    linearer Thread ohne Verzweigungen.

-   Empfänger-Perspektive: Der Empfänger sieht die Antwort als normalen
    E-Mail-Thread, als hätte man aus einem E-Mail-Programm geantwortet.

## 3.9 Inline-Bilder in E-Mails

Eingebettete Bilder in E-Mails (Content-ID-Referenzen im HTML-Body)
werden originalgetreu behandelt:

-   Empfang: Inline-Bilder werden auf dem Server gespeichert. Die
    CID-Referenzen im HTML-Inhalt werden durch lokale URLs ersetzt,
    sodass die Bilder im Ticket korrekt angezeigt werden.

-   Versand: Beim Antworten aus dem Ticket werden Bilder wieder als CID
    eingebettet, sodass der Empfänger sie im E-Mail-Body sieht.

-   Inline-Bilder werden nicht zusätzlich als separate Anhänge
    aufgeführt.

## 3.10 Ausgehende E-Mails

Antwortet ein Benutzer aus einem Ticket heraus per E-Mail, so wird die
Mailbox der Queue verwendet, der das Ticket aktuell zugeordnet ist. Die
E-Mail enthält die konfigurierte Signatur des antwortenden Benutzers
(sofern vorhanden). Die Ticketnummer wird im Betreff mitgeführt. Die
Antwort setzt auf die letzte E-Mail im Thread auf (siehe Kapitel 3.8).

## 3.11 E-Mail-Versand-Queue und Fehlerbehandlung

Alle ausgehenden E-Mails (Ticket-Antworten, Autoantworten,
Benachrichtigungen) werden über eine Versand-Queue verarbeitet. Die
email_queue ist eine Spezialisierung des Plattform-Jobsystems
(Plattform-Dokument, Kapitel 26.9.2) für die SMTP-spezifische Zustell-
und Retry-Semantik, keine davon unabhängige Parallelmechanik. Retry- und
Dead-Letter-Verhalten folgen dem Outbox-Modell der Plattform; endgültig
fehlgeschlagene E-Mails werden als Dead-Letter auch in der
Plattform-Statusfläche sichtbar (Plattform-Dokument, Kapitel 20.2.4). Im
Einzelnen:

-   Jede ausgehende E-Mail wird in einer Datenbanktabelle (email_queue)
    mit Zustellstatus erfasst: ausstehend, zugestellt, fehlgeschlagen.

-   Bei fehlgeschlagenem Versand wird automatisch ein erneuter Versuch
    unternommen. Anzahl der Retries und Intervall sind global im
    Admin-Bereich konfigurierbar.

-   Der Zustellstatus ist im Ticket sichtbar (z.B. grünes Häkchen für
    zugestellt, rotes Icon für fehlgeschlagen).

-   Benutzer können bei fehlgeschlagenen E-Mails manuell einen erneuten
    Versand per Klick auslösen.

-   Nach endgültigem Fehlschlag (alle Retries aufgebraucht) wird der
    Administrator per Systembenachrichtigung informiert.

## 3.12 System-E-Mails

Für Systembenachrichtigungen wird eine separate, global konfigurierte
System-Mailbox verwendet. Über diese Mailbox werden ausschließlich
ausgehende Benachrichtigungen versendet -- sie wird nicht für den
Empfang eingehender E-Mails genutzt.

## 3.13 Verbindungstest

Im Admin-Interface soll für jede Mailbox ein Verbindungstest (IMAP und
SMTP) per Klick möglich sein, um die Konfiguration zu verifizieren.

## 3.14 REST-API für externe Ticket-Erstellung und Statusabfrage

Das System stellt eine optionale REST-API zur Verfügung, über die
externe Systeme, insbesondere Shopsysteme, Tickets erstellen sowie den
Status und öffentliche Einträge bestehender Tickets abrufen können. Die
REST-API ist bewusst funktional begrenzt und dient ausschließlich der
technischen Anbindung externer Systeme. Sie bildet keinen vollwertigen
alternativen Bedienkanal zum Webinterface.

### 3.14.1 Anwendungszweck

Die REST-API ermöglicht insbesondere folgende Anwendungsfälle:

-   Automatische Erstellung eines Tickets aus einem externen System,
    z.B. bei einer Bestellung, Reklamation oder Rückfrage

-   Abruf des aktuellen Ticketstatus durch das externe System

-   Abruf der öffentlichen Einträge eines Tickets zur Anzeige im
    externen System

-   Lookup eines Tickets anhand einer Kundenreferenz

Die weitere Bearbeitung des Tickets erfolgt anschließend regulär über
die bestehenden Systemmechanismen. Insbesondere kann ein Bearbeiter über
das Ticketsystem öffentliche Rückfragen an den Kunden stellen. Diese
öffentlichen Einträge können durch das externe System über die REST-API
abgerufen und angezeigt werden.

### 3.14.2 Authentifizierung

Der Zugriff auf die REST-API erfolgt über einen API-Token, der im
Admin-Bereich generiert und verwaltet wird. Jeder Token ist an genau
eine Queue gebunden. Bei der Ticketerstellung per API wird automatisch
die Queue des Tokens verwendet -- eine explizite Queue-Angabe ist nicht
erforderlich.

Abgrenzung zum Plattform-API-Modell: Dieser queue-gebundene API-Token
ist bewusst ein eigenes, gast-äquivalentes Zugangsschema für externe
Systeme (Sichtbarkeit ausschließlich auf Gast-Level, siehe Kapitel
3.14.4) und kein benutzergebundener Plattform-API-Zugang. Er ist nicht
an eine Core-Identität gebunden und trägt nicht die BREAD-Rechte eines
Benutzers (Plattform-Dokument, Kapitel 27.16.3). Eine interne,
vollwertige API-Nutzung erfolgt – sofern vorgesehen – über
benutzergebundene Plattform-Token; die externe Ticket-API bleibt auf
Erstellung und lesenden Gast-Level-Abruf beschränkt.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String | Frei vergebbarer Anzeigename des Tokens |
| Token | String | Technischer Zugriffsschlüssel, nur bei Erstellung vollständig sichtbar |
| Queue | FK Queue | Die dem Token zugeordnete Queue (Pflichtfeld) |
| Ablaufdatum | DateTime (nullable) | Optionales Ablaufdatum des Tokens |
| Rate-Limit | Integer | Maximal erlaubte Requests pro Minute für diesen Token |
| Aktiv | Boolean | Token aktiviert/deaktiviert |
| Erstellt am | DateTime | Zeitpunkt der Erstellung |
| Letzter Zugriff | DateTime (nullable) | Zeitpunkt des letzten erfolgreichen API-Zugriffs |

Verhalten:

-   API-Tokens werden ausschließlich durch Administratoren im
    Admin-Bereich verwaltet.

-   Ein Token kann optional mit einem Ablaufdatum versehen werden.

-   Abgelaufene oder deaktivierte Tokens dürfen keine API-Zugriffe mehr
    autorisieren.

-   Der Token ist bei API-Zugriffen verpflichtend zu übermitteln.

-   Der letzte erfolgreiche Zugriff auf einen Token wird protokolliert.

-   Der vollständige Token-Wert ist aus Sicherheitsgründen nur direkt
    bei Erstellung vollständig sichtbar. Danach darf er nicht erneut im
    Klartext angezeigt werden.

-   API-Zugriffe werden im Audit-Log protokolliert (gleichermaßen wie
    GUI-Zugriffe).

-   Das Rate-Limit wird pro Token konfiguriert (Requests pro Minute).
    Bei Überschreitung wird eine entsprechende Fehlermeldung mit
    HTTP-Status 429 zurückgegeben.

### 3.14.3 Zugriff auf bestehende Tickets

Der lesende Zugriff auf ein Ticket über die REST-API erfolgt nach
demselben fachlichen Prinzip wie der Gastzugang. Für den Abruf eines
Tickets sind erforderlich: ein gültiger API-Token, die Ticketnummer und
die E-Mail-Adresse des Requesters. Anstelle eines CAPTCHA erfolgt die
Authentifizierung über den API-Token.

Ein externer Zugriff ist nur zulässig, wenn:

-   der API-Token gültig ist (aktiv, nicht abgelaufen, Rate-Limit nicht
    überschritten)

-   die Ticketnummer existiert

-   die übermittelte E-Mail-Adresse mit der im Ticket hinterlegten
    Requester-E-Mail-Adresse exakt übereinstimmt

### 3.14.4 Sichtbarkeit der über die API gelieferten Daten

Die REST-API liefert ausschließlich die Daten, die auch im Gastzugang
sichtbar wären. Externe Systeme erhalten somit keine weitergehenden
Informationen als Gäste.

Über die REST-API sichtbar:

-   Ticketnummer, Betreff, aktueller Status, Erstellungsdatum, Priorität

-   Öffentliche Einträge (z.B. Gast-Kommentare, E-Mails, Abschlussmeldungen)

-   Öffentliche Anhänge

-   Kundenreferenz (sofern vorhanden)

-   Erstellkanal (email, api, manual)

Nicht über die REST-API sichtbar: interne Einträge, Queue-Zuordnung,
zugewiesener Benutzer, Eskalationsinformationen, interne Statushistorie,
interne Timeline-Einträge, interne E-Mail-Zustellinformationen und
sonstige rein interne Metadaten.

### 3.14.5 Ticket-Erstellung per REST-API

Externe Systeme können über die REST-API neue Tickets anlegen. Das
Ticket wird der Queue zugeordnet, die am API-Token konfiguriert ist. Bei
erfolgreicher Erstellung liefert die API mindestens die erzeugte
Ticketnummer zurück.

| **Parameter** | **Pflicht** | **Beschreibung** |
| --- | --- | --- |
| subject | Ja | Betreff des Tickets |
| body | Ja | Beschreibung / initialer Eintrag |
| requester_email | Ja | E-Mail-Adresse des Anfragenden (wird als Requester-E-Mail gespeichert) |
| requester_name | Nein | Name des Anfragenden |
| customer_reference | Nein | Kundenreferenz (z.B. Bestellnummer). Muss systemweit eindeutig sein |
| priority | Nein | Priorität (bei Weglassen wird Standard-Priorität verwendet) |
| ticket_type | Nein | Tickettyp (interner Schlüssel). Muss in der Queue des Tokens aktiv sein. Bei Weglassen bleibt das Feld leer |
| custom_fields | Nein | Objekt mit Schlüssel-Wert-Paaren für freie Felder (Schlüssel = interner Schlüssel des Feldes). Nur in der Queue aktive Felder werden übernommen. Unbekannte Schlüssel werden ignoriert. Pflichtfeld-Regeln (Kapitel 5.18) werden validiert |
| attachments | Nein | Array von Anhängen. Pro Anhang: filename (Pflicht), content (Base64-kodiert, Pflicht), content_type (MIME-Typ, Pflicht). Erlaubte Dateitypen und maximale Dateigröße gemäß Systemkonfiguration (Kapitel 6.4) |

Bei API-Erstellung gelten folgende Regeln:

-   Das Ticket wird fachlich wie ein regulär eingegangenes Ticket
    behandelt.

-   Es wird keine Autoantwort gesendet. Das externe System ist selbst
    für die Kundenkommunikation verantwortlich.

-   Sofern für die Queue konfiguriert, greift die automatische
    Zuweisung.

-   Regelbasierte Prioritätszuweisung wird angewendet, sofern keine
    explizite Priorität übergeben wurde.

-   SLA-Zeiten werden berechnet und gesetzt.

-   Der Erstellkanal wird auf "api" gesetzt.

### 3.14.6 Erstellkanal eines Tickets

Jedes Ticket enthält ein Feld zur Kennzeichnung des Erstellkanals:

| **Wert** | **Beschreibung** |
| --- | --- |
| email | Ticket wurde durch eingehende E-Mail erstellt |
| api | Ticket wurde über die REST-API erstellt |
| manual | Ticket wurde manuell über die GUI erstellt |

Dieses Feld dient der Nachvollziehbarkeit, Auswertung und Filterung. Der
Erstellkanal ist in der Ticketliste als Filterspalte verfügbar und wird
in der Ticket-Timeline protokolliert.

### 3.14.7 Kundenreferenz

Jedes Ticket kann optional eine Kundenreferenz enthalten. Diese dient
der Zuordnung zu einem externen Geschäftsvorgang, z.B. einer
Bestellnummer oder Vorgangsnummer.

Eigenschaften der Kundenreferenz:

-   Die Kundenreferenz ist optional.

-   Sie kann manuell in der GUI gepflegt werden (bei Erstellung und
    nachträglich).

-   Sie kann bei Erstellung über die REST-API mitgegeben werden.

-   Sie kann bei bestehenden Tickets nachträglich geändert werden.

-   Die Kundenreferenz ist systemweit eindeutig.

-   Die Eindeutigkeit ist sowohl auf Anwendungsebene als auch auf
    Datenbankebene sicherzustellen (Unique Index).

-   Vor dem Speichern (GUI oder API) wird geprüft, ob die gewünschte
    Kundenreferenz bereits in einem anderen Ticket verwendet wird.

### 3.14.8 Verhalten bei doppelter Kundenreferenz

Verhalten in der REST-API:

-   Wird bei API-Ticketerstellung eine Kundenreferenz übermittelt, die
    bereits in einem bestehenden Ticket verwendet wird, wird kein neues
    Ticket erstellt.

-   Die API liefert eine strukturierte Fehlermeldung: maschinenlesbarer
    Fehlercode (customer_reference_exists), sprechender Fehlertext und
    die Ticketnummer des bereits vorhandenen Tickets.

Verhalten in der GUI:

-   Wird bei manueller Ticketerstellung oder bei nachträglicher
    Bearbeitung eines Tickets eine Kundenreferenz eingegeben, die
    bereits in einem anderen Ticket vorhanden ist, wird kein
    Speichervorgang durchgeführt.

-   Es wird ein deutlicher Hinweis angezeigt, dass bereits ein Ticket
    mit dieser Kundenreferenz existiert.

-   Der Hinweis enthält mindestens die Ticketnummer des bestehenden
    Tickets.

-   Der Benutzer kann die Eingabe korrigieren oder das bereits
    vorhandene Ticket direkt öffnen.

### 3.14.9 Lookup per Kundenreferenz

Zusätzlich zum Abruf über Ticketnummer kann die REST-API einen Lookup
per Kundenreferenz bereitstellen. Voraussetzungen: gültiger API-Token,
vorhandene Kundenreferenz und Übereinstimmung der übermittelten
E-Mail-Adresse des Requesters mit der im Ticket hinterlegten
Requester-E-Mail-Adresse. Wird ein passendes Ticket gefunden, liefert
die API die zugehörige Ticketnummer zurück.

Fehlerfälle:

-   Kundenreferenz nicht übergeben: Fehlercode
    "customer_reference_missing", HTTP 400.

-   E-Mail-Adresse nicht übergeben: Fehlercode "email_missing",
    HTTP 400.

-   Kundenreferenz existiert nicht oder E-Mail stimmt nicht überein:
    Fehlercode "customer_reference_not_found", HTTP 404. Aus
    Sicherheitsgründen wird bei E-Mail-Mismatch dieselbe Antwort
    geliefert wie bei nicht existierender Kundenreferenz, um nicht
    offenzulegen, ob eine Kundenreferenz im System vorhanden ist.

### 3.14.10 Antwortformat der REST-API

Die REST-API liefert Antworten in strukturierter Form (JSON). Erfolgs-
und Fehlerantworten müssen so aufgebaut sein, dass externe Systeme sie
zuverlässig automatisiert verarbeiten können. Erfolgsantworten enthalten
die für den jeweiligen Vorgang relevanten Nutzdaten. Fehlerantworten
enthalten mindestens einen Fehlercode und einen Fehlertext. Bei
fachlichen Konflikten (z.B. doppelte Kundenreferenz) wird soweit
sinnvoll die bereits vorhandene Ticketnummer mitgeliefert.

### 3.14.11 Admin-Bereich für REST-API

Im Admin-Bereich wird ein eigener Verwaltungsbereich für API-Tokens
bereitgestellt. Administratoren können dort:

-   Neue Tokens erzeugen (mit Queue-Zuordnung, optionalem Ablaufdatum
    und Rate-Limit)

-   Bestehende Tokens deaktivieren

-   Optionale Ablaufdaten und Rate-Limits setzen oder ändern

-   Token-Metadaten einsehen (Name, Queue, Erstelldatum, letzter
    Zugriff)

-   Den letzten erfolgreichen Zugriff je Token einsehen

### 3.14.12 Nicht-Ziel der REST-API

Die REST-API dient nicht zur vollständigen Abbildung der internen
Benutzeroberfläche. Insbesondere sind über die REST-API nicht
vorgesehen: Zugriff auf interne Einträge, Statuswechsel durch externe
Systeme, Queue-Wechsel, Benutzerzuweisungen, Zugriff auf interne
Admin-Funktionen und erweiterte Schreibzugriffe jenseits der
Ticketerstellung. Die REST-API ist bewusst auf die externe
Ticketerstellung sowie den lesenden Abruf der öffentlichen Ticketansicht
beschränkt.

# 4. Queues

## 4.1 Queue-Eigenschaften

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String | Eindeutiger Anzeigename |
| Beschreibung | Text | Optionale Beschreibung des Aufgabenbereichs |
| Queue-Gruppe | FK QueueGroup | Zugeordnete Queue-Gruppe (Pflicht). Steuert, zwischen welchen Queues Tickets verschoben werden dürfen |
| Eingangs-Mailbox | FK Mailbox (nullable) | Mailbox für den E-Mail-Empfang. Optional (nicht jede Queue muss E-Mails empfangen). Eine Mailbox darf nur einmal als Eingang zugeordnet werden |
| Ausgangs-Mailbox | FK Mailbox | Mailbox für den E-Mail-Versand (Pflicht). Eine Mailbox darf mehreren Queues als Ausgang zugeordnet werden |
| Autoantwort aktiv | Boolean | Automatische Antwort bei Ticketerstellung per E-Mail (nicht bei manueller Erstellung) |
| Autoantwort Betreff | String | Betreff der automatischen Antwort (mit Platzhaltern) |
| Autoantwort Text | Text | Inhalt der automatischen Antwort (mit Platzhaltern) |
| Pausenzeit von SLA abziehen | Boolean | Ob die Zeit im Status "pausiert" die SLA-Berechnung beeinflusst |
| SLA-Kalender | FK SlaCalendar (nullable) | Zugeordneter SLA-Kalender. NULL = 24×7-Betrieb (SLA läuft durchgehend). Siehe Kapitel 7.7. Diese Spalte wird durch die Migration des Extension-Moduls SLA-Kalender angelegt, nicht durch das Main-Modul Ticketing (siehe Plattform-Dokument, Kapitel 23.15.2). Ohne installiertes SLA-Kalender-Modul existiert diese Spalte nicht |
| Aufbewahrungsdauer | Integer (nullable) | Monate bis zur automatischen Löschung geschlossener Tickets. NULL = unbegrenzt |
| Aktiv | Boolean | Queue aktiviert/deaktiviert (nie löschbar, siehe Plattform-Dokument, Kapitel 1.6) |

Hinweis: Benutzer werden nicht direkt auf Queues berechtigt, sondern
indirekt über Benutzergruppen (siehe Kapitel 2.3). Die Zuordnung
entfällt daher als Queue-Eigenschaft.

## 4.2 Platzhalter für Autoantworten

Folgende Platzhalter stehen in Autoantworten zur Verfügung:

| **Platzhalter** | **Ersetzung** |
| --- | --- |
| {ticket_number} | Ticketnummer |
| {ticket_subject} | Betreff des Tickets |
| {requester_name} | Name des Anfragenden |
| {requester_email} | E-Mail-Adresse des Anfragenden |
| {queue_name} | Name der Queue |
| {priority} | Priorität des Tickets |
| {created_date} | Erstellungsdatum |
| {app_name} | Name der Anwendung |

## 4.3 Queue-Wechsel

Ein Ticket kann von einer Queue in eine andere Queue innerhalb derselben
Queue-Gruppe verschoben werden. Ein Queue-Wechsel über Queue-Gruppen
hinweg ist nicht zulässig. Der Queue-Wechsel-Dialog enthält:

-   Ziel-Queue (Pflichtfeld, nur Queues derselben Queue-Gruppe)

-   Begründung (Pflichttext)

-   Neuer Benutzer (optional, Dropdown mit Benutzern, die über ihre
    Benutzergruppen Zugriff auf die Ziel-Queue haben)

Verhaltensregeln:

-   Hat der aktuell zugewiesene Benutzer über seine Benutzergruppen
    Zugriff auf die Ziel-Queue, bleibt er vorausgewählt.

-   Hat er keinen Zugriff: Wird kein neuer Benutzer gewählt, wird die
    Zuweisung aufgehoben und der Status auf "neu" gesetzt. Wird ein
    Benutzer gewählt, wird der Status auf "zugewiesen" gesetzt.

-   Der Queue-Wechsel wird in einer eigenen Historientabelle
    protokolliert.

-   Nach dem Wechsel wird die Ausgangs-Mailbox der neuen Queue für den
    weiteren E-Mail-Versand verwendet.

-   Die SLA-Zeiten werden anhand der Eskalationsregeln der neuen Queue
    neu berechnet, ausgehend vom ursprünglichen Ticket-Erstellungsdatum
    (kein SLA-Reset durch Queue-Wechsel).

## 4.4 Vorgefertigte Antworten / Textbausteine

Pro Queue können vorgefertigte Antwort-Textbausteine konfiguriert
werden. Benutzer wählen beim Beantworten eines Tickets aus einem
Dropdown einen Textbaustein, dessen Inhalt in das Antwortfeld eingefügt
wird. Der Text kann vor dem Absenden bearbeitet werden.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String | Anzeigename im Dropdown (z.B. "Rückfrage Kundendaten") |
| Inhalt | Text (HTML) | Der vorgefertigte Antworttext, unterstützt Platzhalter wie bei Autoantworten |
| Queue | FK Queue | Zugeordnete Queue (ein Textbaustein gehört zu genau einer Queue) |
| Filter: Status | FK Status (nullable) | Optional: Nur anzeigen, wenn das Ticket diesen Status hat |
| Filter: Priorität | FK Priority (nullable) | Optional: Nur anzeigen, wenn das Ticket diese Priorität hat |
| Filter: Tags | m:n Tag (nullable) | Optional: Nur anzeigen, wenn das Ticket mindestens einen dieser Tags hat |
| Sortierung | Integer | Reihenfolge im Dropdown |
| Aktiv | Boolean | Aktiviert/Deaktiviert |

Textbausteine werden im Admin-Bereich pro Queue verwaltet (erstellen, bearbeiten, aktivieren/deaktivieren -- nie löschen, siehe Plattform-Dokument, Kapitel 1.6). Sie
unterstützen dieselben Platzhalter wie Autoantworten (siehe Kapitel
4.2). Die optionalen Filterbedingungen (Status, Priorität, Tags)
schränken ein, wann ein Textbaustein im Dropdown angezeigt wird. Ohne
Bedingung wird der Textbaustein immer angezeigt. Sind mehrere
Bedingungen gesetzt, müssen alle zutreffen (UND-Verknüpfung). So sieht
der Benutzer nur die für die aktuelle Situation relevanten
Textbausteine.

## 4.5 Automatische Zuweisung

Pro Queue kann eine automatische Zuweisung neuer Tickets aktiviert und
konfiguriert werden:

| **Einstellung** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Automatische Zuweisung aktiv | Boolean | Aktiviert/Deaktiviert pro Queue |
| Modus | Enum | Round-Robin: Reihum-Zuweisung an die Benutzer der Queue. Least-Load: Zuweisung an den Benutzer mit den wenigsten offenen Tickets. |

Verhalten: Bei Ticketerstellung (per E-Mail oder manuell) wird
automatisch ein Benutzer zugewiesen. Nur aktive Benutzer, die über ihre
Benutzergruppen Zugriff auf die Queue haben (siehe Kapitel 2.3/2.4),
werden berücksichtigt. Der Ticketstatus wechselt direkt von
"neu" auf "zugewiesen". Ein automatisch generierter System-Eintrag
dokumentiert die Zuweisung. Die SLA-Annahmezeit gilt als erfüllt.

## 4.6 Regelbasierte Prioritätszuweisung

Pro Queue können Regeln konfiguriert werden, die eingehenden Tickets
automatisch eine erhöhte Priorität zuweisen. Die Konfiguration erfolgt
im Admin-Backend.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Queue | FK Queue | Zugeordnete Queue |
| Regeltyp | Enum | keyword_subject (Keyword im Betreff), sender_email (exakte Absenderadresse), sender_domain (Absender-Domain) |
| Wert | String | Das zu prüfende Keyword, die E-Mail-Adresse oder Domain |
| Ziel-Priorität | FK Priority | Priorität, die bei Treffer zugewiesen wird |
| Sortierung | Integer | Reihenfolge der Regelauswertung (erste zutreffende Regel gewinnt) |
| Aktiv | Boolean | Aktiviert/Deaktiviert |

Auswertungslogik: Bei Ticketerstellung werden die aktiven Regeln der
Queue in Sortierungsreihenfolge geprüft. Die erste zutreffende Regel
bestimmt die Priorität. Falls keine Regel greift, wird die
Standard-Priorität zugewiesen. Keyword-Matching im Betreff ist
case-insensitive.

## 4.7 Makros / Aktionspakete

Makros ermöglichen es, mehrere Ticket-Aktionen gebündelt mit einem Klick
auszuführen. Makros sind optional -- sie werden nur angezeigt, wenn
mindestens ein Makro konfiguriert und der jeweiligen Queue per Matrix
zugeordnet ist.

### 4.7.1 Makro-Definition

Makros werden im Admin-Bereich global definiert. Pro Makro werden die
auszuführenden Aktionen konfiguriert (alle optional, mindestens eine
muss aktiv sein):

| **Aktion** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Statuswechsel | FK Status (nullable) | Ticket-Status auf den gewählten Wert setzen |
| Eintrag einfügen | Text + Sichtbarkeit + Eintragstyp (nullable) | Eintrag mit konfiguriertem Text, Sichtbarkeit (öffentlich/intern) und Eintragstyp |
| Priorität ändern | FK Priority (nullable) | Priorität auf den gewählten Wert setzen |
| Wiedervorlage setzen | Integer (nullable) | Wiedervorlage auf X Tage ab jetzt setzen |
| Tags hinzufügen | m:n Tag (nullable) | Die gewählten Tags zum Ticket hinzufügen |
| Zuweisung ändern | Enum (nullable) | an_mich (zugewiesenen Benutzer auf den ausführenden setzen), entfernen (Zuweisung aufheben) |

Weitere Eigenschaften pro Makro:

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String | Anzeigename im Dropdown (z.B. "Warten auf Kundenrückmeldung") |
| Beschreibung | Text (nullable) | Optionale Beschreibung für den Admin-Bereich |
| Sortierung | Integer | Reihenfolge im Dropdown |
| Aktiv | Boolean | Makro aktiviert/deaktiviert |

### 4.7.2 Queue-Zuordnung

Die Zuordnung von Makros zu Queues erfolgt per Matrix (Zeilen: Makros,
Spalten: Queues). Ein Makro kann mehreren Queues zugeordnet sein. In der
Ticket-Detailansicht erscheint ein "Makro ausführen"-Dropdown nur,
wenn mindestens ein Makro für die Queue des Tickets konfiguriert und
aktiv ist.

### 4.7.3 Ausführung

Bei Auswahl eines Makros wird eine Vorschau aller auszuführenden
Aktionen angezeigt (z.B. "Status wird auf pausiert gesetzt,
öffentlicher Eintrag wird eingefügt, Wiedervorlage wird auf 7 Tage
gesetzt"). Der Eintragstext kann vor der Ausführung vom Benutzer
bearbeitet werden. Nach Bestätigung werden alle Aktionen in einem
Schritt ausgeführt. Die Ausführung wird in der Ticket-Timeline
protokolliert (inkl. Name des Makros). Statuswechsel durch Makros
erfordern keinen separaten Pflichtkommentar, da der Makro-Eintrag
diese Funktion übernimmt.

# 5. Ticket-Lebenszyklus

## 5.1 Ticketerstellung per E-Mail

Ein Ticket wird automatisch erstellt, wenn eine reguläre E-Mail (siehe
Kapitel 3.4) in einer Queue-Mailbox eingeht:

1.  Eine eindeutige Ticketnummer wird gemäß dem konfigurierten Muster
    generiert.

2.  Das Ticket erhält den Status "neu" und wird der Queue der
    empfangenden Mailbox zugeordnet.

3.  Die Standard-Priorität wird zugewiesen.

4.  Der E-Mail-Body wird als initialer Eintrag gespeichert
    (Eintragstyp: Initiale Nachricht, öffentlich; siehe Kapitel 6.6.1).

5.  E-Mail-Anhänge werden als Ticket-Anhänge gespeichert.

6.  Sofern konfiguriert, wird eine Autoantwort an den Absender gesendet.

7.  SLA-Zeiten (Annahme- und Lebenszeit-Eskalation) werden berechnet und
    gesetzt.

8.  Benachrichtigungen an berechtigte Benutzer werden ausgelöst.

## 5.2 Manuelle Ticketerstellung über GUI

Benutzer können Tickets auch manuell über das Webinterface erstellen.
Dabei werden folgende Felder angegeben:

-   Queue (Pflichtfeld, nur Queues mit Zugriffsberechtigung)

-   Betreff (Pflichtfeld)

-   Beschreibung (Pflichtfeld, wird als initialer Eintrag gespeichert,
    Eintragstyp: Initiale Nachricht)

-   Priorität (optional, Standard-Priorität vorausgewählt)

-   Requester-E-Mail (Pflichtfeld)

-   Requester-Name (optional)

-   Kundenreferenz (optional, systemweit eindeutig -- siehe Kapitel
    3.14.7)

-   Anhänge (optional, Upload)

-   E-Mail an Requester senden (optionale Checkbox)

Bei manueller Erstellung wird keine Autoantwort ausgelöst. Über die
optionale Checkbox kann der Benutzer steuern, ob der Requester per
E-Mail über die Ticketerstellung informiert wird.

## 5.3 Ticketnummern-Format

Das Format der Ticketnummer ist über die GUI konfigurierbar. Die
Konfiguration erfolgt mittels eines Musters mit Platzhaltern:

| **Platzhalter** | **Bedeutung** | **Beispiel** |
| --- | --- | --- |
| {YYYY} | Aktuelles Jahr (4-stellig) | 2026 |
| {YY} | Aktuelles Jahr (2-stellig) | 26 |
| {MM} | Aktueller Monat | 03 |
| {DD} | Aktueller Tag | 31 |
| {N+} | Laufende Nummer, Anzahl N bestimmt Padding | 000001 |

Beispiel: Das Muster "TKT-{YYYY}-{NNNNNN}" erzeugt
"TKT-2026-000001".

Konfigurationsoptionen:

-   Jährlicher Reset der Sequenz: Konfigurierbar (ja/nein). Bei
    Aktivierung wird validiert, dass {YYYY} oder {YY} im Muster
    enthalten ist. Andernfalls wird die Einstellung mit einer
    Fehlermeldung abgelehnt.

-   Formatänderung im laufenden Betrieb: Sofort wirksam. Bestehende
    Tickets behalten ihre Nummer. Die Sequenz läuft weiter. Vor dem
    Speichern wird geprüft, ob das neue Muster mit der aktuellen Sequenz
    eine bereits existierende Nummer erzeugen würde (Duplikat-Prüfung).
    Im Admin-Bereich wird eine Vorschau der nächsten generierten
    Ticketnummer angezeigt.

## 5.4 Status und Statuswechsel

Tickets durchlaufen einen definierten Lebenszyklus mit folgenden Status:

| **Status** | **Interner Schlüssel** | **Beschreibung** |
| --- | --- | --- |
| Neu | new | Ticket wurde erstellt, aber noch nicht angenommen |
| Zugewiesen | assigned | Ticket wurde einem Benutzer zugewiesen |
| In Bearbeitung | in_progress | Ticket wird aktiv bearbeitet |
| Pausiert | paused | Bearbeitung temporär unterbrochen (z.B. Warten auf Rückmeldung) |
| Erfolgreich geschlossen | closed_success | Anfrage wurde erfolgreich gelöst (Endstatus) |
| Nicht erfolgreich geschlossen | closed_failure | Anfrage konnte nicht gelöst werden (Endstatus) |
| Abgebrochen | cancelled | Ticket wurde abgebrochen (Endstatus) |

## 5.5 Erlaubte Statusübergänge

Manuelle Übergänge (über die UI):

| **Von** | **Nach** |
| --- | --- |
| Neu | Zugewiesen, Abgebrochen |
| Zugewiesen | In Bearbeitung, Abgebrochen |
| In Bearbeitung | Zugewiesen, Pausiert, Erfolgreich geschlossen, Nicht erfolgreich geschlossen, Abgebrochen |
| Pausiert | In Bearbeitung, Abgebrochen |
| Erfolgreich geschlossen | (kein manueller Wechsel -- Endstatus) |
| Nicht erfolgreich geschlossen | (kein manueller Wechsel -- Endstatus) |
| Abgebrochen | (kein manueller Wechsel -- Endstatus) |

System-Sonderübergänge (nur automatisch, nicht über die UI):

| **Von** | **Nach** | **Auslöser** |
| --- | --- | --- |
| closed_success / closed_failure / cancelled | paused | Eingehende E-Mail auf geschlossenes Ticket (Wiedereröffnung) |
| Beliebiger Status | cancelled | "Als Spam markieren"-Aktion (gleichzeitig Blacklist-Eintrag) |

## 5.6 Pflichtkommentar bei Statuswechsel

Jeder Statuswechsel erfordert die Eingabe eines Pflichttextes (im
Folgenden "Pflichtkommentar" als etablierter UI-Begriff für die
Benutzereingabe; die resultierende Speicherung erfolgt als Eintrag).
Dieser wird als Eintrag vom Eintragstyp "Statuswechsel" gespeichert
(interner Systemtyp; siehe Kapitel 6.6.1). Bei Abschluss-Status
(erfolgreich geschlossen, nicht erfolgreich geschlossen, abgebrochen)
wird der Eintrag automatisch als Eintragstyp "Abschlussmeldung"
gespeichert und ist öffentlich sichtbar (siehe Kapitel 6.1).

## 5.7 Ticket-Löschung

Das System unterstützt zwei Löschverfahren:

-   Soft-Delete: Administratoren können Tickets als "gelöscht"
    markieren. Sie verschwinden aus allen Listen, bleiben aber in der
    Datenbank. Wiederherstellung ist möglich.

-   Hard-Delete: Administratoren können soft-gelöschte Tickets endgültig
    aus der Datenbank entfernen. Dabei werden Ticket, Einträge,
    Anhänge (inkl. Dateien auf dem Storage) und personenbezogene Daten
    vollständig gelöscht. Eine Pflichtbegründung ist erforderlich. Ein
    Audit-Log-Eintrag wird erstellt mit: Ticket-ID, Ticketnummer,
    ausführender Admin, Zeitstempel, Begründung.

## 5.8 Ticket-Zusammenführung (Merge)

Wenn derselbe Anfragende zum gleichen Thema mehrfach schreibt, können
Tickets zusammengeführt werden:

-   Ein Ticket wird als Hauptticket ausgewählt.

-   Die anderen Tickets (Quelltickets) werden als "zusammengeführt"
    geschlossen (eigener Abschlusseintrag mit Verweis auf das
    Hauptticket).

-   Alle Einträge und Anhänge der Quelltickets werden in das
    Hauptticket übernommen. Die ursprünglichen Zeitstempel bleiben
    erhalten.

-   Die Quelltickets erhalten einen Verweis auf das Hauptticket und sind
    weiterhin über ihre ursprüngliche Ticketnummer auffindbar (auch für
    Gäste: Gast-Login mit Quellticket-Nummer leitet auf das Hauptticket
    um).

-   Ein Pflichtkommentar (Begründung) ist erforderlich.

-   Merge ist nur innerhalb derselben Queue-Gruppe zulässig. Auch
    Administratoren können nicht über Queue-Gruppen hinweg mergen.
    Innerhalb derselben Queue-Gruppe ist Merge queueübergreifend
    möglich.

## 5.9 Ticket-Verknüpfung

Tickets können untereinander verknüpft werden, um Zusammenhänge sichtbar
zu machen. Die Verknüpfung ist rein informativ und hat keine Auswirkung
auf den Status oder die Bearbeitung.

| **Verknüpfungstyp** | **Beschreibung** |
| --- | --- |
| Bezieht sich auf | Allgemeiner thematischer Zusammenhang |
| Blockiert von | Dieses Ticket kann erst bearbeitet werden, wenn das verknüpfte Ticket gelöst ist |
| Duplikat von | Dieses Ticket ist ein Duplikat des verknüpften Tickets (Alternative zu Merge) |

Verknüpfungen werden bidirektional angezeigt: Wenn Ticket A mit Ticket B
verknüpft wird, sieht man die Verknüpfung in beiden Tickets. Das
Erstellen und Löschen von Verknüpfungen wird in der Ticket-Timeline
protokolliert.

## 5.10 Ticket beobachten (Watch)

Benutzer können sich als Beobachter zu einem Ticket hinzufügen, sofern
sie Zugriff auf die Queue des Tickets haben. Beobachter erhalten
dieselben Benachrichtigungen wie der zugewiesene Benutzer (gemäß ihren
persönlichen Benachrichtigungseinstellungen). Auf dem Dashboard gibt es
eine eigene View "Beobachtete Tickets" mit einer Liste aller vom
Benutzer beobachteten offenen Tickets. Der zugewiesene Benutzer eines
Tickets ist automatisch Beobachter. Ein Benutzer kann sich jederzeit als
Beobachter entfernen.

## 5.11 Geplante Statuswechsel

Benutzer können für ein Ticket einen zeitgesteuerten Statuswechsel
konfigurieren:

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Zielstatus | Enum | Der Status, auf den gewechselt werden soll (nur gültige Transitionen) |
| Zeitpunkt | DateTime | Wann der Statuswechsel ausgeführt werden soll |
| Bedingung | Enum | always (immer ausführen) oder no_activity (nur wenn seit Planung keine Aktivität stattfand) |
| Kommentar | Text (Pflicht) | Wird als Statuswechsel-Eintrag gespeichert (siehe Kapitel 6.6.1) |

Typischer Anwendungsfall: "Dieses Ticket in 7 Tagen automatisch auf
erfolgreich geschlossen setzen, wenn keine Antwort kommt." Ein
CLI-Command (bin/cake process_scheduled_changes) prüft und führt fällige
geplante Statuswechsel aus. Pro Ticket kann maximal ein geplanter
Statuswechsel aktiv sein. Bei manueller Statusänderung wird ein
geplanter Wechsel automatisch storniert.

## 5.12 Freie Felder / Ticketattribute

Das System unterstützt frei definierbare Felder, die Tickets um
zusätzliche strukturierte Informationen erweitern. Freie Felder werden
global im Admin-Bereich definiert und per Matrix pro Queue aktiviert
(siehe Plattform-Dokument, Kapitel 1.5 Matrix-Konfiguration).

### 5.12.1 Felddefinition

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String | Anzeigename des Feldes (z.B. "Bestellnummer", "Mandant", "Fehlerklasse") |
| Interner Schlüssel | String | Eindeutiger technischer Bezeichner (für API und Export) |
| Feldtyp | Enum | text (Freitext), number (Zahl), date (Datum), boolean (Ja/Nein), select (Einfachauswahl), multiselect (Mehrfachauswahl) |
| Optionen | Text (nullable) | Vordefinierte Auswahlwerte bei Typ select/multiselect (durch Admin pflegbar) |
| Pflichtfeld | Boolean | Ob das Feld bei Ticketerstellung/-bearbeitung ausgefüllt werden muss |
| Sortierung | Integer | Reihenfolge der Anzeige im Ticket |
| Aktiv | Boolean | Feld aktiviert/deaktiviert |

### 5.12.2 Queue-Zuordnung per Matrix

Im Admin-Bereich wird über eine zweidimensionale Matrix konfiguriert,
welche freien Felder in welcher Queue aktiv sind. Die Zeilen der Matrix
sind die definierten Felder, die Spalten die Queues. Per Checkbox wird
aktiviert/deaktiviert. So sieht Queue "E-Commerce" z.B. die Felder
Bestellnummer und Lieferland, Queue "IT-Support" das Feld
Fehlerklasse.

### 5.12.3 Nutzung

Verhalten der freien Felder:

-   In der Ticket-Detailansicht werden die aktiven Felder der Queue in
    der Sidebar angezeigt (unterhalb der Standard-Metadaten).

-   Bei manueller Ticketerstellung über die GUI erscheinen die Felder im
    Erstellungsformular.

-   Bei Ticketerstellung per E-Mail bleiben freie Felder zunächst leer
    und können vom Bearbeiter nachträglich ausgefüllt werden.

-   Bei Ticketerstellung per REST-API können freie Felder als
    Schlüssel-Wert-Paare übergeben werden (anhand des internen
    Schlüssels).

-   Freie Felder sind in der Ticketliste als Filterspalten verfügbar und
    im CSV/Excel-Export enthalten.

-   Bei Queue-Wechsel: Hat die Ziel-Queue andere aktive Felder, bleiben
    bereits ausgefüllte Werte erhalten (auch wenn das Feld in der neuen
    Queue nicht aktiv ist). Sie werden nicht angezeigt, aber nicht
    gelöscht.

## 5.13 Tags / Schlagworte

Tickets können mit Tags (Schlagworten) versehen werden, um zusätzlich zu
Queue und Priorität eine flexible Kategorisierung zu ermöglichen.

### 5.13.1 Tag-Verwaltung

Administratoren können im Admin-Bereich einen Tag-Katalog mit
vordefinierten Tags verwalten:

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String | Anzeigename des Tags (z.B. "payment", "shipping", "vip") |
| Farbe | String (Hex) | Hintergrundfarbe für die visuelle Darstellung |
| Beschreibung | Text (nullable) | Optionale Beschreibung des Tags |
| Aktiv | Boolean | Tag aktiviert/deaktiviert |

### 5.13.2 Tag-Vergabe

Benutzer können bei Tickets Tags vergeben:

-   Eingabefeld mit Autovervollständigung: Beim Tippen wird ein Lookup
    auf alle existierenden Tags durchgeführt (vordefinierte + bereits
    verwendete freie Tags).

-   Vordefinierte Tags werden bevorzugt vorgeschlagen und sind farblich
    gekennzeichnet.

-   Benutzer können auch neue, freie Tags eingeben. Diese werden beim
    ersten Verwenden automatisch als freier Tag gespeichert und stehen
    danach ebenfalls im Lookup zur Verfügung.

-   Ein Ticket kann mehrere Tags haben.

-   Tags werden in der Ticket-Sidebar, in der Ticketliste und im Export
    angezeigt.

-   Tags sind als Filter in der Ticketübersicht verfügbar
    (Mehrfachauswahl möglich).

## 5.14 Interne Checklisten pro Ticket

Tickets können interne Checklisten (Aufgabenlisten) enthalten, die den
Bearbeitungsprozess strukturieren.

### 5.14.1 Checklisten-Vorlagen

Administratoren definieren im Admin-Bereich Checklisten-Vorlagen. Die
Zuordnung zu Queues erfolgt per Matrix (Zeilen: Vorlagen, Spalten:
Queues). Pro Queue können mehrere Vorlagen aktiv sein.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String | Anzeigename der Vorlage (z.B. "Reklamationsprozess") |
| Items | Liste | Die einzelnen Checkpunkte (geordnet, z.B. "Rechnung geprüft", "Kunde kontaktiert", "Ersatz versendet") |
| Queue-Zuordnung | Matrix | In welchen Queues diese Vorlage automatisch angewendet wird |
| Aktiv | Boolean | Vorlage aktiviert/deaktiviert |

### 5.14.2 Nutzung im Ticket

Verhalten der Checklisten:

-   Bei Ticketerstellung wird die aktive Checklisten-Vorlage(n) der
    Queue automatisch als Checkliste(n) an das Ticket angehängt.

-   Benutzer können einzelne Items abhaken (Checkbox). Der abhakende
    Benutzer und der Zeitstempel werden gespeichert.

-   Benutzer können zusätzlich individuelle Items zu einer bestehenden
    Checkliste hinzufügen.

-   Benutzer können eine komplett neue, leere Checkliste an ein Ticket
    anhängen (z.B. für Sonderfälle).

-   Der Fortschritt jeder Checkliste wird als Fortschrittsbalken
    angezeigt (z.B. "3/5 erledigt").

-   Checklisten werden in der Ticket-Sidebar unterhalb der Tags
    angezeigt.

-   Checklisten sind intern -- sie sind für Gäste nicht sichtbar.

-   Das Abhaken/Enthäken eines Items wird in der Ticket-Timeline
    protokolliert.

## 5.15 Wiedervorlage / Follow-up Datum

Benutzer können für ein Ticket ein Wiedervorlagedatum setzen. Im
Gegensatz zum geplanten Statuswechsel (Kapitel 5.11) bewirkt die
Wiedervorlage keine automatische Änderung, sondern dient ausschließlich
als Erinnerung.

-   Pro Ticket kann ein Wiedervorlagedatum (Datum + optionale Uhrzeit)
    gesetzt werden.

-   Am Wiedervorlagedatum erhält der zugewiesene Benutzer eine
    Benachrichtigung (neuer Typ "ticket_followup", separat
    aktivierbar). Ist dem Ticket kein Benutzer zugewiesen, wird die
    Benachrichtigung an alle berechtigten Benutzer der Queue gesendet
    (sofern der Benachrichtigungstyp für den jeweiligen Benutzer
    aktiviert ist).

-   Im Dashboard erscheint eine eigene Ansicht "Fällige
    Wiedervorlagen" mit allen Tickets, deren Wiedervorlagedatum heute
    oder überschritten ist.

-   Die Wiedervorlage wird in der Ticket-Sidebar angezeigt (Datum,
    verbleibende Zeit).

-   Nach Benachrichtigung bleibt die Wiedervorlage bestehen, bis sie
    manuell entfernt oder ein neues Datum gesetzt wird.

-   Ein geplanter Statuswechsel und eine Wiedervorlage können
    gleichzeitig existieren (unabhängig voneinander).

## 5.16 Tickettypen / Servicekatalog

Zusätzlich zu Queue und Priorität kann jedem Ticket ein Tickettyp
zugeordnet werden. Tickettypen dienen als weitere
Klassifizierungsdimension und ermöglichen differenziertes Reporting.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String (unique) | Anzeigename (z.B. "Reklamation", "Lieferproblem", "Zahlungsproblem") |
| Beschreibung | Text (nullable) | Optionale Beschreibung |
| Sortierung | Integer | Reihenfolge im Dropdown |
| Aktiv | Boolean | Aktiviert/Deaktiviert (nie löschbar, siehe Plattform-Dokument, Kapitel 1.6) |

Default-Werte nach Installation: "Reklamation", "Lieferproblem",
"Zahlungsproblem", "Stammdatenänderung" -- alle änder- und
erweiterbar.

Verhalten:

-   Das Tickettyp-Dropdown wird nur angezeigt und ist Pflicht, wenn
    mindestens ein Tickettyp konfiguriert und aktiv ist. Ohne aktive
    Tickettypen entfällt das Feld.

-   Die Zuordnung der Tickettypen zu Queues erfolgt per Matrix (Zeilen:
    Tickettypen, Spalten: Queues). Nur in der Queue aktivierte
    Tickettypen erscheinen im Dropdown.

-   Tickettyp wird bei Erstellung gesetzt: GUI → Dropdown, API →
    optionaler Parameter, E-Mail → nachträglich durch Bearbeiter.

-   Der Tickettyp kann nachträglich geändert werden. Die Änderung wird
    in der Ticket-Timeline protokolliert.

-   Der Tickettyp wird in der Sidebar (Bereich 1: Stammdaten), in
    Filtern und im CSV/Excel-Export angezeigt.

## 5.17 Abschlussgründe

Bei einem Statuswechsel auf einen Abschluss-Status (closed_success,
closed_failure, cancelled) wird zusätzlich zum Pflichtkommentar ein
Abschlussgrund aus einem Dropdown ausgewählt.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String (unique) | Anzeigename (z.B. "Rückerstattung erfolgt", "Fehler nicht reproduzierbar") |
| Anwendbar auf Status | m:n Status | Für welche Abschluss-Status der Grund wählbar ist |
| Sortierung | Integer | Reihenfolge im Dropdown |
| Aktiv | Boolean | Aktiviert/Deaktiviert (nie löschbar, siehe Plattform-Dokument, Kapitel 1.6) |

Default-Werte nach Installation: "Kunde nicht erreichbar",
"Rückerstattung erfolgt", "Fehler nicht reproduzierbar", "An
Drittanbieter übergeben" -- alle änder- und erweiterbar.

Verhalten:

-   Das Dropdown wird nur angezeigt und ist Pflicht, wenn mindestens ein
    Abschlussgrund konfiguriert und aktiv ist. Ohne aktive
    Abschlussgründe bleibt der Workflow wie bisher (nur
    Pflichtkommentar).

-   Die Zuordnung der Abschlussgründe zu Queues erfolgt per Matrix
    (Zeilen: Abschlussgründe, Spalten: Queues). Nur in der Queue
    aktivierte Abschlussgründe erscheinen im Dropdown.

-   Zusätzlich wird pro Abschlussgrund konfiguriert, für welche
    Abschluss-Status er wählbar ist (z.B. "Rückerstattung erfolgt" nur
    bei closed_success, "Kunde nicht erreichbar" bei allen drei).

-   Der gewählte Abschlussgrund wird am Ticket gespeichert und ist in
    der Sidebar, in Filtern und im Export verfügbar.

-   Der Abschlussgrund ist für Gäste sichtbar (als Teil der öffentlichen
    Abschlussmeldung).

## 5.18 Pflichtfeld-Regeln

Im Admin-Bereich können kontextabhängige Pflichtfeld-Regeln definiert
werden. Diese bestimmen, welche Felder unter welchen Bedingungen
ausgefüllt werden müssen. Die Konfiguration erfolgt in einem eigenen
Admin-Bereich "Pflichtfeld-Regeln" mit Matrix-Ansicht.

Die Matrix zeigt:

-   Zeilen: Alle verfügbaren Felder (freie Felder + Standardfelder wie
    Kundenreferenz, Tickettyp)

-   Spalten: Queues

-   Pro Zelle konfigurierbar: nicht Pflicht, immer Pflicht, Pflicht bei
    bestimmtem Status, Pflicht bei bestimmtem Eintragstyp

Unterstützte Bedingungstypen:

| **Bedingung** | **Beschreibung** | **Beispiel** |
| --- | --- | --- |
| Immer Pflicht | Feld muss in dieser Queue immer ausgefüllt sein | Kundennummer ist in Queue "B2B" immer Pflicht |
| Pflicht bei Status | Feld wird Pflicht, wenn das Ticket einen bestimmten Status erreicht | Abschlussgrund wird Pflicht bei closed_success / closed_failure / cancelled |
| Pflicht bei Eintragstyp | Feld wird Pflicht, wenn ein Eintrag mit diesem Typ erstellt wird | Kundenreferenz wird Pflicht bei Eintragstyp "Eingehender Anruf" |

Die Validierung erfolgt beim Speichern. Ist ein Pflichtfeld nicht
ausgefüllt, wird der Speichervorgang verhindert und ein Hinweis
angezeigt.

Zusammenspiel mit der Felddefinition (Kapitel 5.12.1): Freie Felder
besitzen in ihrer globalen Definition eine Eigenschaft "Pflichtfeld".
Diese wirkt als Basis-Einstellung: Ist ein Feld global als Pflichtfeld
markiert, ist es in jeder Queue Pflicht, in der es aktiv ist --
unabhängig von den Pflichtfeld-Regeln. Die Pflichtfeld-Regeln wirken
additiv: Sie können ein ansonsten optionales Feld unter bestimmten
Bedingungen zur Pflicht machen, aber sie können ein global als Pflicht
markiertes Feld nicht wieder optional machen. Es gilt stets die
strengste Anforderung.

# 6. Einträge und Sichtbarkeit

## 6.1 Einträge und Sichtbarkeit

Jeder Eintrag in einem Ticket hat einen Eintragstyp (siehe Kapitel 6.6)
und eine Sichtbarkeit (öffentlich/intern). Pro Eintragstyp gelten drei
Regeln: die Standard-Sichtbarkeit beim Erstellen, ob der Benutzer sie
ändern darf, und ob sie nachträglich änderbar ist.

| **Eintragstyp (System)** | **Standard- Sichtbarkeit** | **Benutzer darf ändern?** | **Beschreibung** |
| --- | --- | --- | --- |
| Initiale Nachricht | Öffentlich | Nein (fest) | Die ursprüngliche E-Mail / Anfrage |
| Eingehende E-Mail | Öffentlich | Nein (fest) | Folge-E-Mail des Anfragenden |
| Ausgehende E-Mail | Öffentlich | Nein (fest) | Antwort per E-Mail aus dem Ticket |
| Autoantwort | Öffentlich | Nein (fest) | Automatische Antwort bei Ticketerstellung |
| Statuswechsel | Intern | Nur bei Abschluss- Status: automatisch öffentlich | Pflichttext bei Status- änderung |
| Queue-Wechsel | Intern | Nein (fest) | Begründung bei Queue-Änderung |
| Benutzerwechsel | Intern | Nein (fest) | Neuzuweisung an einen anderen Benutzer |
| Gast-Kommentar | Öffentlich | Nein (fest) | Eintrag eines Gastes über das Webinterface |
| Abschlussmeldung | Öffentlich | Nein (fest) | Eintrag bei Abschluss / Abbruch |
| System-Aktion | Intern | Nein (fest) | Automatisierte Aktionen (Merge, Spam, etc.) |
| Benutzerdefinierte Typen | Intern | Ja (Benutzer wählt öffentlich oder intern) | Manuell erstellt Einträge |

Erläuterung: "Fest" bedeutet, die Sichtbarkeit wird vom System gesetzt
und kann weder beim Erstellen noch nachträglich geändert werden.
E-Mail-basierte Einträge (eingehend, ausgehend, Autoantwort) sind immer
öffentlich, da sie an externe Empfänger gehen bzw. von externen
Absendern stammen. Statuswechsel-Einträge bei Abschluss-Status
(closed_success, closed_failure, cancelled) werden automatisch als
öffentlich markiert (Abschlussmeldung). Nur bei benutzerdefinierten
Eintragstypen hat der Benutzer die Wahl.

## 6.2 Sichtbarkeitsregeln für Gäste

Gäste sehen ausschließlich Einträge mit dem Flag is_public = true.
Folgende Eintragstypen sind daher für Gäste sichtbar:

-   Initiale Nachricht (immer öffentlich)

-   Eingehende E-Mails (immer öffentlich)

-   Ausgehende E-Mails (immer öffentlich)

-   Autoantworten (immer öffentlich)

-   Gast-Kommentare (immer öffentlich)

-   Abschlussmeldungen (immer öffentlich)

-   Benutzerdefinierte Einträge, sofern vom Benutzer als öffentlich
    markiert

Nicht sichtbar für Gäste: interne Statuswechsel-Einträge (außer
Abschlussmeldungen), Queue-Wechsel, Benutzerwechsel, System-Aktionen
und alle als intern markierten benutzerdefinierten Einträge.

## 6.3 Gast-Kommentare

Gäste können nach erfolgreicher Verifizierung (Ticketnummer + E-Mail +
CAPTCHA) öffentliche Einträge zum Ticket hinzufügen. Diese Einträge
werden automatisch mit dem Eintragstyp "Gast-Kommentar" gespeichert
(siehe Kapitel 6.6.1) und sind immer öffentlich. Die E-Mail-Adresse aus
dem Gast-Login wird dem Eintrag zugeordnet, sodass Benutzer über das
Ticket per E-Mail darauf antworten können. Der zugewiesene Benutzer
erhält eine Benachrichtigung bei neuem Gast-Kommentar.

## 6.4 Anhänge

Anhänge können sowohl automatisch (aus eingehenden E-Mails) als auch
manuell (Upload über das Webinterface) einem Ticket oder Eintrag
zugeordnet werden.

-   Maximale Dateigröße pro Anhang: Konfigurierbar über Admin-GUI.

-   Erlaubte Dateitypen: Whitelist-Modus. Die erlaubten
    MIME-Typen/Endungen werden über die Admin-GUI konfiguriert.

-   Speicherort: Konfigurierbar zwischen lokalem Dateisystem und
    S3-kompatiblem Storage. Die Storage-Anbindung (Pfad, S3-Credentials,
    Bucket, Endpoint) wird in config/app.php konfiguriert.

-   Gespeicherte Metadaten pro Anhang: Dateiname, Dateipfad, Dateigröße,
    MIME-Typ.

## 6.5 @Mention in Einträgen

In internen Einträgen können Benutzer andere Benutzer per
\@benutzername erwähnen. Das System bietet beim Tippen von "@" eine
Autovervollständigung mit den Benutzern der aktuellen Queue an. Der
erwähnte Benutzer erhält eine Benachrichtigung (sofern der
Benachrichtigungstyp "ticket_mention" für ihn aktiviert ist), auch
wenn er nicht dem Ticket zugewiesen oder Beobachter ist. Mentions werden
im Eintragstext visuell hervorgehoben. Mentions sind nur in internen
Einträgen möglich, nicht in öffentlichen Einträgen oder
E-Mail-Antworten.

## 6.6 Eintragstypen

Jeder Eintrag in einem Ticket hat einen Eintragstyp, der die Art der
Interaktion kennzeichnet. Der Eintragstyp ist in der Aktivitätenliste
sichtbar und filterbar. Es gibt zwei Kategorien von Eintragstypen:

### 6.6.1 Systemtypen (fest, nicht änderbar)

Diese Typen werden automatisch vom System gesetzt und können nicht
geändert oder gelöscht werden:

| **Systemtyp** | **Beschreibung** | **Ersteller-Anzeige** |
| --- | --- | --- |
| Eingehende E-Mail | E-Mail, die dem Ticket zugeordnet wurde | Kunde |
| Ausgehende E-Mail | E-Mail-Antwort aus dem Ticket | Benutzername |
| Autoantwort | Automatisch generierte Antwort bei Ticketerstellung | System |
| Statuswechsel | Pflichttext bei Statusänderung | Benutzername / System |
| Queue-Wechsel | Begründung bei Queue-Änderung | Benutzername |
| Benutzerwechsel | Neuzuweisung an einen anderen Benutzer | Benutzername / System |
| Initiale Nachricht | Erste Nachricht bei Ticketerstellung | Kunde / Benutzername / System |
| Abschlussmeldung | Eintrag bei Abschluss oder Abbruch | Benutzername |
| Gast-Kommentar | Eintrag eines Gastes über das Webinterface | Kunde |
| System-Aktion | Automatisierte Aktionen (Merge, Spam-Markierung, geplanter Statuswechsel) | System |

Hinweis: Der Eintragstyp "Initiale Nachricht" wird bei jeder
Ticketerstellung verwendet, unabhängig vom Erstellkanal. Bei Erstellung
per E-Mail ist der Ersteller "Kunde", bei manueller Erstellung über
die GUI der erstellende Benutzer, bei Erstellung per REST-API "System"
(mit Referenz auf den API-Token).

### 6.6.2 Benutzerdefinierte Typen (Admin-konfigurierbar)

Administratoren können im Admin-Bereich zusätzliche Eintragstypen global
definieren. Diese stehen Benutzern bei der manuellen Erstellung von
Einträgen als Pflicht-Auswahl zur Verfügung.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String | Anzeigename (z.B. "Eingehender Anruf", "Detailklärung", "Installationsinfo") |
| Sortierung | Integer | Reihenfolge im Dropdown |
| Aktiv | Boolean | Typ aktiviert/deaktiviert |

Beispiele für benutzerdefinierte Typen: Eingehender Anruf, Abgehender
Anruf, Detailklärung, Installationsinfo, Interne Notiz, Rückrufbitte,
Vor-Ort-Termin.

Verhalten: Bei manuellen Einträgen ist die Auswahl
eines Eintragstyps ein Pflichtfeld. Das Dropdown zeigt die aktiven
benutzerdefinierten Typen. Systemtypen werden automatisch gesetzt und
erscheinen nicht im Dropdown.

# 7. Eskalation und SLA

## 7.1 Eskalationstypen

| **Typ** | **Beschreibung** | **Auslöser** |
| --- | --- | --- |
| Annahme-Eskalation | Das Ticket verbleibt zu lange im Status "neu" ohne Zuweisung. | Konfigurierte Dauer pro Queue + Priorität überschritten |
| Lebenszeit-Eskalation | Das Ticket ist insgesamt zu lange offen (unabhängig vom aktuellen Status). | Konfigurierte Gesamtdauer pro Queue + Priorität überschritten |

## 7.2 Eskalationsregeln

Die Eskalationszeiten werden pro Kombination aus Queue und Priorität
konfiguriert:

| **Feld** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Queue | FK Queue | Zugeordnete Queue |
| Priorität | FK Priority | Zugeordnete Priorität |
| Annahmezeit (Minuten) | Integer (nullable) | Max. Minuten im Status "neu". NULL = keine Eskalation |
| Lebenszeit (Minuten) | Integer (nullable) | Max. Gesamtdauer in Minuten. NULL = keine Eskalation |
| Vorwarnung Annahme | Integer (nullable) | Minuten vor Ablauf der Annahmezeit ODER Prozentsatz. NULL = keine Vorwarnung |
| Vorwarnung Lebenszeit | Integer (nullable) | Minuten vor Ablauf der Lebenszeit ODER Prozentsatz. NULL = keine Vorwarnung |

## 7.3 Eskalations-Vorwarnung

Pro Eskalationsregel können optionale Vorwarnungen konfiguriert werden.
Die Vorwarnung wird als Minuten vor Ablauf oder als Prozentsatz der
SLA-Zeit angegeben. Beim Erreichen des Vorwarnungs-Schwellwerts wird
eine Benachrichtigung vom Typ "ticket_escalation_warning" an die
berechtigten Benutzer der Queue gesendet (sofern dieser
Benachrichtigungstyp für den Benutzer aktiviert ist). Im Dashboard und
in der Ticketliste wird der SLA-Countdown visuell hervorgehoben (z.B.
Farbumschlag von Grün auf Gelb bei Vorwarnung, auf Rot bei Eskalation).

## 7.4 Pausenzeit-Behandlung

Pro Queue ist konfigurierbar, ob die Zeit im Status "pausiert" von der
SLA-Berechnung abgezogen wird (Feld: escalation_pause_excludes). Ist
diese Option aktiviert, wird bei jedem Wechsel in den Status
"pausiert" ein Zeitstempel gesetzt. Beim Verlassen des Status wird die
Differenz zur Gesamtpausenzeit addiert.

Ist der Queue ein SLA-Kalender zugeordnet, wird die Pausenzeit in
Geschäftsminuten gemessen (siehe Kapitel 7.8.4). Ohne Kalender werden
Wanduhr-Minuten verwendet. Die SLA-Berechnung berücksichtigt dann:
Effektive Geschäftszeit = Gesamte Geschäftszeit - Pausenzeit (in
Geschäftsminuten).

## 7.5 SLA-Berechnung bei Queue-Wechsel

Bei einem Queue-Wechsel werden die SLA-Zeiten anhand der
Eskalationsregeln und des SLA-Kalenders der neuen Queue neu berechnet.
Der Startzeitpunkt bleibt das ursprüngliche Ticket-Erstellungsdatum.
Ein Queue-Wechsel setzt die SLA-Uhr nicht zurück. Hat die neue Queue
einen anderen Kalender (oder keinen), werden die Zielzeiten auf Basis
der neuen Geschäftszeiten neu berechnet. Wenn die neue Queue kürzere
SLA-Zeiten hat und das Ticket dadurch sofort als eskaliert gilt, ist
dies ein berechtigtes Signal.

## 7.6 Eskalations-Prüfung

Ein separater CLI-Command (bin/cake check_escalations) prüft regelmäßig
per Cronjob alle offenen Tickets auf SLA-Verletzungen. Bei einer
Eskalation wird eine Benachrichtigung an die berechtigten Benutzer der
Queue gesendet (sofern die Benachrichtigung "ticket_escalation" für
den Benutzer aktiviert ist).

## 7.7 SLA-Kalender

Hinweis zur modularen Architektur: Die SLA-Kalender-Funktionalität wird
als Extension-Modul zum Ticketing-Main-Modul bereitgestellt (Kapitel
23.15.2). Ohne installiertes SLA-Kalender-Modul gilt automatisch
24×7-Betrieb (Resolver-Default). Die Ausnahmetag-Listen (Kapitel 7.7.3)
werden als separates Extension-Modul "Feiertagskalender" bereitgestellt
(Plattform-Dokument, Kapitel 23.15.3). Ohne dieses Modul gilt eine leere Feiertagsmenge.
Die in diesem Kapitel beschriebenen fachlichen Anforderungen definieren,
was die Extension-Module leisten müssen.

SLA-Kalender definieren die Geschäftszeiten, in denen SLA-Zeiten
laufen. Ist einer Queue kein Kalender zugeordnet, gilt ein 24×7-Betrieb
(SLA läuft durchgehend). SLA-Kalender können im Admin-Bereich erstellt,
bearbeitet, aktiviert und deaktiviert werden -- nie gelöscht (siehe
Plattform-Dokument, Kapitel 1.6).

### 7.7.1 Kalender-Definition

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String (unique) | Eindeutiger Anzeigename des Kalenders (z.B. "Geschäftszeiten DE", "24x5 Support") |
| Beschreibung | Text (nullable) | Optionale Beschreibung |
| Zeitzone | String | Referenz-Zeitzone des Kalenders (z.B. "Europe/Berlin"). Die Systemzeit ist ausschlaggebend für die Berechnung |
| Aktiv | Boolean | Kalender aktiviert/deaktiviert (nie löschbar, siehe Plattform-Dokument, Kapitel 1.6) |

### 7.7.2 Geschäftszeit-Fenster

Pro Kalender können beliebig viele Geschäftszeit-Fenster definiert
werden. Jedes Fenster legt fest, an welchem Wochentag und in welchem
Zeitraum der SLA aktiv ist.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Kalender | FK SlaCalendar | Zugeordneter Kalender |
| Wochentag | Enum | monday, tuesday, wednesday, thursday, friday, saturday, sunday |
| Ganztägig | Boolean | Wenn ja: SLA läuft von 00:00 bis 23:59. Startzeit und Endzeit werden ignoriert |
| Startzeit | Time (nullable) | Beginn des Zeitfensters (z.B. 08:00). Pflicht wenn nicht ganztägig |
| Endzeit | Time (nullable) | Ende des Zeitfensters (z.B. 17:00). Pflicht wenn nicht ganztägig. Muss nach Startzeit liegen |

Pro Wochentag können mehrere Fenster existieren (z.B. Montag 08:00--
12:00 und 13:00--17:00 für eine Mittagspause). Fenster desselben
Wochentags dürfen sich nicht überlappen; die Überlappungsfreiheit wird
über ein Exclusion-Constraint (GiST) in der Datenbank erzwungen
(Plattform-Dokument, Kapitel 30.2). Gibt es für einen Wochentag kein
Fenster, so ist dieser Tag nicht geschäftsaktiv (SLA ruht).

Beispielkonfiguration "Bürozeiten DE":

-   Montag bis Freitag: 08:00--12:00 und 13:00--17:00
-   Samstag: kein Fenster (SLA ruht)
-   Sonntag: kein Fenster (SLA ruht)

### 7.7.3 Ausnahmetag-Listen

Ausnahmetag-Listen definieren Tage, an denen der SLA unabhängig vom
Wochentagsplan ruht (z.B. Feiertage, Betriebsferien). Listen werden
als eigenständige Entitäten verwaltet und können einem oder mehreren
Kalendern zugeordnet werden.

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String (unique) | Eindeutiger Anzeigename der Liste (z.B. "Deutsche Feiertage 2026", "Betriebsferien Winter") |
| Beschreibung | Text (nullable) | Optionale Beschreibung |
| Aktiv | Boolean | Liste aktiviert/deaktiviert (nie löschbar, siehe Plattform-Dokument, Kapitel 1.6) |

Jede Liste enthält einzelne Ausnahmetage:

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Liste | FK SlaExceptionList | Zugeordnete Ausnahmetag-Liste |
| Datum | Date | Das Datum des Ausnahmetags |
| Bezeichnung | String (nullable) | Bezeichnung des Tages (z.B. "Weihnachten", "Karfreitag") |

Pro Liste muss jedes Datum eindeutig sein. Ein Ausnahmetag überschreibt
die Geschäftszeit-Fenster des betreffenden Wochentags vollständig: an
diesem Tag läuft kein SLA, unabhängig davon, welcher Wochentag es ist.

Die Zuordnung der Listen zu Kalendern erfolgt per m:n-Relation. Eine
Liste kann mehreren Kalendern zugeordnet werden (z.B. "Deutsche
Feiertage 2026" wird allen Kalendern mit deutschem Standort
zugeordnet). Ein Kalender kann mehrere Listen haben (z.B.
"Feiertage" + "Betriebsferien").

### 7.7.4 Queue-Zuordnung

Jede Queue kann optional einen SLA-Kalender zugeordnet bekommen. Die
Zuordnung erfolgt als einfaches Select-Feld (FK, nullable) in der
Queue-Konfiguration:

-   Kein Kalender zugeordnet (NULL): SLA läuft 24×7
    (Rückwärtskompatibilität, Standardverhalten)
-   Kalender zugeordnet: SLA-Zeiten werden ausschließlich innerhalb der
    definierten Geschäftszeiten gemessen

Im Admin-Bereich steht zusätzlich eine Übersichtsseite zur Verfügung,
die alle Queues gruppiert untereinander auflistet, jeweils mit einem
Select-Feld für den zugeordneten Kalender. So kann die Zuordnung
zentral überblickt und gepflegt werden.

Wird ein zugeordneter Kalender deaktiviert, wechseln die betroffenen
Queues auf 24×7-Verhalten, bis ein neuer aktiver Kalender zugeordnet
wird. Bestehende Tickets behalten ihre bereits berechneten SLA-Zeiten.

## 7.8 SLA-Berechnung mit Kalender

### 7.8.1 Grundprinzip

Alle SLA-relevanten Zeitberechnungen basieren auf Geschäftsminuten.
Eine Geschäftsminute ist eine Minute, die innerhalb eines aktiven
Geschäftszeit-Fensters des zugeordneten Kalenders liegt und nicht auf
einen Ausnahmetag fällt. Ohne Kalender entspricht eine Geschäftsminute
einer Wanduhr-Minute (24×7).

Die Eskalationsregeln (Kapitel 7.2) definieren Annahmezeit und
Lebenszeit weiterhin in Minuten. Diese Minuten werden als
Geschäftsminuten interpretiert, wenn der Queue ein Kalender zugeordnet
ist.

### 7.8.2 Zielzeit-Berechnung (Soll)

Die SLA-Zielzeiten werden berechnet bei:

-   Ticketerstellung: Ausgehend vom Erstellungszeitpunkt werden die
    konfigurierten Geschäftsminuten für Annahme und Lösung auf dem
    Kalender vorwärts gerechnet. Das Ergebnis sind zwei konkrete
    Zielzeitpunkte (DateTime): Annahme-Zielzeit und Lösungs-Zielzeit.

-   Prioritätswechsel: Die Zielzeiten werden anhand der
    Eskalationsregeln der neuen Priorität neu berechnet. Startzeitpunkt
    bleibt das ursprüngliche Erstellungsdatum.

-   Queue-Wechsel: Die Zielzeiten werden anhand der Eskalationsregeln
    und des Kalenders der neuen Queue neu berechnet (siehe Kapitel 7.5).
    Startzeitpunkt bleibt das ursprüngliche Erstellungsdatum.

Aus den Zielzeiten werden die Vorwarn- und Eskalationszeitpunkte
abgeleitet (basierend auf den Vorwarnungs-Einstellungen der
Eskalationsregel).

### 7.8.3 Ist-Zeit-Berechnung

Die tatsächlich verbrauchten Geschäftsminuten werden bei folgenden
Ereignissen ermittelt und am Ticket gespeichert:

-   Annahme-Ist-Zeit: Wird bei der ersten Zuweisung des Tickets
    berechnet. Gezählt werden die Geschäftsminuten zwischen
    Erstellungszeitpunkt und dem Zeitpunkt der ersten Zuweisung.

-   Lösungs-Ist-Zeit: Wird beim Abschluss des Tickets berechnet
    (Status: closed_success, closed_failure oder cancelled). Gezählt
    werden die Geschäftsminuten zwischen Erstellungszeitpunkt und
    Abschlusszeitpunkt.

Aus dem Vergleich von Soll- und Ist-Zeiten ergibt sich:

-   Annahme-SLA gehalten: Annahme-Ist-Zeit ≤ Annahme-Soll-Zeit
-   Lösungs-SLA gehalten: Lösungs-Ist-Zeit ≤ Lösungs-Soll-Zeit

Diese Werte werden pro Ticket gespeichert und sind in der
Ticket-Sidebar (Bereich 1: SLA-Status), in Filtern und im Export
verfügbar.

### 7.8.4 Pausenzeit und Kalender

Die bestehende Pausenzeit-Regelung (Kapitel 7.4) wird konsistent mit
dem Kalender angewendet: Auch die Pausenzeit wird in Geschäftsminuten
gemessen. Ist ein Ticket pausiert, werden nur die Geschäftsminuten
innerhalb der Pause von der SLA-Berechnung abgezogen -- nicht die
gesamte Wanduhr-Dauer der Pause.

Beispiel: Ein Ticket wird freitags um 16:00 Uhr pausiert und montags
um 09:00 Uhr wieder aufgenommen. Bei einem Kalender mit Mo--Fr 08:00--
17:00 beträgt die Pausenzeit 2 Geschäftsstunden (Freitag 16:00--17:00
+ Montag 08:00--09:00), nicht 65 Wanduhr-Stunden.

## 7.9 SLA-Auswertung

Im Admin-Dashboard steht eine SLA-Auswertung pro Queue zur Verfügung.
Die Auswertung ist über den Admin-Bereich erreichbar und bietet
folgende Metriken:

| **Metrik** | **Beschreibung** |
| --- | --- |
| Annahme-SLA-Einhaltungsquote | Prozentsatz der Tickets, bei denen der Annahme-SLA gehalten wurde (Annahme-Ist ≤ Annahme-Soll) |
| Lösungs-SLA-Einhaltungsquote | Prozentsatz der Tickets, bei denen der Lösungs-SLA gehalten wurde (Lösungs-Ist ≤ Lösungs-Soll) |
| Durchschnittliche Annahmezeit | Mittelwert der Annahme-Ist-Zeiten in Geschäftsminuten |
| Durchschnittliche Lösungszeit | Mittelwert der Lösungs-Ist-Zeiten in Geschäftsminuten |
| Tickets innerhalb SLA | Absolute Anzahl der Tickets mit eingehaltenem Lösungs-SLA |
| Tickets außerhalb SLA | Absolute Anzahl der Tickets mit verletztem Lösungs-SLA |

Filter:

-   Zeitraum (Pflicht): Von/Bis-Datum, Voreinstellungen für laufender
    Monat, letzter Monat, letztes Quartal, letztes Jahr
-   Priorität: Einzelauswahl oder alle
-   Queue: Einzelauswahl (Pflicht)

Die Auswertung berücksichtigt nur abgeschlossene Tickets
(closed_success, closed_failure, cancelled) im gewählten Zeitraum.
Tickets ohne Eskalationsregel (und somit ohne Soll-Zeiten) werden nicht
in die SLA-Quoten einbezogen.

# 8. Benachrichtigungen

Architektur-Hinweis: Benachrichtigungen, Dashboard-Ereignisse (Kapitel
8.3) und Digest-Versand sind Listener auf Ticket-Domänenereignisse, die
über den transaktionalen Outbox der Plattform asynchron zugestellt
werden (Plattform-Dokument, Kapitel 26.9.2). Ticketing-Domänenaktionen
emittieren Ereignisse – z.B. ticket.created, ticket.assigned,
ticket.status_changed, ticket.reply_received, ticket.escalated,
ticket.escalation_warning, ticket.followup_due, ticket.mention,
email.send_failed. Die in diesem Kapitel beschriebenen
Benachrichtigungstypen sind die Listener-Reaktionen auf diese Ereignisse.
Die Zustellung erfolgt mindestens-einmal; die Listener sind idempotent.
Dadurch hängt die Grundfunktion einer Ticketaktion nicht am Erfolg der
Benachrichtigung.

## 8.1 Benachrichtigungstypen

| **Typ** | **Schlüssel** | **Auslöser** |
| --- | --- | --- |
| Neues Ticket in Queue | new_ticket | Ein neues Ticket wird in einer Queue erstellt |
| Ticketzuweisung | ticket_assigned | Ein Ticket wird einem Benutzer zugewiesen |
| Ticket-Eskalation | ticket_escalation | Ein SLA-Schwellwert wird überschritten |
| Eskalations-Vorwarnung | ticket_escalation_warning | Ein SLA-Schwellwert wird in Kürze erreicht (konfigurierbar pro Regel) |
| Statuswechsel | ticket_status_changed | Der Status eines Tickets ändert sich |
| Neue Antwort | ticket_reply | Eine neue E-Mail-Antwort oder ein Gast-Kommentar geht ein |
| \@Mention | ticket_mention | Ein Benutzer wurde in einem internen Eintrag erwähnt |
| E-Mail-Versandfehler | email_send_failure | Eine E-Mail konnte nach allen Retries nicht zugestellt werden |
| Wiedervorlage fällig | ticket_followup | Das Wiedervorlagedatum eines zugewiesenen Tickets ist erreicht |

## 8.2 Versandmodus

Pro Benutzer ist konfigurierbar, ob Benachrichtigungen sofort oder
gesammelt als Digest versendet werden:

-   Sofort: Benachrichtigung wird unmittelbar bei Eintreten des
    Ereignisses versendet.

-   Digest: Benachrichtigungen werden gesammelt und in konfigurierbaren
    Intervallen (z.B. 15/30/60 Minuten) als zusammengefasste E-Mail
    versendet.

Benachrichtigungen werden ausschließlich an interne Benutzer versendet,
nicht an externe Anfragende (Requester).

## 8.3 Dashboard-Ereignisse

Zusätzlich zum E-Mail-Versand werden neue Ereignisse seit dem letzten
Login des Benutzers auf dem Dashboard angezeigt. Es werden nur noch
relevante Ereignisse gezeigt (z.B. kein "neues Ticket", wenn dieses
bereits geschlossen wurde). Einzelne Ereignisse können als gelesen
markiert werden (einzeln und alle).

## 8.4 Konfiguration

Administratoren können pro Benutzer individuell festlegen, welche
Benachrichtigungstypen aktiviert oder deaktiviert sind, sowie den
Versandmodus (sofort/Digest) und das Digest-Intervall.

## 8.5 Benachrichtigungs-Templates

Die E-Mail-Templates für Benachrichtigungen sind frei über die Admin-GUI
konfigurierbar (HTML mit Platzhaltern). Pro Benachrichtigungstyp und pro
Sprache existiert ein Template. Nach der Installation sind
Default-Templates in Englisch und Deutsch vorausgefüllt. Verfügbare
Platzhalter entsprechen denen der Autoantworten (Kapitel 4.2), ergänzt
um {status}, {assigned_user}, {queue_name} und {escalation_time}.

# 9. Gastzugang

## 9.1 Gast-Login-Seite

Der Gastzugang verfügt über eine eigene Login-Seite (separates Layout,
kein Zugang zum internen Bereich).

## 9.2 DSGVO-Consent-Banner

Beim Aufruf der Gast-Login-Seite wird immer ein Consent-Banner angezeigt
(auch wegen System-Cookies):

-   Der Banner enthält einen readonly Switch für "Notwendige
    System-Cookies" (immer an, ausgegraut, nicht abwählbar).

-   Es gibt keinen separaten Switch für CAPTCHA. CAPTCHA fällt unter
    "notwendig", sofern ein CAPTCHA-Modul installiert und aktiviert ist.

-   Bei Klick auf "Akzeptieren": Consent-Cookie wird gesetzt, Banner
    minimiert sich zu einem Cookie-Icon unten rechts. Klick auf das Icon
    öffnet den Banner erneut (Widerruf möglich).

-   Bei Ablehnung: Weiterleitung zu about:blank. Kein Consent-Cookie
    wird gesetzt, sodass beim nächsten Aufruf der Banner erneut
    erscheint.

-   Bei Widerruf: Cookie wird gelöscht, Seite wird neu geladen, Banner
    erscheint erneut.

-   Externe CAPTCHA-Scripts werden erst nach Consent geladen (kein
    externes Script vor Zustimmung).

## 9.3 Zugriffsmethode

Hinweis zur modularen Architektur: Die CAPTCHA-Funktionalität wird als
Extension-Modul "Gastportal-CAPTCHA" bereitgestellt (Plattform-Dokument, Kapitel 23.15.4).
Ohne installiertes CAPTCHA-Modul wird kein CAPTCHA angezeigt
(Resolver-Default: kein CAPTCHA). Der Brute-Force-Schutz per
Rate-Limiting (Kapitel 9.4) ist davon unabhängig und Bestandteil des
Ticketing-Main-Moduls.

Gäste geben Ticketnummer und E-Mail-Adresse ein. Beide Angaben müssen
korrekt sein. Zusätzlich ist ein CAPTCHA zu lösen, sofern das
Gastportal-CAPTCHA-Modul installiert und aktiviert ist. Die
CAPTCHA-Konfiguration (Provider, Site-Key, Secret-Key) erfolgt über die
Admin-GUI des Extension-Moduls. Ohne CAPTCHA-Modul wird kein CAPTCHA
angezeigt und der Consent-Banner entfällt (da keine externen Scripts
geladen werden; System-Cookies gelten als notwendig).

## 9.4 Brute-Force-Schutz

Zusätzlich zum CAPTCHA (sofern installiert) gilt ein Rate-Limiting per IP-Adresse. Maximal
X Fehlversuche innerhalb von Y Minuten. X und Y sind global im
Admin-Bereich konfigurierbar. Nach Überschreitung wird die IP-Adresse
temporär gesperrt.

## 9.5 Gast-Portal (Ansicht nach Anmeldung)

Nach erfolgreicher Verifizierung wird dem Gast ein zweigeteiltes Portal
angezeigt:

### 9.5.1 Oberer Bereich: Ticketübersicht

Eine Liste aller Tickets, die unter der angegebenen E-Mail-Adresse
eröffnet wurden. Sortierung: Erstelldatum absteigend (neueste zuerst).
Sichtbare Spalten pro Ticket:

-   Ticketnummer

-   Betreff

-   Status (farbkodiert)

-   Erstellungsdatum

-   Priorität

Ein Klick auf ein Ticket in der Liste wählt dieses aus und zeigt die
Details im unteren Bereich an.

### 9.5.2 Unterer Bereich: Ticket-Detailansicht

Die Detailansicht des aktuell ausgewählten Tickets. Beim Laden der Seite
ist automatisch das Ticket vorausgewählt, dessen Nummer bei der
Anmeldung eingegeben wurde. Sichtbare Informationen:

-   Ticketnummer und Betreff

-   Aktueller Status (mit Farbkodierung)

-   Erstellungsdatum und Priorität

-   Alle als öffentlich markierten Einträge (inkl. initiale Nachricht
    und Abschlussmeldung)

-   Öffentliche Anhänge

### 9.5.3 Gast-Aktionen

Gäste können:

-   Öffentliche Einträge zum ausgewählten Ticket hinzufügen
    (Eintragstyp: Gast-Kommentar; die E-Mail aus dem Gast-Login wird
    dem Eintrag zugeordnet).

-   Zwischen Tickets in der oberen Liste wechseln, um Details anderer
    eigener Tickets einzusehen.

Gäste haben keinen Zugriff auf: interne Einträge, Queue-Zuordnung,
zugewiesene Benutzer, Eskalationsinformationen oder Tickets anderer
Anfragender.

### 9.5.4 Lesebestätigung für Gäste

Bei öffentlichen Einträgen wird Gästen angezeigt, ob der Eintrag vom
Bearbeiter gelesen wurde. Die Anzeige erfolgt als dezenter Hinweis unter
dem Eintrag (z.B. "Gesehen am 31.03.2026, 14:30 Uhr"). Ein
öffentlicher Eintrag gilt als "gelesen", wenn der zugewiesene
Benutzer das Ticket nach dem Eintrags-Zeitstempel geöffnet hat. Dies
reduziert Rückfragen der Art "Haben Sie meine Nachricht erhalten?".

# 10. Signaturen und Autoantworten

## 10.1 Benutzer-Signaturen

Jeder Benutzer kann eine individuelle E-Mail-Signatur konfigurieren
(HTML und/oder Plain-Text). Diese Signatur wird automatisch an
ausgehende E-Mails angehängt, die aus einem Ticket heraus versendet
werden. Die Signatur-Verwaltung erfolgt im Benutzerprofil.

## 10.2 Autoantworten pro Queue

Pro Queue kann eine automatische Antwort konfiguriert werden, die bei
Erstellung eines neuen Tickets per E-Mail-Eingang an den Anfragenden
gesendet wird. Die Autoantwort umfasst: einen konfigurierbaren Betreff
(mit Platzhaltern), einen konfigurierbaren Nachrichtentext (mit
Platzhaltern) und ein Aktivierungs-Flag. Autoantworten werden nur bei
der initialen Ticketerstellung per E-Mail versendet, nicht bei
Folge-E-Mails und nicht bei manueller Ticketerstellung.

# 11. Prioritäten

Prioritäten sind frei konfigurierbar und umfassen folgende
Eigenschaften:

| **Eigenschaft** | **Typ** | **Beschreibung** |
| --- | --- | --- |
| Name | String | Anzeigename (z.B. "Niedrig", "Normal", "Hoch", "Kritisch") |
| Farbe | String (Hex) | Farbcode für die UI-Darstellung |
| Sortierung | Integer | Reihenfolge in Dropdowns und Listen |
| Standard | Boolean | Genau eine Priorität ist als Standard markiert |

Die Standardpriorität wird automatisch neuen Tickets zugewiesen.
Prioritäten können nie gelöscht, sondern nur deaktiviert werden (siehe
Plattform-Dokument, Kapitel 1.6). Deaktivierte Prioritäten erscheinen nicht mehr in
Dropdowns, bleiben aber in allen historischen Referenzen erhalten.

# 12. Benutzeroberfläche

## 12.1 Allgemein

Die Oberfläche wird serverseitig mit CakePHP-Templates gerendert und
nutzt Bootstrap 5 als CSS-Framework. Der interne Bereich (Dashboard,
Ticketlisten, Detailansichten, Admin) ist für Desktop- und Tablet-Größen
optimiert -- eine Smartphone-Optimierung ist hier bewusst nicht
vorgesehen, da die geteilten Ansichten (Drittelung, Sidebar) auf kleinen
Bildschirmen nicht sinnvoll nutzbar sind. Der Gastzugang ist zusätzlich
für Smartphones responsiv optimiert (Ticketliste und Detailansicht
untereinander statt nebeneinander). Die Sprache der Oberfläche ist
mehrsprachig angelegt (CakePHP I18n), mit Englisch als Standardsprache
und Deutsch als zusätzlicher Sprache. Benutzer können ihre bevorzugte
Sprache im Profil einstellen.

## 12.2 Dashboard

Das Dashboard ist die Startseite nach dem Login und enthält folgende
Elemente:

-   Neue Ereignisse seit letztem Login: Anzeige noch relevanter
    Ereignisse (neue Tickets, Zuweisungen, Eskalationen, Antworten).
    Einzeln und alle als gelesen markierbar.

-   Offene Tickets pro Queue: Balkendiagramm mit Anzahl offener Tickets
    pro Queue (nur Queues, die über die Benutzergruppen des Benutzers
    erreichbar sind; siehe Kapitel 2.5 für Administrator-Verhalten).

-   Eigene zugewiesene Tickets: Liste der dem Benutzer zugewiesenen
    offenen Tickets.

-   Eskalierte Tickets: Warnliste mit Tickets, die SLA-Schwellwerte
    überschritten haben.

-   Beobachtete Tickets: Liste der vom Benutzer beobachteten offenen
    Tickets.

-   Fällige Wiedervorlagen: Tickets mit Wiedervorlagedatum heute oder
    überschritten.

Statistiken (jeweils nur für Queues, die über die Benutzergruppen des
Benutzers erreichbar sind; Administratoren ohne Benutzergruppen-
Zuordnung sehen keine Statistiken -- siehe Kapitel 2.5):

-   Durchschnittliche Bearbeitungszeit

-   SLA-Einhaltungsquote pro Queue

-   Tickets pro Status (Tortendiagramm)

-   Ticketvolumen letzte 30 Tage (Liniendiagramm)

Interaktionen auf dem Dashboard:

-   Klick auf ein Ticket: Öffnet die Ticket-Detailansicht (siehe 12.3).

-   Klick auf den Listennamen (z.B. "Mir zugewiesene Tickets",
    "Eskalierte Tickets"): Öffnet die Listen-Detailansicht (siehe
    12.4) mit den Tickets dieser Kategorie.

## 12.3 Ticket-Detailansicht

Wird erreicht durch Klick auf ein einzelnes Ticket (z.B. aus dem
Dashboard oder aus einer Liste). Die Ansicht besteht aus einem
Hauptbereich (links) und einer Metadaten-Sidebar (rechts). Der
Hauptbereich ist zweigeteilt im Verhältnis 1/3 zu 2/3:

### 12.3.1 Sidebar rechts: Ticket-Daten (Ticket-Ebene)

Eine fixe Sidebar auf der rechten Seite zeigt alle Informationen, die
für das gesamte Ticket gelten. Die Sidebar ist scrollbar und in folgende
Bereiche gegliedert (von oben nach unten):

Bereich 1: Stammdaten + Personen + SLA

-   Ticketnummer

-   Status (farbkodiertes Badge)

-   Priorität (farbkodiertes Badge)

-   Tickettyp (sofern Tickettypen konfiguriert und aktiv)

-   Queue

-   Erstellkanal (E-Mail / API / Manuell)

-   Erstellungsdatum

-   Zugewiesener Benutzer

-   Requester (Name + E-Mail)

-   Beobachter-Liste (mit Möglichkeit, sich selbst hinzuzufügen/zu
    entfernen)

-   SLA-Countdown Annahme (mit Farbumschlag: Grün → Gelb bei Vorwarnung
    → Rot bei Eskalation)

-   SLA-Countdown Lebenszeit (mit Farbumschlag)

-   Abschlussgrund (nur bei geschlossenen/abgebrochenen Tickets, sofern
    Abschlussgründe konfiguriert)

Bereich 2: Referenzen + Termine

-   Kundenreferenz (sofern vorhanden, editierbar)

-   Verknüpfte Tickets (mit Klick zum Öffnen)

-   Wiedervorlagedatum (sofern gesetzt, mit verbleibender Zeit)

-   Geplanter Statuswechsel (Zielstatus + Zeitpunkt, sofern aktiv)

Bereich 3: Checklisten

-   Alle Checklisten des Tickets mit Fortschrittsbalken (z.B. "3/5
    erledigt")

-   Aufklappbar: Einzelne Items mit Checkbox zum Abhaken

-   Button zum Hinzufügen individueller Items oder neuer Checklisten

Bereich 4: Tags + Freie Felder

-   Zugeordnete Tags (mit Eingabefeld für weitere Tags,
    Autovervollständigung)

-   Alle für die Queue aktiven freien Felder mit aktuellen Werten
    (editierbar)

Bereich 5: Ähnliche Tickets (aufklappbar)

-   Automatische Suche nach Tickets mit gleicher Requester-E-Mail
    (mehrere Treffer möglich) oder gleicher Kundenreferenz (maximal ein
    anderer Treffer, da systemweit eindeutig).

-   Ergebnisse gruppiert angezeigt (z.B. "3 Tickets desselben
    Anfragenden", "1 Ticket mit gleicher Kundenreferenz"). Das
    aktuelle Ticket wird in den Ergebnissen nicht aufgeführt.

-   Pro Treffer: Ticketnummer, Betreff, Status. Klick öffnet das Ticket.

-   Die Suche läuft beim Öffnen des Tickets asynchron im Hintergrund
    (keine Verzögerung der Ticket-Ansicht).

-   Standardmäßig eingeklappt -- aufklappbar bei Bedarf.

Die Sidebar ist in der Listen-Detailansicht (Kapitel 12.4) ebenfalls
sichtbar und bezieht sich auf das aktuell ausgewählte Ticket.

### 12.3.2 Oberes Drittel: Aktivitätenliste

Über der Aktivitätenliste wird der Ticket-Betreff als fixer Header
angezeigt (nicht scrollbar). Darunter folgt die scrollbare Liste aller
Einträge des Tickets. Sortierung: Datum absteigend (neuester Eintrag
oben). Der neueste Eintrag ist beim Öffnen automatisch ausgewählt (aktiv
markiert). Ein Klick auf einen anderen Eintrag wählt diesen aus und
zeigt dessen Details im unteren Bereich an. Die Liste ist nach
Eintragstyp filterbar.

Sichtbare Informationen pro Eintrag in der Liste (in dieser
Reihenfolge):

-   Datum/Uhrzeit

-   Eintragstyp (z.B. "Eingehende E-Mail", "Statuswechsel",
    "Eingehender Anruf")

-   Ersteller: Benutzername des Bearbeiters, "Kunde" bei eingehenden
    Einträgen (E-Mails, Gast-Kommentare), oder "System" bei
    automatisierten Einträgen (Autoantwort, Eskalation, geplanter
    Statuswechsel)

-   Sichtbarkeit (öffentlich/intern)

Es wird keine Inhalts-Vorschau in der Liste angezeigt. Den vollständigen
Inhalt sieht man im Detailbereich darunter.

### 12.3.3 Untere zwei Drittel: Detailanzeige (Eintrag-Ebene)

Zeigt den vollständigen Inhalt des in der oberen Liste ausgewählten
Eintrags an. Alle hier angezeigten Informationen gehören zum einzelnen
Eintrag, nicht zum Ticket:

-   Eintragstyp, Ersteller, Datum/Uhrzeit, Sichtbarkeit (Kopfzeile des
    Eintrags)

-   Vollständiger Text des Eintrags (inkl. inline
    dargestellter Bilder)

-   Angehängte Dateien des Eintrags (Bilder und PDFs mit Inline-Vorschau
    im Browser, alle anderen Dateitypen als Download)

-   E-Mail-Zustellstatus (nur bei ausgehenden E-Mails: zugestellt /
    fehlgeschlagen / ausstehend)

-   \@Mentions (visuell hervorgehoben im Text, nur bei internen
    Einträgen)

Zusätzlich stehen im Detailbereich die Ticket-Aktionen zur Verfügung:

-   Statuswechsel (mit Pflichtkommentar)

-   Queue-Wechsel (mit Pflichtbegründung und optionaler Neuzuweisung)

-   E-Mail-Antwort verfassen (setzt auf die letzte E-Mail auf, Zitat
    editierbar, mit Signatur)

-   Eintrag hinzufügen (öffentlich/intern, mit \@Mention-Unterstützung
    in internen Einträgen)

-   Anhang hochladen

-   Ticket zuweisen

-   Als Spam markieren

-   Makro ausführen (Dropdown, nur sichtbar wenn Makros für die Queue
    konfiguriert sind, mit Vorschau und Bestätigung)

## 12.4 Listen-Detailansicht

Wird erreicht durch Klick auf einen Listennamen im Dashboard (z.B. "Mir
zugewiesene Tickets", "Eskalierte Tickets", "Offene Tickets"). Die
Ansicht ist dreigeteilt, jeweils ein Drittel:

### 12.4.1 Oberes Drittel: Ticketliste

Scrollbare Liste der Tickets der gewählten Kategorie. Die Sortierung
entspricht der jeweiligen Dashboard-Sortierung:

| **Kategorie** | **Sortierung** |
| --- | --- |
| Offene Tickets | Erstelldatum absteigend (neueste zuerst) |
| Eigene zugewiesene Tickets | Letzte Aktivität aufsteigend (längste Inaktivität oben) |
| Eskalierte Tickets | Längste Zeit seit Eskalation absteigend |
| Neue Ereignisse | Erstelldatum absteigend (neueste zuerst) |
| Beobachtete Tickets | Letzte Aktivität absteigend (neueste Aktivität oben) |
| Fällige Wiedervorlagen | Wiedervorlagedatum aufsteigend (älteste Überfällige oben) |

Das erste Ticket in der Liste ist beim Öffnen automatisch ausgewählt.
Ein Klick auf ein anderes Ticket wählt dieses aus und aktualisiert die
mittlere und untere Ansicht.

Sichtbare Spalten pro Ticket:

-   Ticketnummer

-   Betreff

-   Status (farbkodiert)

-   Priorität (farbkodiert)

-   Zugewiesener Benutzer

-   Datum (Erstellung oder letzte Aktivität, je nach Sortierung)

### 12.4.2 Mittleres Drittel: Einträge des ausgewählten Tickets

Scrollbare Liste aller Aktivitäten/Einträge des oben ausgewählten
Tickets. Sortierung: Datum absteigend (neuester Eintrag oben). Der
neueste Eintrag ist beim Laden automatisch ausgewählt. Ein Klick auf
einen anderen Eintrag aktualisiert die untere Detailanzeige.

Die Darstellung der Einträge entspricht der Aktivitätenliste in der
Ticket-Detailansicht (Datum/Uhrzeit, Eintragstyp, Ersteller,
Sichtbarkeit).

### 12.4.3 Unteres Drittel: Detailanzeige des ausgewählten Eintrags

Zeigt den vollständigen Inhalt des im mittleren Bereich ausgewählten
Eintrags an. Die Darstellung und verfügbaren Aktionen entsprechen dem
Detailbereich der Ticket-Detailansicht (Kapitel 12.3.3).

## 12.5 Filterbare Ticketübersicht

Neben der Dashboard-Navigation existiert eine allgemeine
Ticketübersicht, die alle Tickets der eigenen Queues enthält.

### 12.5.1 Freitextsuche

Ein einzelnes Suchfeld steht oberhalb der Ticketliste zur Verfügung. Der
eingegebene Text wird in Suchbegriffe aufgeteilt (Leerzeichen als
Trenner). Jeder Begriff muss in mindestens einem der durchsuchten Felder
vorkommen (UND-Verknüpfung). Durchsuchte Felder: Ticketnummer, Betreff,
Requester-E-Mail, Requester-Name, Kundenreferenz, Eintragstexte
(öffentlich und intern), Anhang-Dateinamen.

Technische Umsetzung: Die Freitextsuche nutzt die native Volltextsuche
von PostgreSQL (tsvector mit GIN-Index) über die durchsuchten Felder.
Dadurch entfällt das Skalierungsrisiko führender LIKE-Wildcards auf der
großen Tabelle ticket_comments (vgl. R2 / Kapitel 20.5). Für kurze
Präfix- und Teilstring-Lookups (z.B. Ticketnummer) können ergänzend
Trigram-Indizes (pg_trgm) eingesetzt werden. Eine externe Suchmaschine
(z.B. Elasticsearch) bleibt nur für sehr große Installationen oder
erweiterte Anforderungen (Relevanz-Ranking, unscharfe Suche,
Anhang-Inhalte) eine Option (siehe Kapitel 19, Offene Punkte).

Skalierungshinweis (Muss-Bewusstsein): Die LIKE-Suche mit führendem
Platzhalter (%term%) kann keine Indizes nutzen und erzeugt insbesondere
auf der Eintragstabelle (ticket_comments, öffentliche und interne
Eintragstexte) Full-Scans. Diese Tabelle ist die größte des Systems. Das
NFR-Ziel "10.000 Tickets < 2 s" (Kapitel 18) gilt für die paginierte
Listenanzeige, nicht zwingend für die Volltext-Eintragssuche. Für v1
gilt daher: Der Volltext-Suchumfang ist bewusst zu begrenzen (z.B.
Eintragstexte optional bzw. abschaltbar), oder der dedizierte Suchindex
(Kapitel 19.1) ist früh einzuplanen. Siehe auch die Indizierungs- und
Partitionierungsstrategie für ticket_comments in Kapitel 20.5.

### 12.5.2 Strukturierte Filter

Zusätzlich zum Freitextfeld stehen strukturierte Filter zur Verfügung:
Status, Queue, Priorität, Tickettyp, zugewiesener Benutzer, Zeitraum,
Erstellkanal (E-Mail/API/Manuell), Abschlussgrund, Tags
(Mehrfachauswahl), freie Felder (sofern für die Queue aktiv),
Wiedervorlagedatum (mit/ohne, überfällig). Freitextsuche und
strukturierte Filter sind kombinierbar (UND-Verknüpfung).

Einträge pro Seite: Konfigurierbar pro Benutzer im Profil
(10/25/50/100). Der Standardwert wird vom Administrator global
festgelegt. Systemdefault nach Installation: 25.

Standard-Filter: Nur offene Tickets. Die Ansicht verwendet das
Listen-Detail-Layout (Kapitel 12.4) mit den gefilterten Tickets im
oberen Drittel.

### 12.5.3 Gespeicherte Filter / Views

Benutzer können häufig genutzte Filterkombinationen als persönliche
Views speichern:

-   Name der View (frei wählbar, z.B. "Meine offenen High-Priority
    Tickets in Queue Support")

-   Alle aktiven Filterkriterien werden gespeichert (Status, Queue,
    Priorität, zugewiesener Benutzer, Zeitraum)

-   Gespeicherte Views erscheinen als Schnellzugriff oberhalb der
    Filterliste

-   Ein Klick auf eine gespeicherte View setzt alle Filter entsprechend

-   Benutzer können Views bearbeiten und löschen

Zusätzlich können Administratoren globale Views anlegen, die allen
Benutzern zur Verfügung stehen. Globale Views sind als solche
gekennzeichnet und können von Benutzern nicht bearbeitet oder gelöscht
werden.

### 12.5.4 CSV/Excel-Export

Die aktuell gefilterte und sortierte Ticketliste kann als CSV- oder
Excel-Datei exportiert werden:

-   Benutzer: Nur Tickets der eigenen Queues, gemäß aktuellem Filter

-   Administratoren: Tickets der Queues, denen sie über
    Benutzergruppen zugeordnet sind (siehe Kapitel 2.5). Ohne
    Benutzergruppen-Zuordnung kein Ticket-Export. Mit frei wählbaren
    Spalten

-   Exportierbare Spalten: Ticketnummer, Betreff, Status, Priorität,
    Tickettyp, Queue, zugewiesener Benutzer, Requester-E-Mail,
    Erstellungsdatum, letzte Aktivität, Alter des Tickets, SLA-Status,
    Erstellkanal, Kundenreferenz, Abschlussgrund, Tags, freie Felder,
    Wiedervorlagedatum, Checklisten-Fortschritt

-   Formate: CSV (UTF-8) und XLSX

-   Ein Export-Button befindet sich oberhalb der Ticketliste

## 12.6 Ticket-Timeline / Audit-Trail

Neben der Aktivitätenliste (Einträge) verfügt jedes Ticket
über eine separate, kompakte Timeline. Diese zeigt alle Änderungen
chronologisch an:

-   Statuswechsel (von → nach, Benutzer, Zeitstempel)

-   Queue-Wechsel (von → nach, Benutzer, Zeitstempel)

-   Prioritätsänderung (von → nach, Benutzer, Zeitstempel)

-   Zuweisungsänderung (von → nach, Benutzer, Zeitstempel)

-   Beobachter hinzugefügt/entfernt

-   Verknüpfungen erstellt/gelöscht

-   Ticket-Merge (Quelltickets, Zeitstempel)

-   Geplante Statuswechsel erstellt/ausgeführt/storniert

Die Timeline ist in der Ticket-Detailansicht als eigener Reiter oder
Seitenbereich zugänglich und ermöglicht eine schnelle Übersicht über die
Lebensgeschichte eines Tickets ohne die ausführlichen Eintragstexte.

## 12.7 Tastaturnavigation

Für Power-User, die viele Tickets pro Tag bearbeiten, unterstützt das
System Tastaturnavigation:

| **Taste** | **Aktion** |
| --- | --- |
| ↑ / ↓ (Pfeiltasten) | Navigation zwischen Einträgen in der aktiven Liste (Tickets oder Aktivitäten) |
| Enter | Öffnet das ausgewählte Ticket / den ausgewählten Eintrag |
| Escape | Zurück zur übergeordneten Liste / Schließen eines Dialogs |
| R | Antwort-Dialog öffnen (E-Mail-Antwort verfassen) |
| C | Eintrags-Dialog öffnen |
| S | Statuswechsel-Dialog öffnen |
| ? | Tastaturkürzel-Hilfe anzeigen |

Die Tastaturnavigation ist standardmäßig aktiv und kann in den
Benutzereinstellungen deaktiviert werden. Shortcuts sind nur aktiv, wenn
kein Eingabefeld fokussiert ist.

## 12.8 Weitere Bereiche

| **Bereich** | **Beschreibung** |
| --- | --- |
| Profil | Eigene Daten bearbeiten, Signatur verwalten, Sprache ändern, Passwort ändern, Einträge pro Seite einstellen |
| Gast-Ansicht | Zweigeteiltes Portal: Oben Liste aller Tickets der E-Mail-Adresse (sortiert nach Datum absteigend), unten Detailansicht des ausgewählten Tickets mit Eintragsfunktion (Gast-Kommentar) |

## 12.9 Admin-Bereich

Hinweis zur modularen Architektur: Die Admin-Bereiche für
SLA-Kalender, Ausnahmetag-Listen und CAPTCHA-Konfiguration werden von
den jeweiligen Extension-Modulen bereitgestellt (Plattform-Dokument, Kapitel 23.5) und
erscheinen im Admin-Bereich nur, wenn das jeweilige Modul installiert
und aktiv ist. Die SLA-Kalender-Zuordnung in der Queue-Verwaltung ist
ebenfalls nur sichtbar, wenn das SLA-Kalender-Modul aktiv ist.

Hinweis zu Administrationsbereichen: Welche der folgenden Admin-Bereiche
ein Administrator sieht und bedienen kann, richtet sich nach den ihm
zugewiesenen Core-Administrationsbereichen (Plattform-Dokument, Kapitel
27.3.1). Ein Volladministrator sieht alle Bereiche; ein delegierter
Administrator nur die seinem Bereich entsprechenden (z.B.
Benutzerverwaltung und Einladungs-Dashboard im Bereich "Benutzer- und
Gruppenverwaltung").

| **Bereich** | **Beschreibung** |
| --- | --- |
| Benutzerverwaltung | Benutzer per Einladung erstellen, Rollenzuweisung, Benutzergruppen-Zuordnung, Benachrichtigungs-Einstellungen, Benutzer aktivieren/deaktivieren (kein Löschen) |
| Einladungs-Dashboard | Status aller Einladungen (ausstehend, abgelaufen, abgeschlossen, widerrufen). Aktionen: Widerrufen, Erneut versenden, Aus Liste entfernen |
| Queue-Gruppen | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von Queue-Gruppen (logische Bereichstrennung). Nie löschbar, nur deaktivierbar |
| Benutzergruppen | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von Benutzergruppen. Queue-Zuordnung (m:n, auch queue-gruppenübergreifend). Benutzer-Zuordnung. Nie löschbar |
| Queue-Verwaltung | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von Queues, Queue-Gruppen-Zuordnung, Eingangs-/Ausgangs-Mailbox-Zuordnung, SLA-Kalender-Zuordnung, Autoantwort-Konfiguration, Aufbewahrungsdauer, automatische Zuweisung (Modus), Textbausteine. Nie löschbar |
| Mailbox-Verwaltung | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von Mailboxen, Verbindungstest, System-Mailbox-Konfiguration |
| Prioritäten | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von Prioritäten, Sortierung, Standard-Markierung. Nie löschbar |
| Eskalationsregeln | Konfiguration pro Queue + Priorität, Pausenzeit-Verhalten, Vorwarnungen |
| SLA-Kalender | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von SLA-Kalendern. Geschäftszeit-Fenster pro Wochentag (beliebig viele, mit Startzeit/Endzeit oder ganztägig). Übersichtsseite mit allen Queues und zugeordnetem Kalender (Select-Feld). Nie löschbar |
| Ausnahmetag-Listen | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von Ausnahmetag-Listen (Feiertage, Betriebsferien). Einzelne Ausnahmetage pro Liste pflegen (Datum + Bezeichnung). Zuordnung Listen ↔ Kalender (m:n). Nie löschbar |
| SLA-Auswertung | Auswertung pro Queue: Annahme-/Lösungs-SLA-Einhaltungsquote, Durchschnittszeiten, Anzahl Tickets innerhalb/außerhalb SLA. Filterbar nach Zeitraum und Priorität |
| Prioritätsregeln | Regelbasierte Prioritätszuweisung pro Queue (Keyword, E-Mail, Domain) |
| Freie Felder | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von benutzerdefinierten Feldern (Name, Typ, Optionen, Pflicht). Matrix-Konfiguration: Zuordnung Felder ↔ Queues |
| Tags / Schlagworte | Verwaltung vordefinierter Tags (Name, Farbe, Beschreibung). Übersicht über freie Tags |
| Checklisten-Vorlagen | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von Vorlagen mit Items. Matrix-Konfiguration: Zuordnung Vorlagen ↔ Queues |
| Eintragstypen | Verwaltung benutzerdefinierter Eintragstypen (Name, Sortierung, Aktiv). Systemtypen sind einsehbar, aber nicht änderbar |
| Makros | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von Aktionspaketen (Aktionen konfigurieren, Sortierung). Matrix-Konfiguration: Zuordnung Makros ↔ Queues |
| Tickettypen | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von Servicekatalog / Tickettypen. Matrix-Konfiguration: Zuordnung Tickettypen ↔ Queues. Nie löschbar |
| Abschlussgründe | Erstellen, Bearbeiten, Aktivieren/Deaktivieren von Abschlussgründen (mit Status-Zuordnung). Matrix-Konfiguration: Zuordnung Abschlussgründe ↔ Queues. Nie löschbar |
| Pflichtfeld-Regeln | Matrix-Konfiguration: Felder (frei + Standard) ↔ Queues mit Bedingung (immer / bei Status / bei Eintragstyp) |
| Benachrichtigungs-Templates | HTML-Templates pro Typ und Sprache, mit Platzhaltern |
| Blacklist | Verwaltung gesperrter E-Mail-Adressen und Domains |
| Globale Views | Verwaltung globaler gespeicherter Filter/Views für alle Benutzer |
| DSGVO | Suche nach E-Mail/Domain, Vorschau betroffener Daten, Hard-Delete mit Begründung |
| Audit-Log | Einsicht in protokollierte Aktionen (GUI und API gleichermaßen) |
| API-Tokens | Erstellen, Deaktivieren, Konfigurieren von REST-API-Tokens: Queue-Zuordnung, Ablaufdatum, Rate-Limit, letzter Zugriff |
| Massenaktionen | Ticket-Massenbearbeitung (nur Admins): Zuweisen, Priorität ändern, Queue wechseln, Statuswechsel, Soft-Delete. Bestätigungsdialog mit Ticketanzahl und Pflichtkommentar. Audit-Log pro Ticket. Bei Massen-Queue-Wechsel gilt dieselbe Queue-Gruppen-Regel wie bei Einzeltickets: nur innerhalb derselben Queue-Gruppe zulässig. Enthält die Auswahl Tickets aus verschiedenen Queue-Gruppen, wird der Queue-Wechsel für nicht zulässige Tickets blockiert und diese werden separat ausgewiesen |
| Systemeinstellungen | Ticketnummern-Format, App-Name, Standard-Sprache, System-Mailbox, Passwort-Policy, Session-Timeout, Rate-Limits, Max. Dateigröße, erlaubte Dateitypen, E-Mail-Retry-Konfiguration, Standard-Seitengröße |

## 12.10 UX-Prinzipien

Die folgenden Prinzipien gelten als verbindliche Leitlinien für die
Gestaltung aller Oberflächen (Ticket-Bearbeitung, Admin-Bereich,
Gastzugang):

**Wenige Klicks für Standardaktionen.** Die häufigsten Aktionen
(Antworten, Statuswechsel, Zuweisung) sind direkt aus der
Ticket-Detailansicht erreichbar, ohne Navigation in Untermenüs.
Tastaturkürzel (Kapitel 12.7) ergänzen die Maussteuerung.

**Wichtige Informationen sind immer sichtbar.** SLA-Countdown,
Status, Priorität und zugewiesener Benutzer sind in der Sidebar
permanent sichtbar, ohne Scrollen oder Aufklappen. Erst sekundäre
Informationen (ähnliche Tickets, Checklisten-Details) sind
standardmäßig eingeklappt.

**Gefährliche Aktionen immer mit Kontext.** Hard-Delete,
Massenaktionen, Spam-Markierung und Ticket-Merge erfordern einen
Bestätigungsdialog, der explizit anzeigt, was passieren wird (z.B.
"3 Tickets werden als gelöscht markiert"). Pflichtbegründungen
stellen sicher, dass destruktive Aktionen nachvollziehbar bleiben.

**Gleiche Muster an allen Stellen.** Deaktivierbare Entitäten
(Prioritäten, Queues, Benutzer, Tags etc.) verwenden überall
dasselbe UI-Pattern: aktiv/inaktiv-Toggle, nie einen Löschen-Button.
Alle Matrix-Konfigurationen (Felder ↔ Queues, Makros ↔ Queues etc.)
verwenden dasselbe Tabellen-Layout mit Checkboxen. Alle Dropdowns
sortieren nach der konfigurierten Sortierung.

**Keine versteckten Zustandsänderungen.** Jede Aktion, die den
Zustand eines Tickets oder einer Konfiguration ändert, gibt dem
Benutzer sofortiges visuelles Feedback (Erfolgsanzeige, aktualisierte
Sidebar, Timeline-Eintrag). Es gibt keine stillen Hintergrund-
Änderungen ohne Rückmeldung.

**Admin-Bereich ist ein Produkt im Produkt.** Die Administrations-
oberfläche folgt denselben UX-Standards wie die Ticket-Bearbeitung:

-   Jeder Admin-Bereich hat eine klare Startseite mit Übersicht
-   Abhängigkeiten werden sichtbar gemacht (z.B. "Diese Priorität
    wird von 3 Eskalationsregeln und 142 Tickets referenziert")
-   Validierungsfehler zeigen verständliche Meldungen mit Kontext
    (nicht nur "Feld ungültig", sondern "Dieser Name wird bereits
    von einer anderen Priorität verwendet")
-   Konfigurationsänderungen mit Auswirkung auf bestehende Daten
    zeigen eine Vorschau (z.B. Ticketnummern-Vorschau, SLA-
    Neuberechnung bei Regeländerung)
-   Test- und Vorschaufunktionen, wo sinnvoll (Mailbox-
    Verbindungstest, Autoantwort-Vorschau, Benachrichtigungs-
    Template-Vorschau)

**Verbindliche Admin-Detailanforderungen:**

-   **Referenzanzeige vor Deaktivierung (Muss):** Vor der
    Deaktivierung einer Entität (Priorität, Queue, Tickettyp,
    Abschlussgrund, Tag, freies Feld, Benutzergruppe, SLA-Kalender)
    zeigt das System die Anzahl aktiver Referenzen an (z.B. "Diese
    Priorität wird von 3 Eskalationsregeln und 142 offenen Tickets
    referenziert"). Die Deaktivierung ist trotzdem möglich, aber der
    Administrator trifft eine informierte Entscheidung.

-   **Warnstufen bei kritischen Änderungen (Muss):** Konfigurations-
    änderungen mit Auswirkung auf laufende Prozesse erhalten eine
    gelbe Warnung (z.B. "Änderung betrifft 42 offene Tickets") oder
    eine rote Warnung (z.B. "Diese Mailbox ist als Eingang für Queue
    'Support' konfiguriert"). Der Bestätigungsdialog zeigt die
    konkrete Auswirkung.

-   **"Zuletzt geändert" an Konfigurationsobjekten (Muss):** Alle
    konfigurierbaren Entitäten (Queues, Prioritäten, Mailboxen,
    Eskalationsregeln, SLA-Kalender, Makros etc.) zeigen in der
    Admin-Ansicht an: zuletzt geändert von (Benutzername), zuletzt
    geändert am (Zeitstempel). Diese Information stammt aus dem
    Audit-Log.

-   **Globale Suche im Admin-Bereich (Soll):** Ein Suchfeld im
    Admin-Bereich ermöglicht die Suche über alle Konfigurationselemente
    (Queues, Prioritäten, Benutzer, Mailboxen, Regeln etc.) anhand
    ihres Namens. Ergebnisse verlinken direkt auf die jeweilige
    Bearbeitungsseite.

## 12.11 Reporting und operative Steuerung

Zusätzlich zu den Dashboard-Statistiken (Kapitel 12.2) und der
SLA-Auswertung (Kapitel 7.9) stellt das System folgende operative
Kennzahlen bereit, die für Teamleiter und Administratoren im
Tagesbetrieb zentral sind:

**Pflicht-Kennzahlen im Dashboard (Muss):**

-   Offene Tickets pro Queue (absolut und Trend)
-   Eigene zugewiesene Tickets (mit Alter und SLA-Status)
-   Eskalierte Tickets (Anzahl und Liste)
-   Durchschnittliche Annahmezeit pro Queue (aktueller Monat)
-   Durchschnittliche Lösungszeit pro Queue (aktueller Monat)
-   SLA-Einhaltungsquote pro Queue (aktueller Monat)

**Erweiterte operative Kennzahlen (Soll):**

-   Tickets pro Benutzer (offene Zuweisung, zur Lastverteilung)
-   Tickets pro Tickettyp (zur Mustererkennung)
-   Tickets pro Abschlussgrund (zur Ursachenanalyse)
-   Tickets pro Erstellkanal (E-Mail/API/Manuell)
-   Älteste offene Tickets ohne Aktivität
-   Tickets mit überfälligen Wiedervorlagen

**Filter für alle Kennzahlen (Muss):**

Alle Kennzahlen sind filterbar nach: Queue, Priorität, Zeitraum
(Von/Bis), Tickettyp und zugewiesenem Benutzer.

# 13. Mehrsprachigkeit

Das System nutzt die I18n-Funktionalität von CakePHP. Alle Zeichenketten
der Oberfläche werden über die \_\_() Funktion lokalisiert. Unterstützte
Sprachen zum Launch: Englisch (en) als Standard, Deutsch (de). Die
Sprache kann pro Benutzer im Profil eingestellt werden. Die
Gastzugangsseite verwendet die Standard-Sprache oder die Browser-Locale.

# 14. CLI-Commands

| **Command** | **Beschreibung** | **Empfohlenes Intervall** |
| --- | --- | --- |
| bin/cake fetch_mails | Ruft E-Mails aller aktiven Queue-Mailboxen ab, klassifiziert sie, prüft Blacklist und Duplikate, erstellt/aktualisiert Tickets | Alle 2--5 Minuten |
| bin/cake check_escalations | Prüft alle offenen Tickets auf SLA-Verletzungen und sendet Eskalationsbenachrichtigungen | Alle 5--10 Minuten |
| bin/cake process_email_queue | Verarbeitet die E-Mail-Versand-Queue (Retries fehlgeschlagener E-Mails) | Jede Minute |
| bin/cake send_digest | Versendet Digest-Benachrichtigungen an Benutzer mit Digest-Modus | Alle 15 Minuten (oder gem. kleinstem Intervall) |
| bin/cake purge_tickets | Löscht geschlossene/abgebrochene Tickets nach Ablauf der pro Queue konfigurierten Aufbewahrungsdauer (Hard-Delete + Audit-Log) | Täglich (z.B. 02:00 Uhr) |
| bin/cake process_scheduled_changes | Prüft und führt fällige geplante Statuswechsel aus (Bedingung no_activity wird geprüft) | Alle 5 Minuten |
| bin/cake send_followup_reminders | Prüft alle Tickets mit fälligem Wiedervorlagedatum und sendet Erinnerungs-Benachrichtigungen an zugewiesene Benutzer | Alle 15 Minuten |
| bin/cake create_admin | Erstellt einen initialen Administrator-Account (einzige Möglichkeit zur Benutzererstellung ohne Einladung, für die Ersteinrichtung) | Einmalig |
| bin/cake generate_encryption_key | Generiert einen sicheren AES-256-GCM Encryption Key für config/app.php | Einmalig |

# 15. Datenmodell

## 15.1 Entitätenübersicht

Hinweis zur modularen Architektur: In der Plattformarchitektur (Kapitel
23) gehören die folgenden Entitäten zu unterschiedlichen Modulen:
**Core** (User, UserInvitation, UserGroup, UserGroupUser, AuditLog),
**Ticketing-Main-Modul** (alle Ticket-, Queue-, Mailbox-, Eskalations-,
Benachrichtigungs- und Eintrags-Entitäten), **Extension-Modul
SLA-Kalender** (SlaCalendar, SlaBusinessHour), **Extension-Modul
Feiertagskalender** (SlaExceptionList, SlaExceptionDay,
SlaExceptionListCalendar). Die Tabellennamen sind technische
Persistenznamen; die Modulzugehörigkeit ergibt sich aus der
Architektur.

| **Entität** | **Tabelle** | **Beschreibung** |
| --- | --- | --- |
| Priority | priorities | Konfigurierbare Ticketprioritäten |
| Mailbox | mailboxes | IMAP/SMTP-Konfigurationen für Queue- und System-Mail |
| Queue | queues | Bearbeitungsgruppen mit Mailbox-Zuordnung und Aufbewahrungsdauer |
| User | users | Benutzer mit Rollen, Signatur, Status (eingeladen/aktiv/deaktiviert), nie löschbar |
| UserInvitation | user_invitations | Einladungen mit Token, Ablaufdatum, Status (ausstehend/abgelaufen/abgeschlossen/widerrufen) |
| QueueGroup | queue_groups | Queue-Gruppen für logische Bereichstrennung (Name, Beschreibung, nie löschbar) |
| UserGroup | user_groups | Benutzergruppen für indirekte Queue-Berechtigung (Name, Beschreibung, nie löschbar) |
| UserGroupQueue | user_groups_queues | m:n Zuordnung Benutzergruppe ↔ Queue |
| UserGroupUser | user_groups_users | m:n Zuordnung Benutzer ↔ Benutzergruppe |
| Ticket | tickets | Kernobjekt mit Status, Priorität, SLA-Daten (Annahme-Soll/Ist, Lösungs-Soll/Ist, SLA-Einhaltung), Soft-Delete-Flag, Erstellkanal, Kundenreferenz, Wiedervorlagedatum, Tickettyp, Abschlussgrund |
| TicketComment | ticket_comments | Ticket-Einträge (intern/öffentlich) mit Eintragstyp. Fachlich: "Eintrag"; der technische Entitätsname `TicketComment` und die Tabelle `ticket_comments` bleiben aus Gründen der Rückwärtskompatibilität bestehen. Speichert alle Eintragstypen: E-Mails, Statuswechsel, Gast-Kommentare, System-Aktionen und benutzerdefinierte Einträge |
| TicketAttachment | ticket_attachments | Dateianhänge an Tickets/Einträgen |
| EscalationRule | escalation_rules | SLA-Zeiten pro Queue + Priorität |
| NotificationType | notification_types | Definierte Benachrichtigungstypen |
| UserNotification | user_notifications | Pro-Benutzer Benachrichtigungseinstellungen (Typ, Modus, Intervall) |
| NotificationTemplate | notification_templates | E-Mail-Templates pro Benachrichtigungstyp und Sprache |
| SystemSetting | system_settings | Key-Value Store für Systemkonfiguration |
| TicketQueueChange | ticket_queue_changes | Protokoll der Queue-Wechsel mit Pflichtbegründung |
| EmailQueue | email_queue | Ausgehende E-Mails mit Zustellstatus und Retry-Zähler |
| EmailBlacklist | email_blacklist | Gesperrte E-Mail-Adressen und Domains |
| ProcessedEmail | processed_emails | Message-IDs bereits verarbeiteter E-Mails (Duplikat-Schutz) |
| AuditLog | audit_logs | Protokollierung aller Aktionen (GUI und API gleichermaßen) |
| DashboardEvent | dashboard_events | Ereignisse für Dashboard-Ansicht nach Login |
| CannedResponse | canned_responses | Vorgefertigte Antwort-Textbausteine pro Queue |
| PriorityRule | priority_rules | Regelbasierte Prioritätszuweisung pro Queue |
| TicketLink | ticket_links | Verknüpfungen zwischen Tickets (bezieht sich auf, blockiert von, Duplikat) |
| TicketWatcher | ticket_watchers | Beobachter-Zuordnung (m:n Benutzer ↔ Ticket) |
| TicketMerge | ticket_merges | Protokoll zusammengeführter Tickets (Quellticket → Hauptticket) |
| ScheduledStatusChange | scheduled_status_changes | Geplante zeitgesteuerte Statuswechsel pro Ticket |
| TicketTimelineEntry | ticket_timeline_entries | Kompakte Änderungschronik pro Ticket (automatisch generiert) |
| CustomField | custom_fields | Global definierte freie Felder (Name, Typ, Optionen, Pflicht) |
| CustomFieldQueue | custom_fields_queues | Matrix-Zuordnung: Welche freien Felder in welcher Queue aktiv sind |
| CustomFieldValue | custom_field_values | Gespeicherte Werte der freien Felder pro Ticket |
| Tag | tags | Vordefinierte und freie Schlagworte für Tickets |
| TicketTag | tickets_tags | m:n Zuordnung Ticket ↔ Tag |
| ChecklistTemplate | checklist_templates | Checklisten-Vorlagen mit Items |
| ChecklistTemplateQueue | checklist_templates_queues | Matrix-Zuordnung: Welche Vorlagen in welcher Queue aktiv sind |
| TicketChecklist | ticket_checklists | Checklisten-Instanzen pro Ticket (aus Vorlage oder individuell) |
| TicketChecklistItem | ticket_checklist_items | Einzelne Checkpunkte einer Ticket-Checkliste (mit Status, Benutzer, Zeitstempel) |
| EntryType | entry_types | System- und benutzerdefinierte Eintragstypen für Ticket-Einträge (Name, is_system, Sortierung) |
| Macro | macros | Aktionspakete mit konfigurierbaren Aktionen (Status, Eintrag, Priorität, Wiedervorlage, Tags, Zuweisung) |
| MacroQueue | macros_queues | Matrix-Zuordnung: Welche Makros in welcher Queue verfügbar sind |
| TicketType | ticket_types | Servicekatalog / Tickettypen (Name, Beschreibung, Sortierung, nie löschbar) |
| TicketTypeQueue | ticket_types_queues | Matrix-Zuordnung: Welche Tickettypen in welcher Queue verfügbar sind |
| ClosingReason | closing_reasons | Abschlussgründe bei Ticket-Abschluss (Name, anwendbare Status, Sortierung, nie löschbar) |
| ClosingReasonQueue | closing_reasons_queues | Matrix-Zuordnung: Welche Abschlussgründe in welcher Queue verfügbar sind |
| FieldRequirementRule | field_requirement_rules | Kontextabhängige Pflichtfeld-Regeln (Feld, Queue, Bedingung: immer/Status/Eintragstyp) |
| SavedFilter | saved_filters | Gespeicherte Filter/Views (persönlich oder global) |
| ApiToken | api_tokens | REST-API-Zugriffsschlüssel mit Queue-Zuordnung und Rate-Limit |
| SlaCalendar | sla_calendars | SLA-Kalender mit Name, Beschreibung, Zeitzone. Nie löschbar (siehe Plattform-Dokument, Kapitel 1.6) |
| SlaBusinessHour | sla_business_hours | Geschäftszeit-Fenster pro Kalender (Wochentag, Startzeit, Endzeit oder ganztägig). Mehrere Fenster pro Wochentag möglich |
| SlaExceptionList | sla_exception_lists | Ausnahmetag-Listen (Feiertage, Betriebsferien). Zuordnung zu Kalendern per m:n. Nie löschbar |
| SlaExceptionDay | sla_exception_days | Einzelne Ausnahmetage innerhalb einer Liste (Datum + Bezeichnung). Datum pro Liste eindeutig |
| SlaExceptionListCalendar | sla_exception_lists_calendars | m:n Zuordnung Ausnahmetag-Liste ↔ Kalender |

## 15.2 Beziehungen

-   Queue belongsTo QueueGroup (Pflicht)

-   Queue hasOne Eingangs-Mailbox (nullable, unique), belongsTo
    Ausgangs-Mailbox (Pflicht, nicht unique)

-   Queue belongsTo SlaCalendar (nullable -- NULL = 24×7-Betrieb,
    Beziehung nur bei installiertem Extension-Modul SLA-Kalender)

-   QueueGroup hasMany Queues

-   UserGroup belongsToMany Queues (m:n über user_groups_queues)

-   UserGroup belongsToMany Users (m:n über user_groups_users)

-   User belongsToMany UserGroups (effektiver Queue-Zugriff =
    Vereinigung aller Queues aller Gruppen)

-   Queue hasMany CannedResponses, PriorityRules, ApiTokens

-   User hasOne UserInvitation (nullable, nur bei per Einladung
    erstellten Benutzern)

-   Ticket belongsTo Queue, Priority, AssignedUser, SourceMailbox

-   Ticket hasMany TicketComments, TicketAttachments,
    TicketQueueChanges, TicketTimelineEntries

-   Ticket belongsToMany Watchers (m:n über ticket_watchers, Ziel:
    Users)

-   Ticket hasMany TicketLinks (als source_ticket und target_ticket)

-   Ticket hasOne ScheduledStatusChange (nullable, max. 1 aktiv)

-   Ticket hasMany TicketMerges (als main_ticket, Quelltickets verweisen
    hierher)

-   TicketComment belongsTo Ticket, User

-   TicketAttachment belongsTo Ticket, TicketComment

-   EscalationRule belongsTo Queue, Priority (unique: queue_id +
    priority_id)

-   UserNotification belongsTo User, NotificationType (unique: user_id +
    notification_type_id)

-   NotificationTemplate belongsTo NotificationType (unique:
    notification_type_id + locale)

-   EmailQueue belongsTo Ticket (nullable), User (nullable)

-   AuditLog belongsTo User (nullable, für API-Zugriffe:
    API-Token-Referenz statt User)

-   DashboardEvent belongsTo User, Ticket (nullable)

-   CannedResponse belongsTo Queue

-   PriorityRule belongsTo Queue, Priority

-   TicketLink: Bidirektionale Verknüpfung zweier Tickets
    (source_ticket_id, target_ticket_id, link_type)

-   TicketWatcher belongsTo Ticket, User (unique: ticket_id + user_id)

-   ScheduledStatusChange belongsTo Ticket, User (erstellt von). Max. 1
    aktiver pro Ticket

-   SavedFilter belongsTo User (nullable -- NULL = globale View)

-   TicketTimelineEntry belongsTo Ticket, User (nullable für
    System-Einträge)

-   ApiToken belongsTo Queue. Token-Wert nur bei Erstellung sichtbar

-   CustomField belongsToMany Queues (m:n über custom_fields_queues,
    Matrix-Konfiguration)

-   CustomFieldValue belongsTo Ticket, CustomField (unique: ticket_id +
    custom_field_id)

-   Tag hasMany TicketTags

-   Ticket belongsToMany Tags (m:n über tickets_tags)

-   ChecklistTemplate belongsToMany Queues (m:n über
    checklist_templates_queues, Matrix-Konfiguration)

-   TicketChecklist belongsTo Ticket, ChecklistTemplate (nullable für
    individuelle Checklisten)

-   TicketChecklistItem belongsTo TicketChecklist, User (abhakender
    Benutzer, nullable)

-   TicketComment belongsTo EntryType (Pflicht -- jeder Eintrag hat
    einen Typ)

-   EntryType hasMany TicketComments. Systemtypen: is_system = true,
    nicht löschbar

-   Macro belongsToMany Queues (m:n über macros_queues,
    Matrix-Konfiguration)

-   Ticket belongsTo TicketType (nullable), ClosingReason (nullable)

-   TicketType belongsToMany Queues (m:n über ticket_types_queues,
    Matrix-Konfiguration)

-   ClosingReason belongsToMany Queues (m:n über closing_reasons_queues,
    Matrix-Konfiguration)

-   FieldRequirementRule belongsTo Queue, verweist auf ein Feld
    (CustomField oder Standardfeld) mit Bedingungstyp und -wert

-   SlaCalendar hasMany Queues, hasMany SlaBusinessHours

-   SlaCalendar belongsToMany SlaExceptionLists (m:n über
    sla_exception_lists_calendars)

-   SlaBusinessHour belongsTo SlaCalendar (unique: calendar_id +
    weekday + start_time, keine Überlappung pro Wochentag)

-   SlaExceptionList hasMany SlaExceptionDays, belongsToMany
    SlaCalendars (m:n über sla_exception_lists_calendars)

-   SlaExceptionDay belongsTo SlaExceptionList (unique: list_id + date)

# 16. Sicherheitsanforderungen

## 16.1 Authentifizierung und Passwörter

-   Passwörter werden mit bcrypt gehasht (CakePHP
    DefaultPasswordHasher).

-   Passwort-Policy ist über Admin-GUI konfigurierbar: Mindestlänge
    (Default: 8), Großbuchstaben erforderlich (ja/nein), Kleinbuchstaben
    erforderlich (ja/nein), Zahlen erforderlich (ja/nein), Sonderzeichen
    erforderlich (ja/nein). Alle Regeln sind nach Installation
    standardmäßig aktiviert.

-   Session-Timeout nach Inaktivität: Konfigurierbar über Admin-GUI.
    Default: 30 Minuten.

-   Gast-Session-Timeout: Eigener konfigurierbarer Wert in
    config/app.php. Default: 15 Minuten. Nach Ablauf muss sich der Gast
    erneut mit Ticketnummer + E-Mail + CAPTCHA anmelden.

-   Passwort-Reset per Self-Service: Ein "Passwort vergessen"-Link auf
    der Login-Seite sendet einen zeitlich begrenzten Token
    (konfigurierbare Gültigkeitsdauer, Default: 1 Stunde) über die
    System-Mailbox. Der Link führt zu einem Formular zum Setzen eines
    neuen Passworts. Die konfigurierte Passwort-Policy gilt auch hier.

## 16.2 Gleichzeitige Bearbeitung

Das System schützt gegen Datenverlust bei gleichzeitiger Bearbeitung
desselben Tickets durch zwei Mechanismen:

-   Optimistic Locking: Beim Laden eines Tickets wird ein
    Versions-Zeitstempel gespeichert. Beim Speichern wird geprüft, ob
    sich das Ticket zwischenzeitlich geändert hat. Falls ja, wird eine
    Fehlermeldung angezeigt ("Ticket wurde zwischenzeitlich geändert")
    mit der Möglichkeit, die Seite neu zu laden.

-   Bearbeitungshinweis: Wenn ein anderer Benutzer das Ticket gerade
    geöffnet hat, wird ein visueller Hinweis angezeigt ("Benutzer X
    bearbeitet dieses Ticket gerade"). Die Erkennung erfolgt über kurze
    AJAX-Heartbeats. Speichern ist trotzdem möglich -- das Optimistic
    Locking fängt Konflikte ab.

## 16.3 Brute-Force-Schutz

-   Login: Temporäre Sperre mit progressiver Verzögerung (1s, 2s, 4s,
    8s\...). Nach X Fehlversuchen temporäre Account-Sperre für Y
    Minuten. X und Y konfigurierbar (Defaults: 5 Versuche, 15 Minuten).
    Audit-Log-Eintrag bei Sperre.

-   Gastzugang: Rate-Limiting per IP + CAPTCHA über Extension-Modul
    (optional). Rate-Limit konfigurierbar im Admin-Bereich.

## 16.4 Verschlüsselung

-   IMAP/SMTP-Passwörter: AES-256-GCM mit dediziertem Encryption-Key in
    config/app.php (getrennt vom Security.salt). CLI-Command bin/cake
    generate_encryption_key erzeugt einen sicheren Schlüssel.

-   Passwörter werden im Admin-Bereich nie im Klartext angezeigt. Felder
    sind beim Bearbeiten immer leer.

## 16.5 Weitere Sicherheitsmaßnahmen

-   CSRF-Schutz ist über CakePHP CsrfProtectionMiddleware aktiviert.

-   Input-Validierung und Output-Escaping gemäß CakePHP-Standards.

-   Datei-Uploads werden auf MIME-Typ (Whitelist) und Größe geprüft.

-   Der Gastzugang erfordert exakte Übereinstimmung von Ticketnummer UND
    E-Mail-Adresse.

-   Queue-Zugriffskontrolle wird auf Datenbankebene durchgesetzt (nicht
    nur im Frontend).

-   Admin-Routen sind über CakePHP Authorization abgesichert.

# 17. DSGVO und Datenlebenszyklus

## 17.1 Automatische Löschung

Pro Queue wird eine Aufbewahrungsdauer konfiguriert (in Monaten).
Geschlossene und abgebrochene Tickets, die diese Frist überschreiten,
werden automatisch per Hard-Delete gelöscht (Ticket, Einträge, Anhänge
inkl. Dateien auf dem Storage). Pro gelöschtem Ticket wird ein
Audit-Log-Eintrag erstellt (Ticketnummer, Queue, Löschzeitpunkt, Grund
"Automatische Löschung nach X Monaten"). Der CLI-Command bin/cake
purge_tickets führt die Bereinigung per Cronjob durch. Queues mit NULL
als Aufbewahrungsdauer behalten Tickets unbegrenzt.

## 17.2 DSGVO-Bereich im Admin

Der DSGVO-Bereich ermöglicht die manuelle Löschung personenbezogener
Daten auf Anfrage:

-   Suche nach E-Mail-Adresse oder Domain (z.B. \@firma.de löscht alle
    Tickets von dieser Domain).

-   Ergebnisanzeige: Alle betroffenen Tickets, Einträge, Anhänge
    aufgelistet.

-   Vorschau-Funktion: Zeigt, was gelöscht werden würde.

-   Bestätigungsdialog mit Pflichtbegründung (z.B. "DSGVO-Löschanfrage
    vom \...").

-   Hard-Delete aller betroffenen Daten inkl. Dateien.

-   Audit-Log-Eintrag pro gelöschtem Ticket.

Hinweis zu Benutzer-Accounts: Die Anonymisierung interner
Benutzer-Accounts im Rahmen des Rechts auf Löschung ist eine
Plattformfunktion und erfolgt gemäß Plattform-Dokument, Kapitel 27.15.3
(irreversible Anonymisierung der Identitätsfelder wie Vorname, Nachname,
E-Mail, Benutzername; technische ID und historische Referenzen bleiben
erhalten). Das Ticketing-Modul löscht keine Benutzer-Accounts, sondern
nutzt die zentrale Plattform-Anonymisierung. Die ticketing-spezifische
Löschung von Ticketdaten (Hard-Delete von Tickets, Einträgen und
Anhängen) bleibt davon unberührt und ist oben beschrieben.

# 18. Nicht-funktionale Anforderungen

| **Kategorie** | **Anforderung** |
| --- | --- |
| Performance | Ticketliste mit bis zu 10.000 Tickets muss in unter 2 Sekunden laden (paginiert) |
| Skalierbarkeit | Das Datenmodell unterstützt beliebig viele Queues, Benutzer und Tickets |
| Verfügbarkeit | Standard-Webserver-Setup (Apache/Nginx + PHP-FPM) |
| Wartbarkeit | Saubere MVC-Architektur gemäß CakePHP-Konventionen |
| Testbarkeit | Unit-Tests für Geschäftslogik (Statuswechsel, SLA-Berechnung, Ticketnummern, E-Mail-Klassifizierung) |
| Datenschutz | Passwörter gehasht, E-Mail-Zugangsdaten AES-256-GCM verschlüsselt, sensible Felder im API-Output versteckt, DSGVO-Löschfunktionen |
| Logging | Fehler und kritische Operationen werden in konfigurierbarem Log-Verzeichnis protokolliert. Verworfene E-Mails werden geloggt. |
| Audit | Hard-Deletes, Account-Sperren und sicherheitsrelevante Aktionen werden im Audit-Log protokolliert |
| Storage | Anhänge wahlweise auf lokalem Dateisystem oder S3-kompatiblem Storage |

# 19. Offene Punkte und Empfehlungen für spätere Versionen

| **Nr.** | **Thema** | **Beschreibung** |
| --- | --- | --- |
| 1 | Volltextsuche | Suche über Tickets, Einträge und Anhänge (z.B. Elasticsearch-Integration). Siehe auch das Skalierungsrisiko der LIKE-basierten Suche in Kapitel 12.5.1 |
| 2 | Erweitertes Reporting | Dedizierte Reporting-Ansicht mit konfigurierbaren Auswertungen, Diagrammen und geplanten PDF-Reports |
| 3 | LDAP/SSO-Integration | Plattformfähigkeit über austauschbaren Auth-Resolver (OIDC/SAML, Default lokal; Plattform-Dokument, Kapitel 27.2.2). Kein modul-eigenes Feature – das Modul erbt die Plattform-Authentifizierung |
| 4 | Wissensdatenbank-Integration | Anbindung einer Wissensdatenbank über ein Integrations-Extension-Modul. Das Ticketing-Main-Modul stellt dafür Contracts bzw. UI-Erweiterungspunkte bereit, über die strukturierte Wissensartikel-Treffer in die Ticketansicht eingebracht werden (Plattform-Dokument, Kapitel 26.3.4 und 29). Die Verknüpfung lebt im Integrationsmodul, nicht im Ticket-Datenmodell |
| 5 | E-Mail-Vorlagen | Konfigurierbare HTML-Layouts für die äußere Gestaltung aller ausgehenden E-Mails (Branding) |
| 6 | Zwei-Faktor-Authentifizierung | TOTP-basierte 2FA als Bestandteil der Plattform-Authentifizierung (Auth-Resolver, Plattform-Dokument, Kapitel 27.2.2), kein modul-eigenes Feature |
| 7 | Erweiterte REST-API | Webhooks für Ereignisbenachrichtigungen, erweiterte Schreibzugriffe (Statuswechsel, Einträge) für externe Systeme |
| 8 | Archivierung | Separate Archivtabellen für geschlossene Tickets bei hohem Datenvolumen |


# 20. Betrieb und Betreiberperspektive

Hinweis: Plattformweite Betriebsaspekte (Backup, System-Health,
Datenvolumen, Audit-Log, Betriebsgrenzen) sind im
Plattform-Anforderungsdokument (Kapitel 20) beschrieben. Die
folgenden Abschnitte beschreiben ticketing-spezifische
Betriebsaspekte.

(Hosting, Administration, Überwachung). Die Anforderungsklassifikation
(Plattform-Dokument, Kapitel 1.7) wird hier explizit angewendet, um Muss-Anforderungen an
die Software klar von Betriebsempfehlungen an den Betreiber zu trennen.

## 20.1 Backup und Wiederherstellung (Empfehlung)

Das System speichert Daten in zwei Bereichen, die beide gesichert
werden müssen. Das Backup selbst ist **keine Systemfunktion**, sondern
liegt in der Verantwortung des Betreibers:

-   **Datenbank (PostgreSQL):** Alle Tickets, Einträge, Konfigurationen,
    Benutzer, Audit-Log. Standard-PostgreSQL-Backup-Verfahren (pg_dump,
    Streaming-Replikation / Point-in-Time-Recovery) sind anwendbar.

-   **Datei-Storage:** Anhänge und Inline-Bilder (lokal oder S3).
    Der Speicherpfad ist in config/app.php konfiguriert.

Für eine konsistente Wiederherstellung müssen Datenbank und
Datei-Storage zum selben Zeitpunkt gesichert werden. Das System selbst
bietet keine integrierte Backup-Funktion; die Sicherung liegt in der
Verantwortung des Betreibers.

## 20.2 System-Health und Monitoring (Muss: Health-Collector-Beiträge / Empfehlung: Betreiber-Alerting)

Health-Endpoint und Observability-Infrastruktur sind Plattformfunktionen
(Plattform-Dokument, Kapitel 20.2). Das Ticketing-Modul stellt keinen
eigenen Monitoring-Endpunkt bereit, sondern liefert seine
modulspezifischen Prüfungen als Beiträge an den Health-Collector der
Plattform (Plattform-Dokument, Kapitel 20.2.2).

Vom Ticketing-Modul beigesteuerte Health-Checks (Muss):

-   Mailbox-Erreichbarkeit (IMAP/SMTP) pro aktiver Queue- und
    System-Mailbox
-   Fehlerstand der E-Mail-Versand-Queue (email_queue: fehlgeschlagen /
    Dead-Letter)
-   Aktualität der ticketing-spezifischen CLI-Worker (fetch_mails,
    check_escalations, process_email_queue, send_digest,
    process_scheduled_changes, send_followup_reminders, purge_tickets)

Diese Beiträge erscheinen im Plattform-Health-Endpoint (HTTP GET /health)
und in der Admin-Statusfläche (Plattform-Dokument, Kapitel 20.2.4).

Betreiberseitige Überwachung (Empfehlung): Die plattformweite Übersicht
der zu überwachenden Betriebszustände steht im Plattform-Dokument
(Kapitel 20.2.5). Ticketing-spezifisch sind insbesondere
Mailbox-Erreichbarkeit und der Fehlerstand der E-Mail-Versand-Queue.

## 20.3 Cronjob-Überwachung (Muss: Protokollierung / Soll: Dashboard-Widget)

Die CLI-Commands (siehe Kapitel 14) sind für den Betrieb essenziell.
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
erfolgreichen Lauf mit Zeitstempel in der Tabelle system_settings.

Dashboard-Widget (Soll): Ein Admin-Dashboard-Widget zeigt den Status
aller Commands an (letzte Ausführung, Dauer, Ergebnis). Bei
Überschreitung des erwarteten Intervalls um mehr als das Doppelte wird
ein visueller Warnhinweis im Admin-Dashboard angezeigt. Diese
Cron-Statusinformationen werden zugleich als Health-Collector-Beitrag an
die Plattform-Admin-Statusfläche gemeldet (Plattform-Dokument, Kapitel
20.2.4).

## 20.4 E-Mail-Betriebsüberwachung (Muss: Logging und Statusanzeige / Empfehlung: aktives Monitoring)

Da E-Mail der primäre Kanal ist (siehe Kapitel 1.3.1), erfordert
der Mailbox-Betrieb besondere Aufmerksamkeit:

-   **Fehlgeschlagene E-Mails:** Die email_queue-Tabelle zeigt den
    Zustellstatus aller ausgehenden E-Mails. E-Mails mit Status
    "fehlgeschlagen" nach Ausschöpfung aller Retries lösen eine
    Admin-Benachrichtigung aus (siehe Kapitel 3.11).

-   **Verworfene E-Mails:** E-Mails, die bei der Klassifizierung
    verworfen werden (OOF, Kalendereinladungen, DSN, Blacklist),
    werden im Applikationslog protokolliert (siehe Kapitel 3.4/3.5).
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
Kapitel 18, Nicht-funktionale Anforderungen):

-   Bis zu 10.000 offene Tickets mit Ladezeiten unter 2 Sekunden
    (paginiert)
-   Beliebig viele geschlossene Tickets (abhängig von
    Aufbewahrungsdauer und Hardware)
-   Beliebig viele Queues und Benutzer

Datenbank-Indizes (Muss): Das Datenmodell setzt Indizes auf alle
Fremdschlüssel und häufig gefilterte Felder (Status, Queue, Priorität,
Erstellungsdatum). Diese Indizes sind Teil des Datenbankschemas und
werden bei der Installation automatisch angelegt.

ticket_comments-Strategie (Muss): Die Eintragstabelle ticket_comments
hält alle Eintragstypen (E-Mails, Statuswechsel, Gast-Kommentare,
System-Aktionen, benutzerdefinierte Einträge) und ist damit die größte
und meistgelesene Tabelle des Systems. Für sie gilt zusätzlich: Indizes
auf ticket_id, Eintragstyp und is_public; Vermeidung von
Volltext-LIKE-Abfragen direkt auf dieser Tabelle (siehe Kapitel 12.5.1).
Bei hohem Datenvolumen wird eine deklarative Partitionierung nach
Zeitraum oder Ticket empfohlen (PostgreSQL, Plattform-Dokument, Kapitel
30.8).

Betreibermaßnahmen bei wachsendem Datenvolumen (Empfehlung):

-   **Aufbewahrungsdauer nutzen:** Pro Queue eine sinnvolle
    Aufbewahrungsdauer konfigurieren (Kapitel 4.1). Der Command
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
-   Datenbankpartitionierung nach Zeitraum (z.B. monatliche
    Partitionen)
-   Gezielte Indizierung für häufige Abfragen (nach Benutzer,
    Zeitraum, Entitätstyp)

Das Audit-Log selbst darf nicht im System gelöscht oder verändert
werden. Archivierung erfolgt außerhalb des Systems.

## 20.7 Explizite Betriebsgrenzen

Folgende Funktionen sind bewusst **nicht Teil des Systems** und liegen
in der Verantwortung des Betreibers oder externer Werkzeuge:

| **Thema** | **Systemleistung** | **Betreiberverantwortung** |
| --- | --- | --- |
| Backup/Restore | Konsistentes Datenmodell (DB + Storage) | Backup-Strategie, Scheduling, Aufbewahrung |
| Infrastruktur-Monitoring | Logging in konfiguriertes Verzeichnis | Überwachung, Alerting, Dashboards (Nagios, Zabbix etc.) |
| Hochverfügbarkeit | Standard-Webserver-Setup (Apache/Nginx + PHP-FPM) | Redundanz, Load-Balancing, Failover |
| Queue-Worker | CLI-Commands per Cronjob | Cronjob-Konfiguration und -Überwachung |
| E-Mail-Infrastruktur | IMAP-Abruf und SMTP-Versand | Mailserver-Betrieb, DNS (MX, SPF, DKIM), TLS-Zertifikate |
| Sicherheitsupdates | CakePHP und PHP-Abhängigkeiten | Betriebssystem, Webserver, PostgreSQL, PHP-Runtime |
| Log-Rotation | Schreiben in konfiguriertes Log-Verzeichnis | Log-Rotation, Archivierung, Speicherplatz |
| SIEM/Security-Audit | Audit-Log mit allen relevanten Aktionen | Integration in SIEM-Systeme |
| SSL/TLS-Terminierung | Keine (Anwendung liefert HTTP) | HTTPS-Terminierung über Reverse-Proxy |

# 21. Abnahmekriterien für Kernfunktionen

Dieses Kapitel definiert testbare Abnahmekriterien für die riskantesten
und komplexesten Funktionsbereiche. Jedes Kriterium ist eine konkrete
Erwartung, die bei der Abnahme verifiziert werden muss.

## 21.1 E-Mail-Threading

-   Eingehende E-Mail mit In-Reply-To-Header auf bekannte Message-ID
    wird dem korrekten Ticket zugeordnet
-   Eingehende E-Mail mit References-Header (ohne In-Reply-To) wird
    dem korrekten Ticket zugeordnet
-   Eingehende E-Mail ohne Header-Match, aber mit Ticketnummer im
    Betreff, wird dem korrekten Ticket zugeordnet (Fallback)
-   Eingehende E-Mail ohne Header-Match und ohne Ticketnummer im
    Betreff erzeugt ein neues Ticket
-   Bereits verarbeitete Message-ID wird nicht erneut verarbeitet
    (Duplikat-Schutz)
-   OOF-/Auto-Reply-E-Mails werden erkannt und verworfen (kein Ticket)
-   Kalendereinladungen und DSN werden erkannt und verworfen

## 21.2 Wiedereröffnung geschlossener Tickets

-   E-Mail auf geschlossenes Ticket (closed_success) setzt Status auf
    "pausiert"
-   E-Mail auf abgebrochenes Ticket (cancelled) setzt Status auf
    "pausiert"
-   Der letzte Bearbeiter bleibt zugewiesen
-   Die SLA-Zeiten laufen weiter (keine Neuberechnung, bisherige
    Pausenzeit bleibt)
-   Die E-Mail wird als neuer Eintrag angehängt
-   Eine Benachrichtigung wird an den zugewiesenen Benutzer gesendet

## 21.3 Queue-Wechsel mit SLA-Neuberechnung

-   Queue-Wechsel nur innerhalb derselben Queue-Gruppe möglich
-   SLA-Zielzeiten werden anhand der Eskalationsregeln der neuen Queue
    neu berechnet
-   SLA-Zielzeiten werden anhand des SLA-Kalenders der neuen Queue
    neu berechnet (falls abweichend)
-   Startzeitpunkt bleibt das ursprüngliche Erstellungsdatum
-   Bisherige Pausenzeit bleibt erhalten
-   Ausgangs-Mailbox wechselt auf die der neuen Queue
-   Ohne Neuzuweisung bei fehlendem Zugriff: Zuweisung wird aufgehoben,
    Status auf "neu"

## 21.4 Gastzugang

-   Zugriff nur mit korrekter Ticketnummer UND korrekter E-Mail
-   Falsches Paar liefert keine Information über Existenz des Tickets
-   Rate-Limiting per IP greift nach X Fehlversuchen
-   CAPTCHA wird erst nach Cookie-Consent geladen (sofern
    CAPTCHA-Modul installiert)
-   Gast sieht nur öffentliche Einträge (is_public = true)
-   Gast sieht keine internen Metadaten (Queue, Bearbeiter, Eskalation)
-   Gast kann öffentliche Einträge (Gast-Kommentar) hinzufügen
-   Ticketübersicht zeigt alle Tickets der E-Mail-Adresse

## 21.5 REST-API-Sichtbarkeit

-   API-Antwort enthält nur Daten, die auch im Gastzugang sichtbar
    wären
-   Interne Einträge, Queue-Zuordnung, zugewiesener Benutzer,
    Eskalationsinformationen sind nie in der API-Antwort enthalten
-   API-Token mit abgelaufenem Datum wird abgewiesen (HTTP 401)
-   API-Token mit überschrittenem Rate-Limit wird abgewiesen (HTTP 429)
-   Ticket-Erstellung setzt Erstellkanal auf "api"
-   Ticket-Erstellung sendet keine Autoantwort
-   Kundenreferenz-Duplikat liefert Fehlercode
    "customer_reference_exists" mit vorhandener Ticketnummer
-   Lookup mit E-Mail-Mismatch liefert identische Antwort wie bei
    nicht existierender Kundenreferenz (HTTP 404)

## 21.6 Pflichtfeld-Regeln

-   Globales Pflichtfeld (Felddefinition) wird in jeder Queue
    durchgesetzt, in der das Feld aktiv ist
-   Kontextabhängige Regel (Pflicht bei Status) greift nur beim
    konfigurierten Status
-   Kontextabhängige Regel (Pflicht bei Eintragstyp) greift nur beim
    konfigurierten Eintragstyp
-   Globales Pflichtfeld kann durch Regel nicht optional gemacht werden
    (strengste Anforderung gewinnt)
-   Speichervorgang wird bei nicht ausgefülltem Pflichtfeld blockiert
    mit verständlicher Fehlermeldung

## 21.7 Ticket-Zusammenführung (Merge)

-   Merge ist nur innerhalb derselben Queue-Gruppe zulässig
-   Alle Einträge und Anhänge der Quelltickets werden ins Hauptticket
    übernommen
-   Ursprüngliche Zeitstempel der übernommenen Einträge bleiben
    erhalten
-   Quelltickets erhalten Verweis auf Hauptticket
-   Gast-Login mit Quellticket-Nummer leitet auf Hauptticket um
-   Pflichtbegründung ist erforderlich

## 21.8 SLA-Kalender (nur bei installiertem Extension-Modul)

Die folgenden Kriterien gelten nur, wenn das Extension-Modul
SLA-Kalender installiert und aktiv ist (Plattform-Dokument, Kapitel 23.15.2):

-   Soll-Zeiten werden ausschließlich in Geschäftsminuten des
    zugeordneten Kalenders berechnet
-   Ausnahmetage überschreiben Geschäftszeit-Fenster vollständig
    (kein SLA an Feiertagen)
-   Ohne Kalender gilt 24×7 (Rückwärtskompatibilität)
-   Pausenzeit wird in Geschäftsminuten gemessen (nicht Wanduhr)
-   Bei Queue-Wechsel auf Queue mit anderem Kalender werden Zielzeiten
    auf Basis des neuen Kalenders neu berechnet
-   Deaktivierter Kalender führt zu 24×7-Verhalten für betroffene
    Queues

## 21.9 Hard-Delete und DSGVO

-   Hard-Delete entfernt Ticket, alle Einträge, alle Anhänge (inkl.
    Dateien auf Storage) und alle personenbezogenen Daten vollständig
-   Hard-Delete erzeugt einen Audit-Log-Eintrag (Ticket-ID,
    Ticketnummer, Admin, Zeitstempel, Begründung)
-   Pflichtbegründung ist erforderlich
-   DSGVO-Suche nach E-Mail-Adresse findet alle betroffenen Tickets
-   DSGVO-Suche nach Domain findet alle betroffenen Tickets
-   Vorschau zeigt betroffene Daten vor Löschung
-   Benutzer-Anonymisierung ersetzt personenbezogene Daten durch
    "Gelöschter Benutzer #ID"

# 22. Release-Kriterien Version 1.0

Version 1.0 ist releasefähig, wenn alle folgenden Kriterien erfüllt
sind. Jedes Kriterium ist eine Muss-Anforderung.

## 22.1 Kernfunktionen

-   E-Mail-Eingang: Abruf, Klassifizierung, Threading und
    Ticketerstellung funktionieren stabil über mindestens 48 Stunden
    Dauerbetrieb ohne manuelle Eingriffe
-   Ticketbearbeitung: Statuswechsel, Zuweisung, Queue-Wechsel,
    Prioritätswechsel und Eintragserfassung sind fehlerfrei bedienbar
-   E-Mail-Antworten: Ausgehende E-Mails werden korrekt threaded,
    enthalten Signatur und Ticketnummer und werden zuverlässig
    zugestellt
-   Gastzugang: Login, Ticketübersicht, Detailansicht und
    Gast-Kommentar-Erstellung sind stabil und sicher
-   REST-API: Ticket-Erstellung, Statusabfrage und Lookup per
    Kundenreferenz funktionieren gemäß Spezifikation

## 22.2 SLA und Eskalation

-   SLA-Zielzeiten werden bei Erstellung, Prioritätswechsel und
    Queue-Wechsel korrekt berechnet (ohne SLA-Kalender-Modul: 24×7)
-   Eskalations-Prüfung erkennt SLA-Verletzungen und sendet
    Benachrichtigungen
-   Falls Extension-Modul SLA-Kalender installiert: Geschäftsminuten-
    Berechnung korrekt (inkl. Ausnahmetage und Pausenzeit)
-   Ohne SLA-Kalender-Modul: 24×7-Betrieb als Resolver-Default
    funktioniert korrekt

## 22.3 Sicherheit und Compliance

-   Audit-Log protokolliert alle kritischen Vorgänge (Statuswechsel,
    Queue-Wechsel, Hard-Delete, Konfigurationsänderungen, Login-
    Versuche, API-Zugriffe)
-   DSGVO-Hard-Delete ist verifiziert (Ticket, Einträge, Anhänge,
    personenbezogene Daten vollständig entfernt)
-   Berechtigungsprüfung auf Datenbankebene: kein Zugriff auf Tickets
    außerhalb der eigenen Queues, auch nicht über direkte URL-Eingabe
-   Brute-Force-Schutz für Login und Gastzugang aktiv
-   CSRF-Schutz aktiv

## 22.4 Administration

-   Alle Admin-Bereiche gemäß Kapitel 12.9 sind vollständig bedienbar
-   Referenzanzeige vor Deaktivierung funktioniert
-   Mailbox-Verbindungstest funktioniert
-   Einladungsprozess für neue Benutzer funktioniert Ende-zu-Ende
-   Standard-Konfiguration nach Installation entspricht Kapitel 1.4

## 22.5 Abnahmekriterien

-   Alle Abnahmekriterien aus Kapitel 21 sind erfolgreich getestet
-   Kein offener Defekt mit Priorität "Kritisch" oder "Hoch"
-   Benachrichtigungen werden für alle konfigurierten Typen korrekt
    versendet


# 23. Mandantenfähigkeit

Das Modul ist mandantenfähig (fail-closed) gemäß Plattform-Entscheidung
185. Jede mandanten-tragende Tabelle trägt `tenant_id` + RLS;
Pre-Auth-Eintritte (REST-Token, Gastportal-Host) und Hintergrund-Worker
setzen bzw. iterieren den Mandantenkontext; Ticketnummern sind pro
Mandant eindeutig. Queue-Gruppen bleiben Bereichstrennung innerhalb eines
Mandanten. Die technische Spezifikation und die Abnahme
(NOBYPASSRLS-Leak-Tests) stehen in der Modul-Spezifikation §9.


## Anhang A: Versionshistorie

| **Version** | **Datum** | **Änderung** |
| --- | --- | --- |
| 1.0 | 31.03.2026 | Initiale Erstellung des Anforderungsdokuments |
| 2.0 | 31.03.2026 | Konkretisierung aller offenen Punkte (26 Entscheidungen) |
| 2.1 | 31.03.2026 | Gastzugang erweitert: Zweigeteiltes Portal mit Ticketübersicht |
| 2.2 | 31.03.2026 | UI-Layout konkretisiert: Ticket-Detail (1/3+2/3), Listen-Detail (gedrittelt) |
| 3.0 | 31.03.2026 | 13 neue Features: Textbausteine, Auto-Zuweisung, Prioritätsregeln, Ticket-Merge, Verknüpfungen, Watch, \@Mention, geplante Statuswechsel, gespeicherte Filter, Timeline, Tastaturnavigation, CSV/Excel-Export, Lesebestätigung für Gäste |
| 3.1 | 31.03.2026 | REST-API für externe Systeme (Kapitel 3.12): Token-basierte Authentifizierung mit Queue-Bindung, Ticketerstellung, Statusabfrage, Kundenreferenz, Lookup, Rate-Limiting, Erstellkanal-Feld, GUI-Duplikatprüfung |
| 3.2 | 31.03.2026 | Konkretisierung: Sidebar für Ticket-Metadaten, Freitextsuche (UND-verknüpft), Massenaktionen (nur Admin), Dateivorschau, Passwort-Reset, Eskalations-Vorwarnung, Gast-Session, Optimistic Locking + Bearbeitungshinweis, Original-E-Mail-Speicherung, Inline-Bilder, Smartphone-Optimierung Gastzugang |
| 3.3 | 31.03.2026 | Benutzererstellung ausschließlich per Admin-Einladung: Einladungs-E-Mail mit zeitlich begrenztem Link, Einladungs-Dashboard, Benutzer nie löschbar (nur deaktivierbar), DSGVO-Anonymisierung für Benutzer-Accounts |
| 3.4 | 31.03.2026 | Freie Felder (global definiert, per Matrix pro Queue aktivierbar), Tags/Schlagworte (vordefiniert + frei mit Lookup), interne Checklisten (Vorlagen per Matrix + individuelle Items), Wiedervorlage/Follow-up, Matrix-Konfigurationsprinzip (Kapitel 1.5) |
| 3.5 | 31.03.2026 | Eintragstypen (Kapitel 6.6): System- und benutzerdefinierte Typen für Ticket-Einträge, Pflichtfeld bei manuellen Einträgen, filterbar in Aktivitätenliste. Überarbeitung Aktivitätenliste: Ticket-Betreff als fixer Header, schlanke Darstellung (Datum, Typ, Ersteller, Sichtbarkeit), keine Inhalts-Vorschau |
| 3.6 | 31.03.2026 | Klare Trennung Ticket-Ebene vs. Eintrag-Ebene. Sidebar gegliedert in 4 Bereiche: Stammdaten+Personen+SLA, Referenzen+Termine, Checklisten, Tags+Freie Felder. Detailbereich explizit als Eintrag-Ebene gekennzeichnet |
| 3.7 | 31.03.2026 | Makros/Aktionspakete (optional, per Matrix pro Queue, Vorschau+Bestätigung). Textbausteine um Kontextfilter erweitert (Status, Priorität, Tags). Ähnliche Tickets als 5. Sidebar-Bereich (Requester-E-Mail, Kundenreferenz) |
| 3.8 | 31.03.2026 | Governance: Pflichtfeld-Regeln (kontextabhängig per Matrix), Abschlussgründe (global definiert, per Matrix pro Queue, Pflicht bei Abschluss), Tickettypen/Servicekatalog (per Matrix pro Queue, änderbar). Generelle Anforderung: Konfigurierbare Werte nie löschbar, nur deaktivierbar, Audit-Log für Änderungen, keine Duplikate (Plattform-Dokument, Kapitel 1.6) |
| 3.9 | 31.03.2026 | Bereichstrennung: Queue-Gruppen (Queue-Wechsel nur innerhalb Gruppe), Benutzergruppen (indirekte Queue-Berechtigung, keine direkte Zuordnung). Eingangs-Mailbox optional+unique, Ausgangs-Mailbox Pflicht+mehrfach nutzbar. Admin-Dashboard: Ticket-Sichtbarkeit nur über Gruppenzuordnung, Admin-Funktionen uneingeschränkt |
| 4.0 | 31.03.2026 | Konsistenz-Review und Schärfung: CRUD- Formulierungen bereinigt (nie löschen, nur deaktivieren gemäß 1.6). Prioritäten-Regel an 1.6 angeglichen. "Mandantentrennung" ersetzt durch "Bereichstrennung". REST-API als offener Punkt entfernt (bereits in 3.14 spezifiziert), ersetzt durch "Erweiterte REST-API". API- Parameterliste um ticket_type, custom_fields und attachments ergänzt. Merge-Regel präzisiert (nur innerhalb Queue-Gruppe, auch für Admins). Wiedervorlage ohne Zuweisung geregelt. Pflichtfeld-Prioritätsregel ergänzt. Sichtbarkeitsmatrix für Eintragstypen zentral definiert. Massenaktionen und Queue-Gruppen-Regel ergänzt. Initiale Nachricht für alle Erstellkanäle geklärt. Freitextsuche als DB-LIKE-basiert dokumentiert. API-Fehlersemantik für Lookup ergänzt. Referenzfehler 12.4.3 korrigiert |
| 4.1 | 01.04.2026 | SLA-Kalender (Kapitel 7.7--7.9): Kalender mit beliebig vielen Geschäftszeit-Fenstern pro Wochentag, Ausnahmetag-Listen (m:n zu Kalendern), Queue-Zuordnung per Select-Feld (nullable = 24×7). SLA-Berechnung auf Geschäftsminuten umgestellt (Soll-/Ist-Zeiten, Pausenzeit konsistent in Geschäftsminuten). Neuberechnung bei Ticketerstellung, Prioritätswechsel und Queue-Wechsel. Ist-Zeiten bei erster Zuweisung und Abschluss (alle drei Abschluss-Status). SLA-Auswertung pro Queue im Admin-Dashboard. Datenmodell um 5 Entitäten erweitert (SlaCalendar, SlaBusinessHour, SlaExceptionList, SlaExceptionDay, SlaExceptionListCalendar). Queue-Eigenschaft SLA-Kalender ergänzt. Kapitel 7.4 und 7.5 um Kalender-Aspekt aktualisiert. Admin-Bereich um SLA-Kalender, Ausnahmetag-Listen und SLA-Auswertung erweitert |
| 4.2 | 01.04.2026 | Redaktionelle Bereinigung und Konsistenz-Review: API-Lookup Fehlersemantik vereinheitlicht (immer HTTP 404 bei Mismatch, kein Informationsleck). Einladungs-Widerruf als explizite Ausnahme zu 1.6 definiert. Verweis 5.2 korrigiert (3.12.7 → 3.14.7). Automatische Zuweisung (4.5) an Benutzergruppen-Modell angepasst. Dashboard-Statistiken (12.2) an Admin-Sichtbarkeitsregel (2.5) angeglichen. Eintragstyp-Bezeichnungen in 5.1/5.6 an neues Modell (6.6) angepasst. Mailbox-Typen (3.1) an Eingangs-/Ausgangs-Modell (4.1) angeglichen. Nummerierungen in 3.6 und 5.1 korrigiert. Beschädigte Tabellen in 6.6.1, 7.1, 7.9, 12.9 und 15.1 repariert |
| 4.3 | 01.04.2026 | Begriffsvereinheitlichung: "Kommentar" systematisch durch "Eintrag" ersetzt, wo fachlich zutreffend (Kapitel 3.7, 3.8, 3.9, 3.14, 4.7, 5.2, 5.7, 5.8, 6 Überschrift, 6.4, 6.5, 9.5, 12.3, 12.5, 12.6, 15.1, 17). TicketComment als technischer Persistenzname gegen fachliches Modell "Eintrag" abgegrenzt. Tippfehler korrigiert: 6.1 "ursprünglich" → "ursprüngliche", 7.7.3 "FK SlaException List" → "FK SlaExceptionList" |
| 4.3.1 | 01.04.2026 | Abschließende Begriffsbereinigung: Kapitel 19 "Kommentare" → "Einträge". Gast-Kommentar in 2.1, 6.3, 9.5.3 als spezieller Eintragstyp klargestellt. Lesebestätigung (9.5.4) auf Eintrag-Terminologie umgestellt. @Mention (8.1) auf "internen Eintrag" angepasst. Gast-Ansicht (12.8) aktualisiert. Entscheidungsprotokoll (Anhang B) mit Hinweis auf historischen Wortlaut versehen |
| 4.3.2 | 01.04.2026 | Letzte Sprachfeinheiten: 3.14.4 "Öffentliche Kommentare" → "Öffentliche Einträge". 2.1/2.5 "kommentieren" → "Einträge hinzufügen". 2.8 "Kommentare" → "Einträge" in Referenzintegrität und Einladungs-Ausnahme. 4.5 "Systemkommentar" → "System-Eintrag". 5.6 Pflichtkommentar als UI-Begriff gegen Datenmodell-Begriff "Eintrag" abgegrenzt |
| 5.0 | 01.04.2026 | Produktschärfung: Kapitel 1.7 Produktpositionierung und Leitprinzipien (E-Mail first, selbst hostbar, 7 verbindliche Prinzipien, bewusste Nicht-Ziele). Kapitel 1.4 Standard-Konfiguration nach Installation (Defaults für Prioritäten, Tickettypen, Abschlussgründe, Eintragstypen, Sicherheit, Ticketnummern). Kapitel 12.10 UX-Prinzipien (6 verbindliche Gestaltungsleitlinien + Admin-UX-Standards). Kapitel 20 Betrieb und Betreiberperspektive (Backup, System-Health, Cronjob-Überwachung, E-Mail-Betrieb, Datenvolumen, Audit-Log-Betrieb) |
| 5.1 | 01.04.2026 | Von Produktdokument zur testbaren Spezifikation: Kapitel 1.9 Anforderungsklassifikation (Muss/Soll/Empfehlung/Spätere Version). Kapitel 1.10 Architekturprinzipien (9 verbindliche technische Prinzipien). Kapitel 20 durchgängig mit Klassifikation versehen, 20.7 Explizite Betriebsgrenzen ergänzt. Kapitel 12.10 um verbindliche Admin-Detailanforderungen erweitert (Referenzanzeige, Warnstufen, "zuletzt geändert", globale Suche). Kapitel 12.11 Reporting und operative Steuerung (Pflicht- und Soll-Kennzahlen). Kapitel 21 Abnahmekriterien für 9 Kernfunktionen (E-Mail-Threading, Wiedereröffnung, Queue-Wechsel, Gastzugang, API, Pflichtfelder, Merge, SLA-Kalender, DSGVO). Kapitel 22 Release-Kriterien v1.0. Letzte Kommentar→Eintrag-Reste bereinigt. Produktstart-Formulierung präzisiert |
| 5.1.1 | 01.04.2026 | Endpolitur: 20.3 Muss/Empfehlung-Widerspruch aufgelöst (Protokollierung = Muss, Widget = Soll). 20.5 Datenbank-Indizes als verbindliche Muss-Anforderung von Betreiberempfehlungen getrennt. 1.8 Mailbox-Anforderung präzisiert (Eingangs-Mailbox + Ausgangs-Mailbox). 6.1/6.2 letzte "Statuswechsel-Kommentare" → "Statuswechsel-Einträge" |
| 5.2 | 01.04.2026 | Kapitel 23 Modulare Plattformarchitektur: Core-Plattform, Main-Module, Extension-Module, Resolver/Collector/Event-Mechanismen, Contracts, Registry, Marketplace, Lizenzierung, Modul-Lifecycle, BREAD-Berechtigungssystem, Delete-Semantik, Abhängigkeiten, 12 Architekturprinzipien, Beispiel Ticketing mit 3 Extensions. Konsistenzanpassungen in Kapitel 1.3, 1.4, 1.6, 1.10, 2 (Rollen), 7.7 (SLA-Kalender), 9.2/9.3 (CAPTCHA), 12.9, 15.1 (Datenmodell), 16.3, 21.4 für modularen Kontext |
| 5.3 | 01.04.2026 | Kapitel 24 Modul-Manifest, Paketstruktur und Installations-/Updatefluss: Paketformat und -struktur, verbindliches Manifest mit Pflicht-/Optionalfeldern, Typisierung (Main/Extension), Contract- und Registrierungsdeklaration, Lizenzinformationen, Signaturprüfung, Installations-/Aktivierungs-/Deaktivierungs-/Update-/Löschfluss mit konkreten Schrittfolgen, Kompatibilitätsprüfung, Auditierbarkeit, beispielhafte Manifestinhalte |
| 5.4 | 01.04.2026 | Plattform-Dokument, Kapitel 25 BREAD, Ressourcenmodell und Gruppenzuordnung: BREAD-Grundmodell mit Semantik der 5 Standardrechte, Ressourcenmodell (3 Ressourcentypen), generische Gruppenzuordnung, additive Rechteaggregation ohne Deny/Prioritäten, Zusatzaktionen, Main-/Extension-Berechtigungen, keine implizite Rechtevererbung, gruppenfähige vs. nicht gruppenfähige Ressourcen, Admin-Darstellung, serverseitige Laufzeit-Rechteprüfung, Auditierbarkeit, 10 Architekturprinzipien, Beispiele Ticketing/SLA-Kalender/Feiertagskalender |
| 5.5 | 01.04.2026 | Kapitel 26 Contracts, Resolver, Collector und Events: Formale Contract-Typen (Resolver/Collector/Event), Contract-Aufbau mit 10 Pflichtfeldern, Interface-Spezifikation (typisiert, maschinenlesbar), Contract-Versionierung (Patch/Minor/Major), Resolver-Slots (exklusiv), Collector-/Event-Verhalten, Registrierung von Contracts und Providern, Registry im Admin-Bereich, Aktivierungs-Validierung, Deaktivierungs-/Lizenzablauf-Verhalten, Fehlerverhalten zur Laufzeit (Resolver/Collector/Events), Auditierbarkeit, 3 beispielhafte Anwendungen (SLA, Feiertag, CAPTCHA), 10 Architekturprinzipien |
| 5.6 | 02.04.2026 | Kapitel 27 Benutzer, Gruppen, Rollen und Berechtigungsmodell der Plattform: Core-Identitätsmodell (Benutzer mit 11 Eigenschaften, Gruppen mit 6 Eigenschaften), Zwei-Ebenen-Berechtigungsmodell (Core-Rolle vs. Modulberechtigungen), Gruppen-Lifecycle (Aktivierung/Deaktivierung ohne Datenverlust), Benutzer-Lifecycle (Deaktivierung mit Erhalt historischer Referenzen), Ressourcenzuordnung (5 Pflichtfelder), Rechteaggregation (additiv, keine Deny), serverseitige Laufzeit-Prüfung, Auditierbarkeit (6 Vorgangstypen), 12 Architekturprinzipien. Querverweise auf Plattform-Dokument, Kapitel 25 für BREAD-Details, auf Plattform-Dokument, Kapitel 23.3 für Core-Funktionsbereiche |
| 5.7 | 02.04.2026 | Kapitel 28 Core-Update, Modul-Update, Signaturprüfung und Marketplace-Kommunikation: 5 Update-Arten, Marketplace als autoritative Quelle, Marketplace-Kommunikation (verschlüsselt, herkunftsverifiziert), Signaturprüfung (vor Entpacken), Lizenzprüfung (blockiert Aktivierung nicht Daten), Core-Update (12 Schritte), Modul-Update (15 Schritte), Sicherheitsupdates, Wartungsmodus, Kompatibilitätsprüfung (7 Prüfpunkte), Inkompatibilitäts-Verhalten, atomarer Abschluss, Lizenzablauf-Deaktivierung, Update-Historie (7 Felder), Admin-Oberfläche, 10 Architekturprinzipien |
| 5.8 | 02.04.2026 | Widerspruchsprüfung und 7 Konsistenz-Fixes: (1) Admin-Ticketzugriff: 23.3.1 präzisiert (Core-Vollzugriff ≠ automatischer Modul-Datenzugriff), 12.1 Export an 2.5 angeglichen. (2) Queue FK SlaCalendar: als Extension-Modul-Migration gekennzeichnet, nicht als Main-Modul-Schema. (3) Release-Kriterien 22.2: SLA-Kalender als bedingte Kriterien (nur bei installiertem Extension-Modul). (4) Benutzergruppen/Gruppen: Terminologie-Bridge in 2.3 (Benutzergruppen = Core-Gruppen mit Queue-Zuordnung als Ticketing-spezifische Ressourcenzuordnung). (5) Update-Schritte: 24.13, 28.8 und 28.9 reconciliert (Migrationsvorschau, Contract-Prüfung durchgängig). (6) 1.6 Entitätenliste: Extension-Modul-Entitäten (SLA-Kalender, Ausnahmetag-Listen) von Ticketing-Main-Modul-Entitäten getrennt. (7) Gast-Rolle: 27.3.3 ergänzt (Gast = modulspezifisches Zugriffskonzept, keine Core-Rolle) |
| 5.9 | 03.04.2026 | Kapitel 29 Öffentliche Modul-Interfaces und modulübergreifende Integrationen: Abgrenzung zu Contracts (Kap. 26), Grundbegriffe (6 Definitionen), Zielmodell (Main-Module als fachliche Tower), Interface-Anforderungen (10 Pflichtfelder), formale Interface-Spezifikation (Input/Output), Versionierung (Patch/Minor/Major konsistent mit 26.6.3), Nutzungsdeklaration, Integrations-Extension-Module, Datenhaltung (Integrationsbeziehungen im Extension-Modul nicht im Main-Modul), 6 Integrationsregeln, Interface-Registry im Admin-Bereich (11+6 Felder), Kompatibilitätsprüfung (6 Prüfpunkte), Deaktivierungsverhalten (Anbieter/Nutzer), Auditierbarkeit (6 Vorgangstypen), Beispiel Ticketing+Wissensdatenbank, 12 Architekturprinzipien |
| 6.0 | 03.04.2026 | Architektur-Review und Konsolidierung: (1) Plattform-Dokument, Kapitel 23.5 restrukturiert: zwei Extension-Modul-Typen (regulär = genau ein Main-Modul, Integration = mehrere Main-Module über öffentliche Interfaces). 23.14 Architekturprinzipien aktualisiert. (2) Kapitel 24.4.3 Manifest um public_interfaces_provided, public_interfaces_used, integration_relations ergänzt. 24.5.2 um Integrations-Extension-Modul-Typ erweitert. (3) Kapitel 28.12.1 Kompatibilitätsprüfung um öffentliche Interface-Versionen und Integrationsbeziehungen ergänzt. (4) Kapitel 29.3.4 explizite Abgrenzungsregel Contracts vs. öffentliche Modul-Interfaces ergänzt ("ergänzen, nicht ersetzen"). (5) Kapitel 29.8.1 Mehrfachnutzung präzisiert (Standard=erlaubt, Einschränkung explizit, Konsistenz beim Anbieter). (6) Kapitel 27 redaktionell entflechtet: 27.9-27.13, 27.17 und 27.19 von Duplikaten bereinigt und durch Verweise auf Plattform-Dokument, Kapitel 25 ersetzt (~80 Zeilen reduziert, keine Informationsverluste) |
| 6.1 | 03.04.2026 | Zugriffsschutz für Contracts und öffentliche Modul-Interfaces: (1) Kapitel 24.4.3 um used_contracts ergänzt. 24.7.2 Deklaration angebotener/genutzter Contracts und Interfaces mit Pflichtangaben. 24.7.3 Regel nach Modultyp (Main-Module: used_contracts und used_public_interfaces müssen leer sein; Extension-Module: beides zulässig). (2) Kapitel 26.13.2 Registrierte Nutzung zur Laufzeit: Laufzeit-Guard prüft aufrufendes Modul, Ziel-Contract, Registrierung, Aktivstatus und Version. 26.13.3 Verhalten bei Abweisung: aufrufendes Modul verpflichtet zur kontrollierten fachlichen Behandlung. (3) Kapitel 29.8.3 Registrierte Nutzung zur Laufzeit für öffentliche Interfaces analog zu 26.13.2. 29.8.4 Verhalten bei Abweisung analog zu 26.13.3 |
| 6.2 | 04.06.2026 | Alignment auf Plattform v6.25 (Architektur-Review): A1 Observability (20.2 als Health-Collector-Beiträge statt eigenem Endpoint, 20.3 Cron-Status an Plattform-Statusfläche), A2 Scoped-Admin (2.1/2.5/12.9 Administrationsbereiche), A3 DSGVO-Anonymisierung auf Plattform 27.15.3 verwiesen (17.2), A4 Benachrichtigungen als Event-Listener über Plattform-Outbox (Kap. 8), A5 email_queue als Spezialisierung des Plattform-Jobsystems (3.11), A6 Rechte-Granularität BREAD/Zusatzaktionen (2.4.1), A7 SSO und A9 2FA als Plattform-Auth-Resolver (1.3.3, 19.3/19.6), A8 API-Auth-Abgrenzung (3.14.2), A10 Wissensdatenbank als Integrations-Extension (19.4). R1 Volltextsuche-Skalierungshinweis (12.5.1), R2 ticket_comments-Strategie (20.5). Modul-Entscheidungen 107–118 ergänzt. Cleanup Anhang A (doppelte Versionszeilen 5.0/5.1/5.1.1 entfernt) und Anhang B (16 fälschlich hineinkopierte Fremdzeilen vor Entscheidung 1 entfernt – Konfig-Fragmente, duplizierte Offene-Punkte- und Versionshistorie-Zeilen) |
| 6.3 | 04.06.2026 | DB-Umstellung auf PostgreSQL (Plattform-Entscheidung 173): Backup (20.1: pg_dump / PITR) und Betriebsgrenzen (20.7) angepasst. Keine fachliche Änderung am Datenmodell. Modul-Entscheidung 119 ergänzt |
| 6.4 | 04.06.2026 | PostgreSQL-Leverage im Modul (P8/P9): Freitextsuche auf native PostgreSQL-Volltextsuche (tsvector/GIN) umgestellt (12.5.1, löst R1-Skalierungsrisiko), Exclusion-Constraint für überlappungsfreie SLA-Geschäftszeitfenster (7.7.2). R2-Hinweis um deklarative Partitionierung (Plattform 30.8) ergänzt. Modul-Entscheidungen 120/121 ergänzt |
| 6.5 | 15.06.2026 | Mandantenfähigkeit umgesetzt (Plattform-Entscheidung 185): neues Kapitel „Mandantenfähigkeit" (Verweis auf Modul-Spezifikation §9). Nicht-Ziel „keine echte Mandantenfähigkeit" (1.3.3 / Entscheidung 101) gestrichen; die Changelog-4.0-Aussage „Mandantentrennung ersetzt durch Bereichstrennung" ist damit überholt (Queue-Gruppen = Bereichstrennung innerhalb eines Mandanten, orthogonal). Modul-Entscheidung 122 ergänzt |

## Anhang B: Entscheidungsprotokoll

Die folgenden Entscheidungen wurden im Rahmen der
Ticketing-Modul-Anforderungsanalyse getroffen. Hinweis: Ältere
Einträge verwenden teilweise noch den Begriff "Kommentar", wo das
aktuelle Modell von "Eintrag" spricht (siehe Kapitel 6). Die
Formulierungen spiegeln den Wortlaut zum Zeitpunkt der jeweiligen
Entscheidung wider.

| **Nr.** | **Thema** | **Entscheidung** |
| --- | --- | --- |
| 1 | E-Mail-Threading | Header (In-Reply-To/References) zuerst, Betreff-Suche als Fallback |
| 2 | Antwort auf geschlossenes Ticket | Automatisch wiedereröffnen, Status "pausiert", SLA läuft weiter |
| 3 | Spam/Duplikate | Message-ID-Prüfung + Blacklist + Spam-Button im Ticket |
| 4 | Loop-Prevention | E-Mail-Klassifizierung (OOF, Kalender, DSN filtern) + Rate-Limiting |
| 5 | Fehlgeschlagener Mailversand | Retry-Queue + Statusanzeige im Ticket + Admin-Benachrichtigung |
| 6 | Manuelle Ticketerstellung | Ja, mit optionalem E-Mail-Versand an Requester, ohne Autoantwort |
| 7 | Ticket-Löschung | Soft-Delete + Hard-Delete + Audit-Log-Eintrag |
| 8 | Queue-Wechsel + Benutzer | Optionale Neuzuweisung aus Ziel-Queue |
| 9 | SLA bei Queue-Wechsel | Neuberechnung ab ursprünglichem Erstellungsdatum |
| 10 | Ticketnummern-Reset | Konfigurierbar, bei Reset: Validierung auf Jahres-Platzhalter |
| 11 | Format-Änderung | Sofort wirksam, Sequenz weiter, Duplikat-Prüfung + Vorschau |
| 12 | Gastzugang Brute-Force | Rate-Limiting per IP + reCAPTCHA bei jedem Zugriff |
| 13 | Consent-Banner | Immer, readonly Switch für System-Cookies, kein separater reCAPTCHA-Switch |
| 14 | Gast-Portal | Zweigeteiltes Portal: Ticketübersicht + Detailansicht + Kommentarfunktion + Lesebestätigung |
| 15 | Anhang-Dateigröße | Konfigurierbar über Admin-GUI |
| 16 | Anhang-Dateitypen | Whitelist-Modus, konfigurierbar über Admin-GUI |
| 17 | Anhang-Speicherort | Konfigurierbar (Dateisystem/S3), Anbindung in app.php |
| 18 | Benachrichtigungs-Versand | Pro Benutzer: sofort oder Digest + Dashboard-Events |
| 19 | Requester-Benachrichtigung | Nein, nur interne Benutzer |
| 20 | Benachrichtigungs-Templates | Frei konfigurierbar (HTML) über Admin-GUI, Defaults vorinstalliert |
| 21 | Passwort-Policy | Konfigurierbar: Mindestlänge + Komplexitätsregeln über Admin-GUI |
| 22 | Session-Timeout | Konfigurierbar über Admin-GUI, Default 30 Minuten |
| 23 | Brute-Force Login | Progressive Verzögerung + temporäre Sperre, konfigurierbar |
| 24 | Mailbox-Passwort-Verschlüsselung | AES-256-GCM, dedizierter Key in app.php |
| 25 | Datenlebenszyklus | Auto-Hard-Delete pro Queue (konfigurierbar) + DSGVO-Bereich |
| 26 | Mailbox-Passwörter Anzeige | Nie im Klartext, nur überschreiben |
| 27 | Vorgefertigte Antworten | Textbausteine pro Queue, Platzhalter, sortierbar, Admin-verwaltbar |
| 28 | Automatische Zuweisung | Pro Queue aktivierbar: Round-Robin oder Least-Load |
| 29 | Regelbasierte Priorität | Pro Queue konfigurierbar: Keyword/E-Mail/Domain → Priorität |
| 30 | Ticket-Merge | Zusammenführung: Quelltickets → Hauptticket, Kommentare/Anhänge übernommen |
| 31 | Ticket-Verknüpfung | Bidirektional: bezieht sich auf, blockiert von, Duplikat von |
| 32 | Ticket beobachten | Watch-Funktion + eigene Dashboard-View, Benachrichtigungen wie Zugewiesener |
| 33 | \@Mention | In internen Kommentaren, Autovervollständigung, Benachrichtigung |
| 34 | Geplante Statuswechsel | Zeitgesteuert, Bedingung always/no_activity, CLI-Command |
| 35 | Gespeicherte Filter | Persönliche + globale Views, in Seitenleiste |
| 36 | Ticket-Timeline | Separate kompakte Änderungschronik, automatisch, nicht editierbar |
| 37 | Tastaturnavigation | Pfeiltasten, Enter, Tab, R, S, Esc -- Hilfe über "?" |
| 38 | CSV/Excel-Export | Aus jeder Ticketübersicht, CSV (UTF-8 BOM) und XLSX |
| 39 | Lesebestätigung Gäste | Anzeige ob öffentlicher Kommentar vom Bearbeiter gelesen wurde |
| 40 | REST-API | Optionale REST-API für externe Ticketerstellung und lesenden Statusabruf (öffentliche Daten) |
| 41 | API-Token Queue-Bindung | Jeder Token ist an eine Queue gebunden, Queue muss bei Erstellung nicht angegeben werden |
| 42 | API-Zugriffs-Logging | Im gleichen Audit-Log wie GUI-Zugriffe |
| 43 | API Rate-Limiting | Konfigurierbar pro Token (Requests/Minute) |
| 44 | Kundenreferenz | Systemweit eindeutig, optional, in GUI und API pflegbar, Duplikatprüfung vor Speicherung |
| 45 | Erstellkanal | Neues Feld: email / api / manual -- filterbar in Ticketliste |
| 46 | Ticket-Metadaten | Sidebar rechts neben dem Hauptbereich (Ticketnr, Status, Priorität, Queue, Bearbeiter, Requester, SLA, etc.) |
| 47 | Freitextsuche | Ein Suchfeld, UND-verknüpft über alle setzbaren Felder + strukturierte Filter kombinierbar |
| 48 | Massenaktionen | Nur für Admins im Admin-Menü (Zuweisen, Priorität, Queue, Status, Soft-Delete) |
| 49 | Dateivorschau | Bilder + PDF inline im Browser, Rest nur Download |
| 50 | Passwort-Reset | Self-Service per E-Mail (über System-Mailbox, zeitlich begrenzter Token) |
| 51 | Ersteinrichtung | Kein Wizard -- manuelle Konfiguration über Admin-Bereich |
| 52 | Autoantwort API | Nie bei API-Erstellung, externes System kommuniziert selbst |
| 53 | Eskalations-Vorwarnung | Konfigurierbar pro Regel (Minuten oder Prozentsatz vor Ablauf) |
| 54 | Gast-Session | Eigener Timeout, konfigurierbar in config/app.php, Default 15 Minuten |
| 55 | Gleichzeitige Bearbeitung | Optimistic Locking + visueller Bearbeitungshinweis (Heartbeat) |
| 56 | Inline-Bilder | Originalgetreu beibehalten (CID → lokale URL bei Empfang, zurück bei Versand) |
| 57 | Original-E-Mail | RFC 822 Quelltext gespeichert, Antwort auf letzte E-Mail, Zitat editierbar |
| 58 | Mobile | Nur Gastzugang smartphone-optimiert, interner Bereich Desktop/Tablet |
| 59 | Benutzererstellung | Ausschließlich per Admin-Einladung, kein Self-Registration |
| 60 | Einladungslink-Ablauf | Konfigurierbar, Default 48 Stunden |
| 61 | Abgelaufene Einladung | Admin kann erneut versenden (neuer Link, Timer zurückgesetzt) |
| 62 | Benutzer-Löschung | Nie löschbar, nur deaktivierbar. DSGVO: Anonymisierung personenbezogener Daten |
| 63 | Freie Felder | Global definiert, pro Queue per Matrix aktivierbar. Typen: Text, Zahl, Datum, Boolean, Einfach-/Mehrfachauswahl |
| 64 | Tags / Schlagworte | Vordefiniert (Admin-Katalog) + frei eingebbar. Lookup auf existierende Tags bei Eingabe |
| 65 | Interne Checklisten | Vorlagen per Matrix pro Queue + individuelle Items. Fortschrittsbalken. Abhaken wird protokolliert |
| 66 | Wiedervorlage | Erinnerungsdatum pro Ticket, Benachrichtigung an Zugewiesenen, eigene Dashboard-Ansicht |
| 67 | Matrix-Konfiguration | Zweidimensionale Matrix für Zuordnungen die auf mehrere Elemente wirken (Felder↔Queues, Vorlagen↔Queues) |
| 68 | Eintragstypen | System- + benutzerdefinierte Typen, Pflichtfeld bei manuellen Einträgen, keine Icons/Farben, nur Text |
| 69 | Aktivitätenliste | Fixer Betreff-Header, schlanke Einträge: Datum/Uhrzeit, Typ, Ersteller (Benutzer/Kunde/System), Sichtbarkeit |
| 70 | Ticket- vs. Eintrag-Ebene | Sidebar = Ticket-Ebene (global). Detailbereich = Eintrag-Ebene (lokal pro Eintrag) |
| 71 | Sidebar-Reihenfolge | 1\. Stammdaten+Personen+SLA, 2. Referenzen+Termine, 3. Checklisten, 4. Tags+Freie Felder, 5. Ähnliche Tickets |
| 72 | Makros | Optional, Admin-konfigurierbar, per Matrix pro Queue. Vorschau + Bestätigung vor Ausführung |
| 73 | Kontextfilter Textbausteine | Optionale Filter pro Textbaustein: Status, Priorität, Tags (UND-verknüpft) |
| 74 | Ähnliche Tickets | Sidebar Bereich 5, Kriterien: gleiche Requester-E-Mail + gleiche Kundenreferenz, asynchron |
| 75 | Pflichtfeld-Regeln | Eigener Admin-Bereich mit Matrix. Bedingungen: immer Pflicht, Pflicht bei Status, Pflicht bei Eintragstyp |
| 76 | Abschlussgründe | Global definiert, per Matrix pro Queue. Pflicht bei Abschluss wenn konfiguriert. Defaults nach Installation |
| 77 | Tickettypen/Servicekatalog | Global definiert, per Matrix pro Queue. Nachträglich änderbar. Defaults nach Installation |
| 78 | Datenintegrität | Alle konfigurierbaren Werte: nie löschen, nur deaktivieren. Audit-Log für Änderungen. Unique-Constraint auf Namen |
| 79 | Queue-Gruppen | Queues in Gruppen organisiert. Queue-Wechsel nur innerhalb derselben Gruppe. Nie löschbar |
| 80 | Benutzergruppen | Indirekte Queue-Berechtigung über Gruppen, keine direkte Zuordnung. Benutzer in mehreren Gruppen möglich. Nie löschbar |
| 81 | Mailbox-Zuordnung | Eingangs-Mailbox: optional, unique pro Queue. Ausgangs-Mailbox: Pflicht, mehrfach nutzbar |
| 82 | Admin-Gruppenbindung | Admin-Zugriff gemäß zugewiesenen Core-Administrationsbereichen (Volladministrator = alle Bereiche; Plattform-Dokument, Kapitel 27.3.1, siehe Entscheidung 108). Ticket-Sichtbarkeit/Dashboard weiterhin nur über Benutzergruppen-Zuordnung |
| 83 | Ticketnummern-Format | Bleibt global, nicht pro Queue-Gruppe |
| 84 | Merge und Queue-Gruppen | Merge nur innerhalb derselben Queue-Gruppe, auch Administratoren nicht gruppenübergreifend |
| 85 | Anhänge per REST-API | Base64-kodierte Anhänge bei API- Ticketerstellung möglich (filename, content, content_type) |
| 86 | Wiedervorlage ohne Zuweisung | Benachrichtigung an alle berechtigten Benutzer der Queue |
| 87 | Pflichtfeld vs. Pflichtfeld-Regel | Additiv, strengste Anforderung gewinnt. Globales Pflichtfeld kann durch Regel nicht optional gemacht werden |
| 88 | Sichtbarkeit Eintragstypen | Zentrale Matrix mit Standard-Sichtbarkeit und Änderbarkeit pro Typ. Nur benutzerdefinierte Typen frei wählbar |
| 89 | Massenaktionen Queue-Wechsel | Queue-Gruppen-Regel gilt auch bei Massenaktionen. Nicht zulässige Tickets werden blockiert und separat ausgewiesen |
| 90 | SLA-Kalender Zuordnung | Ein Kalender pro Queue per Select-Feld (FK, nullable). Kein Matrix-Muster. NULL = 24×7-Betrieb |
| 91 | SLA-Kalender Löschbarkeit | Nie löschbar, nur deaktivierbar (gemäß 1.6). Deaktivierter Kalender → Queue fällt auf 24×7 zurück |
| 92 | Ausnahmetag-Listen | Eigene Entität mit m:n-Zuordnung zu Kalendern. Ermöglicht Wiederverwendung (z.B. "Feiertage DE" für mehrere Kalender). Nie löschbar |
| 93 | Geschäftszeit-Fenster | Beliebig viele Fenster pro Wochentag (ganztägig oder Startzeit/Endzeit). Keine Überlappung innerhalb eines Wochentags |
| 94 | Pausenzeit und Kalender | Pausenzeit wird in Geschäftsminuten gemessen (konsistent mit Kalender). Nur Geschäftsminuten innerhalb der Pause werden abgezogen |
| 95 | Lösungs-SLA Abschluss-Status | Alle drei Abschluss-Status zählen für die Ist-Zeit-Berechnung: closed_success, closed_failure, cancelled |
| 96 | SLA-Neuberechnungs-Trigger | Ticketerstellung, Prioritätswechsel und Queue-Wechsel. Startzeitpunkt bleibt stets das Erstellungsdatum |
| 97 | API-Lookup Sicherheit | Bei E-Mail-Mismatch immer HTTP 404 mit identischem Fehlercode wie bei nicht existierender Kundenreferenz. Kein Informationsleck |
| 98 | Einladungs-Widerruf Löschung | Explizite Ausnahme zu 1.6: Nicht-aktivierte Einladungs-Accounts (Status "eingeladen") dürfen bei Widerruf vollständig gelöscht werden |
| 99 | Produktpositionierung | Selbst hostbares, E-Mail-zentriertes Ticketsystem für strukturierte Serviceprozesse |
| 100 | Primärer Kanal | E-Mail first. GUI und API sind gleichwertige Ergänzungen, kein Ersatz |
| 101 | Bewusste Nicht-Ziele v1 | Kein Omnichannel, kein Workflow-Designer, keine KI, kein modul-eigenes LDAP/SSO, kein Echtzeit-Collaboration. („keine echte Mandantenfähigkeit" gestrichen — durch Entscheidung 122 / Plattform-185 überholt.) |
| 102 | Standard-Konfiguration | System ist nach Installation sofort lauffähig. Queue- und Mailbox-Einrichtung ist der einzige notwendige manuelle Schritt |
| 103 | Anforderungsklassifikation | Vierstufig: Muss, Soll, Empfehlung, Spätere Version. Ohne explizite Kennzeichnung gilt Muss |
| 104 | Architekturprinzipien | 9 verbindliche technische Prinzipien (Service-Schichten, zentrale Rechte, getrennte Mail-Ingestion, SLA-Service, einheitliches Eintragsmodell, Audit als Querschnitt, gemeinsame Fachlogik API/GUI, Referenzvalidierung, rückwärtskompatible Migrationen) |
| 105 | Abnahmekriterien | Testbare Kriterien für 9 Kernfunktionen als Muss-Anforderung für Release |
| 106 | Release-Kriterien v1 | Definierte Mindestanforderungen in 5 Kategorien (Kern, SLA, Sicherheit, Admin, Abnahme) als Voraussetzung für Produktivbetrieb |
| 107 | Observability via Health-Collector | Modul stellt keinen eigenen Monitoring-Endpoint, sondern liefert Mailbox-, E-Mail-Queue- und Cron-Checks als Health-Collector-Beiträge an die Plattform (Plattform 20.2.2/20.2.4). Widerspruch des alten 20.2 aufgelöst (A1) |
| 108 | Scoped-Admin (Administrationsbereiche) | Admin-Zugriff richtet sich nach den im Core zugewiesenen Administrationsbereichen (Plattform 27.3.1): Volladministrator = alle, delegierter Administrator = Teilmenge. Ersetzt das binäre „Administrator = alles" (2.1/2.5/12.9, A2) |
| 109 | DSGVO-Anonymisierung zentral | Benutzer-Anonymisierung erfolgt über die zentrale Plattformfunktion (Plattform 27.15.3); das Modul dupliziert sie nicht mehr. Ticket-Hard-Delete bleibt moduldefiniert (17.2, A3) |
| 110 | Benachrichtigungen event-getrieben | Benachrichtigungen, Dashboard-Ereignisse und Digest sind Listener auf Ticket-Domänenereignisse über den transaktionalen Plattform-Outbox (Plattform 26.9.2); mindestens-einmal, idempotent (Kap. 8, A4) |
| 111 | email_queue als Outbox-Spezialisierung | email_queue ist eine SMTP-spezifische Spezialisierung des Plattform-Jobsystems (Plattform 26.9.2), keine Parallelwelt; Dead-Letter auch in der Plattform-Statusfläche sichtbar (3.11, A5) |
| 112 | Rechte-Granularität BREAD/Zusatzaktionen | Queue-Zugriff ist Ausprägung des Plattform-BREAD-Modells (Plattform 25). Standardprofil = volles Agentenprofil; feinere gruppenbezogene Stufen sind ohne Bruch der additiven Aggregation möglich (eigene Ausbaustufe). Ausschlüsse über Ressourcen-Schnitt (Plattform 25.6.3), kein Deny (2.4.1, A6) |
| 113 | SSO über Plattform-Auth-Resolver | LDAP/OIDC/SAML-SSO ist Plattformfähigkeit (austauschbarer Auth-Resolver, Default lokal; Plattform 27.2.2), kein modul-eigenes Feature; aus den Modul-Nicht-Zielen entfernt (1.3.3/19.3, A7) |
| 114 | 2FA über Plattform-Auth | TOTP-2FA ist Bestandteil der Plattform-Authentifizierung (Plattform 27.2.2), kein Ticketing-Feature (19.6, A9) |
| 115 | API-Auth-Abgrenzung | Der queue-gebundene externe API-Token ist ein bewusst gast-äquivalentes Schema (Gast-Level-Sichtbarkeit), nicht benutzergebunden und ohne BREAD-Rechte; abgegrenzt vom benutzergebundenen Plattform-API-Modell (Plattform 27.16.3) (3.14.2, A8) |
| 116 | Wissensdatenbank-Integration | Anbindung über Integrations-Extension-Modul; das Ticketing-Main-Modul stellt Contracts/UI-Erweiterungspunkte bereit (Plattform 26.3.4/29). Verknüpfung lebt im Integrationsmodul, nicht im Ticket-Datenmodell (19.4, A10) |
| 117 | Volltextsuche-Skalierung | LIKE-Suche mit führendem Platzhalter erzeugt Full-Scans auf ticket_comments; v1-Suchumfang bewusst begrenzen oder dedizierten Suchindex früh einplanen. NFR-Ziel gilt für Pagination, nicht für Eintrags-Volltextsuche (12.5.1, R1) |
| 118 | ticket_comments-Strategie | Größte/meistgelesene Tabelle: Indizes auf ticket_id, Eintragstyp, is_public; keine Volltext-LIKE direkt darauf; Partitionierung nach Zeitraum/Ticket als Betreibermaßnahme empfohlen (20.5, R2) |
| 119 | Datenbank PostgreSQL | Das Modul folgt der Plattform-Entscheidung 173: PostgreSQL statt MySQL. Backup via pg_dump/PITR (20.1), Betriebsgrenzen (20.7) angepasst. Keine fachliche Änderung am Datenmodell (CakePHP-ORM DB-agnostisch) |
| 120 | Volltextsuche via PostgreSQL FTS | Freitextsuche über native PostgreSQL-Volltextsuche (tsvector/GIN) statt LIKE; löst das R1-Skalierungsrisiko auf ticket_comments ohne externe Suchmaschine. pg_trgm für Präfix-/Teilstring-Lookups; Elasticsearch nur für sehr große/erweiterte Fälle (12.5.1, P8) |
| 121 | Überlappungsfreie SLA-Fenster via Exclusion-Constraint | Nicht-überlappende Geschäftszeit-Fenster pro Wochentag werden über ein GiST-Exclusion-Constraint in der DB erzwungen (7.7.2; Plattform-Dokument 30.2, P9) |
| 122 | Mandantenfähigkeit verbindlich (Plattform-185) | Die frühere Nicht-Ziel-Aussage „keine echte Mandantenfähigkeit" ist überholt. Mandantentrennung ist fail-closed umgesetzt (tenant_id + RLS, Pre-Auth-Tenant-Ableitung, Worker pro Mandant, Ticketnummern pro Mandant); Queue-Gruppen = orthogonale Bereichstrennung innerhalb eines Mandanten. Details/Abnahme: Modul-Spezifikation §9 |

# Modul-Entwicklung — Andock-Punkte & Anforderungen (Konzept)

Wie Module und Extensions an den Core andocken, welche Pflichten sie haben und
wie Integrations-Extension-Module konzipiert sind. Der Core stellt die
Mechanismen bereit; die **Fachlichkeit liegt im Modul**.

## 1. Manifest & Paketstruktur

```
<paket>/
  manifest.json            # Pflichtfelder s. Kap. 24.4.1 (id,name,version,type,
                           # edition,description,core_compatibility,publisher,
                           # php_namespace) + Deklarationen (s. u.)
  src/                     # Code unter dem php_namespace (Autoload-Wurzel = „entrypoint")
  migrations/*.sql         # Modul-Schema mod_<id>, je Datei „-- @DOWN" für Rollback
  locales/<locale>/<id>.po # mind. en_US (i18n, s. I18N.md)
  signature.json           # Paketsignatur (Installation prüft gegen Trust-Anker)
```

Installation legt das Schema `mod_<id>` an, fährt die Migrationen hoch, kopiert
Locales in den Store und registriert die Deklarationen (s. u.).

## 2. Andock-Punkte (Kap. 24.6/24.7, 29) — *C2*

Alle Erweiterungen werden **im Manifest deklariert** und beim Install registriert:

| Manifest-Feld | Zweck | Core-Mechanismus |
|---|---|---|
| `contracts_provided` | Eigene Contracts (Event/Service/Resolver/Collector) | ContractRegistry |
| `contracts_used` | Genutzte Contracts anderer Module | Capability-Binding |
| `events_registered` | Event-Listener (`App\Event\EventListenerInterface`) | Outbox-Worker |
| `services_registered` | Service-Implementierungen (Modul-Interfaces, Kap. 29) | Service-Resolver |
| `resolvers_registered` | Austauschbare Provider (z. B. Auth-Slot) | Resolver-Registry |
| `collectors_registered` | Beiträge zu Core-Collectoren (s. u.) | ContractRegistry-Collector |
| `permissions` | BREAD-Ressourcen (+ `is_scoped`, s. §4) | resources-Tabelle |
| `locales` | Sprachpakete (Domain = `id`) | Managed Locale Store |

> **Regel „enhancing, nicht gating" (Plattform 26.19.1, Entscheidung 184).**
> Optionale Capabilities erweitern einen Ablauf, bedingen ihn nie — die
> Abwesenheit eines Providers muss ein definierter neutraler Zustand sein.
> Daraus zwei konkrete Pflichten für Modul-Entwickler:
> - **Bereitgestellte Resolver-/Service-Contracts** (`contracts_provided` mit
>   `type` `resolver`|`service`) **müssen** das Feld **`error_behavior`**
>   setzen (die Abwesenheits-/Default-Semantik). Fehlt es, **scheitern
>   Manifest-Linter und Aktivierung** (Collector/Event sind ausgenommen —
>   additiv: leere Menge / No-op).
> - **Genutzte Contracts** (`contracts_used`): die Abweisung eines Aufrufs
>   (kein aktiver Anbieter, Kap. 26.13.3) **muss** im Modulcode neutral
>   behandelt werden — Default, leeres Ergebnis oder Ausblenden, nie ein
>   blockierter Pflicht-Flow. Das ist **Review- und Abnahmekriterium**
>   („Funktion bleibt mit abwesendem Provider voll nutzbar"), nicht statisch
>   prüfbar.

### 2.1 Periodische Aufgaben — z. B. Ticketing `fetch_mails` / `check_escalations`

Der Core-Worker tickt registrierte **`ScheduledTaskInterface`**-Implementierungen
im jeweils angegebenen Intervall (fehlerisoliert, mit Heartbeat → erscheinen in
der Worker-Health-Übersicht inkl. Überfälligkeitswarnung).

```php
// im Modul:
final class FetchMailsTask implements \App\Service\Schedule\ScheduledTaskInterface
{
    public function key(): string { return 'ticketing.fetch_mails'; }
    public function intervalSeconds(): int { return 60; }
    public function run(): void { /* IMAP holen, Tickets anlegen … (Modul-Fachlogik) */ }
}
```
Registrierung im Manifest über den Collector `core.collector.scheduled`:
```json
"collectors_registered": [
  { "contract": "core.collector.scheduled", "version": ">=1.0.0 <2.0.0",
    "class": "Ticketing\\Schedule\\FetchMailsTask" }
]
```
Der Core garantiert: Ausführung im Intervall, Fehlerisolation, Heartbeat,
Sichtbarkeit in Health. **Die Mailbox-/Eskalationslogik bleibt im Modul.**

### 2.2 Health-Beiträge

Analog über `core.collector.health` + `App\Service\Health\HealthCheckInterface`
(z. B. „IMAP erreichbar", „Eskalationsrückstand"). Ergebnis fließt in den
Health-Report (`module_contributions`).

### 2.3 E-Mail-Versand

Über den Core-`MailService` (konfigurierter Transport, Dev → Mailpit). Module
liefern Inhalt/Empfänger; Transport/Settings bleiben zentral (E35).

### 2.4 Outbox-Events

Fachliche Ereignisse über die Outbox publizieren (transaktional, At-least-once);
Listener anderer Module reagieren entkoppelt. Dead-Letter-Handling + Retry-GUI
stellt der Core bereit.

### 2.5 Web-Oberflächen (Web-Mount, Kap. 23.16.3)

Server-gerenderte Modul-Seiten werden vom Core unter `/m/<key>[/<pfad>]` montiert
(volle Web-Kette: Session-Auth, CSRF, RLS, Security-Header). **Der Core behält die
Hoheit** über Routing, Auth, Layout und Ausgabe — das Modul liefert nur einen
Handler + eine Template-Datei, **niemals** eigene Core-Routen oder Core-Code.

Manifest-Sektion `web_routes`, je Eintrag:

```json
"web_routes": [
  { "path": "/tickets/{id}", "class": "Ticketing\\Web\\TicketView",
    "template": "ticket_view", "auth": "user" },
  { "path": "/portal", "class": "Ticketing\\Web\\GuestPortal",
    "template": "guest_portal", "auth": "guest" },
  { "path": "/admin/queues", "class": "Ticketing\\Web\\QueueAdmin",
    "template": "admin/queues", "auth": "user",
    "area": "ticketing_admin", "nav_group": "ticketing.nav.group",
    "nav": "ticketing.nav.queues", "title": "Warteschlangen" }
]
```

- **`class`** implementiert `App\Service\Module\ModuleWebInterface::handle(array): array`
  und gibt **nur Daten** zurück (`vars`/`status`/`template`/`redirect`) — kein HTML,
  keine Response-Manipulation.
- **`template`**: Datei unter dem modul-eigenen `templates/`-Verzeichnis,
  **snake_case** (CakePHP inflektiert; PascalCase bricht auf Linux/CI). Unterpfade
  erlaubt (`admin/queues` → `templates/admin/queues.php`). Es stehen die Core-
  `Form`/`UiKit`-Helfer + das gewählte Layout zur Verfügung (CSRF wird automatisch
  eingebunden).
- **`auth`**: `user` (Login nötig) | `guest` (öffentlich).
- **`area`** (optional): macht die Seite zu einer **Admin-Seite** → Rendern im
  Core-Admin-Shell (Admin-Layout + scoped Sidebar), Zugriff nur mit diesem Bereich.
  Mit `nav`+`nav_group` erscheint sie als Sidebar-Eintrag. Der Bereich wird bei
  Aktivierung in `admin_areas` registriert (→ vergebbar) und bei Deinstallation
  entfernt. Ohne `area` rendert die Seite eigenständig im `module`-Layout.
- Vor dem Packen `bin/cake module_lint` (prüft `web_routes`).

### 2.6 Externe REST-API & Auth-Modus (Kap. 29)

Modul-Endpunkte werden unter `/api/v1/m/<key>[/<pfad>]` gemountet (Sektion
`api_routes`: `method`, `path`, `class`→`handle(array):array`, optional `scope`).
Neu: das Feld **`auth`**:

- **`user`** (Default): erfordert ein gültiges Core-Bearer-Token; optionaler
  `scope` wird geprüft.
- **`public`**: **kein** Core-Token nötig — das **Modul verantwortet die Auth
  selbst** (z. B. ein queue-gebundenes Modul-Token, das es aus einem Header liest;
  der Core reicht alle Request-Header im `request['headers']`-Array durch). Läuft
  **ohne Core-Identität** (anonymer RLS-Kontext — gast-sichtbare Daten brauchen
  eine explizite „public-read"-Policy im Modul-Schema) und bleibt **rate-limited
  pro IP**.

Der Core stellt **kein** eigenes Modul-Token-System bereit (Entscheidung D1) — das
Modul speichert/prüft seine Tokens selbst (z. B. in `mod_<key>`).

### 2.7 Benutzer/Gruppen lesen (Identitäts-Zugriff, Kap. 27)

Für eigene Zuordnungen (Queue↔Gruppe, Bearbeiter-Anzeige) löst ein Modul Benutzer
und Gruppen über den Core-Service `App\Service\Identity\IdentityReader` auf
(direkt instanziierbar, wie `MailService`) — **nicht** durch Zugriff auf die
Core-Identitätstabellen.

```php
$reader = new \App\Service\Identity\IdentityReader();
$reader->users();              // [['id'=>…, 'display_name'=>…], …]  (aktive)
$reader->groups();             // [['id'=>…, 'name'=>…], …]
$reader->userGroups($userId);  // Gruppen eines Benutzers
```

**Datensparsam (Entscheidung D4):** nur IDs, Anzeigename und Gruppen-IDs/-Namen —
**kein** E-Mail/Status/sonstiges PII. Läuft im RLS-Kontext des Aufrufers. Wer mehr
braucht, muss eine eigene, auditierte Capability beantragen.

## 3. Integrations-Extension-Module (Kap. 29.9/29.10) — *C5 (Konzept)*

Ein Integrations-Extension-Modul verknüpft **mehrere Main-Module**, ohne deren
Code zu ändern. Anforderungen:

- **Typ** `extension` mit `extends_main_module` + `main_module_compatibility`.
- Deklariert die verknüpften Main-Module über **`integration_relations`** im
  Manifest (nur Integrations-Extensions); nutzt deren öffentliche Contracts via
  `contracts_used` (konsumiert Service-/Event-Interfaces, Kap. 29.5/29.8).
- **Eigene Datenhaltung** ausschließlich im eigenen Schema `mod_<id>`
  (Mapping-/Verknüpfungstabellen); **kein** direkter Zugriff auf fremde
  Modul-Schemata — Kopplung nur über deklarierte Contracts.
- Respektiert Exklusivitäts-Regeln (`multi_use=false`) und die
  Versions-Constraints der genutzten Interfaces.
- Liefert eigene Migrationen (mit `@DOWN`), Locales und ggf. Health-Beiträge.

> Verbindlich umzusetzen erst mit dem konkreten Integrationsmodul; hier als
> Konzept/Checkliste festgehalten (vom Nutzer so beauftragt).

## 4. RLS-Pflicht für scoped Ressourcen (Kap. 30.3) — *C6 (Doku)*

Deklariert ein Modul eine Ressource mit `is_scoped: true`, **muss** sein Schema
nach den Migrationen mindestens eine RLS-aktivierte Tabelle **mit Policy**
enthalten — andernfalls bricht der Core die Installation ab (E47, geprüft via
`pg_class.relrowsecurity` + `pg_policies`).

Der Core setzt pro Request den Zugriffskontext via `SET LOCAL`:

| Setting | Inhalt |
|---|---|
| `app.current_user_id` | UUID des angemeldeten Benutzers (leer = anonym) |
| `app.current_group_ids` | kommaseparierte Gruppen-UUIDs |
| `app.bypass_rls` | `true` für privilegierte Pfade (Worker/Wartung) |

Referenz-Policy (aus dem `sample_module`-Fixture):
```sql
ALTER TABLE my_table ENABLE ROW LEVEL SECURITY;
CREATE POLICY my_table_scope ON my_table
    USING (
        current_setting('app.bypass_rls', true) = 'true'
        OR owner_id IS NULL
        OR owner_id::text = current_setting('app.current_user_id', true)
    );
```
Empfehlung: Scoping-Spalte (`owner_id`/`tenant_id`/Gruppen-Referenz) + `WITH
CHECK` für Schreibpfade ergänzen, wo Einfügungen begrenzt werden sollen. Bei
Breaking-Änderungen an Scoping/Schlüsseln die **Major-Version** erhöhen.

## 5. Lebenszyklus-Hinweise

- Migrationen **reversibel** (`@DOWN`); destruktive Änderungen via expand/contract.
- Deinstallation entfernt Schema + Registrierungen, **behält** aber Sprachdateien.
- Signatur wird **bei der Installation** geprüft (Trust-Anker, Gültigkeitsfenster,
  Vertrauenskette); Widerruf wirkt nachträglich (Kennzeichnung in Modul-Liste/Health).

## 6. Sicherheits- und Vertrauensmodell (Kap. 23.16)

Module laufen **immer in-process** — als vertrauenswürdiger Code im selben
Laufzeitkontext wie der Core (kein technischer Subprozess-Sandbox). Die
Sicherheitsgrenze ist daher **vor der Ausführung** etabliert, nicht zur Laufzeit
erzwungen. Sie hat zwei Stufen:

1. **Vertrauenskette (maßgeblich).** Ein Modulpaket wird **bei der Installation**
   gegen Trust-Anker, Gültigkeitsfenster und Signatur geprüft (Kap. 24.9); Widerruf
   wirkt nachträglich (Kennzeichnung in Modul-Liste/Health). Nur kuratierte,
   signierte Pakete werden zugelassen — Vertrauen wird durch Review + Signatur
   hergestellt, nicht durch Laufzeit-Isolation.

2. **Capability-Gate (Defense in Depth).** Zusätzlich scannt der Core bei der
   Installation den Modul-Quellcode (`src/`) statisch und **weist ein Paket ab**,
   das gefährliche PHP-Primitive verwendet — noch bevor irgendein Seiteneffekt
   eintritt (`ManifestLinter::lintCapabilities` → `ModuleLifecycle::install`).
   Verboten sind u. a.:
   - **Shell/Prozess:** `exec`, `shell_exec`, `system`, `passthru`, `proc_open`,
     `popen`, `pcntl_exec`.
   - **Code-Eval:** `eval`, `create_function`, `assert('…')`.
   - **Reflection/Sichtbarkeits-Umgehung:** `new Reflection*`, `->setAccessible(…)`.
   - **Rohe DB-Verbindung:** `new \PDO`, `pg_connect`, `ConnectionManager::setConfig`
     u. ä. — DB-Zugriff läuft ausschließlich über die Core-`default`-Connection.
   - **Roher Dateizugriff:** `fopen`, `file_get_contents`/`file_put_contents`,
     `mkdir`, `unlink`, `scandir`, `glob` … — Dateien laufen über
     `ModuleStorage::for()` (mandantengetrennt, Kap. 6.x / Inc 8).
   - **Variable-Variablen** (`$$x`) und Umgebungs-/Config-Mutation (`putenv`,
     `ini_set`, `dl`).

   Der Matcher trifft nur **bare** globale Aufrufe, sodass legitime Methoden gleichen
   Namens erlaubt bleiben (`$conn->exec(…)`, `Db::exec(…)`,
   `ConnectionManager::get('default')`, Socket-`fwrite`/`fgets`, `preg_replace('/…/u')`).
   Die drei mitgelieferten Module (Ticketing, Knowledge-Base, Connector) passieren
   das Gate ohne Treffer.

Das Gate ist **Defense in Depth, keine Sandbox:** statische Analyse ist umgehbar
(dynamische Aufrufe, String-Tricks). Die eigentliche Zulassungsgrenze bleibt die
Signatur-/Vertrauenskette; das Gate fängt offensichtliche Verstöße früh und hält das
„review-then-trust"-Modell ehrlich. Die **Mandantentrennung** kommt unabhängig davon
aus Postgres-RLS (App-Rolle `NOBYPASSRLS` + `FORCE ROW LEVEL SECURITY` auf
modul-eigenen Tabellen, gesetzt durch `ModuleTableRls::forceRls`).

Während der Entwicklung meldet `bin/cake module_lint` dieselben Capability-Verstöße
(plus die Storage-Konventions-Prüfung) zur Autorenzeit, damit ein Paket gar nicht
erst beim Install scheitert.

## 7. Erweiterungspunkte des Programms „Wettbewerbsfähigkeit Core" (Tier 1–3)

Über die klassischen Andock-Punkte hinaus stehen Modulen folgende neue
Core-Contracts/Interfaces zur Verfügung (Katalog live: `bin/cake module_contracts`):

| Contract | Manifest-Sektion | Interface | Zweck |
|---|---|---|---|
| `core.api.route` | `api_routes` (method/path/class) | `App\Service\Api\ApiEndpointInterface` | Eigene REST-Endpunkte unter `/api/v1/m/<key>/…` |
| `core.collector.search` | `collectors_registered` | `App\Service\Search\SearchIndexerInterface` | Inhalte für die Volltext-Suche indexieren |
| `core.collector.notification_channel` | `collectors_registered` | `App\Service\Notification\NotificationChannelInterface` | Zusätzliche Benachrichtigungs-Kanäle (z. B. Slack) |
| `core.ai.complete` / `core.ai.embed` | — (Core-Service) | `App\Service\Ai\AiGateway` | LLM-Vervollständigung / Embeddings (provider-agnostisch) |
| `core.collector.scheduled` | `collectors_registered` | `App\Service\Schedule\ScheduledTaskInterface` | Periodische Aufgaben (vom Worker getickt) |

Weitere Core-Primitive (ohne Modul-Contract, direkt nutzbar): Outbound-Webhooks
(Events → externe HTTP-Ziele, HMAC-signiert), Echtzeit-Push via SSE
(`RealtimeService`), Automations-/Workflow-Engine (Event-Condition-Action +
State-Machines), Objekt-Storage (`StorageManager`, lokal/S3), Export
(`ExportService`, CSV/XLSX/PDF), gehärteter HTTP-Egress (`EgressClient`, SSRF-Schutz)
und Cache (`CacheStore`). SDK-Werkzeuge: `module_scaffold`, `module_lint`,
`module_contracts`.

**Hinweis Manifest-Linter:** `bin/cake module_lint <pfad>` prüft u. a., dass
`api_routes[].class` im `php_namespace` liegt und Pfade nur einfache Segmente +
`{platzhalter}` enthalten (keine Regex-Metazeichen).

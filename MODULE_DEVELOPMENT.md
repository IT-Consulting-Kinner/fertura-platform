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

## 6. Optionale Out-of-Process-Isolation (Kap. 23.16.2)

Standardmäßig laufen Module **in-process** (`isolation = in_process`). Für eine
echte, **automatisch verwaltete** technische Isolationsgrenze kann ein Modul auf
`out_of_process` gesetzt werden:

```bash
bin/cake module install /pfad/zum/modul --isolation out_of_process
bin/cake module isolate <key> out_of_process   # nachträglich umschalten
bin/cake module host status                     # laufende Hosts anzeigen
```

- Der Core legt **automatisch** eine **eigene, eingeschränkte DB-Rolle**
  (`mod_<key>`, `LOGIN`, `NOBYPASSRLS`) mit zufälligem, **verschlüsselt**
  gespeichertem Passwort an — Rechte nur auf das eigene Schema, kein Core-
  Tabellenzugriff.
- Die **Modul-Migrationen laufen unter dieser Rolle** (kein Superuser-Code);
  danach erzwingt der Core `FORCE ROW LEVEL SECURITY`, damit RLS auch für die
  modul-eigenen Tabellen greift.
- Der Modulcode läuft in einem vom Core verwalteten **Subprozess**
  (`bin/module-host.php`) mit **bereinigter Umgebung** (kein Core-`DATABASE_URL`,
  kein `BACKUP_PASSWORD`). Ein **Supervisor** startet ihn beim Aktivieren, stoppt
  ihn beim Deaktivieren/Löschen und startet abgestürzte Hosts neu (der Worker
  überwacht periodisch).
- Der Core ruft **alle gängigen Erweiterungspunkte** transparent über RPC im Host
  auf (`RemoteInvoker`/`ContributionRuntime`): **Service-Contracts**
  (`services_registered`), **Collector-Beiträge** (`collectors_registered`, z. B.
  Health/Anonymisierung **und periodische Aufgaben** `core.collector.scheduled`),
  **Event-Listener** (`events_registered`) und **Daten-Resolver**
  (`resolvers_registered`). Der **RLS-Zeilenkontext** der Anfrage wird mitgereicht;
  Beitragsklassen nutzen im Host `ConnectionManager::get('default')` auf die
  Modul-Rolle (Search-Path aufs Modul-Schema). Bei **periodischen Aufgaben**
  bleiben Fälligkeits-Prüfung (Heartbeat) und der Mehrinstanz-Advisory-Lock im
  Core; nur `run()` reist über RPC (Systemkontext, RLS-Bypass).
- **Einzige Ausnahme:** der **Auth-Provider-Slot** (`core.auth.provider`) — er ist
  config-artig (liefert ein In-Process-Authenticator-Objekt, das nicht über RPC
  reichbar ist) und wird bei Isolation **abgelehnt** (statt still in-process).
- **Optionale OS-Härtung (Launcher-Prefix):** Das Setting
  `core.module.host.launcher` setzt ein Befehls-Prefix vor `php`, um den
  Host-Prozess zusätzlich vom OS zu isolieren — **ohne Core-Codeänderung**. Z. B.
  `setpriv --reuid=1001 --regid=1001 --clear-groups --` (eigener OS-Benutzer;
  erfordert OS-Rechte), `bwrap --unshare-all --ro-bind / / --proc /proc --dev /dev
  --die-with-parent` (FS-/Kernel-Sandbox) oder `firejail`. Der Befehl muss das
  Image bereitstellen und Argumente an `php` durchreichen. Leer = kein Prefix
  (Default). Der Launcher muss `php` per `exec` ersetzen oder SIGTERM
  weiterreichen und mit dem Elternprozess sterben (z. B. `setpriv … --`,
  `bwrap … --die-with-parent`) — sonst kann beim Stoppen ein verwaister Host
  zurückbleiben. Wer das Setting setzen darf, kann Code als Worker-Benutzer
  ausführen (auf Shell-Vertrauensstufe beschränken).
- **Pro-Aufruf-Authentifizierung:** Jeder RPC-Aufruf trägt ein aufruf-gebundenes
  Capability-Token (HMAC über die kanonisierte Anfrage + Nonce + Ablauf). Das
  Host-Geheimnis liegt nur in einer 0600-Datei, dient ausschließlich als
  Schlüssel und reist nie über den Socket; der Host weist abgelaufene und
  wiederholte Nonces sowie manipulierte Anfragen ab und startet ohne Geheimnis
  gar nicht erst (fail-closed). Für die Modulentwicklung transparent (der Core
  signiert/prüft automatisch). Das Token sichert die Prozessgrenze (Core→Host),
  nicht den Modulcode selbst — die Isolation leisten DB-Rolle, bereinigte
  Umgebung und die optionale OS-Härtung.
- **Transaktionsgrenze:** Ein Out-of-Process-Beitrag committet in seiner eigenen
  Sitzung — Core-Operation und Modul-Beitrag bilden keine verteilte Transaktion.
- Verifikation: `OutOfProcessIsolationTest` + `OutOfProcessPhase3Test` (E2E) und
  `core/tests/scripts/module_isolation_check.sh` (manuell).

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

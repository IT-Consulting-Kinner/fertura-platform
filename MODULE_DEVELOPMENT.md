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
  { "collector": "core.collector.scheduled", "version": ">=1.0.0 <2.0.0",
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
- Der Core ruft die **Service-Contracts** (`services_registered`) transparent
  über einen Unix-Domain-Socket (JSON-Zeilen-RPC, `RemoteInvoker`) auf;
  `CapabilityHandle::invoke()` routet automatisch dorthin. Ein-/Ausgabe sind
  serialisierbare Contract-Arrays (Kap. 29.8).
- **Einschränkung (bewusst):** Isolierte Module dürfen **nur Service-Contracts**
  anbieten. Deklariert ein Modul Resolver/Collector/Event-Listener, wird die
  Isolation **abgelehnt** (diese Erweiterungspunkte laufen noch nicht über RPC
  und würden sonst still in-process ausgeführt).
- Verifikation: `OutOfProcessIsolationTest` (E2E) und
  `core/tests/scripts/module_isolation_check.sh` (manuell).
